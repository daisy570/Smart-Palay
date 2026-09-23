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

/* ---------- Feature detection ---------- */
$hasBuyerFK = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM payments LIKE 'buyer_id'")->fetchAll();
    $hasBuyerFK = !empty($cols);
} catch (Throwable $e) { $hasBuyerFK = false; }

$hasPurchaseFK = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM payments LIKE 'purchase_id'")->fetchAll();
    $hasPurchaseFK = !empty($cols);
} catch (Throwable $e) { $hasPurchaseFK = false; }

$hasNotes = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM payments LIKE 'notes'")->fetchAll();
    $hasNotes = !empty($cols);
} catch (Throwable $e) { $hasNotes = false; }

/* ============================================================
   POST ACTIONS
   ============================================================ */
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        /* ---------- CREATE / UPDATE ---------- */
        if ($action === 'create' || $action === 'update') {
            $id       = (int) ($_POST['id'] ?? 0);
            $buyerId  = (int) ($_POST['buyer_id'] ?? 0);
            $purId    = (int) ($_POST['purchase_id'] ?? 0) ?: null;
            $amount   = (float) ($_POST['amount'] ?? 0);
            $method   = trim($_POST['method'] ?? '');
            $paidAt   = trim($_POST['paid_at'] ?? '');
            $notes    = trim($_POST['notes'] ?? '');
            $ref      = trim($_POST['reference_no'] ?? '');

            if ($amount <= 0) throw new Exception('Amount must be greater than 0.');
            if (!in_array($method, ['cash','bank_transfer','gcash','check','other'], true)) {
                throw new Exception('Please select a payment method.');
            }

            /* Date is optional; if blank, use now */
            if ($paidAt === '') {
                $paidAt = date('Y-m-d H:i:s');
            } else {
                /* Normalise datetime-local value (YYYY-MM-DDTHH:MM) → Y-m-d H:i:s */
                $paidAt = str_replace('T', ' ', $paidAt);
                if (strlen($paidAt) === 16) $paidAt .= ':00';
            }

            if ($action === 'create') {
                if ($ref === '') {
                    $ref = 'PAY-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                }

                $cols   = ['reference_no', 'amount', 'method', 'paid_at'];
                $vals   = [$ref, $amount, $method, $paidAt];
                $marks  = ['?', '?', '?', '?'];

                if ($hasBuyerFK)   { $cols[] = 'buyer_id';    $vals[] = $buyerId ?: null;  $marks[] = '?'; }
                if ($hasPurchaseFK){ $cols[] = 'purchase_id'; $vals[] = $purId;            $marks[] = '?'; }
                if ($hasNotes)     { $cols[] = 'notes';       $vals[] = $notes;            $marks[] = '?'; }

                $stmt = $pdo->prepare("
                    INSERT INTO payments (" . implode(',', $cols) . ")
                    VALUES (" . implode(',', $marks) . ")
                ");
                $stmt->execute($vals);
                $flash = ['type' => 'success', 'msg' => "Payment {$ref} recorded."];
            } else {
                if ($id <= 0) throw new Exception('Invalid payment ID.');

                $set  = ['amount=?', 'method=?', 'paid_at=?'];
                $vals = [$amount, $method, $paidAt];

                if ($hasBuyerFK)   { $set[] = 'buyer_id=?';    $vals[] = $buyerId ?: null; }
                if ($hasPurchaseFK){ $set[] = 'purchase_id=?'; $vals[] = $purId; }
                if ($hasNotes)     { $set[] = 'notes=?';       $vals[] = $notes; }
                $vals[] = $id;

                $stmt = $pdo->prepare("UPDATE payments SET " . implode(', ', $set) . " WHERE id=?");
                $stmt->execute($vals);
                $flash = ['type' => 'success', 'msg' => 'Payment updated.'];
            }
        }

        /* ---------- DELETE ---------- */
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid payment ID.');

            $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ?");
            $stmt->execute([$id]);

            $flash = ['type' => 'success', 'msg' => 'Payment deleted.'];
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    }
}

/* ============================================================
   FILTERS + PAGINATION
   ============================================================ */
$q        = trim($_GET['q'] ?? '');
$methodF  = $_GET['method'] ?? '';
$buyerF   = (int) ($_GET['buyer'] ?? 0);
$dateFrom = trim($_GET['from'] ?? '');
$dateTo   = trim($_GET['to'] ?? '');
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 10;

$where  = [];
$params = [];

if ($q !== '') {
    $where[]  = "(p.reference_no LIKE ? " . ($hasBuyerFK ? "OR u.full_name LIKE ? OR u.email LIKE ?" : "") . ")";
    $like = "%{$q}%";
    $params[] = $like;
    if ($hasBuyerFK) { $params[] = $like; $params[] = $like; }
}
if (in_array($methodF, ['cash','bank_transfer','gcash','check','other'], true)) {
    $where[]  = "p.method = ?";
    $params[] = $methodF;
}
if ($buyerF > 0 && $hasBuyerFK) {
    $where[]  = "p.buyer_id = ?";
    $params[] = $buyerF;
}
if ($dateFrom !== '') {
    $where[]  = "DATE(p.paid_at) >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[]  = "DATE(p.paid_at) <= ?";
    $params[] = $dateTo;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ---------- Count ---------- */
$totalRows = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM payments p
        " . ($hasBuyerFK ? "LEFT JOIN users u ON u.id = p.buyer_id" : "") . "
        {$whereSql}
    ");
    $stmt->execute($params);
    $totalRows = (int) $stmt->fetchColumn();
} catch (Throwable $e) { $totalRows = 0; }

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

/* ---------- Fetch page ---------- */
$buyerNameSelect = $hasBuyerFK
    ? "COALESCE(u.full_name, '—') AS buyer_name, COALESCE(u.email,'') AS buyer_email"
    : "COALESCE(p.buyer_name, '—') AS buyer_name, '' AS buyer_email";

$purSelect = $hasPurchaseFK
    ? "COALESCE(pu.reference_no, '') AS purchase_ref"
    : "'' AS purchase_ref";

$purJoin = $hasPurchaseFK ? "LEFT JOIN purchases pu ON pu.id = p.purchase_id" : "";

$payments = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            p.id, p.reference_no, p.amount, p.method, p.paid_at, p.created_at
            " . ($hasBuyerFK   ? ", p.buyer_id"    : ", 0 AS buyer_id") . "
            " . ($hasPurchaseFK? ", p.purchase_id" : ", 0 AS purchase_id") . "
            " . ($hasNotes     ? ", p.notes"       : ", '' AS notes") . "
            , {$buyerNameSelect}
            , {$purSelect}
        FROM payments p
        " . ($hasBuyerFK ? "LEFT JOIN users u ON u.id = p.buyer_id" : "") . "
        {$purJoin}
        {$whereSql}
        ORDER BY p.paid_at DESC, p.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $payments = []; }

/* ---------- Stats (unfiltered) ---------- */
$stats = [
    'total'        => 0,
    'total_amount' => 0.0,
    'this_month'   => 0,
    'this_month_amt' => 0.0,
    'avg'          => 0.0,
];
try {
    $row = $pdo->query("
        SELECT COUNT(*) AS total, COALESCE(SUM(amount),0) AS amt
        FROM payments
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['total']        = (int) ($row['total'] ?? 0);
    $stats['total_amount'] = (float) ($row['amt'] ?? 0);
    if ($stats['total'] > 0) $stats['avg'] = $stats['total_amount'] / $stats['total'];
} catch (Throwable $e) {}

try {
    $row = $pdo->query("
        SELECT COUNT(*) c, COALESCE(SUM(amount),0) amt
        FROM payments
        WHERE YEAR(paid_at)=YEAR(CURDATE())
          AND MONTH(paid_at)=MONTH(CURDATE())
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['this_month']     = (int) ($row['c'] ?? 0);
    $stats['this_month_amt'] = (float) ($row['amt'] ?? 0);
} catch (Throwable $e) {}

/* ---------- Method breakdown ---------- */
$methodBreakdown = [];
try {
    $methodBreakdown = $pdo->query("
        SELECT method, COUNT(*) c, COALESCE(SUM(amount),0) amt
        FROM payments
        GROUP BY method
        ORDER BY amt DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ---------- Buyers + Purchases lists ---------- */
$buyersList = [];
if ($hasBuyerFK) {
    try {
        $buyersList = $pdo->query("
            SELECT id, full_name, email
            FROM users
            WHERE role='buyer'
            ORDER BY full_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$purchasesList = [];
if ($hasPurchaseFK) {
    try {
        $purchasesList = $pdo->query("
            SELECT p.id, p.reference_no, p.total_amount, p.balance,
                   u.full_name AS buyer_name
            FROM purchases p
            LEFT JOIN users u ON u.id = p.buyer_id
            WHERE p.balance > 0 OR p.status <> 'paid'
            ORDER BY p.created_at DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

/* ---------- Helpers ---------- */
function peso($n) { return '₱' . number_format((float) $n, 2); }
function niceDate($s) {
    if (!$s) return '—';
    $t = strtotime($s);
    return $t ? date('M j, Y', $t) : '—';
}
function niceDateTime($s) {
    if (!$s) return '—';
    $t = strtotime($s);
    return $t ? date('M j, Y g:i A', $t) : '—';
}
function initials($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) $out .= strtoupper(mb_substr($p, 0, 1));
    return $out ?: '?';
}
function methodLabel($m) {
    return [
        'cash'          => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'gcash'         => 'GCash',
        'check'         => 'Check',
        'other'         => 'Other',
    ][$m] ?? ucfirst((string) $m);
}
function methodIcon($m) {
    return [
        'cash'          => 'bi-cash-coin',
        'bank_transfer' => 'bi-bank',
        'gcash'         => 'bi-phone',
        'check'         => 'bi-file-earmark-text',
        'other'         => 'bi-three-dots',
    ][$m] ?? 'bi-cash-coin';
}

$logoFileName = '5e861afa-4a95-423a-b004-d69c59fa88dc.png';
$logoDiskPath = __DIR__ . '/../images/' . $logoFileName;
$smartPalayLogo = is_file($logoDiskPath)
    ? BASE_URL . 'images/' . rawurlencode($logoFileName)
    : '';

$firstName = sanitize(explode(' ', $fullName)[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#B0641E">
    <title>Payments | SmartPalay Admin</title>

    <?php if ($smartPalayLogo): ?>
        <link rel="icon" type="image/png" href="<?= $smartPalayLogo ?>">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">

    <style>
        /* ============================================================
           SmartPalay — Admin Payments
           ============================================================ */
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

        /* Sidebar */
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

        /* Main */
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

        /* Flash */
        .sp-flash {
            display:flex; align-items:center; gap:12px;
            padding:14px 18px; border-radius:14px;
            font-size:.88rem; font-weight:500; border:1px solid;
            animation: spFlashIn .35s ease both;
        }
        @keyframes spFlashIn {
            from { opacity:0; transform: translateY(-6px); }
            to   { opacity:1; transform: translateY(0); }
        }
        .sp-flash.is-success { background: var(--success-bg); border-color: var(--success-bd); color: var(--success); }
        .sp-flash.is-error   { background: var(--danger-bg);  border-color: var(--danger-bd);  color: var(--danger); }
        .sp-flash i { font-size:1.2rem; }

        /* Stats — single row of 4 */
        .sp-stats {
            display:grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        @media (max-width: 900px)  { .sp-stats { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px)  { .sp-stats { grid-template-columns: 1fr; } }

        .sp-stat {
            position:relative;
            padding:18px 18px 16px;
            border-radius:16px;
            background:#fff;
            border:1px solid var(--line);
            box-shadow:
                0 1px 0 rgba(255,255,255,.9) inset,
                0 14px 30px -22px rgba(74,44,16,.45);
            transition: transform .28s cubic-bezier(.2,.7,.3,1),
                        box-shadow .28s, border-color .28s;
            overflow:hidden;
            display:flex; flex-direction:column; gap: 10px;
            min-height: 148px;
        }
        .sp-stat::before {
            content:''; position:absolute; top:0; left:0;
            width:100%; height:4px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
            transform: scaleX(0); transform-origin:left;
            transition: transform .4s cubic-bezier(.2,.7,.3,1);
        }
        .sp-stat::after {
            content:''; position:absolute;
            top:-30px; right:-30px;
            width: 110px; height: 110px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(232,176,90,.18), transparent 65%);
            pointer-events: none;
            opacity: 0;
            transition: opacity .3s ease;
        }
        .sp-stat:hover {
            transform: translateY(-5px);
            border-color: rgba(176,100,30,.35);
            box-shadow: 0 24px 46px -22px rgba(74,44,16,.5);
        }
        .sp-stat:hover::before { transform: scaleX(1); }
        .sp-stat:hover::after  { opacity: 1; }

        .sp-stat-head {
            display:flex; align-items:center; justify-content:space-between;
            gap: 8px;
        }
        .sp-stat-ico {
            width:40px; height:40px; border-radius:12px;
            display:grid; place-items:center; font-size:1.05rem;
            background: var(--gold-soft); color: var(--gold);
            border:1px solid rgba(176,100,30,.18);
            transition: transform .35s cubic-bezier(.2,.7,.3,1);
            flex-shrink: 0;
        }
        .sp-stat:hover .sp-stat-ico { transform: scale(1.1) rotate(-8deg); }
        .sp-stat-ico.is-info    { background: var(--info-bg);    color: var(--info);    border-color: #C6DCF0; }
        .sp-stat-ico.is-warn    { background: var(--warn-bg);    color: var(--warn);    border-color: var(--warn-bd); }
        .sp-stat-ico.is-success { background: var(--success-bg); color: var(--success); border-color: var(--success-bd); }
        .sp-stat-ico.is-danger  { background: var(--danger-bg);  color: var(--danger);  border-color: var(--danger-bd); }
        .sp-stat-trend {
            display:inline-flex; align-items:center; gap:3px;
            padding:2px 8px; border-radius:999px;
            font-size:.62rem; font-weight:700; letter-spacing:.3px;
        }
        .sp-stat-trend.is-up   { background: var(--success-bg); color: var(--success); }
        .sp-stat-trend.is-flat { background: var(--paper-2);    color: var(--text-3); }
        .sp-stat-label {
            font-size:.62rem; font-weight:700; letter-spacing:1.1px;
            text-transform:uppercase; color: var(--text-3); margin-bottom:4px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sp-stat-value {
            font-family:'Fraunces', Georgia, serif;
            font-size: clamp(1.25rem, 1.7vw, 1.55rem);
            font-weight:700; letter-spacing:-.5px;
            color: var(--brown); line-height:1.05;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sp-stat-value small {
            font-size:.68rem; font-weight:600; color: var(--text-3);
            margin-left:3px; letter-spacing:0;
        }
        .sp-stat-foot {
            display:flex; align-items:center; gap:5px;
            margin-top:auto;
            font-size:.68rem; font-weight:500; color: var(--text-3);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sp-stat-foot i { font-size:.78rem; color: var(--gold); flex-shrink: 0; }

        /* Panel */
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

        /* Method breakdown bars */
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

        /* Toolbar / Filters */
        .sp-toolbar {
            display:flex; align-items:center; gap:10px;
            flex-wrap: wrap;
            width: 100%;
        }
        .sp-search {
            display:flex; align-items:center;
            background:#fff;
            border:1.5px solid var(--line-2);
            border-radius:11px;
            transition: border-color .18s, box-shadow .18s;
            overflow:hidden;
            min-height:42px;
            flex: 1 1 220px;
            max-width: 320px;
        }
        .sp-search:focus-within {
            border-color: var(--gold);
            box-shadow: 0 0 0 4px rgba(176,100,30,.13);
        }
        .sp-search i {
            padding: 0 4px 0 14px;
            color: var(--text-3); font-size:.95rem;
            transition: color .18s;
        }
        .sp-search:focus-within i { color: var(--gold); }
        .sp-search input {
            flex:1; border:0; outline:0;
            background:transparent;
            padding: 10px 14px 10px 10px;
            font-size:.86rem; font-weight:500;
            color: var(--text); font-family:inherit;
            min-width:0; width:100%;
        }
        .sp-search input::placeholder { color:#A9A392; font-weight:400; }

        .sp-filter {
            display:inline-flex; align-items:center;
            background:#fff;
            border:1.5px solid var(--line-2);
            border-radius:11px;
            min-height:42px;
            padding-right:12px;
            transition: border-color .18s, box-shadow .18s;
        }
        .sp-filter:focus-within {
            border-color: var(--gold);
            box-shadow: 0 0 0 4px rgba(176,100,30,.13);
        }
        .sp-filter i {
            padding: 0 4px 0 14px;
            color: var(--text-3); font-size:.95rem;
        }
        .sp-filter select,
        .sp-filter input[type="date"] {
            border:0; outline:0;
            background:transparent;
            padding: 10px 6px;
            font-size:.86rem; font-weight:500;
            color: var(--text); font-family:inherit;
            cursor:pointer;
            appearance: none;
            padding-right: 18px;
        }
        .sp-filter input[type="date"] {
            cursor: text;
            padding-right: 6px;
        }

        .sp-btn-clear {
            display:inline-flex; align-items:center; gap:6px;
            padding:9px 14px; border-radius:11px;
            border:1.5px solid var(--line-2);
            background:#fff; color: var(--brown-2);
            font-weight:600; font-size:.82rem;
            cursor:pointer; font-family:inherit;
            text-decoration:none;
            transition: border-color .15s, color .15s;
        }
        .sp-btn-clear:hover { border-color: var(--gold); color: var(--gold); }

        /* Table */
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

        .sp-ref {
            font-family:'JetBrains Mono', ui-monospace, monospace;
            font-size:.76rem; font-weight:600;
            color: var(--brown-2);
            background: var(--paper-2);
            border:1px solid var(--line-2);
            padding:3px 8px; border-radius:5px;
            white-space: nowrap;
        }

        .sp-user-cell {
            display:flex; align-items:center; gap:10px;
        }
        .sp-avatar {
            width:34px; height:34px; border-radius:50%;
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
            font-size:.86rem; margin:0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 180px;
        }
        .sp-user-email {
            font-size:.7rem; color: var(--text-3); margin:0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 180px;
        }

        .sp-amount {
            font-family:'Fraunces', Georgia, serif;
            font-weight:700; color: var(--success); font-size:1rem;
        }

        .sp-method-chip {
            display:inline-flex; align-items:center; gap:6px;
            padding:4px 10px; border-radius:999px;
            font-size:.72rem; font-weight:600;
            background: var(--cream-2);
            border:1px solid var(--line-2);
            color: var(--brown-2);
            white-space: nowrap;
        }
        .sp-method-chip i { font-size:.85rem; color: var(--gold); }
        .sp-method-chip.is-cash    { background: var(--success-bg); border-color: var(--success-bd); color: var(--success); }
        .sp-method-chip.is-cash i  { color: var(--success); }
        .sp-method-chip.is-bank    { background: var(--info-bg);    border-color: #C6DCF0; color: var(--info); }
        .sp-method-chip.is-bank i  { color: var(--info); }
        .sp-method-chip.is-gcash   { background:#E8F7F0; border-color:#B8E4CE; color:#0F7A4E; }
        .sp-method-chip.is-gcash i { color:#0F7A4E; }
        .sp-method-chip.is-check   { background: var(--warn-bg);    border-color: var(--warn-bd); color: var(--warn); }
        .sp-method-chip.is-check i { color: var(--warn); }

        .sp-actions { display:flex; align-items:center; gap:6px; justify-content:flex-end; }
        .sp-action-btn {
            width:34px; height:34px;
            border-radius:9px;
            border:1.5px solid var(--line-2);
            background:#fff;
            color: var(--brown-2);
            display:inline-flex; align-items:center; justify-content:center;
            cursor:pointer; font-size:.9rem;
            transition: border-color .15s, color .15s, transform .15s, background .15s;
        }
        .sp-action-btn:hover {
            border-color: var(--gold); color: var(--gold); transform: translateY(-1px);
        }
        .sp-action-btn.is-danger:hover {
            border-color: var(--danger); color: var(--danger);
            background: var(--danger-bg);
        }

        /* Empty */
        .sp-empty { padding:48px 26px; text-align:center; color: var(--text-3); }
        .sp-empty-ico {
            width:68px; height:68px; margin:0 auto 16px;
            border-radius:50%; display:grid; place-items:center;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            color: var(--gold); font-size:1.7rem;
            border:1px solid rgba(176,100,30,.2);
            box-shadow: 0 10px 24px -12px rgba(176,100,30,.4);
        }
        .sp-empty h4 {
            font-family:'Fraunces', Georgia, serif;
            font-size:1.1rem; font-weight:600; color: var(--brown);
            margin:0 0 6px;
        }
        .sp-empty p {
            font-size:.85rem; margin:0 0 18px;
            max-width:38ch; margin-inline:auto; line-height:1.6;
        }

        /* Pagination */
        .sp-pagination {
            display:flex; align-items:center; justify-content:space-between;
            gap:12px; padding:16px 22px;
            border-top:1px solid var(--line);
            background: var(--cream-2);
            flex-wrap: wrap;
        }
        .sp-pagination-info {
            font-size:.78rem; color: var(--text-3); font-weight:500;
        }
        .sp-pagination-info strong { color: var(--brown); font-weight:700; }
        .sp-pages { display:flex; align-items:center; gap:5px; }
        .sp-page-btn {
            min-width:34px; height:34px;
            padding: 0 10px;
            border-radius:9px;
            border:1.5px solid var(--line-2);
            background:#fff;
            color: var(--brown-2);
            font-weight:600; font-size:.82rem;
            display:inline-flex; align-items:center; justify-content:center;
            text-decoration:none;
            cursor:pointer; font-family:inherit;
            transition: border-color .15s, color .15s, background .15s;
        }
        .sp-page-btn:hover:not(:disabled):not(.is-current) {
            border-color: var(--gold); color: var(--gold);
        }
        .sp-page-btn.is-current {
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            border-color: transparent; color:#fff;
            box-shadow: 0 3px 8px -3px rgba(176,100,30,.7);
        }

        /* Modal */
        .sp-modal-backdrop {
            position:fixed; inset:0;
            background: rgba(42,30,16,.6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index:400;
            display:none;
            align-items:center; justify-content:center;
            padding:20px;
            opacity:0;
            transition: opacity .25s;
        }
        .sp-modal-backdrop.is-open { display:flex; opacity:1; }
        .sp-modal {
            background:#fff;
            border-radius:22px;
            width:100%; max-width:640px;
            max-height: calc(100vh - 40px);
            display:flex; flex-direction:column;
            overflow:hidden;
            box-shadow: 0 46px 100px -34px rgba(40,22,6,.8);
            transform: scale(.95) translateY(10px);
            transition: transform .3s cubic-bezier(.2,.7,.3,1);
        }
        .sp-modal.is-danger { max-width: 480px; }
        .sp-modal.is-view   { max-width: 600px; }
        .sp-modal-backdrop.is-open .sp-modal { transform: scale(1) translateY(0); }
        .sp-modal-head {
            position:relative;
            padding:24px 26px 20px;
            background:
                radial-gradient(circle at 85% 15%, rgba(232,176,90,.35), transparent 55%),
                linear-gradient(135deg, #4A2C10 0%, #2A1E10 100%);
            color:#fff;
            flex-shrink:0; overflow:hidden;
        }
        .sp-modal.is-danger .sp-modal-head {
            background:
                radial-gradient(circle at 85% 15%, rgba(232,120,90,.35), transparent 55%),
                linear-gradient(135deg, #5A2010 0%, #2A1E10 100%);
        }
        .sp-modal-head::before {
            content:''; position:absolute; inset:0;
            background-image:
                repeating-linear-gradient(92deg, transparent 0 22px, rgba(232,176,90,.06) 22px 24px),
                repeating-linear-gradient(88deg, transparent 0 34px, rgba(232,176,90,.04) 34px 37px);
            pointer-events:none;
        }
        .sp-modal-head > * { position:relative; z-index:1; }
        .sp-modal-close {
            position:absolute; top:18px; right:18px;
            width:36px; height:36px;
            border-radius:10px;
            border:1px solid rgba(255,255,255,.2);
            background: rgba(255,255,255,.1);
            color:#fff;
            cursor:pointer;
            display:inline-flex; align-items:center; justify-content:center;
            font-size:1rem;
            transition: background .18s, transform .25s;
        }
        .sp-modal-close:hover {
            background: rgba(255,255,255,.22);
            transform: rotate(90deg);
        }
        .sp-modal-tag {
            display:inline-flex; align-items:center; gap:6px;
            padding:4px 11px; border-radius:999px;
            background: rgba(232,176,90,.15);
            border:1px solid rgba(232,176,90,.3);
            font-size:.62rem; font-weight:700;
            letter-spacing:1.2px; text-transform:uppercase;
            color: var(--gold-3);
            margin-bottom:12px;
        }
        .sp-modal-title {
            font-family:'Fraunces', Georgia, serif;
            font-size:1.45rem; font-weight:600;
            letter-spacing:-.45px; margin:0 0 4px; line-height:1.2;
        }
        .sp-modal-sub {
            margin:0; font-size:.85rem;
            color: rgba(255,255,255,.72); line-height:1.55;
        }
        .sp-modal-body {
            padding:24px 26px;
            overflow-y:auto; overflow-x:hidden;
            flex:1 1 auto; min-height:0;
            display:flex; flex-direction:column; gap:16px;
            scrollbar-width:thin;
            scrollbar-color: var(--line-3) transparent;
        }
        .sp-modal-body::-webkit-scrollbar { width:8px; }
        .sp-modal-body::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--line-2), var(--line-3));
            border-radius:4px;
        }
        .sp-modal-foot {
            padding:16px 26px 22px;
            border-top:1px solid var(--line);
            background: var(--cream-2);
            display:flex; align-items:center; justify-content:flex-end;
            gap:10px; flex-shrink:0;
            flex-wrap: wrap;
        }

        .sp-section-title {
            display:flex; align-items:center; gap:10px;
            font-size:.68rem; font-weight:700; letter-spacing:1.2px;
            text-transform:uppercase; color: var(--brown-2);
            margin-top:4px; padding-top:10px;
            border-top:1px dashed var(--line-2);
        }
        .sp-section-title i {
            width:22px; height:22px; border-radius:7px;
            background: var(--gold-soft); color: var(--gold);
            display:grid; place-items:center; font-size:.8rem;
        }

        .sp-form-row {
            display:grid;
            grid-template-columns: 1fr 1fr;
            gap:14px;
        }
        @media (max-width:540px) { .sp-form-row { grid-template-columns: 1fr; } }
        .sp-form-field { display:flex; flex-direction:column; gap:6px; }
        .sp-form-field.is-full { grid-column: 1 / -1; }
        .sp-form-field label {
            font-size:.66rem; font-weight:700; letter-spacing:1.1px;
            text-transform:uppercase; color: var(--brown-2);
        }
        .sp-form-field label .req { color: var(--danger); margin-left:2px; }
        .sp-form-input {
            display:flex; align-items:center;
            background:#fff;
            border:1.5px solid var(--line-2);
            border-radius:11px;
            transition: border-color .18s, box-shadow .18s;
            overflow:hidden; min-height:46px;
        }
        .sp-form-input:focus-within {
            border-color: var(--gold);
            box-shadow: 0 0 0 4px rgba(176,100,30,.13);
        }
        .sp-form-input i {
            padding: 0 4px 0 14px;
            color: var(--text-3); font-size:.95rem;
            transition: color .18s;
        }
        .sp-form-input:focus-within i { color: var(--gold); }
        .sp-form-input input,
        .sp-form-input select,
        .sp-form-input textarea {
            flex:1; border:0; outline:0;
            background:transparent;
            padding: 11px 14px 11px 10px;
            font-size:.89rem; font-weight:500;
            color: var(--text); font-family:inherit;
            min-width:0; width:100%;
        }
        .sp-form-input textarea { resize: vertical; min-height:76px; padding-top:12px; }
        .sp-form-input input::placeholder,
        .sp-form-input textarea::placeholder { color:#A9A392; font-weight:400; }
        .sp-form-input select option[value=""] { color: #A9A392; }
        .sp-form-hint {
            font-size:.7rem; color: var(--text-3);
            font-weight:500; padding-left:2px;
        }

        .sp-amount-preview {
            padding: 16px;
            border-radius: 14px;
            background: linear-gradient(135deg, #EAF7EC, #fff);
            border: 1px dashed var(--success-bd);
            display: flex; align-items: center; justify-content: space-between;
        }
        .sp-amount-preview-label {
            display: inline-flex; align-items: center; gap: 9px;
            font-size: .85rem;
            color: var(--text-2);
            font-weight: 600;
        }
        .sp-amount-preview-label i { color: var(--success); font-size: 1rem; }
        .sp-amount-preview-value {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.4rem; font-weight: 700;
            color: var(--success);
        }

        .sp-btn-submit {
            display:inline-flex; align-items:center; justify-content:center;
            gap:8px;
            padding:12px 22px;
            border-radius:11px;
            border:0;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color:#fff; font-weight:700; font-size:.87rem;
            cursor:pointer; font-family:inherit;
            box-shadow: 0 3px 0 rgba(0,0,0,.12), 0 14px 24px -12px rgba(176,100,30,.75);
            transition: transform .15s, filter .15s;
        }
        .sp-btn-submit:hover { transform: translateY(-1px); filter: brightness(1.04); }
        .sp-btn-cancel {
            display:inline-flex; align-items:center; justify-content:center;
            gap:7px;
            padding:12px 20px;
            border-radius:11px;
            border:1.5px solid var(--line-2);
            background:#fff;
            color: var(--brown-2);
            font-weight:600; font-size:.87rem;
            cursor:pointer; font-family:inherit;
            transition: border-color .15s, color .15s, transform .15s;
        }
        .sp-btn-cancel:hover { border-color: var(--gold); color: var(--gold); transform: translateY(-1px); }
        .sp-btn-danger {
            display:inline-flex; align-items:center; justify-content:center;
            gap:8px;
            padding:12px 22px;
            border-radius:11px;
            border:0;
            background: linear-gradient(135deg, var(--danger), #C25A3A);
            color:#fff; font-weight:700; font-size:.87rem;
            cursor:pointer; font-family:inherit;
            box-shadow: 0 3px 0 rgba(0,0,0,.12), 0 14px 24px -12px rgba(162,58,26,.75);
            transition: transform .15s, filter .15s;
        }
        .sp-btn-danger:hover { transform: translateY(-1px); filter: brightness(1.04); }

        .sp-danger-preview {
            padding: 16px;
            border-radius: 12px;
            background: var(--danger-bg);
            border: 1px solid var(--danger-bd);
            display: flex; align-items: center; gap: 12px;
        }
        .sp-danger-preview i {
            font-size: 1.6rem; color: var(--danger);
            flex-shrink: 0;
        }
        .sp-danger-preview strong { color: var(--brown); font-weight: 700; }
        .sp-danger-preview small {
            display: block; color: var(--text-3);
            font-size: .78rem; margin-top: 2px;
        }

        /* Detail view */
        .sp-detail-grid {
            display:grid; grid-template-columns: 1fr 1fr; gap: 14px;
        }
        @media (max-width:540px) { .sp-detail-grid { grid-template-columns: 1fr; } }
        .sp-detail-item {
            padding: 13px 15px;
            border-radius: 12px;
            background: var(--cream-2);
            border: 1px solid var(--line);
        }
        .sp-detail-item.is-full { grid-column: 1 / -1; }
        .sp-detail-label {
            font-size:.66rem; font-weight:700; letter-spacing:1.1px;
            text-transform:uppercase; color: var(--text-3);
            margin-bottom: 5px;
            display:flex; align-items:center; gap:6px;
        }
        .sp-detail-label i { color: var(--gold); font-size:.8rem; }
        .sp-detail-value {
            font-family:'Fraunces', Georgia, serif;
            font-size:1rem; font-weight:600;
            color: var(--brown); line-height: 1.3;
        }
        .sp-detail-value.is-mono {
            font-family:'JetBrains Mono', monospace;
            font-size:.85rem;
        }
        .sp-detail-value.is-normal { font-family:'Inter', sans-serif; font-size:.9rem; font-weight:500; }
        .sp-detail-value.is-amount {
            color: var(--success);
            font-size: 1.25rem;
        }

        /* Toast */
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
            .sp-pagination { padding: 12px 16px; }
            .sp-modal-body { padding:18px 18px; }
            .sp-modal-foot { padding:12px 18px 16px; }
            .sp-methods { padding: 14px 16px; }
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
        <a href="<?= BASE_URL ?>admin/payments.php" class="sp-nav-link is-active">
            <i class="bi bi-cash-coin"></i> Payments
        </a>

        <span class="sp-nav-label">System</span>
        <a href="<?= BASE_URL ?>admin/reports.php" class="sp-nav-link">
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
                <h1 class="sp-page-title">Payments</h1>
                <span class="sp-page-sub">
                    All recorded payments —
                    <strong><?= number_format($stats['total']) ?></strong> total
                </span>
            </div>
        </div>

        <div class="sp-topbar-right">
            <button class="sp-icon-btn" id="notifBtn" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <span class="badge-dot"></span>
            </button>
            <button type="button" class="sp-cta" id="topAddBtn">
                <i class="bi bi-plus-lg"></i>
                <span>Record Payment</span>
            </button>
        </div>
    </header>

    <main class="sp-content">

        <?php if (!empty($flash['msg'])): ?>
            <div class="sp-flash is-<?= htmlspecialchars($flash['type']) ?>">
                <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
                <span><?= htmlspecialchars($flash['msg']) ?></span>
            </div>
        <?php endif; ?>

        <!-- Stats — single row of 4 -->
        <section class="sp-stats">
            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-success"><i class="bi bi-cash-coin"></i></div>
                    <span class="sp-stat-trend is-up"><i class="bi bi-arrow-up-right"></i> +<?= $stats['this_month'] ?></span>
                </div>
                <div>
                    <div class="sp-stat-label">Total Payments</div>
                    <div class="sp-stat-value"><?= number_format($stats['total']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-clock-history"></i> All time</div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico"><i class="bi bi-cash-stack"></i></div>
                    <span class="sp-stat-trend is-up"><i class="bi bi-check2-circle"></i> Collected</span>
                </div>
                <div>
                    <div class="sp-stat-label">Total Amount</div>
                    <div class="sp-stat-value"><?= peso($stats['total_amount']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-check-circle"></i> All methods</div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-info"><i class="bi bi-graph-up"></i></div>
                    <span class="sp-stat-trend is-up">
                        <i class="bi bi-arrow-up-right"></i> <?= peso($stats['this_month_amt']) ?>
                    </span>
                </div>
                <div>
                    <div class="sp-stat-label">This Month</div>
                    <div class="sp-stat-value"><?= peso($stats['this_month_amt']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-calendar3"></i> <?= date('F Y') ?></div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-warn"><i class="bi bi-bar-chart"></i></div>
                </div>
                <div>
                    <div class="sp-stat-label">Average Payment</div>
                    <div class="sp-stat-value"><?= peso($stats['avg']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-calculator"></i> Per transaction</div>
            </article>
        </section>

        <!-- Method breakdown -->
        <?php if (!empty($methodBreakdown)): ?>
        <section class="sp-panel">
            <div class="sp-panel-head">
                <h3 class="sp-panel-title">
                    <i class="bi bi-pie-chart"></i>
                    Breakdown by Method
                </h3>
            </div>
            <div class="sp-methods">
                <?php foreach ($methodBreakdown as $m): ?>
                    <?php
                        $mm = $m['method'];
                    ?>
                    <div class="sp-method-card">
                        <div class="sp-method-ico">
                            <i class="bi <?= methodIcon($mm) ?>"></i>
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
        </section>
        <?php endif; ?>

        <!-- Panel -->
        <section class="sp-panel">

            <div class="sp-panel-head">
                <h3 class="sp-panel-title">
                    <i class="bi bi-list-ul"></i>
                    Payment Log
                </h3>
            </div>

            <div style="padding: 16px 22px; border-bottom: 1px solid var(--line); background: var(--cream-2);">
                <form method="get" class="sp-toolbar" id="filterForm">
                    <label class="sp-search">
                        <i class="bi bi-search"></i>
                        <input type="search" name="q"
                               placeholder="Search reference or buyer…"
                               value="<?= htmlspecialchars($q) ?>" autocomplete="off">
                    </label>

                    <label class="sp-filter">
                        <i class="bi bi-credit-card-2-front"></i>
                        <select name="method" onchange="document.getElementById('filterForm').submit()">
                            <option value="">All methods</option>
                            <option value="cash"          <?= $methodF === 'cash'          ? 'selected' : '' ?>>Cash</option>
                            <option value="bank_transfer" <?= $methodF === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                            <option value="gcash"         <?= $methodF === 'gcash'         ? 'selected' : '' ?>>GCash</option>
                            <option value="check"         <?= $methodF === 'check'         ? 'selected' : '' ?>>Check</option>
                            <option value="other"         <?= $methodF === 'other'         ? 'selected' : '' ?>>Other</option>
                        </select>
                    </label>

                    <?php if ($hasBuyerFK && !empty($buyersList)): ?>
                    <label class="sp-filter">
                        <i class="bi bi-person"></i>
                        <select name="buyer" onchange="document.getElementById('filterForm').submit()">
                            <option value="0">All buyers</option>
                            <?php foreach ($buyersList as $b): ?>
                                <option value="<?= (int) $b['id'] ?>" <?= $buyerF === (int)$b['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($b['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php endif; ?>

                    <label class="sp-filter" title="From date">
                        <i class="bi bi-calendar3"></i>
                        <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"
                               min="2000-01-01" max="2099-12-31"
                               onchange="document.getElementById('filterForm').submit()">
                    </label>

                    <label class="sp-filter" title="To date">
                        <i class="bi bi-calendar3"></i>
                        <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"
                               min="2000-01-01" max="2099-12-31"
                               onchange="document.getElementById('filterForm').submit()">
                    </label>

                    <?php if ($q !== '' || $methodF !== '' || $buyerF > 0 || $dateFrom !== '' || $dateTo !== ''): ?>
                        <a href="payments.php" class="sp-btn-clear">
                            <i class="bi bi-x-lg"></i> Clear filters
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (!empty($payments)): ?>
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Buyer</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Purchase</th>
                                <th>Date</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $pay): ?>
                                <?php
                                    $mm = $pay['method'] ?? 'cash';
                                    $chipCls = 'is-' . ($mm === 'bank_transfer' ? 'bank' : $mm);
                                ?>
                                <tr>
                                    <td><span class="sp-ref"><?= sanitize($pay['reference_no'] ?? '—') ?></span></td>

                                    <td>
                                        <div class="sp-user-cell">
                                            <div class="sp-avatar"><?= htmlspecialchars(initials($pay['buyer_name'] ?? '?')) ?></div>
                                            <div class="sp-user-meta">
                                                <p class="sp-user-name"><?= sanitize($pay['buyer_name'] ?? '—') ?></p>
                                                <?php if (!empty($pay['buyer_email'])): ?>
                                                    <p class="sp-user-email"><?= sanitize($pay['buyer_email']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <td><span class="sp-amount"><?= peso($pay['amount'] ?? 0) ?></span></td>

                                    <td>
                                        <span class="sp-method-chip <?= $chipCls ?>">
                                            <i class="bi <?= methodIcon($mm) ?>"></i>
                                            <?= sanitize(methodLabel($mm)) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php if (!empty($pay['purchase_ref'])): ?>
                                            <span class="sp-ref"><?= sanitize($pay['purchase_ref']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:.8rem;">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= niceDate($pay['paid_at'] ?? null) ?></td>

                                    <td>
                                        <div class="sp-actions">
                                            <button type="button" class="sp-action-btn js-view"
                                                title="View details"
                                                data-id="<?= (int) $pay['id'] ?>"
                                                data-ref="<?= sanitize($pay['reference_no'] ?? '') ?>"
                                                data-buyer="<?= sanitize($pay['buyer_name'] ?? '') ?>"
                                                data-buyer-email="<?= sanitize($pay['buyer_email'] ?? '') ?>"
                                                data-amount="<?= (float) ($pay['amount'] ?? 0) ?>"
                                                data-method="<?= sanitize($mm) ?>"
                                                data-method-label="<?= sanitize(methodLabel($mm)) ?>"
                                                data-purchase="<?= sanitize($pay['purchase_ref'] ?? '') ?>"
                                                data-date="<?= sanitize($pay['paid_at'] ?? '') ?>"
                                                data-notes="<?= sanitize($pay['notes'] ?? '') ?>">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button type="button" class="sp-action-btn js-edit"
                                                title="Edit payment"
                                                data-id="<?= (int) $pay['id'] ?>"
                                                data-buyer-id="<?= (int) ($pay['buyer_id'] ?? 0) ?>"
                                                data-purchase-id="<?= (int) ($pay['purchase_id'] ?? 0) ?>"
                                                data-amount="<?= (float) ($pay['amount'] ?? 0) ?>"
                                                data-method="<?= sanitize($mm) ?>"
                                                data-date="<?= sanitize($pay['paid_at'] ?? '') ?>"
                                                data-notes="<?= sanitize($pay['notes'] ?? '') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="sp-action-btn is-danger js-delete"
                                                title="Delete payment"
                                                data-id="<?= (int) $pay['id'] ?>"
                                                data-ref="<?= sanitize($pay['reference_no'] ?? '') ?>"
                                                data-amount="<?= peso($pay['amount'] ?? 0) ?>">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="sp-pagination">
                    <div class="sp-pagination-info">
                        Showing <strong><?= count($payments) ?></strong> of
                        <strong><?= number_format($totalRows) ?></strong> payments
                        · Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
                    </div>

                    <div class="sp-pages">
                        <?php
                            $qs = $_GET; unset($qs['page']);
                            $buildUrl = function ($p) use ($qs) {
                                $qs['page'] = $p;
                                return 'payments.php?' . http_build_query($qs);
                            };
                        ?>
                        <a class="sp-page-btn" href="<?= $page <= 1 ? '#' : htmlspecialchars($buildUrl($page - 1)) ?>"
                           <?= $page <= 1 ? 'style="pointer-events:none;opacity:.45;"' : '' ?>>
                            <i class="bi bi-chevron-left"></i>
                        </a>
                        <?php
                            $start = max(1, $page - 2);
                            $end   = min($totalPages, $page + 2);
                            if ($start > 1) {
                                echo '<a class="sp-page-btn" href="' . htmlspecialchars($buildUrl(1)) . '">1</a>';
                                if ($start > 2) echo '<span style="color:var(--text-3);padding:0 4px;">…</span>';
                            }
                            for ($i = $start; $i <= $end; $i++) {
                                $cls = $i === $page ? 'is-current' : '';
                                echo '<a class="sp-page-btn ' . $cls . '" href="' . htmlspecialchars($buildUrl($i)) . '">' . $i . '</a>';
                            }
                            if ($end < $totalPages) {
                                if ($end < $totalPages - 1) echo '<span style="color:var(--text-3);padding:0 4px;">…</span>';
                                echo '<a class="sp-page-btn" href="' . htmlspecialchars($buildUrl($totalPages)) . '">' . $totalPages . '</a>';
                            }
                        ?>
                        <a class="sp-page-btn" href="<?= $page >= $totalPages ? '#' : htmlspecialchars($buildUrl($page + 1)) ?>"
                           <?= $page >= $totalPages ? 'style="pointer-events:none;opacity:.45;"' : '' ?>>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <div class="sp-empty">
                    <div class="sp-empty-ico"><i class="bi bi-receipt-cutoff"></i></div>
                    <h4><?= ($q !== '' || $methodF !== '' || $buyerF || $dateFrom || $dateTo) ? 'No payments match your filters' : 'No payments yet' ?></h4>
                    <p>
                        <?= ($q !== '' || $methodF !== '' || $buyerF || $dateFrom || $dateTo)
                            ? 'Try adjusting your filters or clearing them.'
                            : 'Record your first payment to start tracking collections.' ?>
                    </p>
                    <?php if ($q !== '' || $methodF !== '' || $buyerF || $dateFrom || $dateTo): ?>
                        <a href="payments.php" class="sp-btn-cancel">
                            <i class="bi bi-x-lg"></i> Clear filters
                        </a>
                    <?php else: ?>
                        <button type="button" class="sp-btn-submit" id="emptyAddBtn">
                            <i class="bi bi-plus-lg"></i> Record Payment
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </section>

    </main>
</div>

<!-- ADD / EDIT MODAL -->
<div class="sp-modal-backdrop" id="modalPayment">
    <div class="sp-modal" role="dialog" aria-modal="true">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
            <span class="sp-modal-tag" id="payModalTag">
                <i class="bi bi-plus-circle"></i>
                <span id="payModalTagText">New Payment</span>
            </span>
            <h3 class="sp-modal-title" id="payModalTitle">Record a Payment</h3>
            <p class="sp-modal-sub" id="payModalSub">
                Log a payment toward a purchase.
            </p>
        </div>

        <form id="paymentForm" method="post" autocomplete="off" style="display:contents;">
            <input type="hidden" name="action" id="payAction" value="create">
            <input type="hidden" name="id" id="payId" value="">

            <div class="sp-modal-body">

                <div class="sp-section-title">
                    <i class="bi bi-people"></i> Parties
                </div>

                <div class="sp-form-row">

                    <?php if ($hasBuyerFK): ?>
                    <div class="sp-form-field">
                        <label for="pf_buyer">Buyer</label>
                        <div class="sp-form-input">
                            <i class="bi bi-bag-check"></i>
                            <select id="pf_buyer" name="buyer_id">
                                <option value="" disabled selected>— Select a buyer —</option>
                                <?php foreach ($buyersList as $b): ?>
                                    <option value="<?= (int) $b['id'] ?>">
                                        <?= sanitize($b['full_name']) ?>
                                        <?= !empty($b['email']) ? ' (' . sanitize($b['email']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($hasPurchaseFK): ?>
                    <div class="sp-form-field">
                        <label for="pf_purchase">Purchase (optional)</label>
                        <div class="sp-form-input">
                            <i class="bi bi-receipt"></i>
                            <select id="pf_purchase" name="purchase_id">
                                <option value="" disabled selected>— Select a purchase —</option>
                                <?php foreach ($purchasesList as $p): ?>
                                    <option value="<?= (int) $p['id'] ?>">
                                        <?= sanitize($p['reference_no']) ?>
                                        <?php if (!empty($p['buyer_name'])): ?> · <?= sanitize($p['buyer_name']) ?><?php endif; ?>
                                        — balance <?= peso($p['balance']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sp-form-hint">Link to a specific unpaid purchase, or leave blank.</div>
                    </div>
                    <?php endif; ?>

                </div>

                <div class="sp-section-title">
                    <i class="bi bi-cash-coin"></i> Payment
                </div>

                <div class="sp-form-row">

                    <div class="sp-form-field">
                        <label for="pf_amount">Amount (₱) <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-cash-stack"></i>
                            <input type="number" id="pf_amount" name="amount"
                                   min="0.01" step="0.01" placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="sp-form-field">
                        <label for="pf_method">Method <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-credit-card-2-front"></i>
                            <select id="pf_method" name="method" required>
                                <option value="" disabled selected>Select a method…</option>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="gcash">GCash</option>
                                <option value="check">Check</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="sp-form-field is-full">
                        <label for="pf_date">Payment Date &amp; Time</label>
                        <div class="sp-form-input">
                            <i class="bi bi-calendar3"></i>
                            <input type="datetime-local" id="pf_date" name="paid_at">
                        </div>
                        <div class="sp-form-hint">Leave blank to use the current date &amp; time.</div>
                    </div>

                    <?php if ($hasNotes): ?>
                    <div class="sp-form-field is-full">
                        <label for="pf_notes">Notes (optional)</label>
                        <div class="sp-form-input">
                            <i class="bi bi-chat-left-text"></i>
                            <textarea id="pf_notes" name="notes"
                                      placeholder="e.g. Partial settlement, receipt #12345…"></textarea>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>

                <div class="sp-amount-preview">
                    <span class="sp-amount-preview-label">
                        <i class="bi bi-cash-coin"></i>
                        Payment Amount
                    </span>
                    <span class="sp-amount-preview-value" id="pf_preview">₱0.00</span>
                </div>

                <div class="sp-amount-preview" id="pf_balanceRow" style="background: linear-gradient(135deg, var(--warn-bg), #fff); border-color: var(--warn-bd); display:none;">
                    <span class="sp-amount-preview-label">
                        <i class="bi bi-hourglass-split" style="color: var(--warn);"></i>
                        Remaining after this payment
                    </span>
                    <span class="sp-amount-preview-value" id="pf_balanceAfter" style="color: var(--warn);">₱0.00</span>
                </div>

            </div>

            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-cancel" data-close-modal>
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="submit" class="sp-btn-submit" id="paySubmit">
                    <i class="bi bi-check-lg"></i> Save Payment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW MODAL -->
<div class="sp-modal-backdrop" id="modalView">
    <div class="sp-modal is-view" role="dialog" aria-modal="true">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
            <span class="sp-modal-tag">
                <i class="bi bi-eye"></i> Payment Details
            </span>
            <h3 class="sp-modal-title" id="viewRef">—</h3>
            <p class="sp-modal-sub">Full payment breakdown.</p>
        </div>

        <div class="sp-modal-body">
            <div class="sp-detail-grid">
                <div class="sp-detail-item is-full">
                    <div class="sp-detail-label"><i class="bi bi-hash"></i> Reference</div>
                    <div class="sp-detail-value is-mono" id="viewRefVal">—</div>
                </div>

                <div class="sp-detail-item is-full">
                    <div class="sp-detail-label"><i class="bi bi-cash-stack"></i> Amount</div>
                    <div class="sp-detail-value is-amount" id="viewAmount">—</div>
                </div>

                <div class="sp-detail-item">
                    <div class="sp-detail-label"><i class="bi bi-bag-check"></i> Buyer</div>
                    <div class="sp-detail-value is-normal" id="viewBuyer">—</div>
                </div>

                <div class="sp-detail-item">
                    <div class="sp-detail-label"><i class="bi bi-credit-card-2-front"></i> Method</div>
                    <div id="viewMethod">—</div>
                </div>

                <div class="sp-detail-item">
                    <div class="sp-detail-label"><i class="bi bi-receipt"></i> Linked Purchase</div>
                    <div class="sp-detail-value is-mono" id="viewPurchase">—</div>
                </div>

                <div class="sp-detail-item">
                    <div class="sp-detail-label"><i class="bi bi-calendar3"></i> Paid At</div>
                    <div class="sp-detail-value is-normal" id="viewDate">—</div>
                </div>

                <div class="sp-detail-item is-full" id="viewNotesWrap">
                    <div class="sp-detail-label"><i class="bi bi-chat-left-text"></i> Notes</div>
                    <div class="sp-detail-value is-normal" id="viewNotes">—</div>
                </div>
            </div>
        </div>

        <div class="sp-modal-foot">
            <button type="button" class="sp-btn-cancel" data-close-modal>
                <i class="bi bi-x-lg"></i> Close
            </button>
            <button type="button" class="sp-btn-submit" id="viewEditBtn">
                <i class="bi bi-pencil"></i> Edit
            </button>
        </div>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="sp-modal-backdrop" id="modalDelete">
    <div class="sp-modal is-danger" role="dialog" aria-modal="true">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
            <span class="sp-modal-tag" style="background:rgba(239,198,176,.18);border-color:rgba(239,198,176,.4);color:#FFC7AD;">
                <i class="bi bi-exclamation-triangle"></i> Confirm Delete
            </span>
            <h3 class="sp-modal-title">Delete this payment?</h3>
            <p class="sp-modal-sub">This action cannot be undone.</p>
        </div>

        <form method="post" id="deleteForm" style="display:contents;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId" value="">

            <div class="sp-modal-body">
                <div class="sp-danger-preview">
                    <i class="bi bi-receipt-cutoff"></i>
                    <div>
                        <strong id="deleteRef">—</strong>
                        <small id="deleteAmount">—</small>
                    </div>
                </div>
            </div>

            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-cancel" data-close-modal>
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="submit" class="sp-btn-danger">
                    <i class="bi bi-trash3"></i> Delete Payment
                </button>
            </div>
        </form>
    </div>
</div>

<div class="sp-toast" id="spToast">
    <i class="bi bi-check-circle-fill" id="spToastIcon"></i>
    <span id="spToastText">Ready</span>
</div>

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

    /* Modals */
    const modalPayment = document.getElementById('modalPayment');
    const modalView    = document.getElementById('modalView');
    const modalDelete  = document.getElementById('modalDelete');

    function openModal(m) {
        m.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        setTimeout(() => m.querySelector('input:not([type=hidden]), select')?.focus(), 120);
    }
    function closeModal(m) {
        if (!m) return;
        m.classList.remove('is-open');
        if (!document.querySelector('.sp-modal-backdrop.is-open')) {
            document.body.style.overflow = '';
        }
    }
    document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', () => closeModal(btn.closest('.sp-modal-backdrop')));
    });
    document.querySelectorAll('.sp-modal-backdrop').forEach(m => {
        m.addEventListener('click', (e) => { if (e.target === m) closeModal(m); });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const open = [...document.querySelectorAll('.sp-modal-backdrop.is-open')].pop();
            if (open) closeModal(open);
        }
    });

    /* ---------- Add / Edit form ---------- */
    const pForm       = document.getElementById('paymentForm');
    const payAction   = document.getElementById('payAction');
    const payId       = document.getElementById('payId');
    const pf_buyer    = document.getElementById('pf_buyer');
    const pf_purchase = document.getElementById('pf_purchase');
    const pf_amount   = document.getElementById('pf_amount');
    const pf_method   = document.getElementById('pf_method');
    const pf_date     = document.getElementById('pf_date');
    const pf_notes    = document.getElementById('pf_notes');
    const pf_preview  = document.getElementById('pf_preview');

    const payTagText = document.getElementById('payModalTagText');
    const payTitle   = document.getElementById('payModalTitle');
    const paySub     = document.getElementById('payModalSub');

    function pesoFmt(n) {
        n = Number(n) || 0;
        return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /* Balance lookup for linked purchases */
    const balanceMap = <?php
        $bm = [];
        foreach ($purchasesList as $pp) $bm[(int)$pp['id']] = (float)$pp['balance'];
        echo json_encode($bm);
    ?>;

    function recalcPreview() {
        const amt = parseFloat(pf_amount?.value) || 0;
        if (pf_preview) pf_preview.textContent = pesoFmt(amt);

        const row = document.getElementById('pf_balanceRow');
        const out = document.getElementById('pf_balanceAfter');
        if (!row || !out) return;

        const pid = parseInt(pf_purchase?.value || '0', 10);
        if (!pid || !(pid in balanceMap)) {
            row.style.display = 'none';
            return;
        }
        const remaining = Math.max(0, balanceMap[pid] - amt);
        out.textContent = pesoFmt(remaining);
        out.style.color = remaining <= 0 ? 'var(--success)' : 'var(--warn)';
        row.style.display = '';
    }
    pf_amount?.addEventListener('input', recalcPreview);
    pf_purchase?.addEventListener('change', recalcPreview);

    function toLocalDateTimeValue(iso) {
        if (!iso) return '';
        const d = new Date(iso.replace(' ', 'T'));
        if (isNaN(d)) return '';
        d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
        return d.toISOString().slice(0, 16);
    }

    function resetPaymentForm() {
        pForm.reset();
        payAction.value = 'create';
        payId.value = '';
        if (pf_buyer)    pf_buyer.value    = '';
        if (pf_purchase) pf_purchase.value = '';
        if (pf_amount)   pf_amount.value   = '';
        if (pf_date)     pf_date.value     = '';   // empty by default
        if (pf_method)   pf_method.value   = '';   // empty by default
        if (pf_notes)    pf_notes.value    = '';
        recalcPreview();
        payTagText.textContent = 'New Payment';
        payTitle.textContent   = 'Record a Payment';
        paySub.textContent     = 'Log a payment toward a purchase.';
    }

    document.getElementById('topAddBtn')?.addEventListener('click', () => {
        resetPaymentForm();
        openModal(modalPayment);
    });
    document.getElementById('emptyAddBtn')?.addEventListener('click', () => {
        resetPaymentForm();
        openModal(modalPayment);
    });

    document.querySelectorAll('.js-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            const d = btn.dataset;
            payAction.value = 'update';
            payId.value     = d.id || '';
            if (pf_buyer && d.buyerId)       pf_buyer.value = d.buyerId;
            if (pf_purchase && d.purchaseId) pf_purchase.value = d.purchaseId;
            if (pf_amount) pf_amount.value = d.amount || '';
            if (pf_method) pf_method.value = d.method || '';
            if (pf_date)   pf_date.value   = toLocalDateTimeValue(d.date);
            if (pf_notes)  pf_notes.value  = d.notes || '';

            payTagText.textContent = 'Edit Payment';
            payTitle.textContent   = 'Edit Payment';
            paySub.textContent     = 'Update payment details.';

            recalcPreview();
            openModal(modalPayment);
        });
    });

    /* ---------- View ---------- */
    const viewRef      = document.getElementById('viewRef');
    const viewRefVal   = document.getElementById('viewRefVal');
    const viewAmount   = document.getElementById('viewAmount');
    const viewBuyer    = document.getElementById('viewBuyer');
    const viewMethod   = document.getElementById('viewMethod');
    const viewPurchase = document.getElementById('viewPurchase');
    const viewDate     = document.getElementById('viewDate');
    const viewNotes    = document.getElementById('viewNotes');
    const viewEditBtn  = document.getElementById('viewEditBtn');

    let currentViewId = null;

    const methodIcons = {
        cash: 'bi-cash-coin',
        bank_transfer: 'bi-bank',
        gcash: 'bi-phone',
        check: 'bi-file-earmark-text',
        other: 'bi-three-dots',
    };
    function chipClass(m) {
        if (m === 'bank_transfer') return 'is-bank';
        if (m === 'cash') return 'is-cash';
        if (m === 'gcash') return 'is-gcash';
        if (m === 'check') return 'is-check';
        return '';
    }
    function niceDateTime(s) {
        if (!s) return '—';
        const d = new Date(s.replace(' ', 'T'));
        if (isNaN(d)) return s;
        return d.toLocaleString('en-PH', {
            year:'numeric', month:'short', day:'numeric',
            hour:'numeric', minute:'2-digit'
        });
    }

    document.querySelectorAll('.js-view').forEach(btn => {
        btn.addEventListener('click', () => {
            const d = btn.dataset;
            currentViewId = d.id;

            viewRef.textContent     = d.ref || 'Payment';
            viewRefVal.textContent  = d.ref || '—';
            viewAmount.textContent  = pesoFmt(d.amount);
            viewBuyer.textContent   = d.buyer || '—';
            viewPurchase.textContent= d.purchase || '—';
            viewDate.textContent    = niceDateTime(d.date);
            if (viewNotes) viewNotes.textContent = d.notes || '—';

            const m = d.method || 'cash';
            const label = d.methodLabel || m;
            const ico = methodIcons[m] || 'bi-cash-coin';
            viewMethod.innerHTML = '<span class="sp-method-chip ' + chipClass(m) + '">' +
                                   '<i class="bi ' + ico + '"></i> ' + label + '</span>';

            openModal(modalView);
        });
    });

    viewEditBtn?.addEventListener('click', () => {
        closeModal(modalView);
        const editBtn = document.querySelector('.js-edit[data-id="' + currentViewId + '"]');
        setTimeout(() => editBtn?.click(), 220);
    });

    /* ---------- Delete ---------- */
    const deleteId     = document.getElementById('deleteId');
    const deleteRef    = document.getElementById('deleteRef');
    const deleteAmount = document.getElementById('deleteAmount');

    document.querySelectorAll('.js-delete').forEach(btn => {
        btn.addEventListener('click', () => {
            deleteId.value = btn.dataset.id || '';
            deleteRef.textContent = btn.dataset.ref || '—';
            deleteAmount.textContent = btn.dataset.amount || '';
            openModal(modalDelete);
        });
    });

    /* ---------- Toast ---------- */
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

    /* ---------- Debounced search ---------- */
    const searchInput = document.querySelector('.sp-search input');
    const filterForm  = document.getElementById('filterForm');
    let searchTimer;
    searchInput?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => filterForm.submit(), 450);
    });
})();
</script>
</body>
</html>