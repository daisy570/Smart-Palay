<?php
require_once __DIR__ . '/../config/database.php';

if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$userId   = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Administrator';

/* ============================================================
   DATE RANGE — month & year focus
   ============================================================ */
$preset = $_GET['preset'] ?? 'month';
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to']   ?? '');

/* Compute the ranges used for the SQL queries.
   For "custom", keep $from/$to as-is (may be empty) and fall back
   to the current month only for the query so the page has data. */
if ($preset === 'custom') {
    $queryFrom = $from !== '' ? $from : date('Y-m-01');
    $queryTo   = $to   !== '' ? $to   : date('Y-m-t');
} else {
    switch ($preset) {
        case 'month':
            $queryFrom = date('Y-m-01');
            $queryTo   = date('Y-m-t');
            break;
        case 'last_month':
            $queryFrom = date('Y-m-01', strtotime('first day of last month'));
            $queryTo   = date('Y-m-t',  strtotime('last day of last month'));
            break;
        case 'quarter':
            $q = (int) ceil(date('n') / 3);
            $startMonth = ($q - 1) * 3 + 1;
            $queryFrom = date('Y-' . str_pad($startMonth, 2, '0', STR_PAD_LEFT) . '-01');
            $queryTo   = date('Y-m-t', strtotime($queryFrom . ' +2 months'));
            break;
        case 'year':
            $queryFrom = date('Y-01-01');
            $queryTo   = date('Y-12-31');
            break;
        case 'last_year':
            $y = (int) date('Y') - 1;
            $queryFrom = $y . '-01-01';
            $queryTo   = $y . '-12-31';
            break;
        default:
            $queryFrom = date('Y-m-01');
            $queryTo   = date('Y-m-t');
    }
}

if (strtotime($queryFrom) > strtotime($queryTo)) {
    [$queryFrom, $queryTo] = [$queryTo, $queryFrom];
}

$fromDT = $queryFrom . ' 00:00:00';
$toDT   = $queryTo   . ' 23:59:59';

/* Previous period (same length, immediately before) */
$days = max(1, (int) ((strtotime($queryTo) - strtotime($queryFrom)) / 86400) + 1);
$prevTo   = date('Y-m-d', strtotime($queryFrom . ' -1 day'));
$prevFrom = date('Y-m-d', strtotime($prevTo  . ' -' . ($days - 1) . ' days'));
$prevFromDT = $prevFrom . ' 00:00:00';
$prevToDT   = $prevTo   . ' 23:59:59';

/* ============================================================
   HELPERS
   ============================================================ */
function peso($n) { return '₱' . number_format((float) $n, 2); }
function kg($n)   { return number_format((float) $n, 2) . ' kg'; }
function pct($cur, $prev) {
    if ($prev <= 0 && $cur <= 0) return 0;
    if ($prev <= 0) return 100;
    return (($cur - $prev) / $prev) * 100;
}
function niceDate($s) {
    if (!$s) return '—';
    $t = strtotime($s);
    return $t ? date('M j, Y', $t) : '—';
}

/* ============================================================
   CORE METRICS (current period)
   ============================================================ */
$cur = [
    'purchases'      => 0,
    'palay_kg'       => 0.0,
    'gross'          => 0.0,
    'paid'           => 0.0,
    'outstanding'    => 0.0,
    'payments_count' => 0,
    'payments_amt'   => 0.0,
    'new_farmers'    => 0,
    'new_buyers'     => 0,
];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c,
               COALESCE(SUM(weight_kg),0) AS kg,
               COALESCE(SUM(total_amount),0) AS gross,
               COALESCE(SUM(total_amount - balance),0) AS paid,
               COALESCE(SUM(balance),0) AS outstanding
        FROM purchases
        WHERE created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$fromDT, $toDT]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $cur['purchases']   = (int) ($r['c'] ?? 0);
    $cur['palay_kg']    = (float) ($r['kg'] ?? 0);
    $cur['gross']       = (float) ($r['gross'] ?? 0);
    $cur['paid']        = (float) ($r['paid'] ?? 0);
    $cur['outstanding'] = (float) ($r['outstanding'] ?? 0);
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) c, COALESCE(SUM(amount),0) amt
        FROM payments
        WHERE paid_at BETWEEN ? AND ?
    ");
    $stmt->execute([$fromDT, $toDT]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $cur['payments_count'] = (int) ($r['c'] ?? 0);
    $cur['payments_amt']   = (float) ($r['amt'] ?? 0);
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='farmer' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$fromDT, $toDT]);
    $cur['new_farmers'] = (int) $stmt->fetchColumn();
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='buyer' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$fromDT, $toDT]);
    $cur['new_buyers'] = (int) $stmt->fetchColumn();
} catch (Throwable $e) {}

/* ============================================================
   PREVIOUS PERIOD
   ============================================================ */
$prev = [
    'purchases'      => 0,
    'palay_kg'       => 0.0,
    'gross'          => 0.0,
    'paid'           => 0.0,
    'payments_count' => 0,
    'payments_amt'   => 0.0,
    'new_farmers'    => 0,
    'new_buyers'     => 0,
];
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) c, COALESCE(SUM(weight_kg),0) kg,
               COALESCE(SUM(total_amount),0) gross,
               COALESCE(SUM(total_amount - balance),0) paid
        FROM purchases
        WHERE created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$prevFromDT, $prevToDT]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $prev['purchases'] = (int) ($r['c'] ?? 0);
    $prev['palay_kg']  = (float) ($r['kg'] ?? 0);
    $prev['gross']     = (float) ($r['gross'] ?? 0);
    $prev['paid']      = (float) ($r['paid'] ?? 0);
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) c, COALESCE(SUM(amount),0) amt
        FROM payments WHERE paid_at BETWEEN ? AND ?
    ");
    $stmt->execute([$prevFromDT, $prevToDT]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $prev['payments_count'] = (int) ($r['c'] ?? 0);
    $prev['payments_amt']   = (float) ($r['amt'] ?? 0);
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='farmer' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$prevFromDT, $prevToDT]);
    $prev['new_farmers'] = (int) $stmt->fetchColumn();
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='buyer' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$prevFromDT, $prevToDT]);
    $prev['new_buyers'] = (int) $stmt->fetchColumn();
} catch (Throwable $e) {}

/* Deltas */
$delta = [
    'purchases'      => pct($cur['purchases'], $prev['purchases']),
    'palay_kg'       => pct($cur['palay_kg'], $prev['palay_kg']),
    'gross'          => pct($cur['gross'], $prev['gross']),
    'payments_amt'   => pct($cur['payments_amt'], $prev['payments_amt']),
];

/* ============================================================
   CHART SERIES — bucket by day / month based on range length
   ============================================================ */
$series = [];
$cursor = strtotime($queryFrom);
$end    = strtotime($queryTo);

$rangeDays = max(1, (int) ((($end - $cursor) / 86400) + 1));
$useMonthlyBuckets = $rangeDays > 45;

if ($useMonthlyBuckets) {
    $mCursor = strtotime(date('Y-m-01', $cursor));
    $mEnd    = strtotime(date('Y-m-01', $end));
    while ($mCursor <= $mEnd) {
        $series[date('Y-m', $mCursor)] = ['kg' => 0.0, 'amount' => 0.0];
        $mCursor = strtotime('+1 month', $mCursor);
    }
} else {
    while ($cursor <= $end) {
        $d = date('Y-m-d', $cursor);
        $series[$d] = ['kg' => 0.0, 'amount' => 0.0];
        $cursor += 86400;
    }
}

try {
    if ($useMonthlyBuckets) {
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') ym,
                   COALESCE(SUM(weight_kg),0) kg,
                   COALESCE(SUM(total_amount),0) amt
            FROM purchases
            WHERE created_at BETWEEN ? AND ?
            GROUP BY ym
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT DATE(created_at) d,
                   COALESCE(SUM(weight_kg),0) kg,
                   COALESCE(SUM(total_amount),0) amt
            FROM purchases
            WHERE created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at)
        ");
    }
    $stmt->execute([$fromDT, $toDT]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $useMonthlyBuckets ? $row['ym'] : $row['d'];
        if (isset($series[$key])) {
            $series[$key]['kg']     = (float) $row['kg'];
            $series[$key]['amount'] = (float) $row['amt'];
        }
    }
} catch (Throwable $e) {}

$chartData = [];
$chartLabels = [];
if ($useMonthlyBuckets) {
    foreach ($series as $ym => $v) {
        $chartData[]   = $v['kg'];
        $chartLabels[] = date('M', strtotime($ym . '-01'));
    }
} else {
    $maxBars = 14;
    if (count($series) > $maxBars) {
        $bucketSize = (int) ceil(count($series) / $maxBars);
        $bucket = ['kg' => 0.0, 'amount' => 0.0, 'start' => null, 'count' => 0];
        $i = 0;
        foreach ($series as $date => $v) {
            if ($bucket['start'] === null) $bucket['start'] = $date;
            $bucket['kg']     += $v['kg'];
            $bucket['amount'] += $v['amount'];
            $bucket['count']++;
            $i++;
            if ($i >= $bucketSize) {
                $chartData[]   = $bucket['kg'];
                $chartLabels[] = date('M j', strtotime($bucket['start']));
                $bucket = ['kg' => 0.0, 'amount' => 0.0, 'start' => null, 'count' => 0];
                $i = 0;
            }
        }
        if ($bucket['count'] > 0) {
            $chartData[]   = $bucket['kg'];
            $chartLabels[] = date('M j', strtotime($bucket['start']));
        }
    } else {
        foreach ($series as $date => $v) {
            $chartData[]   = $v['kg'];
            $chartLabels[] = date('M j', strtotime($date));
        }
    }
}
$maxChart = max(1.0, max($chartData ?: [1.0]));
$chartUnit = $useMonthlyBuckets ? 'Monthly' : 'Daily';

/* ============================================================
   TOP BUYERS
   ============================================================ */
$topBuyers = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.email,
               COUNT(p.id) AS purchase_count,
               COALESCE(SUM(p.weight_kg),0) AS total_kg,
               COALESCE(SUM(p.total_amount),0) AS total_amount,
               COALESCE(SUM(p.balance),0) AS balance
        FROM purchases p
        LEFT JOIN users u ON u.id = p.buyer_id
        WHERE p.created_at BETWEEN ? AND ?
        GROUP BY p.buyer_id
        ORDER BY total_amount DESC
        LIMIT 6
    ");
    $stmt->execute([$fromDT, $toDT]);
    $topBuyers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ============================================================
   TOP SELLERS
   ============================================================ */
$topSellers = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.seller_name,
               COUNT(*) AS purchase_count,
               COALESCE(SUM(p.weight_kg),0) AS total_kg,
               COALESCE(SUM(p.total_amount),0) AS total_amount
        FROM purchases p
        WHERE p.created_at BETWEEN ? AND ?
          AND p.seller_name IS NOT NULL AND p.seller_name <> ''
        GROUP BY p.seller_name
        ORDER BY total_amount DESC
        LIMIT 6
    ");
    $stmt->execute([$fromDT, $toDT]);
    $topSellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ============================================================
   STATUS BREAKDOWN
   ============================================================ */
$statusBreakdown = [];
try {
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) c, COALESCE(SUM(total_amount),0) amt
        FROM purchases
        WHERE created_at BETWEEN ? AND ?
        GROUP BY status
    ");
    $stmt->execute([$fromDT, $toDT]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $statusBreakdown[strtolower($r['status'])] = [
            'count' => (int) $r['c'],
            'amount' => (float) $r['amt'],
        ];
    }
} catch (Throwable $e) {}

$statusTotalCount  = max(1, array_sum(array_column($statusBreakdown, 'count')));
$statusTotalAmount = max(0.01, array_sum(array_column($statusBreakdown, 'amount')));

/* ============================================================
   PAYMENT METHOD BREAKDOWN
   ============================================================ */
$methodBreakdown = [];
try {
    $stmt = $pdo->prepare("
        SELECT method, COUNT(*) c, COALESCE(SUM(amount),0) amt
        FROM payments
        WHERE paid_at BETWEEN ? AND ?
        GROUP BY method
        ORDER BY amt DESC
    ");
    $stmt->execute([$fromDT, $toDT]);
    $methodBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$methodIcons = [
    'cash'          => 'bi-cash-coin',
    'bank_transfer' => 'bi-bank',
    'gcash'         => 'bi-phone',
    'check'         => 'bi-file-earmark-text',
    'other'         => 'bi-three-dots',
];
function methodLabel($m) {
    return [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'gcash' => 'GCash',
        'check' => 'Check',
        'other' => 'Other',
    ][$m] ?? ucfirst((string) $m);
}

/* ---------- CSV Export ---------- */
if (($_GET['export'] ?? '') === '1') {
    $filename = 'smartpalay-report-' . $queryFrom . '-to-' . $queryTo . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, ['SmartPalay — Report']);
    fputcsv($out, ['Period', $queryFrom . ' to ' . $queryTo]);
    fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
    fputcsv($out, []);

    fputcsv($out, ['Summary']);
    fputcsv($out, ['Metric', 'Current', 'Previous', 'Change %']);
    fputcsv($out, ['Purchases',       $cur['purchases'],      $prev['purchases'],      number_format($delta['purchases'], 2)]);
    fputcsv($out, ['Palay (kg)',      number_format($cur['palay_kg'], 2, '.', ''),  number_format($prev['palay_kg'], 2, '.', ''),  number_format($delta['palay_kg'], 2)]);
    fputcsv($out, ['Gross Amount',    number_format($cur['gross'], 2, '.', ''),     number_format($prev['gross'], 2, '.', ''),     number_format($delta['gross'], 2)]);
    fputcsv($out, ['Total Paid',      number_format($cur['paid'], 2, '.', ''),      number_format($prev['paid'], 2, '.', ''),      '']);
    fputcsv($out, ['Outstanding',     number_format($cur['outstanding'], 2, '.', ''), '', '']);
    fputcsv($out, ['Payments Count',  $cur['payments_count'], $prev['payments_count'], '']);
    fputcsv($out, ['Payments Amount', number_format($cur['payments_amt'], 2, '.', ''), number_format($prev['payments_amt'], 2, '.', ''), number_format($delta['payments_amt'], 2)]);
    fputcsv($out, ['New Sellers',     $cur['new_farmers'],    $prev['new_farmers'],    '']);
    fputcsv($out, ['New Buyers',      $cur['new_buyers'],     $prev['new_buyers'],     '']);
    fputcsv($out, []);

    fputcsv($out, ['Top Buyers']);
    fputcsv($out, ['Name', 'Email', 'Purchases', 'Total kg', 'Total amount', 'Balance']);
    foreach ($topBuyers as $b) {
        fputcsv($out, [
            $b['full_name'] ?: '—',
            $b['email'] ?: '',
            $b['purchase_count'],
            number_format((float)$b['total_kg'], 2, '.', ''),
            number_format((float)$b['total_amount'], 2, '.', ''),
            number_format((float)$b['balance'], 2, '.', ''),
        ]);
    }
    fputcsv($out, []);

    fputcsv($out, ['Top Sellers']);
    fputcsv($out, ['Seller', 'Purchases', 'Total kg', 'Total amount']);
    foreach ($topSellers as $s) {
        fputcsv($out, [
            $s['seller_name'],
            $s['purchase_count'],
            number_format((float)$s['total_kg'], 2, '.', ''),
            number_format((float)$s['total_amount'], 2, '.', ''),
        ]);
    }

    fclose($out);
    exit;
}

/* ---------- Logo ---------- */
$logoFileName = '5e861afa-4a95-423a-b004-d69c59fa88dc.png';
$logoDiskPath = __DIR__ . '/../images/' . $logoFileName;
$smartPalayLogo = is_file($logoDiskPath)
    ? BASE_URL . 'images/' . rawurlencode($logoFileName)
    : '';

$firstName = sanitize(explode(' ', $fullName)[0]);

/* ---------- Presets (month/year focus) ---------- */
$presets = [
    'month'      => 'This Month',
    'last_month' => 'Last Month',
    'quarter'    => 'This Quarter',
    'year'       => 'This Year',
    'last_year'  => 'Last Year',
    'custom'     => 'Custom',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#B0641E">
    <title>Reports | SmartPalay Admin</title>

    <?php if ($smartPalayLogo): ?>
        <link rel="icon" type="image/png" href="<?= $smartPalayLogo ?>">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --gold:#B0641E; --gold-2:#C97628; --gold-3:#E8B05A; --gold-4:#F4C87A;
            --gold-soft:#FBF1DC;
            --brown:#4A2C10; --brown-2:#6B4423; --brown-3:#8A5A30;
            --cream:#FBF6EA; --cream-2:#FDFAF1; --paper:#FAF3E5; --paper-2:#F2E7D0;
            --line:#EADFC8; --line-2:#DDCDA8; --line-3:#C9B68A;
            --ink:#2A1E10; --text:#3A2A18; --text-2:#6B5A44; --text-3:#96856E;
            --success:#2E7D32; --success-bg:#EAF7EC; --success-bd:#BEE0C2;
            --warn:#7A5A0F; --warn-bg:#FCF3D6; --warn-bd:#EBD79A;
            --danger:#A23A1A; --danger-bg:#FBEDE6; --danger-bd:#EFC6B0;
            --info:#1E5FA8; --info-bg:#E8F0FA;
            --sidebar-w:260px;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height:100%; margin:0; }
        body.sp-dash {
            font-family:'Inter', system-ui, -apple-system, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 100% 0%, rgba(232,176,90,.14), transparent 42%),
                radial-gradient(circle at 0% 100%, rgba(184,92,46,.08), transparent 42%),
                var(--cream-2);
            -webkit-font-smoothing: antialiased;
            line-height:1.5; min-height:100vh; display:flex;
        }

        .sp-sidebar {
            position:fixed; top:0; left:0; bottom:0;
            width: var(--sidebar-w); z-index:200;
            display:flex; flex-direction:column;
            background:
                radial-gradient(circle at 20% 10%, rgba(232,176,90,.2), transparent 55%),
                radial-gradient(circle at 80% 90%, rgba(184,92,46,.14), transparent 55%),
                linear-gradient(180deg, #4A2C10 0%, #2A1E10 100%);
            color:#fff;
            border-right:1px solid rgba(232,176,90,.18);
            overflow:hidden;
            transition: transform .3s cubic-bezier(.2,.7,.3,1);
        }
        .sp-sidebar::before {
            content:''; position:absolute; inset:0; pointer-events:none;
            background-image:
                repeating-linear-gradient(92deg, transparent 0 22px, rgba(232,176,90,.04) 22px 24px),
                repeating-linear-gradient(88deg, transparent 0 34px, rgba(232,176,90,.03) 34px 37px);
        }
        .sp-sidebar > * { position:relative; z-index:1; }
        .sp-sidebar-brand {
            position: relative;
            display:flex; align-items:center; justify-content:center;
            padding:26px 20px 22px; text-decoration:none; color:inherit;
            border-bottom:1px solid rgba(232,176,90,.15);
            flex-shrink:0; text-align:center;
        }
        .sp-sidebar-brand::after {
            content: '';
            position: absolute;
            bottom: -1px; left: 50%;
            transform: translateX(-50%);
            width: 60px; height: 2px;
            background: linear-gradient(90deg, transparent, var(--gold-3), transparent);
            border-radius: 2px;
            opacity: .7;
        }
        .sp-sidebar-brand-name {
            font-family:'Fraunces', Georgia, serif;
            font-size:1.4rem; font-weight:700; letter-spacing:-.5px;
            margin:0; line-height:1; color:#fff;
        }
        .sp-sidebar-brand-name span { color: var(--gold-3); transition: color .25s ease; }
        .sp-sidebar-brand:hover .sp-sidebar-brand-name span { color: var(--gold-4); }

        .sp-sidebar-user {
            padding:22px 22px 12px;
            border-bottom:1px solid rgba(232,176,90,.12);
            display:flex; align-items:center; justify-content:center;
            flex-shrink:0; position:relative;
        }
        .sp-sidebar-user::before {
            content:''; position:absolute; width:140px; height:140px;
            border-radius:50%;
            background: radial-gradient(circle, rgba(232,176,90,.28), transparent 65%);
            pointer-events:none;
        }
        .sp-user-avatar {
            position:relative; width:82px; height:82px; border-radius:50%;
            flex-shrink:0; padding:3px;
            background: linear-gradient(135deg, #F4C87A 0%, #C97628 55%, #8A5A30 100%);
            box-shadow:
                0 0 0 1px rgba(74,44,16,.35),
                0 10px 24px -8px rgba(0,0,0,.8),
                0 4px 10px -3px rgba(0,0,0,.5);
            transition: transform .3s ease, box-shadow .3s ease;
        }
        .sp-user-avatar:hover {
            transform: translateY(-3px) scale(1.03);
            box-shadow:
                0 0 0 1px rgba(74,44,16,.4),
                0 16px 30px -8px rgba(0,0,0,.85),
                0 6px 14px -3px rgba(0,0,0,.55);
        }
        .sp-user-avatar::after {
            content:''; position:absolute; inset:-6px; border-radius:50%;
            border:1px solid rgba(232,176,90,.35);
            animation: spAvatarPulse 3s ease-in-out infinite;
            pointer-events:none;
        }
        @keyframes spAvatarPulse {
            0%,100% { transform: scale(1); opacity:.6; }
            50%     { transform: scale(1.08); opacity:0; }
        }
        .sp-user-avatar-img {
            width:100%; height:100%; border-radius:50%; overflow:hidden;
            background:#FDFAF1; display:grid; place-items:center;
        }
        .sp-user-avatar-img img { width:100%; height:100%; object-fit:cover; display:block; border-radius:50%; }

        .sp-sidebar-role {
            display: flex; align-items: center; justify-content: center;
            padding: 0 22px 18px;
            margin-top: -4px;
            border-bottom: 1px solid rgba(232,176,90,.12);
            flex-shrink: 0;
        }
        .sp-sidebar-role-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            background: rgba(232, 176, 90, .14);
            border: 1px solid rgba(232, 176, 90, .32);
            color: var(--gold-3);
            font-size: .64rem; font-weight: 700;
            letter-spacing: 1.1px; text-transform: uppercase;
            white-space: nowrap;
        }
        .sp-sidebar-role-chip i { font-size: .78rem; }

        .sp-nav {
            padding:16px 12px;
            display:flex; flex-direction:column; gap:3px;
            flex:1 1 auto; min-height:0;
            overflow-y:auto; overflow-x:hidden;
            scrollbar-width:thin;
            scrollbar-color: rgba(232,176,90,.35) transparent;
        }
        .sp-nav::-webkit-scrollbar { width:6px; }
        .sp-nav::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, rgba(232,176,90,.45), rgba(176,100,30,.35));
            border-radius:4px;
        }
        .sp-nav::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, rgba(232,176,90,.75), rgba(176,100,30,.65));
        }
        .sp-nav-label {
            padding:12px 12px 6px;
            font-size:.64rem; font-weight:700; letter-spacing:1.4px;
            text-transform:uppercase; color: rgba(255,255,255,.42);
        }
        .sp-nav-link {
            display:flex; align-items:center; gap:12px;
            padding:11px 14px; border-radius:10px;
            color: rgba(255,255,255,.82); text-decoration:none;
            font-size:.87rem; font-weight:500;
            transition: background .18s, color .18s, transform .18s;
            position:relative; flex-shrink:0;
        }
        .sp-nav-link i {
            font-size:1.05rem; width:20px; text-align:center;
            color: rgba(255,255,255,.58);
            transition: color .18s;
        }
        .sp-nav-link:hover {
            background: rgba(232,176,90,.12); color:#fff; transform: translateX(2px);
        }
        .sp-nav-link:hover i { color: var(--gold-3); }
        .sp-nav-link::after {
            content: '';
            position: absolute;
            right: 14px; top: 50%;
            transform: translateY(-50%) translateX(6px);
            width: 5px; height: 5px;
            border-radius: 50%;
            background: var(--gold-3);
            opacity: 0;
            transition: opacity .2s ease, transform .2s ease;
        }
        .sp-nav-link:hover::after { opacity: .9; transform: translateY(-50%) translateX(0); }
        .sp-nav-link.is-active {
            background: linear-gradient(135deg, rgba(232,176,90,.28), rgba(176,100,30,.18));
            color:#fff; font-weight:600;
            box-shadow: inset 0 0 0 1px rgba(232,176,90,.3);
            animation: spNavGlow 4s ease-in-out infinite;
        }
        @keyframes spNavGlow {
            0%, 100% { box-shadow: inset 0 0 0 1px rgba(232,176,90,.3); }
            50%      { box-shadow: inset 0 0 0 1px rgba(232,176,90,.5), 0 0 18px -6px rgba(232,176,90,.4); }
        }
        .sp-nav-link.is-active i { color: var(--gold-3); }
        .sp-nav-link.is-active::before {
            content:''; position:absolute; left:0; top:22%; bottom:22%; width:3px;
            border-radius:0 3px 3px 0; background: var(--gold-3);
        }
        .sp-nav-link.is-active::after { display: none; }

        .sp-sidebar-foot {
            padding:14px 12px 18px;
            border-top:1px solid rgba(232,176,90,.12);
            flex-shrink:0;
        }
        .sp-nav-link.sp-logout { color: rgba(255,200,180,.92); }
        .sp-nav-link.sp-logout:hover { background: rgba(162,58,26,.25); color:#fff; }
        .sp-nav-link.sp-logout:hover i { color:#FFB199; transform: translateX(2px); transition: transform .2s ease; }
        .sp-sidebar-tag {
            padding: 10px 22px 0;
            text-align: center;
            font-size: .62rem;
            color: rgba(255,255,255,.32);
            font-weight: 500;
            letter-spacing: .4px;
        }

        .sp-main {
            flex:1; min-width:0;
            margin-left: var(--sidebar-w);
            display:flex; flex-direction:column;
        }
        .sp-topbar {
            position: sticky; top:0; z-index:100;
            display:flex; align-items:center; justify-content:space-between;
            gap:16px;
            padding: 14px clamp(20px,3vw,38px);
            background: rgba(253,250,241,.88);
            backdrop-filter: blur(16px) saturate(150%);
            -webkit-backdrop-filter: blur(16px) saturate(150%);
            border-bottom:1px solid var(--line);
        }
        .sp-topbar-left { display:flex; align-items:center; gap:14px; min-width:0; }
        .sp-menu-btn {
            display:none; width:40px; height:40px;
            border:1.5px solid var(--line-2); background:#fff;
            border-radius:10px; color: var(--brown-2); font-size:1.1rem;
            cursor:pointer; align-items:center; justify-content:center;
            transition: border-color .18s, color .18s;
        }
        .sp-menu-btn:hover { border-color: var(--gold); color: var(--gold); }
        .sp-page-title {
            font-family:'Fraunces', Georgia, serif;
            font-size: clamp(1.15rem,1.9vw,1.4rem);
            font-weight:700; letter-spacing:-.4px;
            color: var(--brown); margin:0; line-height:1.2;
        }
        .sp-page-sub {
            display:block; font-size:.74rem; font-weight:500;
            color: var(--text-3); margin-top:2px;
        }
        .sp-page-sub strong { color: var(--gold); font-weight:700; }
        .sp-topbar-right { display:flex; align-items:center; gap:10px; }
        .sp-icon-btn {
            position:relative; width:40px; height:40px;
            border:1.5px solid var(--line-2); background:#fff; border-radius:10px;
            color: var(--brown-2); font-size:1rem; cursor:pointer;
            display:inline-flex; align-items:center; justify-content:center;
            transition: border-color .18s, color .18s, transform .18s;
        }
        .sp-icon-btn:hover { border-color: var(--gold); color: var(--gold); transform: translateY(-1px); }
        .sp-icon-btn .badge-dot {
            position:absolute; top:8px; right:9px; width:8px; height:8px;
            border-radius:50%; background: var(--danger);
            box-shadow: 0 0 0 2px #fff;
        }
        .sp-cta {
            display:inline-flex; align-items:center; gap:8px;
            padding:10px 18px; border-radius:10px;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color:#fff; font-weight:700; font-size:.85rem;
            text-decoration:none; border:0; cursor:pointer; font-family:inherit;
            box-shadow: 0 3px 0 rgba(0,0,0,.12), 0 12px 24px -12px rgba(176,100,30,.7);
            transition: transform .15s, filter .15s, box-shadow .15s;
        }
        .sp-cta:hover {
            color:#fff; transform: translateY(-1px); filter: brightness(1.04);
            box-shadow: 0 5px 0 rgba(0,0,0,.12), 0 16px 30px -12px rgba(176,100,30,.8);
        }
        .sp-content {
            padding: clamp(20px,3vw,38px);
            display:flex; flex-direction:column;
            gap: clamp(18px,2.4vh,26px);
        }

        .sp-filterbar {
            display:flex; align-items:center; gap:12px;
            flex-wrap: wrap;
            padding: 16px 22px;
            border-radius: 16px;
            background: #fff;
            border:1px solid var(--line);
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset, 0 14px 30px -22px rgba(74,44,16,.4);
        }
        .sp-filterbar-label {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: .68rem; font-weight: 700;
            letter-spacing: 1.2px; text-transform: uppercase;
            color: var(--text-3);
            white-space: nowrap;
        }
        .sp-filterbar-label i { color: var(--gold); font-size: .95rem; }

        .sp-preset-group {
            display: flex; align-items: center;
            gap: 4px;
            padding: 4px;
            border-radius: 12px;
            background: var(--cream-2);
            border: 1px solid var(--line);
            flex-wrap: wrap;
        }
        .sp-preset {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 13px;
            border-radius: 9px;
            border: 0;
            background: transparent;
            color: var(--brown-2);
            font-size: .8rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .18s, color .18s, transform .15s;
            white-space: nowrap;
            font-family: inherit;
        }
        .sp-preset:hover {
            background: rgba(176,100,30,.08);
            color: var(--gold);
        }
        .sp-preset.is-active {
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color: #fff;
            box-shadow: 0 3px 8px -3px rgba(176,100,30,.65);
        }

        .sp-custom-range {
            display: flex; align-items: center; gap: 10px;
            flex-wrap: wrap;
            margin-left: 4px;
        }
        .sp-date-field {
            display:inline-flex; align-items:center;
            background:#fff;
            border:1.5px solid var(--line-2);
            border-radius:11px;
            min-height:42px;
            transition: border-color .18s, box-shadow .18s;
        }
        .sp-date-field:focus-within {
            border-color: var(--gold);
            box-shadow: 0 0 0 4px rgba(176,100,30,.13);
        }
        .sp-date-field i {
            padding: 0 4px 0 14px;
            color: var(--text-3); font-size:.95rem;
        }
        .sp-date-field input[type="date"] {
            border:0; outline:0;
            background:transparent;
            padding: 10px 12px;
            font-size:.86rem; font-weight:500;
            color: var(--text); font-family:inherit;
            cursor: text;
        }

        .sp-filter-actions { display: flex; align-items: center; gap: 8px; margin-left: auto; }

        .sp-btn-apply {
            display:inline-flex; align-items:center; gap:7px;
            padding:10px 18px; border-radius:11px;
            border:0;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color:#fff; font-weight:700; font-size:.82rem;
            cursor:pointer; font-family:inherit;
            box-shadow: 0 3px 0 rgba(0,0,0,.12), 0 12px 22px -12px rgba(176,100,30,.7);
            transition: transform .15s, filter .15s;
            text-decoration:none;
        }
        .sp-btn-apply:hover { color:#fff; transform: translateY(-1px); filter: brightness(1.04); }

        .sp-btn-export {
            display:inline-flex; align-items:center; gap:7px;
            padding:10px 18px; border-radius:11px;
            border:1.5px solid var(--line-2);
            background:#fff;
            color: var(--brown-2);
            font-weight:700; font-size:.82rem;
            cursor:pointer; font-family:inherit;
            text-decoration:none;
            transition: border-color .15s, color .15s, transform .15s;
        }
        .sp-btn-export:hover {
            border-color: var(--success);
            color: var(--success);
            transform: translateY(-1px);
        }

        .sp-stats {
            display:grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr));
            gap: clamp(14px,1.8vw,20px);
        }
        .sp-stat {
            position:relative; padding:22px 24px 20px;
            border-radius:18px; background:#fff; border:1px solid var(--line);
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset, 0 14px 30px -22px rgba(74,44,16,.45);
            transition: transform .28s cubic-bezier(.2,.7,.3,1), box-shadow .28s, border-color .28s;
            overflow:hidden;
        }
        .sp-stat::before {
            content:''; position:absolute; top:0; left:0;
            width:100%; height:4px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
            transform: scaleX(0); transform-origin:left;
            transition: transform .4s cubic-bezier(.2,.7,.3,1);
        }
        .sp-stat:hover {
            transform: translateY(-5px);
            border-color: rgba(176,100,30,.35);
            box-shadow: 0 24px 46px -22px rgba(74,44,16,.5);
        }
        .sp-stat:hover::before { transform: scaleX(1); }
        .sp-stat-head {
            display:flex; align-items:center; justify-content:space-between;
            margin-bottom:16px;
        }
        .sp-stat-ico {
            width:46px; height:46px; border-radius:14px;
            display:grid; place-items:center; font-size:1.22rem;
            background: var(--gold-soft); color: var(--gold);
            border:1px solid rgba(176,100,30,.18);
            transition: transform .35s cubic-bezier(.2,.7,.3,1);
        }
        .sp-stat:hover .sp-stat-ico { transform: scale(1.1) rotate(-8deg); }
        .sp-stat-ico.is-info    { background: var(--info-bg);    color: var(--info);    border-color: #C6DCF0; }
        .sp-stat-ico.is-warn    { background: var(--warn-bg);    color: var(--warn);    border-color: var(--warn-bd); }
        .sp-stat-ico.is-success { background: var(--success-bg); color: var(--success); border-color: var(--success-bd); }
        .sp-stat-ico.is-danger  { background: var(--danger-bg);  color: var(--danger);  border-color: var(--danger-bd); }

        .sp-stat-trend {
            display:inline-flex; align-items:center; gap:4px;
            padding:3px 9px; border-radius:999px;
            font-size:.68rem; font-weight:700; letter-spacing:.3px;
        }
        .sp-stat-trend.is-up   { background: var(--success-bg); color: var(--success); }
        .sp-stat-trend.is-down { background: var(--danger-bg);  color: var(--danger); }
        .sp-stat-trend.is-flat { background: var(--paper-2);    color: var(--text-3); }
        .sp-stat-trend i { font-size:.78rem; }

        .sp-stat-label {
            font-size:.68rem; font-weight:700; letter-spacing:1.2px;
            text-transform:uppercase; color: var(--text-3); margin-bottom:8px;
        }
        .sp-stat-value {
            font-family:'Fraunces', Georgia, serif;
            font-size: clamp(1.6rem,2.6vw,2rem);
            font-weight:700; letter-spacing:-.6px;
            color: var(--brown); line-height:1.05;
        }
        .sp-stat-value small {
            font-size:.72rem; font-weight:600; color: var(--text-3);
            margin-left:4px; letter-spacing:0;
        }
        .sp-stat-foot {
            display:flex; align-items:center; gap:6px;
            margin-top:12px; font-size:.74rem; font-weight:500; color: var(--text-3);
        }
        .sp-stat-foot i { font-size:.85rem; color: var(--gold); }

        .sp-panel {
            position:relative; background:#fff;
            border:1px solid var(--line); border-radius:18px;
            overflow:hidden;
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset, 0 14px 30px -22px rgba(74,44,16,.4);
        }
        .sp-panel::before {
            content:''; position:absolute; top:0; left:0;
            width:56px; height:3px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
            border-radius: 0 3px 3px 0; opacity:.55;
            transition: opacity .25s, width .3s;
        }
        .sp-panel:hover::before { opacity:1; width:84px; }
        .sp-panel-head {
            display:flex; align-items:center; justify-content:space-between;
            gap:12px; padding:18px 22px;
            border-bottom:1px solid var(--line);
            background: linear-gradient(180deg, #fff, var(--cream-2));
            flex-wrap: wrap;
        }
        .sp-panel-title {
            display:flex; align-items:center; gap:11px;
            font-family:'Fraunces', Georgia, serif;
            font-size:1.06rem; font-weight:600;
            color: var(--brown); margin:0;
        }
        .sp-panel-title i {
            width:32px; height:32px; border-radius:10px;
            display:grid; place-items:center;
            background: var(--gold-soft); color: var(--gold);
            font-size:.95rem; border:1px solid rgba(176,100,30,.18);
        }

        .sp-chart { padding: 22px; }
        .sp-chart-head {
            display: flex; align-items: baseline; justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap; gap: 12px;
        }
        .sp-chart-title {
            font-size:.72rem; font-weight:700;
            letter-spacing:1.1px; text-transform:uppercase;
            color: var(--text-3);
        }
        .sp-chart-total {
            font-family:'Fraunces', Georgia, serif;
            font-size:1.35rem; font-weight:700;
            color: var(--brown);
            letter-spacing:-.4px;
        }
        .sp-chart-total small {
            font-size:.68rem; font-weight:600;
            color: var(--text-3);
            margin-left:6px;
            letter-spacing:0;
        }
        .sp-bars {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            height: 200px;
            padding: 0 2px;
        }
        .sp-bar-col {
            flex: 1;
            display: flex; flex-direction: column;
            align-items: center;
            gap: 8px;
            height: 100%;
            justify-content: flex-end;
            min-width: 0;
        }
        .sp-bar {
            width: 100%;
            border-radius: 8px 8px 4px 4px;
            background: linear-gradient(180deg, var(--gold-3), var(--gold));
            box-shadow: 0 3px 8px -4px rgba(176,100,30,.5);
            transition: transform .2s ease, filter .2s ease;
            animation: spBarRise .9s cubic-bezier(.2,.7,.3,1) both;
            transform-origin: bottom;
            position: relative;
            min-height: 2px;
        }
        .sp-bar:hover { filter: brightness(1.08); transform: scaleY(1.04); }
        .sp-bar.is-empty {
            background: var(--paper-2);
            box-shadow: none;
            min-height: 3px;
        }
        .sp-bar-label {
            font-size: .7rem;
            color: var(--text-3);
            font-weight: 700;
            letter-spacing: .3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }
        .sp-bar-value {
            font-size: .62rem;
            color: var(--brown-2);
            font-weight: 700;
            font-family: 'JetBrains Mono', monospace;
            white-space: nowrap;
            opacity: 0;
            transition: opacity .18s;
            margin-bottom: -4px;
        }
        .sp-bar-col:hover .sp-bar-value { opacity: 1; }
        @keyframes spBarRise { from { transform: scaleY(.05); } to { transform: scaleY(1); } }

        .sp-bar[data-value]:hover::after {
            content: attr(data-value);
            position: absolute;
            top: -28px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--brown);
            color: #fff;
            font-size: .68rem;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            white-space: nowrap;
            z-index: 10;
            pointer-events: none;
        }

        .sp-table-wrap { overflow-x:auto; }
        .sp-table { width:100%; border-collapse:collapse; font-size:.86rem; }
        .sp-table thead th {
            text-align:left; padding:12px 22px;
            font-size:.66rem; font-weight:700; letter-spacing:1.1px;
            text-transform:uppercase; color: var(--text-3);
            border-bottom:1px solid var(--line); white-space:nowrap;
            background: var(--cream-2);
        }
        .sp-table tbody td {
            padding:14px 22px;
            border-bottom:1px dashed var(--line-2);
            color: var(--text); vertical-align:middle;
        }
        .sp-table tbody tr:last-child td { border-bottom:0; }
        .sp-table tbody tr { transition: background .18s; }
        .sp-table tbody tr:hover { background: var(--gold-soft); }

        .sp-rank {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px;
            border-radius: 8px;
            background: var(--paper-2);
            color: var(--brown-2);
            font-family: 'Fraunces', Georgia, serif;
            font-size: .85rem;
            font-weight: 700;
            border: 1px solid var(--line-2);
        }
        .sp-rank.is-1 { background: linear-gradient(135deg, #F4C87A, #C97628); color:#fff; border-color: transparent; box-shadow: 0 4px 10px -4px rgba(176,100,30,.7); }
        .sp-rank.is-2 { background: linear-gradient(135deg, #E0E0E0, #A8A8A8); color:#fff; border-color: transparent; }
        .sp-rank.is-3 { background: linear-gradient(135deg, #E2B48A, #A96A3D); color:#fff; border-color: transparent; }

        .sp-user-cell { display:flex; align-items:center; gap:12px; }
        .sp-avatar {
            width:36px; height:36px; border-radius:50%;
            display:grid; place-items:center;
            background: linear-gradient(135deg, #F4C87A 0%, #C97628 100%);
            color:#fff; font-weight:700; font-size:.72rem;
            box-shadow: 0 3px 8px -3px rgba(176,100,30,.7);
            flex-shrink: 0;
            font-family:'Fraunces', Georgia, serif;
        }
        .sp-user-meta { min-width:0; }
        .sp-user-name {
            font-weight:600; color: var(--brown);
            font-size:.88rem; margin:0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 220px;
        }
        .sp-user-email {
            font-size:.72rem; color: var(--text-3); margin:0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 220px;
        }

        .sp-amount {
            font-family:'Fraunces', Georgia, serif;
            font-weight:700; color: var(--brown); font-size:.9rem;
        }

        .sp-breakdown { padding: 18px 22px 22px; display:flex; flex-direction: column; gap: 14px; }
        .sp-breakdown-row { display: flex; flex-direction: column; gap: 7px; }
        .sp-breakdown-meta {
            display: flex; justify-content: space-between;
            align-items: baseline;
            font-size: .82rem;
            color: var(--text-2);
            font-weight: 600;
        }
        .sp-breakdown-meta strong {
            font-family:'Fraunces', Georgia, serif;
            font-size: 1rem;
            color: var(--brown);
            font-weight: 700;
        }
        .sp-breakdown-meta small {
            display: block;
            color: var(--text-3);
            font-size: .7rem;
            font-weight: 500;
            margin-top: 1px;
        }
        .sp-breakdown-label {
            display: inline-flex; align-items: center; gap: 8px;
        }
        .sp-breakdown-label i { color: var(--gold); font-size: .95rem; }
        .sp-breakdown-track {
            height: 9px; border-radius: 9px;
            background: var(--paper-2); overflow: hidden;
        }
        .sp-breakdown-fill {
            height: 100%;
            border-radius: 9px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
            transform-origin: left;
            animation: spBarFill 1.2s cubic-bezier(.2,.7,.3,1) both;
        }
        .sp-breakdown-fill.is-success { background: linear-gradient(90deg, var(--success), #6FBF73); }
        .sp-breakdown-fill.is-info    { background: linear-gradient(90deg, var(--info), #6A9CD9); }
        .sp-breakdown-fill.is-warn    { background: linear-gradient(90deg, var(--warn), var(--gold-3)); }
        .sp-breakdown-fill.is-danger  { background: linear-gradient(90deg, var(--danger), #E07A55); }
        @keyframes spBarFill { from { transform: scaleX(0); } to { transform: scaleX(1); } }

        .sp-methods {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(180px,1fr));
            gap: 14px;
            padding: 18px 22px;
        }
        .sp-method-card {
            padding: 14px 16px;
            border-radius: 14px;
            background: linear-gradient(180deg, #fff, var(--cream-2));
            border: 1px solid var(--line);
            display: flex; align-items: center; gap: 12px;
            transition: transform .22s ease, border-color .22s ease;
        }
        .sp-method-card:hover {
            transform: translateY(-3px);
            border-color: rgba(176,100,30,.35);
        }
        .sp-method-ico {
            width: 40px; height: 40px;
            border-radius: 12px;
            display: grid; place-items: center;
            background: var(--gold-soft);
            color: var(--gold);
            font-size: 1.05rem;
            border: 1px solid rgba(176,100,30,.2);
            flex-shrink: 0;
        }
        .sp-method-body { min-width: 0; }
        .sp-method-label {
            font-size:.66rem; font-weight:700; letter-spacing:1.1px;
            text-transform:uppercase; color: var(--text-3);
            margin: 0 0 3px;
        }
        .sp-method-amount {
            font-family:'Fraunces', Georgia, serif;
            font-size: 1.05rem; font-weight: 700;
            color: var(--brown); line-height: 1.15;
        }
        .sp-method-count {
            font-size: .7rem; color: var(--text-3);
            font-weight: 500;
        }

        .sp-empty { padding:40px 24px; text-align:center; color: var(--text-3); }
        .sp-empty-ico {
            width:60px; height:60px; margin:0 auto 14px;
            border-radius:50%; display:grid; place-items:center;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            color: var(--gold); font-size:1.5rem;
            border:1px solid rgba(176,100,30,.2);
            box-shadow: 0 10px 24px -12px rgba(176,100,30,.4);
        }
        .sp-empty h4 {
            font-family:'Fraunces', Georgia, serif;
            font-size:1.05rem; font-weight:600; color: var(--brown);
            margin:0 0 5px;
        }
        .sp-empty p {
            font-size:.82rem; margin:0;
            max-width:38ch; margin-inline:auto; line-height:1.6;
        }

        .sp-footnote {
            display:flex; align-items:center; justify-content:space-between;
            gap:16px; flex-wrap:wrap;
            padding:16px 22px;
            border-radius:16px;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            border:1px solid rgba(176,100,30,.22);
            font-size:.82rem;
            color: var(--text-2);
        }
        .sp-footnote-left {
            display:inline-flex; align-items:center; gap:12px;
            font-weight:500;
        }
        .sp-footnote-left i {
            width:34px; height:34px;
            border-radius:10px;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color:#fff;
            display:grid; place-items:center;
            font-size:1rem;
            box-shadow: 0 4px 10px -3px rgba(176,100,30,.6);
        }
        .sp-footnote-left strong { color: var(--brown); font-weight:700; }

        .sp-toast {
            position:fixed; bottom:26px; left:50%;
            transform: translate(-50%,120%);
            background: linear-gradient(135deg, #4A2C10, #2A1E10);
            color:#fff; padding:14px 22px; border-radius:14px;
            box-shadow: 0 24px 46px -20px rgba(0,0,0,.75);
            display:inline-flex; align-items:center; gap:11px;
            font-size:.88rem; font-weight:600; z-index:500;
            transition: transform .38s cubic-bezier(.2,.7,.3,1);
            border:1px solid rgba(232,176,90,.3);
            max-width: calc(100vw - 40px);
        }
        .sp-toast.is-visible { transform: translate(-50%,0); }
        .sp-toast i { font-size:1.2rem; color: var(--gold-3); }
        .sp-toast.is-success i { color:#7DD68A; }
        .sp-toast.is-error i   { color:#FF9B7A; }

        .sp-sidebar-overlay {
            display:none; position:fixed; inset:0;
            background: rgba(42,30,16,.5); z-index:150;
            opacity:0; pointer-events:none;
            transition: opacity .25s;
        }
        @media (max-width:900px) {
            .sp-sidebar { transform: translateX(-100%); box-shadow: 24px 0 60px -30px rgba(0,0,0,.7); }
            body.sp-sidebar-open .sp-sidebar { transform: translateX(0); }
            body.sp-sidebar-open .sp-sidebar-overlay { display:block; opacity:1; pointer-events:auto; }
            .sp-main { margin-left:0; }
            .sp-menu-btn { display:inline-flex; }
        }
        @media (max-width:600px) {
            .sp-topbar { padding:12px 16px; }
            .sp-content { padding:16px; }
            .sp-cta span { display:none; }
            .sp-cta { padding:10px 12px; }
            .sp-table thead th, .sp-table tbody td { padding:10px 14px; }
            .sp-panel-head { padding:14px 16px; }
            .sp-chart { padding: 16px; }
            .sp-bars { height: 150px; gap: 5px; }
            .sp-methods { padding: 14px 16px; }
            .sp-filter-actions { margin-left: 0; width: 100%; }
            .sp-btn-apply, .sp-btn-export { flex: 1; justify-content: center; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration:.001ms !important;
                transition-duration:.001ms !important;
            }
        }
    </style>
</head>
<body class="sp-dash">

<div class="sp-sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sp-sidebar" id="sidebar">
    <a href="<?= BASE_URL ?>index.php" class="sp-sidebar-brand">
        <h1 class="sp-sidebar-brand-name">Smart<span>Palay</span></h1>
    </a>

    <div class="sp-sidebar-user">
        <div class="sp-user-avatar" title="Administrator">
            <div class="sp-user-avatar-img">
                <?php if ($smartPalayLogo): ?>
                    <img src="<?= $smartPalayLogo ?>" alt="SmartPalay">
                <?php else: ?>
                    <i class="bi bi-shield-lock" style="font-size:1.6rem; color: var(--text-3);"></i>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="sp-sidebar-role">
        <span class="sp-sidebar-role-chip">
            <i class="bi bi-shield-lock-fill"></i> Administrator
        </span>
    </div>

    <nav class="sp-nav">
        <span class="sp-nav-label">Overview</span>
        <a href="<?= BASE_URL ?>admin/dashboard.php" class="sp-nav-link">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>

        <span class="sp-nav-label">Manage</span>
        <a href="<?= BASE_URL ?>admin/users.php" class="sp-nav-link">
            <i class="bi bi-people"></i> Users
        </a>
        <a href="<?= BASE_URL ?>admin/purchases.php" class="sp-nav-link">
            <i class="bi bi-receipt"></i> Purchases
        </a>
        <a href="<?= BASE_URL ?>admin/payments.php" class="sp-nav-link">
            <i class="bi bi-cash-coin"></i> Payments
        </a>

        <span class="sp-nav-label">System</span>
        <a href="<?= BASE_URL ?>admin/reports.php" class="sp-nav-link is-active">
            <i class="bi bi-graph-up-arrow"></i> Reports
        </a>
        <a href="<?= BASE_URL ?>admin/settings.php" class="sp-nav-link">
            <i class="bi bi-gear"></i> Settings
        </a>
    </nav>

    <div class="sp-sidebar-foot">
        <a href="<?= BASE_URL ?>auth/logout.php" class="sp-nav-link sp-logout">
            <i class="bi bi-box-arrow-right"></i> Sign Out
        </a>
        <div class="sp-sidebar-tag">SmartPalay &copy; <?= date('Y') ?></div>
    </div>
</aside>

<div class="sp-main">

    <header class="sp-topbar">
        <div class="sp-topbar-left">
            <button class="sp-menu-btn" id="menuBtn" aria-label="Open menu">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h1 class="sp-page-title">Reports</h1>
                <span class="sp-page-sub">
                    Analytics &amp; insights —
                    <strong><?= niceDate($queryFrom) ?></strong> to <strong><?= niceDate($queryTo) ?></strong>
                </span>
            </div>
        </div>

        <div class="sp-topbar-right">
            <button class="sp-icon-btn" id="notifBtn" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <span class="badge-dot"></span>
            </button>
            <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"
               class="sp-cta" id="topExportBtn">
                <i class="bi bi-download"></i>
                <span>Export CSV</span>
            </a>
        </div>
    </header>

    <main class="sp-content">

        <!-- Filter bar -->
        <form method="get" class="sp-filterbar" id="filterForm">
            <span class="sp-filterbar-label">
                <i class="bi bi-calendar3"></i> Period
            </span>

            <div class="sp-preset-group">
                <?php foreach ($presets as $key => $label): ?>
                    <a class="sp-preset <?= $preset === $key ? 'is-active' : '' ?>"
                       href="?preset=<?= $key ?>">
                        <?= $label ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="sp-custom-range" id="customRange" style="<?= $preset === 'custom' ? '' : 'display:none;' ?>">
                <span class="sp-filterbar-label">
                    <i class="bi bi-arrow-right"></i> From
                </span>
                <label class="sp-date-field">
                    <i class="bi bi-calendar-event"></i>
                    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
                </label>

                <span class="sp-filterbar-label">
                    <i class="bi bi-arrow-right"></i> To
                </span>
                <label class="sp-date-field">
                    <i class="bi bi-calendar-event"></i>
                    <input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
                </label>
            </div>

            <input type="hidden" name="preset" value="<?= htmlspecialchars($preset) ?>" id="presetHidden">

            <div class="sp-filter-actions">
                <button type="submit" class="sp-btn-apply">
                    <i class="bi bi-funnel-fill"></i> Apply
                </button>
                <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"
                   class="sp-btn-export">
                    <i class="bi bi-download"></i> Export
                </a>
            </div>
        </form>

        <!-- Stat cards -->
        <section class="sp-stats">
            <?php
                $statCards = [
                    [
                        'label' => 'Purchases',
                        'value' => number_format($cur['purchases']),
                        'delta' => $delta['purchases'],
                        'ico'   => 'bi-receipt',
                        'cls'   => 'is-warn',
                        'foot'  => 'vs previous period',
                        'footIco' => 'bi-clock-history',
                    ],
                    [
                        'label' => 'Palay Volume',
                        'value' => number_format($cur['palay_kg'], 0) . '<small>kg</small>',
                        'delta' => $delta['palay_kg'],
                        'ico'   => 'bi-box-seam',
                        'cls'   => 'is-info',
                        'foot'  => 'total weight received',
                        'footIco' => 'bi-graph-up',
                    ],
                    [
                        'label' => 'Gross Amount',
                        'value' => peso($cur['gross']),
                        'delta' => $delta['gross'],
                        'ico'   => 'bi-cash-stack',
                        'cls'   => 'is-success',
                        'foot'  => 'total value',
                        'footIco' => 'bi-check-circle',
                    ],
                    [
                        'label' => 'Payments Collected',
                        'value' => peso($cur['payments_amt']),
                        'delta' => $delta['payments_amt'],
                        'ico'   => 'bi-cash-coin',
                        'cls'   => '',
                        'foot'  => number_format($cur['payments_count']) . ' transaction' . ($cur['payments_count'] === 1 ? '' : 's'),
                        'footIco' => 'bi-list-check',
                    ],
                ];
            ?>

            <?php foreach ($statCards as $c): ?>
                <?php
                    $d = $c['delta'];
                    $trendCls = $d > 0.5 ? 'is-up' : ($d < -0.5 ? 'is-down' : 'is-flat');
                    $trendIco = $d > 0.5 ? 'bi-arrow-up-right' : ($d < -0.5 ? 'bi-arrow-down-right' : 'bi-dash');
                    $trendTxt = abs($d) < 0.5 ? '0%' : (($d > 0 ? '+' : '') . number_format($d, 1) . '%');
                ?>
                <article class="sp-stat">
                    <div class="sp-stat-head">
                        <div class="sp-stat-ico <?= $c['cls'] ?>"><i class="bi <?= $c['ico'] ?>"></i></div>
                        <span class="sp-stat-trend <?= $trendCls ?>">
                            <i class="bi <?= $trendIco ?>"></i> <?= $trendTxt ?>
                        </span>
                    </div>
                    <div class="sp-stat-label"><?= $c['label'] ?></div>
                    <div class="sp-stat-value"><?= $c['value'] ?></div>
                    <div class="sp-stat-foot">
                        <i class="bi <?= $c['footIco'] ?>"></i> <?= $c['foot'] ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <!-- Trend chart -->
        <section class="sp-panel">
            <div class="sp-panel-head">
                <h3 class="sp-panel-title">
                    <i class="bi bi-bar-chart-line"></i>
                    Palay Volume Trend
                </h3>
                <span class="sp-chart-total">
                    <?= number_format($cur['palay_kg'], 0) ?> kg
                    <small>
                        <i class="bi <?= $delta['palay_kg'] >= 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short' ?>"></i>
                        <?= number_format(abs($delta['palay_kg']), 1) ?>% vs prev
                    </small>
                </span>
            </div>
            <div class="sp-chart">
                <div class="sp-chart-head">
                    <span class="sp-chart-title"><?= $chartUnit ?> volume (<?= count($chartData) ?> bars)</span>
                    <span class="sp-chart-title">Peak: <?= number_format($maxChart, 0) ?> kg</span>
                </div>
                <div class="sp-bars">
                    <?php if (!empty($chartData)): ?>
                        <?php foreach ($chartData as $i => $val): ?>
                            <?php
                                $pct = ($val / $maxChart) * 100;
                                $pct = max(2, min(100, $pct));
                                $isEmpty = $val <= 0;
                            ?>
                            <div class="sp-bar-col">
                                <span class="sp-bar-value"><?= number_format($val, 0) ?></span>
                                <div class="sp-bar <?= $isEmpty ? 'is-empty' : '' ?>"
                                     style="height: <?= $isEmpty ? 2 : $pct ?>%"
                                     data-value="<?= number_format($val, 0) ?> kg"
                                     title="<?= $chartLabels[$i] ?? '' ?>: <?= number_format($val, 0) ?> kg"></div>
                                <span class="sp-bar-label"><?= htmlspecialchars($chartLabels[$i] ?? '') ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="width:100%;text-align:center;color:var(--text-3);font-size:.85rem;padding:40px 0;">
                            No data to display.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Two-column grid: Status + Methods -->
        <section class="sp-grid" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:clamp(14px,1.8vw,22px);align-items:start;">

            <!-- Status breakdown -->
            <div class="sp-panel">
                <div class="sp-panel-head">
                    <h3 class="sp-panel-title">
                        <i class="bi bi-pie-chart"></i>
                        Purchase Status Breakdown
                    </h3>
                </div>
                <div class="sp-breakdown">
                    <?php
                        $statusMeta = [
                            'paid'    => ['label' => 'Paid',    'cls' => 'is-success', 'ico' => 'bi-check-circle-fill'],
                            'partial' => ['label' => 'Partial', 'cls' => 'is-warn',    'ico' => 'bi-circle-half'],
                            'unpaid'  => ['label' => 'Unpaid',  'cls' => 'is-danger',  'ico' => 'bi-x-circle-fill'],
                            'pending' => ['label' => 'Pending', 'cls' => 'is-info',    'ico' => 'bi-hourglass-split'],
                        ];
                        $any = false;
                        foreach ($statusMeta as $key => $meta):
                            $row = $statusBreakdown[$key] ?? null;
                            if (!$row || $row['count'] <= 0) continue;
                            $any = true;
                            $pctCount = ($row['count'] / $statusTotalCount) * 100;
                    ?>
                        <div class="sp-breakdown-row">
                            <div class="sp-breakdown-meta">
                                <span class="sp-breakdown-label">
                                    <i class="bi <?= $meta['ico'] ?>"></i>
                                    <?= $meta['label'] ?>
                                    <small><?= number_format($row['count']) ?> purchase<?= $row['count'] === 1 ? '' : 's' ?></small>
                                </span>
                                <strong><?= peso($row['amount']) ?></strong>
                            </div>
                            <div class="sp-breakdown-track">
                                <div class="sp-breakdown-fill <?= $meta['cls'] ?>" style="width: <?= $pctCount ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!$any): ?>
                        <div class="sp-empty">
                            <div class="sp-empty-ico"><i class="bi bi-pie-chart"></i></div>
                            <h4>No purchases in this period</h4>
                            <p>Try widening the date range or selecting a different preset.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment methods -->
            <div class="sp-panel">
                <div class="sp-panel-head">
                    <h3 class="sp-panel-title">
                        <i class="bi bi-credit-card-2-front"></i>
                        Payments by Method
                    </h3>
                </div>
                <?php if (!empty($methodBreakdown)): ?>
                    <div class="sp-methods" style="grid-template-columns: 1fr;">
                        <?php foreach ($methodBreakdown as $m): ?>
                            <?php
                                $mm = $m['method'];
                                $ico = $methodIcons[$mm] ?? 'bi-cash-coin';
                            ?>
                            <div class="sp-method-card">
                                <div class="sp-method-ico">
                                    <i class="bi <?= $ico ?>"></i>
                                </div>
                                <div class="sp-method-body">
                                    <p class="sp-method-label"><?= sanitize(methodLabel($mm)) ?></p>
                                    <div class="sp-method-amount"><?= peso($m['amt']) ?></div>
                                    <span class="sp-method-count">
                                        <?= number_format((int)$m['c']) ?> payment<?= ((int)$m['c']) === 1 ? '' : 's' ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="sp-empty">
                        <div class="sp-empty-ico"><i class="bi bi-credit-card-2-front"></i></div>
                        <h4>No payments in this period</h4>
                        <p>Once payments are recorded in this range, the breakdown appears here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Top Buyers + Top Sellers -->
        <section class="sp-grid" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:clamp(14px,1.8vw,22px);align-items:start;">

            <!-- Top Buyers -->
            <div class="sp-panel">
                <div class="sp-panel-head">
                    <h3 class="sp-panel-title">
                        <i class="bi bi-trophy"></i>
                        Top Buyers
                    </h3>
                    <a href="<?= BASE_URL ?>admin/users.php?role=buyer" class="sp-panel-link"
                       style="font-size:.78rem;font-weight:600;color:var(--gold);text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                        View all <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <?php if (!empty($topBuyers)): ?>
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th style="width:40px;">#</th>
                                    <th>Buyer</th>
                                    <th>Volume</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topBuyers as $i => $b): ?>
                                    <tr>
                                        <td>
                                            <span class="sp-rank <?= $i === 0 ? 'is-1' : ($i === 1 ? 'is-2' : ($i === 2 ? 'is-3' : '')) ?>">
                                                <?= $i + 1 ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="sp-user-cell">
                                                <div class="sp-avatar">
                                                    <?php
                                                        $nm = $b['full_name'] ?: '—';
                                                        $parts = preg_split('/\s+/', trim($nm));
                                                        echo htmlspecialchars(strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '')));
                                                    ?>
                                                </div>
                                                <div class="sp-user-meta">
                                                    <p class="sp-user-name"><?= sanitize($nm) ?></p>
                                                    <p class="sp-user-email">
                                                        <?= (int)$b['purchase_count'] ?> purchase<?= ((int)$b['purchase_count']) === 1 ? '' : 's' ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= number_format((float)$b['total_kg'], 0) ?> kg</td>
                                        <td><span class="sp-amount"><?= peso($b['total_amount']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="sp-empty">
                        <div class="sp-empty-ico"><i class="bi bi-trophy"></i></div>
                        <h4>No buyer activity</h4>
                        <p>Top buyers in this period will be ranked here.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Top Sellers -->
            <div class="sp-panel">
                <div class="sp-panel-head">
                    <h3 class="sp-panel-title">
                        <i class="bi bi-award"></i>
                        Top Sellers
                    </h3>
                </div>
                <?php if (!empty($topSellers)): ?>
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th style="width:40px;">#</th>
                                    <th>Seller</th>
                                    <th>Volume</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topSellers as $i => $s): ?>
                                    <tr>
                                        <td>
                                            <span class="sp-rank <?= $i === 0 ? 'is-1' : ($i === 1 ? 'is-2' : ($i === 2 ? 'is-3' : '')) ?>">
                                                <?= $i + 1 ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="sp-user-cell">
                                                <div class="sp-avatar">
                                                    <?php
                                                        $nm = $s['seller_name'] ?: '—';
                                                        $parts = preg_split('/\s+/', trim($nm));
                                                        echo htmlspecialchars(strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '')));
                                                    ?>
                                                </div>
                                                <div class="sp-user-meta">
                                                    <p class="sp-user-name"><?= sanitize($nm) ?></p>
                                                    <p class="sp-user-email">
                                                        <?= (int)$s['purchase_count'] ?> purchase<?= ((int)$s['purchase_count']) === 1 ? '' : 's' ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= number_format((float)$s['total_kg'], 0) ?> kg</td>
                                        <td><span class="sp-amount"><?= peso($s['total_amount']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="sp-empty">
                        <div class="sp-empty-ico"><i class="bi bi-award"></i></div>
                        <h4>No seller activity</h4>
                        <p>Top sellers in this period will be ranked here.</p>
                    </div>
                <?php endif; ?>
            </div>

        </section>

        <!-- Info footnote -->
        <div class="sp-footnote">
            <span class="sp-footnote-left">
                <i class="bi bi-info-circle"></i>
                <span>
                    <strong>Period:</strong>
                    <?= niceDate($queryFrom) ?> – <?= niceDate($queryTo) ?>
                    (<?= $days ?> day<?= $days === 1 ? '' : 's' ?>)
                    · Compared to <?= niceDate($prevFrom) ?> – <?= niceDate($prevTo) ?>
                </span>
            </span>
            <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 1]))) ?>"
               class="sp-footnote-right"
               style="display:inline-flex;align-items:center;gap:8px;color:var(--gold);font-weight:700;text-decoration:none;">
                Download full report <i class="bi bi-download"></i>
            </a>
        </div>

    </main>
</div>

<div class="sp-toast" id="spToast">
    <i class="bi bi-check-circle-fill" id="spToastIcon"></i>
    <span id="spToastText">Ready</span>
</div>

<style>
    @media (max-width: 980px) {
        .sp-grid { grid-template-columns: 1fr !important; }
    }
</style>

<script>
(() => {
    'use strict';

    const body    = document.body;
    const menuBtn = document.getElementById('menuBtn');
    const overlay = document.getElementById('sidebarOverlay');

    menuBtn?.addEventListener('click', () => body.classList.add('sp-sidebar-open'));
    overlay?.addEventListener('click', () => body.classList.remove('sp-sidebar-open'));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') body.classList.remove('sp-sidebar-open'); });
    window.addEventListener('resize', () => { if (window.innerWidth > 900) body.classList.remove('sp-sidebar-open'); });

    const toast     = document.getElementById('spToast');
    const toastIcon = document.getElementById('spToastIcon');
    const toastText = document.getElementById('spToastText');
    let toastTimer;

    window.showToast = function (message, type = 'success') {
        toastText.textContent = message;
        toast.classList.remove('is-success','is-error');
        toastIcon.className = 'bi ' + (type === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
        toast.classList.add('is-' + type, 'is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 3200);
    };

    document.getElementById('notifBtn')?.addEventListener('click', () => {
        showToast('No new system notifications.', 'success');
    });

    /* Show/hide custom date range when "Custom" is clicked */
    const presetHidden  = document.getElementById('presetHidden');
    const customRange   = document.getElementById('customRange');
    document.querySelectorAll('.sp-preset').forEach(el => {
        el.addEventListener('click', (e) => {
            const label = el.textContent.trim();
            if (label === 'Custom') {
                e.preventDefault();
                presetHidden.value = 'custom';
                if (customRange) customRange.style.display = '';
                document.querySelectorAll('.sp-preset').forEach(p => p.classList.remove('is-active'));
                el.classList.add('is-active');
            }
        });
    });

    /* Export confirmation toast */
    document.querySelectorAll('.sp-btn-export, .sp-cta[href*="export=1"]').forEach(el => {
        el.addEventListener('click', () => {
            showToast('Preparing CSV export…', 'success');
        });
    });
})();
</script>
</body>
</html>