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

/* ---------- Current user (for auto-selecting buyer) ---------- */
$currentUserRole = $_SESSION['role'] ?? '';
$currentUserId   = (int) ($_SESSION['user_id'] ?? 0);

/* ---------- Feature detection ---------- */
$hasBuyerFK = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'buyer_id'")->fetchAll();
    $hasBuyerFK = !empty($cols);
} catch (Throwable $e) { $hasBuyerFK = false; }

$hasNotes = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'notes'")->fetchAll();
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
            $seller   = trim($_POST['seller_name'] ?? '');
            $weight   = (float) ($_POST['weight_kg'] ?? 0);
            $price    = (float) ($_POST['price_per_kg'] ?? 0);
            $paid     = (float) ($_POST['amount_paid'] ?? 0);
            $status   = $_POST['status'] ?? 'unpaid';
            $date     = trim($_POST['created_at'] ?? '');
            $notes    = trim($_POST['notes'] ?? '');
            $ref      = trim($_POST['reference_no'] ?? '');

            if ($buyerId <= 0)   throw new Exception('Please select a buyer.');
            if ($seller === '')  throw new Exception('Seller name is required.');
            if ($weight <= 0)    throw new Exception('Weight must be greater than 0.');
            if ($price <= 0)     throw new Exception('Price per kg must be greater than 0.');

            /* Date is required and must be valid (allows any year, incl. 2026) */
            if ($date === '') {
                throw new Exception('Please choose a purchase date.');
            }
            $dObj = DateTime::createFromFormat('Y-m-d', $date);
            if (!$dObj || $dObj->format('Y-m-d') !== $date) {
                throw new Exception('Invalid date format.');
            }

            if (!in_array($status, ['pending','unpaid','partial','paid'], true)) {
                $status = 'unpaid';
            }

            $total   = $weight * $price;
            $balance = max(0, $total - $paid);

            if ($balance <= 0)      $status = 'paid';
            elseif ($paid > 0)      $status = 'partial';
            elseif ($status === 'pending') $status = 'pending';
            else                    $status = 'unpaid';

            if ($action === 'create') {
                if ($ref === '') {
                    $ref = 'PUR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                }
                $stmt = $pdo->prepare("
                    INSERT INTO purchases
                        (reference_no, buyer_id, seller_name, weight_kg, price_per_kg,
                         total_amount, balance, status, created_at " . ($hasNotes ? ", notes" : "") . ")
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($hasNotes ? "?" : "") . ")
                ");
                $args = [$ref, $buyerId, $seller, $weight, $price, $total, $balance, $status, $date];
                if ($hasNotes) $args[] = $notes;
                $stmt->execute($args);
                $flash = ['type' => 'success', 'msg' => "Purchase {$ref} recorded successfully."];
            } else {
                if ($id <= 0) throw new Exception('Invalid purchase ID.');
                $sql = "UPDATE purchases
                        SET buyer_id=?, seller_name=?, weight_kg=?, price_per_kg=?,
                            total_amount=?, balance=?, status=?, created_at=?"
                        . ($hasNotes ? ", notes=?" : "")
                        . " WHERE id=?";
                $args = [$buyerId, $seller, $weight, $price, $total, $balance, $status, $date];
                if ($hasNotes) $args[] = $notes;
                $args[] = $id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($args);
                $flash = ['type' => 'success', 'msg' => 'Purchase updated successfully.'];
            }
        }

        /* ---------- DELETE ---------- */
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid purchase ID.');

            $stmt = $pdo->prepare("DELETE FROM purchases WHERE id = ?");
            $stmt->execute([$id]);

            $flash = ['type' => 'success', 'msg' => 'Purchase deleted.'];
        }

        /* ---------- QUICK STATUS UPDATE ---------- */
        if ($action === 'quick_status') {
            $id     = (int) ($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if ($id <= 0) throw new Exception('Invalid purchase ID.');
            if (!in_array($status, ['pending','unpaid','partial','paid'], true)) {
                throw new Exception('Invalid status.');
            }
            if ($status === 'paid') {
                $stmt = $pdo->prepare("UPDATE purchases SET status='paid', balance=0 WHERE id=?");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("UPDATE purchases SET status=? WHERE id=?");
                $stmt->execute([$status, $id]);
            }
            $flash = ['type' => 'success', 'msg' => 'Status updated.'];
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    }
}

/* ============================================================
   FILTERS
   ============================================================ */
$q        = trim($_GET['q'] ?? '');
$status   = $_GET['status'] ?? '';
$buyerF   = (int) ($_GET['buyer'] ?? 0);
$dateFrom = trim($_GET['from'] ?? '');
$dateTo   = trim($_GET['to'] ?? '');
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 10;

$where  = [];
$params = [];

if ($q !== '') {
    $where[]  = "(p.reference_no LIKE ? OR p.seller_name LIKE ? " .
                ($hasBuyerFK ? "OR u.full_name LIKE ? OR u.email LIKE ?" : "") . ")";
    $like = "%{$q}%";
    $params[] = $like;
    $params[] = $like;
    if ($hasBuyerFK) { $params[] = $like; $params[] = $like; }
}
if (in_array($status, ['pending','unpaid','partial','paid'], true)) {
    $where[]  = "p.status = ?";
    $params[] = $status;
}
if ($buyerF > 0 && $hasBuyerFK) {
    $where[]  = "p.buyer_id = ?";
    $params[] = $buyerF;
}
if ($dateFrom !== '') {
    $where[]  = "DATE(p.created_at) >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[]  = "DATE(p.created_at) <= ?";
    $params[] = $dateTo;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalRows = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM purchases p
        " . ($hasBuyerFK ? "LEFT JOIN users u ON u.id = p.buyer_id" : "") . "
        {$whereSql}
    ");
    $stmt->execute($params);
    $totalRows = (int) $stmt->fetchColumn();
} catch (Throwable $e) { $totalRows = 0; }

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$buyerNameSelect = $hasBuyerFK ? "COALESCE(u.full_name, '—') AS buyer_name, COALESCE(u.email,'') AS buyer_email"
                               : "COALESCE(p.buyer_name, '—') AS buyer_name, '' AS buyer_email";

$purchases = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            p.id, p.reference_no,
            " . ($hasBuyerFK ? "p.buyer_id," : "0 AS buyer_id,") . "
            {$buyerNameSelect},
            p.seller_name, p.weight_kg, p.price_per_kg,
            p.total_amount, p.balance, p.status, p.created_at
            " . ($hasNotes ? ", p.notes" : "") . "
        FROM purchases p
        " . ($hasBuyerFK ? "LEFT JOIN users u ON u.id = p.buyer_id" : "") . "
        {$whereSql}
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $purchases = []; }

/* ---------- Stats (unfiltered) ---------- */
$stats = [
    'total'       => 0,
    'palay_kg'    => 0.0,
    'gross'       => 0.0,
    'paid'        => 0.0,
    'outstanding' => 0.0,
    'this_month'  => 0,
    'this_month_kg' => 0.0,
];
try {
    $row = $pdo->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(weight_kg),0)     AS palay_kg,
            COALESCE(SUM(total_amount),0)  AS gross,
            COALESCE(SUM(total_amount - balance),0) AS paid,
            COALESCE(SUM(balance),0)       AS outstanding
        FROM purchases
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['total']       = (int) ($row['total'] ?? 0);
    $stats['palay_kg']    = (float) ($row['palay_kg'] ?? 0);
    $stats['gross']       = (float) ($row['gross'] ?? 0);
    $stats['paid']        = (float) ($row['paid'] ?? 0);
    $stats['outstanding'] = (float) ($row['outstanding'] ?? 0);
} catch (Throwable $e) {}

try {
    $row = $pdo->query("
        SELECT COUNT(*) c, COALESCE(SUM(weight_kg),0) kg
        FROM purchases
        WHERE YEAR(created_at)=YEAR(CURDATE())
          AND MONTH(created_at)=MONTH(CURDATE())
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['this_month']    = (int) ($row['c'] ?? 0);
    $stats['this_month_kg'] = (float) ($row['kg'] ?? 0);
} catch (Throwable $e) {}

$paidPct = $stats['gross'] > 0 ? ($stats['paid'] / $stats['gross']) * 100 : 0;

/* ---------- Buyers list ---------- */
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

/* ---------- Determine default buyer for the New Purchase form ---------- */
$defaultBuyerId = 0;

/* 1. If the logged-in user is themselves a buyer, default to their own ID */
if ($currentUserRole === 'buyer' && $currentUserId > 0) {
    foreach ($buyersList as $b) {
        if ((int) $b['id'] === $currentUserId) {
            $defaultBuyerId = $currentUserId;
            break;
        }
    }
}

/* 2. Otherwise, if the admin is filtering by a buyer, use that */
if ($defaultBuyerId === 0 && $buyerF > 0 && $hasBuyerFK) {
    foreach ($buyersList as $b) {
        if ((int) $b['id'] === $buyerF) {
            $defaultBuyerId = $buyerF;
            break;
        }
    }
}

/* ---------- Helpers ---------- */
function peso($n) { return '₱' . number_format((float) $n, 2); }
function kg($n)   { return number_format((float) $n, 2) . ' kg'; }
function niceDate($s) {
    if (!$s) return '—';
    $t = strtotime($s);
    return $t ? date('M j, Y', $t) : '—';
}
function initials($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) $out .= strtoupper(mb_substr($p, 0, 1));
    return $out ?: '?';
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
    <title>Purchases | SmartPalay Admin</title>

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
           SmartPalay — Admin Purchases
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
        .sp-stat-bar {
            margin-top:6px; height:4px; border-radius:4px;
            background: var(--paper-2); overflow: hidden;
        }
        .sp-stat-bar span {
            display:block; height:100%; border-radius:4px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
            transform-origin:left;
            animation: spBarFill 1.3s cubic-bezier(.2,.7,.3,1) both;
        }
        .sp-stat-bar.is-success span { background: linear-gradient(90deg, var(--success), #6FBF73); }
        .sp-stat-bar.is-danger  span { background: linear-gradient(90deg, var(--danger), #E07A55); }
        @keyframes spBarFill { from { transform: scaleX(0); } to { transform: scaleX(1); } }

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
            font-weight:700; color: var(--brown); font-size:.9rem;
        }
        .sp-amount.is-balance { color: var(--danger); }
        .sp-amount.is-zero    { color: var(--success); }

        .sp-badge {
            display:inline-flex; align-items:center; gap:5px;
            padding:4px 10px; border-radius:999px;
            font-size:.66rem; font-weight:700; letter-spacing:.3px;
            text-transform:uppercase;
        }
        .sp-badge i { font-size:.76rem; }
        .sp-badge-paid    { background: var(--success-bg); color: var(--success); border:1px solid var(--success-bd); }
        .sp-badge-partial { background: var(--warn-bg);    color: var(--warn);    border:1px solid var(--warn-bd); }
        .sp-badge-unpaid  { background: var(--danger-bg);  color: var(--danger);  border:1px solid var(--danger-bd); }
        .sp-badge-pending { background: var(--info-bg);    color: var(--info);    border:1px solid #C6DCF0; }

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
            width:100%; max-width:720px;
            max-height: calc(100vh - 40px);
            display:flex; flex-direction:column;
            overflow:hidden;
            box-shadow: 0 46px 100px -34px rgba(40,22,6,.8);
            transform: scale(.95) translateY(10px);
            transition: transform .3s cubic-bezier(.2,.7,.3,1);
        }
        .sp-modal.is-danger { max-width: 480px; }
        .sp-modal.is-view   { max-width: 620px; }
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

        /* ---------- Form input wrapper (with select fix) ---------- */
        .sp-form-input {
            position: relative;
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
        .sp-form-input > i {
            padding: 0 4px 0 14px;
            color: var(--text-3); font-size:.95rem;
            transition: color .18s;
            flex-shrink: 0;
            pointer-events: none;
        }
        .sp-form-input:focus-within > i { color: var(--gold); }
        .sp-form-input input,
        .sp-form-input select,
        .sp-form-input textarea {
            flex: 1 1 auto;
            border:0; outline:0;
            background:transparent;
            padding: 11px 14px 11px 10px;
            font-size:.89rem; font-weight:500;
            color: var(--text); font-family:inherit;
            min-width:0; width:100%;
            line-height: 1.4;
        }
        .sp-form-input select {
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            padding-right: 36px; /* room for custom chevron */
        }
        .sp-form-input select::-ms-expand { display: none; }
        .sp-form-input select option {
            color: var(--text);
            background: #fff;
            padding: 8px;
        }
        /* Custom gold chevron for selects */
        .sp-form-input.is-select::after {
            content: '';
            position: absolute;
            right: 14px; top: 50%;
            width: 9px; height: 9px;
            border-right: 2px solid var(--gold);
            border-bottom: 2px solid var(--gold);
            transform: translateY(-70%) rotate(45deg);
            pointer-events: none;
            transition: border-color .18s, transform .2s;
        }
        .sp-form-input.is-select:focus-within::after {
            transform: translateY(-30%) rotate(-135deg);
        }
        .sp-form-input textarea { resize: vertical; min-height:76px; padding-top:12px; }
        .sp-form-input input::placeholder,
        .sp-form-input textarea::placeholder { color:#A9A392; font-weight:400; }
        .sp-form-hint {
            font-size:.7rem; color: var(--text-3);
            font-weight:500; padding-left:2px;
        }

        /* Make native date picker icon visible & gold-tinted */
        .sp-form-input input[type="date"],
        .sp-filter input[type="date"] {
            color-scheme: light;
        }
        .sp-form-input input[type="date"]::-webkit-calendar-picker-indicator,
        .sp-filter input[type="date"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
            opacity: .75;
            transition: opacity .15s;
        }
        .sp-form-input input[type="date"]::-webkit-calendar-picker-indicator:hover,
        .sp-filter input[type="date"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
        }

        .sp-form-summary {
            padding: 14px 16px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            border: 1px dashed rgba(176,100,30,.35);
            display: flex; align-items: center; justify-content: space-between;
            font-size:.86rem;
        }
        .sp-form-summary-label {
            display:inline-flex; align-items:center; gap:8px;
            color: var(--text-2); font-weight:600;
        }
        .sp-form-summary-label i { color: var(--gold); font-size:1rem; }
        .sp-form-summary-value {
            font-family:'Fraunces', Georgia, serif;
            font-size:1.2rem; font-weight:700;
            color: var(--gold-2);
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
        <a href="<?= BASE_URL ?>admin/purchases.php" class="sp-nav-link is-active">
            <i class="bi bi-receipt"></i> Purchases
        </a>
        <a href="<?= BASE_URL ?>admin/payments.php" class="sp-nav-link">
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
                <h1 class="sp-page-title">Purchases</h1>
                <span class="sp-page-sub">
                    All palay transactions —
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
                <span>New Purchase</span>
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

        <section class="sp-stats">
            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-warn"><i class="bi bi-receipt"></i></div>
                    <span class="sp-stat-trend is-up"><i class="bi bi-arrow-up-right"></i> +<?= $stats['this_month'] ?></span>
                </div>
                <div>
                    <div class="sp-stat-label">Total Purchases</div>
                    <div class="sp-stat-value"><?= number_format($stats['total']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-clock-history"></i> All time</div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-info"><i class="bi bi-box-seam"></i></div>
                    <span class="sp-stat-trend is-up">
                        <i class="bi bi-arrow-up-right"></i> +<?= number_format($stats['this_month_kg'], 0) ?> kg
                    </span>
                </div>
                <div>
                    <div class="sp-stat-label">Palay Received</div>
                    <div class="sp-stat-value"><?= number_format($stats['palay_kg'], 0) ?><small>kg</small></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-graph-up"></i> Combined weight</div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-success"><i class="bi bi-cash-stack"></i></div>
                    <span class="sp-stat-trend is-up">
                        <i class="bi bi-check2-circle"></i> <?= number_format($paidPct, 0) ?>%
                    </span>
                </div>
                <div>
                    <div class="sp-stat-label">Total Paid</div>
                    <div class="sp-stat-value"><?= peso($stats['paid']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-check-circle"></i> Settled amount</div>
                <div class="sp-stat-bar is-success"><span style="width: <?= min(100, $paidPct) ?>%"></span></div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-danger"><i class="bi bi-hourglass-split"></i></div>
                    <span class="sp-stat-trend is-flat"><i class="bi bi-dot"></i> Pending</span>
                </div>
                <div>
                    <div class="sp-stat-label">Outstanding Balance</div>
                    <div class="sp-stat-value"><?= peso($stats['outstanding']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-exclamation-circle"></i> Needs collection</div>
                <div class="sp-stat-bar is-danger"><span style="width: <?= min(100, $stats['gross'] > 0 ? 100 - $paidPct : 0) ?>%"></span></div>
            </article>
        </section>

        <section class="sp-panel">

            <div class="sp-panel-head">
                <h3 class="sp-panel-title">
                    <i class="bi bi-receipt"></i>
                    Transaction Log
                </h3>
            </div>

            <div style="padding: 16px 22px; border-bottom: 1px solid var(--line); background: var(--cream-2);">
                <form method="get" class="sp-toolbar" id="filterForm">
                    <label class="sp-search">
                        <i class="bi bi-search"></i>
                        <input type="search" name="q"
                               placeholder="Search reference, seller, or buyer…"
                               value="<?= htmlspecialchars($q) ?>" autocomplete="off">
                    </label>

                    <label class="sp-filter">
                        <i class="bi bi-funnel"></i>
                        <select name="status" onchange="document.getElementById('filterForm').submit()">
                            <option value="">All statuses</option>
                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="unpaid"  <?= $status === 'unpaid'  ? 'selected' : '' ?>>Unpaid</option>
                            <option value="partial" <?= $status === 'partial' ? 'selected' : '' ?>>Partial</option>
                            <option value="paid"    <?= $status === 'paid'    ? 'selected' : '' ?>>Paid</option>
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

                    <?php if ($q !== '' || $status !== '' || $buyerF > 0 || $dateFrom !== '' || $dateTo !== ''): ?>
                        <a href="purchases.php" class="sp-btn-clear">
                            <i class="bi bi-x-lg"></i> Clear filters
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (!empty($purchases)): ?>
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Buyer</th>
                                <th>Seller</th>
                                <th>Weight</th>
                                <th>Total</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchases as $p): ?>
                                <?php
                                    $st = strtolower($p['status'] ?? 'unpaid');
                                    $badge = 'sp-badge-unpaid';
                                    $ico   = 'bi-x-circle-fill';
                                    $label = ucfirst($st);
                                    if ($st === 'paid')    { $badge = 'sp-badge-paid';    $ico = 'bi-check-circle-fill'; $label = 'Paid'; }
                                    if ($st === 'partial') { $badge = 'sp-badge-partial'; $ico = 'bi-circle-half';        $label = 'Partial'; }
                                    if ($st === 'pending') { $badge = 'sp-badge-pending'; $ico = 'bi-hourglass-split';    $label = 'Pending'; }

                                    $balanceCls = (float)$p['balance'] <= 0 ? 'is-zero' : 'is-balance';
                                ?>
                                <tr>
                                    <td><span class="sp-ref"><?= sanitize($p['reference_no'] ?? '—') ?></span></td>

                                    <td>
                                        <div class="sp-user-cell">
                                            <div class="sp-avatar"><?= htmlspecialchars(initials($p['buyer_name'] ?? '?')) ?></div>
                                            <div class="sp-user-meta">
                                                <p class="sp-user-name"><?= sanitize($p['buyer_name'] ?? '—') ?></p>
                                                <?php if (!empty($p['buyer_email'])): ?>
                                                    <p class="sp-user-email"><?= sanitize($p['buyer_email']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <td><?= sanitize($p['seller_name'] ?? '—') ?></td>
                                    <td><?= kg($p['weight_kg'] ?? 0) ?></td>
                                    <td><span class="sp-amount"><?= peso($p['total_amount'] ?? 0) ?></span></td>
                                    <td>
                                        <span class="sp-amount <?= $balanceCls ?>">
                                            <?= peso($p['balance'] ?? 0) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="sp-badge <?= $badge ?>">
                                            <i class="bi <?= $ico ?>"></i> <?= $label ?>
                                        </span>
                                    </td>
                                    <td><?= niceDate($p['created_at'] ?? null) ?></td>

                                    <td>
                                        <div class="sp-actions">
                                            <button type="button" class="sp-action-btn js-view"
                                                title="View details"
                                                data-id="<?= (int) $p['id'] ?>"
                                                data-ref="<?= sanitize($p['reference_no'] ?? '') ?>"
                                                data-buyer="<?= sanitize($p['buyer_name'] ?? '') ?>"
                                                data-buyer-email="<?= sanitize($p['buyer_email'] ?? '') ?>"
                                                data-seller="<?= sanitize($p['seller_name'] ?? '') ?>"
                                                data-weight="<?= (float) ($p['weight_kg'] ?? 0) ?>"
                                                data-price="<?= (float) ($p['price_per_kg'] ?? 0) ?>"
                                                data-total="<?= (float) ($p['total_amount'] ?? 0) ?>"
                                                data-balance="<?= (float) ($p['balance'] ?? 0) ?>"
                                                data-status="<?= sanitize($p['status'] ?? '') ?>"
                                                data-date="<?= sanitize($p['created_at'] ?? '') ?>"
                                                data-notes="<?= sanitize($p['notes'] ?? '') ?>">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button type="button" class="sp-action-btn js-edit"
                                                title="Edit purchase"
                                                data-id="<?= (int) $p['id'] ?>"
                                                data-buyer-id="<?= (int) ($p['buyer_id'] ?? 0) ?>"
                                                data-seller="<?= sanitize($p['seller_name'] ?? '') ?>"
                                                data-weight="<?= (float) ($p['weight_kg'] ?? 0) ?>"
                                                data-price="<?= (float) ($p['price_per_kg'] ?? 0) ?>"
                                                data-paid="<?= (float) (($p['total_amount'] ?? 0) - ($p['balance'] ?? 0)) ?>"
                                                data-status="<?= sanitize($p['status'] ?? '') ?>"
                                                data-date="<?= sanitize($p['created_at'] ?? '') ?>"
                                                data-notes="<?= sanitize($p['notes'] ?? '') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="sp-action-btn is-danger js-delete"
                                                title="Delete purchase"
                                                data-id="<?= (int) $p['id'] ?>"
                                                data-ref="<?= sanitize($p['reference_no'] ?? '') ?>"
                                                data-total="<?= peso($p['total_amount'] ?? 0) ?>">
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
                        Showing <strong><?= count($purchases) ?></strong> of
                        <strong><?= number_format($totalRows) ?></strong> purchases
                        · Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
                    </div>

                    <div class="sp-pages">
                        <?php
                            $qs = $_GET; unset($qs['page']);
                            $buildUrl = function ($p) use ($qs) {
                                $qs['page'] = $p;
                                return 'purchases.php?' . http_build_query($qs);
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
                    <div class="sp-empty-ico"><i class="bi bi-receipt"></i></div>
                    <h4><?= ($q !== '' || $status !== '' || $buyerF || $dateFrom || $dateTo) ? 'No purchases match your filters' : 'No purchases yet' ?></h4>
                    <p>
                        <?= ($q !== '' || $status !== '' || $buyerF || $dateFrom || $dateTo)
                            ? 'Try adjusting your filters or clearing them.'
                            : 'Record your first palay transaction to start tracking.' ?>
                    </p>
                    <?php if ($q !== '' || $status !== '' || $buyerF || $dateFrom || $dateTo): ?>
                        <a href="purchases.php" class="sp-btn-cancel">
                            <i class="bi bi-x-lg"></i> Clear filters
                        </a>
                    <?php else: ?>
                        <button type="button" class="sp-btn-submit" id="emptyAddBtn">
                            <i class="bi bi-plus-lg"></i> New Purchase
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </section>

    </main>
</div>

<!-- ADD / EDIT MODAL -->
<div class="sp-modal-backdrop" id="modalPurchase">
    <div class="sp-modal" role="dialog" aria-modal="true">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
            <span class="sp-modal-tag" id="purModalTag">
                <i class="bi bi-plus-circle"></i>
                <span id="purModalTagText">New Purchase</span>
            </span>
            <h3 class="sp-modal-title" id="purModalTitle">Record a Palay Purchase</h3>
            <p class="sp-modal-sub" id="purModalSub">Fill in the details — total and balance are calculated automatically.</p>
        </div>

        <form id="purchaseForm" method="post" autocomplete="off" style="display:contents;">
            <input type="hidden" name="action" id="purAction" value="create">
            <input type="hidden" name="id" id="purId" value="">

            <div class="sp-modal-body">

                <div class="sp-section-title">
                    <i class="bi bi-people"></i> Parties
                </div>

                <div class="sp-form-row">
                    <div class="sp-form-field">
                        <label for="pf_buyer">Buyer <span class="req">*</span></label>
                        <div class="sp-form-input is-select">
                            <i class="bi bi-bag-check"></i>
                            <select id="pf_buyer" name="buyer_id" required>
                                <option value="">— Select a buyer —</option>
                                <?php foreach ($buyersList as $b): ?>
                                    <option value="<?= (int) $b['id'] ?>"
                                        <?= $defaultBuyerId === (int) $b['id'] ? 'selected' : '' ?>>
                                        <?= sanitize($b['full_name']) ?>
                                        <?= !empty($b['email']) ? ' (' . sanitize($b['email']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if (empty($buyersList)): ?>
                            <div class="sp-form-hint">No buyers found. Add one first from <a href="users.php" style="color:var(--gold);font-weight:700;">Users</a>.</div>
                        <?php else: ?>
                            <div class="sp-form-hint">Pre-filled with your account when you're signed in as a buyer.</div>
                        <?php endif; ?>
                    </div>

                    <div class="sp-form-field">
                        <label for="pf_seller">Seller Name <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-person-badge"></i>
                            <input type="text" id="pf_seller" name="seller_name" required
                                   placeholder="e.g. Mang Jose Santos">
                        </div>
                    </div>
                </div>

                <div class="sp-section-title">
                    <i class="bi bi-calculator"></i> Transaction
                </div>

                <div class="sp-form-row">
                    <div class="sp-form-field">
                        <label for="pf_weight">Weight (kg) <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-box-seam"></i>
                            <input type="number" id="pf_weight" name="weight_kg"
                                   min="0.01" step="0.01" placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="sp-form-field">
                        <label for="pf_price">Price per kg (₱) <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-tag"></i>
                            <input type="number" id="pf_price" name="price_per_kg"
                                   min="0.01" step="0.01" placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="sp-form-field">
                        <label for="pf_paid">Amount Paid (₱)</label>
                        <div class="sp-form-input">
                            <i class="bi bi-cash-stack"></i>
                            <input type="number" id="pf_paid" name="amount_paid"
                                   min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="sp-form-hint">Leave blank if unpaid</div>
                    </div>

                    <div class="sp-form-field">
                        <label for="pf_date">Date <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-calendar3"></i>
                            <!-- Empty by default; allows any year including 2026 -->
                            <input type="date" id="pf_date" name="created_at" required
                                   min="2000-01-01" max="2099-12-31">
                        </div>
                        <div class="sp-form-hint">Pick the date this purchase was made.</div>
                    </div>

                    <div class="sp-form-field is-full">
                        <label for="pf_notes">Notes (optional)</label>
                        <div class="sp-form-input">
                            <i class="bi bi-chat-left-text"></i>
                            <textarea id="pf_notes" name="notes"
                                      placeholder="e.g. Delivered to warehouse 2, dry palay…"></textarea>
                        </div>
                    </div>
                </div>

                <div class="sp-form-summary">
                    <span class="sp-form-summary-label"><i class="bi bi-calculator"></i> Total Amount</span>
                    <span class="sp-form-summary-value" id="pf_total">₱0.00</span>
                </div>

                <div class="sp-form-summary" style="background: linear-gradient(135deg, #EAF7EC, #fff); border-color: var(--success-bd);">
                    <span class="sp-form-summary-label"><i class="bi bi-hourglass-split"></i> Balance (remaining)</span>
                    <span class="sp-form-summary-value" id="pf_balance" style="color: var(--danger);">₱0.00</span>
                </div>

            </div>

            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-cancel" data-close-modal>
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="submit" class="sp-btn-submit" id="purSubmit">
                    <i class="bi bi-check-lg"></i> Save Purchase
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
                <i class="bi bi-eye"></i> Purchase Details
            </span>
            <h3 class="sp-modal-title" id="viewRef">—</h3>
            <p class="sp-modal-sub">Full transaction breakdown.</p>
        </div>

        <div class="sp-modal-body">
            <div class="sp-detail-grid">
                <div class="sp-detail-item is-full">
                    <div class="sp-detail-label"><i class="bi bi-hash"></i> Reference</div>
                    <div class="sp-detail-value is-mono" id="viewRefVal">—</div>
                </div>

                <div class="sp-detail-item">
                    <div class="sp-detail-label"><i class="bi bi-bag-check"></i> Buyer</div>
                    <div class="sp-detail-value is-normal" id="viewBuyer">—</div>
                </div>

                <div class="sp-detail-item">
                    <div class="sp-detail-label"><i class="bi bi-person-badge"></i> Seller</div>
                    <div class="sp-detail-value is-normal" id="viewSeller">—</div>
                </div>

                <div class="sp-detail-item">
                    <div class="sp-detail-label"><i class="bi bi-box-seam"></i> Weight</div>
                    <div class="sp-detail-value" id="viewWeight">—</div>
                </div>

                <div class="sp-detail-item">
                    <div class="sp-detail-label"><i class="bi bi-tag"></i> Price / kg</div>
                    <div class="sp-detail-value" id="viewPrice">—</div>
                </div>

                <div class="sp-detail-item">
                    <div class="sp-detail-label"><i class="bi bi-cash-stack"></i> Total Amount</div>
                    <div class="sp-detail-value" id="viewTotal">—</div>
                </div>

                <div class="sp-detail-item">
                    <div class="sp-detail-label"><i class="bi bi-hourglass-split"></i> Balance</div>
                    <div class="sp-detail-value" id="viewBalance">—</div>
                </div>

                <div class="sp-detail-item">
                    <div class="sp-detail-label"><i class="bi bi-flag"></i> Status</div>
                    <div id="viewStatus">—</div>
                </div>

                <div class="sp-detail-item">
                    <div class="sp-detail-label"><i class="bi bi-calendar3"></i> Date</div>
                    <div class="sp-detail-value is-normal" id="viewDate">—</div>
                </div>

                <?php if ($hasNotes): ?>
                <div class="sp-detail-item is-full" id="viewNotesWrap">
                    <div class="sp-detail-label"><i class="bi bi-chat-left-text"></i> Notes</div>
                    <div class="sp-detail-value is-normal" id="viewNotes">—</div>
                </div>
                <?php endif; ?>
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
            <h3 class="sp-modal-title">Delete this purchase?</h3>
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
                        <small id="deleteTotal">—</small>
                    </div>
                </div>
            </div>

            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-cancel" data-close-modal>
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="submit" class="sp-btn-danger">
                    <i class="bi bi-trash3"></i> Delete Purchase
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
    /* Expose the PHP-computed default buyer ID to JS */
    window.__defaultBuyerId = <?= (int) $defaultBuyerId ?>;
</script>

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

    /* ---------- Modals ---------- */
    const modalPurchase = document.getElementById('modalPurchase');
    const modalView     = document.getElementById('modalView');
    const modalDelete   = document.getElementById('modalDelete');

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
    const pForm      = document.getElementById('purchaseForm');
    const purAction  = document.getElementById('purAction');
    const purId      = document.getElementById('purId');
    const pf_buyer   = document.getElementById('pf_buyer');
    const pf_seller  = document.getElementById('pf_seller');
    const pf_weight  = document.getElementById('pf_weight');
    const pf_price   = document.getElementById('pf_price');
    const pf_paid    = document.getElementById('pf_paid');
    const pf_date    = document.getElementById('pf_date');
    const pf_notes   = document.getElementById('pf_notes');
    const pf_total   = document.getElementById('pf_total');
    const pf_balance = document.getElementById('pf_balance');

    const purTagText  = document.getElementById('purModalTagText');
    const purTitle    = document.getElementById('purModalTitle');
    const purSub      = document.getElementById('purModalSub');

    function pesoFmt(n) {
        n = Number(n) || 0;
        return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function recalc() {
        const w = parseFloat(pf_weight?.value) || 0;
        const p = parseFloat(pf_price?.value) || 0;
        const paid = parseFloat(pf_paid?.value) || 0;
        const total = w * p;
        const balance = Math.max(0, total - paid);
        pf_total.textContent   = pesoFmt(total);
        pf_balance.textContent = pesoFmt(balance);
        pf_balance.style.color = balance <= 0 ? 'var(--success)' : 'var(--danger)';
    }
    pf_weight?.addEventListener('input', recalc);
    pf_price?.addEventListener('input', recalc);
    pf_paid?.addEventListener('input', recalc);

    function applyDefaultBuyer() {
        const def = Number(window.__defaultBuyerId) || 0;
        if (def > 0 && pf_buyer) {
            pf_buyer.value = String(def);
        }
    }

    function resetPurchaseForm() {
        pForm.reset();
        purAction.value = 'create';
        purId.value = '';
        /* Date intentionally left empty — user must pick one (can choose 2026) */
        pf_date.value = '';
        /* Re-apply the default buyer (own account if buyer, or filtered buyer) */
        applyDefaultBuyer();
        recalc();
        purTagText.textContent = 'New Purchase';
        purTitle.textContent   = 'Record a Palay Purchase';
        purSub.textContent     = 'Fill in the details — total and balance are calculated automatically.';
    }

    document.getElementById('topAddBtn')?.addEventListener('click', () => {
        resetPurchaseForm();
        openModal(modalPurchase);
    });
    document.getElementById('emptyAddBtn')?.addEventListener('click', () => {
        resetPurchaseForm();
        openModal(modalPurchase);
    });

    /* Edit */
    document.querySelectorAll('.js-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            const d = btn.dataset;
            purAction.value = 'update';
            purId.value     = d.id || '';
            if (d.buyerId && pf_buyer) pf_buyer.value = d.buyerId;
            pf_seller.value = d.seller || '';
            pf_weight.value = d.weight || '';
            pf_price.value  = d.price  || '';
            pf_paid.value   = d.paid   || '';
            pf_date.value   = (d.date || '').slice(0, 10);
            if (pf_notes) pf_notes.value = d.notes || '';

            purTagText.textContent = 'Edit Purchase';
            purTitle.textContent   = 'Edit Purchase';
            purSub.textContent     = 'Update transaction details.';

            recalc();
            openModal(modalPurchase);
        });
    });

    /* ---------- View ---------- */
    const viewRef      = document.getElementById('viewRef');
    const viewRefVal   = document.getElementById('viewRefVal');
    const viewBuyer    = document.getElementById('viewBuyer');
    const viewSeller   = document.getElementById('viewSeller');
    const viewWeight   = document.getElementById('viewWeight');
    const viewPrice    = document.getElementById('viewPrice');
    const viewTotal    = document.getElementById('viewTotal');
    const viewBalance  = document.getElementById('viewBalance');
    const viewStatus   = document.getElementById('viewStatus');
    const viewDate     = document.getElementById('viewDate');
    const viewNotes    = document.getElementById('viewNotes');
    const viewEditBtn  = document.getElementById('viewEditBtn');

    let currentViewId = null;

    function kgFmt(n) {
        return (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' kg';
    }
    function niceDate(s) {
        if (!s) return '—';
        const d = new Date(s.replace(' ', 'T'));
        if (isNaN(d)) return s;
        return d.toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' });
    }

    document.querySelectorAll('.js-view').forEach(btn => {
        btn.addEventListener('click', () => {
            const d = btn.dataset;
            currentViewId = d.id;

            viewRef.textContent     = d.ref || 'Purchase';
            viewRefVal.textContent  = d.ref || '—';
            viewBuyer.textContent   = d.buyer || '—';
            viewSeller.textContent  = d.seller || '—';
            viewWeight.textContent  = kgFmt(d.weight);
            viewPrice.textContent   = pesoFmt(d.price);
            viewTotal.textContent   = pesoFmt(d.total);
            viewBalance.textContent = pesoFmt(d.balance);
            viewBalance.style.color = (Number(d.balance) <= 0) ? 'var(--success)' : 'var(--danger)';
            viewDate.textContent    = niceDate(d.date);

            const st = (d.status || '').toLowerCase();
            let badge = 'sp-badge-unpaid', ico = 'bi-x-circle-fill', label = 'Unpaid';
            if (st === 'paid')    { badge = 'sp-badge-paid';    ico = 'bi-check-circle-fill'; label = 'Paid'; }
            if (st === 'partial') { badge = 'sp-badge-partial'; ico = 'bi-circle-half';       label = 'Partial'; }
            if (st === 'pending') { badge = 'sp-badge-pending'; ico = 'bi-hourglass-split';   label = 'Pending'; }
            viewStatus.innerHTML = '<span class="sp-badge ' + badge + '"><i class="bi ' + ico + '"></i> ' + label + '</span>';

            if (viewNotes) viewNotes.textContent = d.notes || '—';

            openModal(modalView);
        });
    });

    viewEditBtn?.addEventListener('click', () => {
        closeModal(modalView);
        const editBtn = document.querySelector('.js-edit[data-id="' + currentViewId + '"]');
        setTimeout(() => editBtn?.click(), 220);
    });

    /* ---------- Delete ---------- */
    const deleteId    = document.getElementById('deleteId');
    const deleteRef   = document.getElementById('deleteRef');
    const deleteTotal = document.getElementById('deleteTotal');

    document.querySelectorAll('.js-delete').forEach(btn => {
        btn.addEventListener('click', () => {
            deleteId.value = btn.dataset.id || '';
            deleteRef.textContent = btn.dataset.ref || '—';
            deleteTotal.textContent = btn.dataset.total || '';
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