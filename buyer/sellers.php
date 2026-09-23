<?php
require_once __DIR__ . '/../config/database.php';

if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'buyer') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$userId   = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Daisy Joy Dula';
$email    = $_SESSION['email'] ?? '';

/* ---------- Feature detection ---------- */
$hasSellersTable = false;
try {
    $pdo->query("SELECT 1 FROM sellers LIMIT 1");
    $hasSellersTable = true;
} catch (Throwable $e) { $hasSellersTable = false; }

/* ============================================================
   POST ACTIONS
   ============================================================ */
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        /* ---------- CREATE ---------- */
        if ($action === 'create') {
            $name    = trim($_POST['name']    ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $notes   = trim($_POST['notes']   ?? '');

            if ($name === '') throw new Exception('Seller name is required.');

            if (!$hasSellersTable) {
                throw new Exception('Please create the sellers table first.');
            }

            $chk = $pdo->prepare("SELECT id FROM sellers WHERE buyer_id = ? AND name = ? LIMIT 1");
            $chk->execute([$userId, $name]);
            if ($chk->fetchColumn()) {
                throw new Exception('You already have a seller with that name.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO sellers (buyer_id, name, contact, address, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $name, $contact, $address, $notes]);

            $flash = ['type' => 'success', 'msg' => "Seller “{$name}” added."];
        }

        /* ---------- UPDATE ---------- */
        if ($action === 'update') {
            $id      = (int) ($_POST['id'] ?? 0);
            $name    = trim($_POST['name']    ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $notes   = trim($_POST['notes']   ?? '');
            $oldName = trim($_POST['old_name'] ?? '');

            if ($id <= 0) throw new Exception('Invalid seller ID.');
            if ($name === '') throw new Exception('Seller name is required.');

            if (!$hasSellersTable) {
                throw new Exception('Please create the sellers table first.');
            }

            $chk = $pdo->prepare("SELECT id FROM sellers WHERE buyer_id = ? AND name = ? AND id <> ? LIMIT 1");
            $chk->execute([$userId, $name, $id]);
            if ($chk->fetchColumn()) {
                throw new Exception('Another seller already uses that name.');
            }

            $stmt = $pdo->prepare("
                UPDATE sellers
                SET name = ?, contact = ?, address = ?, notes = ?
                WHERE id = ? AND buyer_id = ?
            ");
            $stmt->execute([$name, $contact, $address, $notes, $id, $userId]);

            /* Also update seller_name in purchases if renamed */
            if ($oldName !== '' && $oldName !== $name) {
                $stmt = $pdo->prepare("
                    UPDATE purchases SET seller_name = ?
                    WHERE buyer_id = ? AND seller_name = ?
                ");
                $stmt->execute([$name, $userId, $oldName]);
            }

            $flash = ['type' => 'success', 'msg' => "Seller “{$name}” updated."];
        }

        /* ---------- DELETE ---------- */
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid seller ID.');

            if ($hasSellersTable) {
                $stmt = $pdo->prepare("DELETE FROM sellers WHERE id = ? AND buyer_id = ?");
                $stmt->execute([$id, $userId]);
            }

            $flash = ['type' => 'success', 'msg' => 'Seller removed.'];
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    }
}

/* ============================================================
   FILTERS
   ============================================================ */
$q      = trim($_GET['q'] ?? '');
$sort   = $_GET['sort'] ?? 'name';
$page   = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

$allowedSorts = ['name', 'purchases', 'weight', 'amount'];
if (!in_array($sort, $allowedSorts, true)) $sort = 'name';

/* ============================================================
   SELLERS LIST (from purchases + optional sellers table)
   ============================================================ */
$sellers = [];

try {
    if ($hasSellersTable) {
        $stmt = $pdo->prepare("
            SELECT
                s.id,
                s.name,
                s.contact,
                s.address,
                s.notes,
                s.created_at,
                COALESCE(agg.purchase_count, 0) AS purchase_count,
                COALESCE(agg.total_kg, 0)       AS total_kg,
                COALESCE(agg.total_amount, 0)   AS total_amount,
                COALESCE(agg.balance, 0)        AS balance,
                agg.last_purchase
            FROM sellers s
            LEFT JOIN (
                SELECT
                    seller_name,
                    COUNT(*) AS purchase_count,
                    SUM(weight_kg) AS total_kg,
                    SUM(total_amount) AS total_amount,
                    SUM(balance) AS balance,
                    MAX(created_at) AS last_purchase
                FROM purchases
                WHERE buyer_id = ?
                GROUP BY seller_name
            ) agg ON agg.seller_name = s.name
            WHERE s.buyer_id = ?
        ");
        $stmt->execute([$userId, $userId]);
        $sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT
                NULL AS id,
                seller_name AS name,
                NULL AS contact,
                NULL AS address,
                NULL AS notes,
                MIN(created_at) AS created_at,
                COUNT(*) AS purchase_count,
                COALESCE(SUM(weight_kg), 0) AS total_kg,
                COALESCE(SUM(total_amount), 0) AS total_amount,
                COALESCE(SUM(balance), 0) AS balance,
                MAX(created_at) AS last_purchase
            FROM purchases
            WHERE buyer_id = ? AND seller_name IS NOT NULL AND seller_name <> ''
            GROUP BY seller_name
        ");
        $stmt->execute([$userId]);
        $sellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { $sellers = []; }

/* ---------- Filter by search ---------- */
if ($q !== '') {
    $needle = mb_strtolower($q);
    $sellers = array_values(array_filter($sellers, function ($s) use ($needle) {
        return mb_strpos(mb_strtolower($s['name']),    $needle) !== false
            || mb_strpos(mb_strtolower((string)$s['contact']), $needle) !== false
            || mb_strpos(mb_strtolower((string)$s['address']), $needle) !== false;
    }));
}

/* ---------- Sort ---------- */
usort($sellers, function ($a, $b) use ($sort) {
    switch ($sort) {
        case 'purchases': return $b['purchase_count'] <=> $a['purchase_count'];
        case 'weight':    return $b['total_kg']       <=> $a['total_kg'];
        case 'amount':    return $b['total_amount']   <=> $a['total_amount'];
        default:          return strcasecmp($a['name'], $b['name']);
    }
});

/* ---------- Stats (before pagination) ---------- */
$totalSellers   = count($sellers);
$totalWithPurch = 0;
$totalOwed      = 0.0;
$totalVolume    = 0.0;

foreach ($sellers as $s) {
    if ((int)$s['purchase_count'] > 0) $totalWithPurch++;
    $totalOwed   += (float) $s['balance'];
    $totalVolume += (float) $s['total_kg'];
}

/* ---------- Pagination ---------- */
$totalPages = max(1, (int) ceil($totalSellers / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;
$pageItems  = array_slice($sellers, $offset, $perPage);

/* ---------- Helpers ---------- */
function peso($n) { return '₱' . number_format((float) $n, 2); }
function kg($n)   { return number_format((float) $n, 0) . ' kg'; }
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
    <title>Sellers | SmartPalay</title>

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
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
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
            position: fixed; top:0; left:0; bottom:0;
            width: var(--sidebar-w); z-index: 300;
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
            z-index:0;
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
            content:''; position:absolute;
            bottom:-1px; left:50%;
            transform: translateX(-50%);
            width:60px; height:2px;
            background: linear-gradient(90deg, transparent, var(--gold-3), transparent);
            border-radius:2px; opacity:.7;
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
            content:''; position:absolute;
            width:140px; height:140px; border-radius:50%;
            background: radial-gradient(circle, rgba(232,176,90,.28), transparent 65%);
            pointer-events:none;
        }
        .sp-user-avatar {
            position:relative; width:82px; height:82px;
            border-radius:50%; flex-shrink:0; padding:3px;
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
            display:flex; align-items:center; justify-content:center;
            padding: 0 22px 18px;
            margin-top:-4px;
            border-bottom:1px solid rgba(232,176,90,.12);
            flex-shrink:0;
        }
        .sp-sidebar-role-chip {
            display:inline-flex; align-items:center; gap:6px;
            padding: 5px 12px;
            border-radius:999px;
            background: rgba(232,176,90,.14);
            border:1px solid rgba(232,176,90,.32);
            color: var(--gold-3);
            font-size:.64rem; font-weight:700;
            letter-spacing:1.1px; text-transform:uppercase;
            white-space:nowrap;
        }
        .sp-sidebar-role-chip i { font-size:.78rem; }

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
            position:relative; z-index:2; flex-shrink:0;
            cursor:pointer;
        }
        .sp-nav-link i {
            font-size:1.05rem; width:20px; text-align:center;
            color: rgba(255,255,255,.58);
            transition: color .18s;
            pointer-events:none;
        }
        .sp-nav-link:hover {
            background: rgba(232,176,90,.12); color:#fff; transform: translateX(2px);
            text-decoration:none;
        }
        .sp-nav-link:hover i { color: var(--gold-3); }
        .sp-nav-link::after {
            content:''; position:absolute;
            right:14px; top:50%;
            transform: translateY(-50%) translateX(6px);
            width:5px; height:5px;
            border-radius:50%;
            background: var(--gold-3);
            opacity:0;
            transition: opacity .2s ease, transform .2s ease;
        }
        .sp-nav-link:hover::after { opacity:.9; transform: translateY(-50%) translateX(0); }
        .sp-nav-link.is-active {
            background: linear-gradient(135deg, rgba(232,176,90,.28), rgba(176,100,30,.18));
            color:#fff; font-weight:600;
            box-shadow: inset 0 0 0 1px rgba(232,176,90,.3);
            animation: spNavGlow 4s ease-in-out infinite;
        }
        @keyframes spNavGlow {
            0%,100% { box-shadow: inset 0 0 0 1px rgba(232,176,90,.3); }
            50%     { box-shadow: inset 0 0 0 1px rgba(232,176,90,.5), 0 0 18px -6px rgba(232,176,90,.4); }
        }
        .sp-nav-link.is-active i { color: var(--gold-3); }
        .sp-nav-link.is-active::before {
            content:''; position:absolute; left:0; top:22%; bottom:22%; width:3px;
            border-radius:0 3px 3px 0; background: var(--gold-3);
            pointer-events:none;
        }
        .sp-nav-link.is-active::after { display: none; }
        .sp-sidebar-foot {
            padding:14px 12px 18px;
            border-top:1px solid rgba(232,176,90,.12);
            flex-shrink:0;
            position:relative; z-index:2;
        }
        .sp-nav-link.sp-logout { color: rgba(255,200,180,.92); }
        .sp-nav-link.sp-logout:hover { background: rgba(162,58,26,.25); color:#fff; }
        .sp-nav-link.sp-logout:hover i { color:#FFB199; transform: translateX(2px); }
        .sp-sidebar-tag {
            padding: 10px 22px 0;
            text-align:center;
            font-size: .62rem;
            color: rgba(255,255,255,.32);
            font-weight:500;
            letter-spacing:.4px;
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

        /* Intro */
        .sp-intro {
            position: relative; overflow: hidden;
            padding: clamp(24px,3.2vh,34px) clamp(22px,3vw,36px);
            border-radius: 22px;
            background:
                radial-gradient(circle at 82% 18%, rgba(232,176,90,.32), transparent 55%),
                radial-gradient(circle at 10% 90%, rgba(184,92,46,.18), transparent 50%),
                linear-gradient(135deg, #4A2C10 0%, #2A1E10 100%);
            color:#fff;
            display:flex; align-items:center; justify-content:space-between;
            gap:24px;
            box-shadow: 0 24px 60px -28px rgba(40,22,6,.7);
        }
        .sp-intro::before {
            content:''; position:absolute; inset:0;
            pointer-events:none;
            background-image:
                repeating-linear-gradient(92deg, transparent 0 22px, rgba(232,176,90,.06) 22px 24px),
                repeating-linear-gradient(88deg, transparent 0 34px, rgba(232,176,90,.04) 34px 37px);
        }
        .sp-intro > * { position:relative; z-index:1; }
        .sp-intro-tag {
            display:inline-flex; align-items:center; gap:7px;
            padding:5px 12px; border-radius:999px;
            background: rgba(232,176,90,.16);
            border:1px solid rgba(232,176,90,.35);
            font-size:.66rem; font-weight:700;
            letter-spacing:1.3px; text-transform:uppercase;
            color: var(--gold-3); margin-bottom:14px;
        }
        .sp-intro h2 {
            font-family:'Fraunces', Georgia, serif;
            font-size: clamp(1.5rem,2.4vw,2rem);
            font-weight:600; line-height:1.15;
            letter-spacing:-.5px; margin:0 0 10px;
        }
        .sp-intro h2 em { font-style:italic; color: var(--gold-3); font-weight:500; }
        .sp-intro p {
            font-size:.9rem; color: rgba(255,255,255,.76);
            line-height:1.6; margin:0; max-width:54ch;
        }
        .sp-intro-actions {
            display:flex; flex-wrap:wrap; gap:10px;
            margin-top:18px;
        }
        .sp-intro-btn {
            display:inline-flex; align-items:center; gap:8px;
            padding:10px 18px; border-radius:10px;
            font-size:.84rem; font-weight:700;
            text-decoration:none;
            border:0; cursor:pointer; font-family:inherit;
            transition: transform .15s, filter .15s;
        }
        .sp-intro-btn.is-primary {
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color:#fff;
            box-shadow: 0 3px 0 rgba(0,0,0,.18), 0 14px 26px -12px rgba(176,100,30,.85);
        }
        .sp-intro-btn.is-primary:hover { color:#fff; transform: translateY(-2px); filter: brightness(1.06); }
        .sp-intro-btn.is-ghost {
            background: rgba(255,255,255,.08);
            color: rgba(255,255,255,.92);
            border:1px solid rgba(255,255,255,.2);
            text-decoration:none;
        }
        .sp-intro-btn.is-ghost:hover { background: rgba(255,255,255,.16); color:#fff; transform: translateY(-2px); }

        .sp-intro-mini {
            display:grid;
            grid-template-columns: repeat(3, minmax(0,1fr));
            gap:10px; flex-shrink:0;
        }
        .sp-intro-mini-card {
            background: rgba(255,255,255,.08);
            border:1px solid rgba(232,176,90,.25);
            border-radius:14px;
            padding:12px 14px;
            min-width:120px;
        }
        .sp-intro-mini-label {
            font-size:.62rem; font-weight:700;
            letter-spacing:1.1px; text-transform:uppercase;
            color: rgba(255,255,255,.55);
            margin-bottom:5px;
        }
        .sp-intro-mini-value {
            font-family:'Fraunces', Georgia, serif;
            font-size:1.05rem; font-weight:700;
            letter-spacing:-.3px; color:#fff; line-height:1.1;
        }
        @media (max-width: 1080px) { .sp-intro-mini { display:none; } }

        /* Stats */
        .sp-stats {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(220px,1fr));
            gap: clamp(14px,1.8vw,20px);
        }
        .sp-stat {
            position:relative;
            padding:20px 22px 18px;
            border-radius:16px;
            background:#fff;
            border:1px solid var(--line);
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset, 0 14px 30px -22px rgba(74,44,16,.45);
            transition: transform .28s cubic-bezier(.2,.7,.3,1), box-shadow .28s, border-color .28s;
            display:flex; align-items:center; gap:14px;
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
            transform: translateY(-4px);
            border-color: rgba(176,100,30,.35);
            box-shadow: 0 24px 46px -22px rgba(74,44,16,.5);
        }
        .sp-stat:hover::before { transform: scaleX(1); }
        .sp-stat-ico {
            width:44px; height:44px; border-radius:12px;
            display:grid; place-items:center; font-size:1.1rem;
            background: var(--gold-soft); color: var(--gold);
            border:1px solid rgba(176,100,30,.18);
            flex-shrink:0;
        }
        .sp-stat-ico.is-info    { background: var(--info-bg);    color: var(--info);    border-color: #C6DCF0; }
        .sp-stat-ico.is-success { background: var(--success-bg); color: var(--success); border-color: var(--success-bd); }
        .sp-stat-ico.is-warn    { background: var(--warn-bg);    color: var(--warn);    border-color: var(--warn-bd); }
        .sp-stat-body { min-width:0; }
        .sp-stat-label {
            font-size:.62rem; font-weight:700; letter-spacing:1.1px;
            text-transform:uppercase; color: var(--text-3); margin-bottom:3px;
        }
        .sp-stat-value {
            font-family:'Fraunces', Georgia, serif;
            font-size: clamp(1.25rem,1.9vw,1.5rem);
            font-weight:700; letter-spacing:-.5px;
            color: var(--brown); line-height:1.1;
        }
        .sp-stat-value small {
            font-size:.7rem; font-weight:600; color: var(--text-3);
            margin-left:3px; letter-spacing:0;
        }

        /* Toolbar */
        .sp-toolbar {
            background:#fff;
            border:1px solid var(--line);
            border-radius:16px;
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset, 0 14px 30px -22px rgba(74,44,16,.4);
            padding:14px 18px;
            display:flex; align-items:center; gap:12px;
            flex-wrap:wrap;
        }
        .sp-search {
            display:flex; align-items:center;
            background:#fff;
            border:1.5px solid var(--line-2);
            border-radius:11px;
            transition: border-color .18s, box-shadow .18s;
            overflow:hidden;
            min-height:42px;
            flex:1 1 260px; max-width:400px;
        }
        .sp-search:focus-within {
            border-color: var(--gold);
            box-shadow: 0 0 0 4px rgba(176,100,30,.13);
        }
        .sp-search i {
            padding: 0 4px 0 14px;
            color: var(--text-3); font-size:.95rem;
        }
        .sp-search input {
            flex:1; border:0; outline:0;
            background:transparent;
            padding: 10px 14px 10px 10px;
            font-size:.87rem; font-weight:500;
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
        .sp-filter select {
            border:0; outline:0; background:transparent;
            padding: 10px 6px;
            font-size:.86rem; font-weight:500;
            color: #2A1E10; font-family:inherit;
            cursor:pointer; appearance:none;
            padding-right: 22px;
            -webkit-text-fill-color: #2A1E10;
        }
        .sp-filter select option { color:#2A1E10; background:#fff; }

        /* Seller cards */
        .sp-sellers-grid {
            display:grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 16px;
        }
        .sp-seller-card {
            position:relative;
            background:#fff;
            border:1px solid var(--line);
            border-radius:18px;
            padding: 20px 20px 18px;
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset, 0 14px 30px -22px rgba(74,44,16,.4);
            transition: transform .28s cubic-bezier(.2,.7,.3,1), box-shadow .28s, border-color .28s;
            cursor: pointer;
            display:flex; flex-direction:column; gap:14px;
            overflow:hidden;
        }
        .sp-seller-card::before {
            content:''; position:absolute; top:0; left:0;
            width:56px; height:3px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
            border-radius:0 3px 3px 0; opacity:.55;
            transition: opacity .25s, width .3s;
        }
        .sp-seller-card:hover {
            transform: translateY(-5px);
            border-color: rgba(176,100,30,.35);
            box-shadow: 0 26px 48px -22px rgba(74,44,16,.5);
        }
        .sp-seller-card:hover::before { opacity:1; width:84px; }

        .sp-seller-head {
            display:flex; align-items:center; gap:14px;
        }
        .sp-seller-avatar {
            width:52px; height:52px;
            border-radius:50%;
            flex-shrink:0;
            display:grid; place-items:center;
            background: linear-gradient(135deg, #F4C87A 0%, #C97628 100%);
            color:#fff;
            font-family:'Fraunces', Georgia, serif;
            font-weight:700; font-size:1.05rem;
            letter-spacing:.5px;
            box-shadow: 0 6px 16px -6px rgba(176,100,30,.75);
            border: 2px solid #fff;
        }
        .sp-seller-info { min-width:0; flex:1; }
        .sp-seller-name {
            font-family:'Fraunces', Georgia, serif;
            font-size:1.05rem; font-weight:600;
            color: var(--brown); margin:0 0 2px;
            letter-spacing:-.2px;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        }
        .sp-seller-sub {
            font-size:.74rem; color: var(--text-3);
            font-weight:500;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        }
        .sp-seller-sub i { color: var(--gold); margin-right:4px; }

        .sp-seller-stats {
            display:grid;
            grid-template-columns: repeat(3, 1fr);
            gap:8px;
            padding: 12px;
            border-radius:12px;
            background: var(--cream-2);
            border:1px solid var(--line);
        }
        .sp-seller-stat {
            text-align:center;
            min-width:0;
        }
        .sp-seller-stat-val {
            font-family:'Fraunces', Georgia, serif;
            font-size:.95rem; font-weight:700;
            color: var(--brown); line-height:1.15;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        }
        .sp-seller-stat-val.is-danger { color: var(--danger); }
        .sp-seller-stat-val.is-success { color: var(--success); }
        .sp-seller-stat-lbl {
            font-size:.6rem; font-weight:700;
            letter-spacing:1px; text-transform:uppercase;
            color: var(--text-3); margin-top:3px;
        }

        .sp-seller-actions {
            display:flex; align-items:center; gap:6px;
            padding-top:4px;
        }
        .sp-seller-chip {
            display:inline-flex; align-items:center; gap:5px;
            padding:5px 11px; border-radius:999px;
            font-size:.7rem; font-weight:600;
            background: var(--paper-2);
            border:1px solid var(--line-2);
            color: var(--brown-2);
            white-space:nowrap;
            max-width:100%;
            overflow:hidden; text-overflow:ellipsis;
        }
        .sp-seller-chip i { font-size:.78rem; color: var(--gold); }
        .sp-seller-chip.is-empty {
            background: transparent; border-style:dashed;
            color: var(--text-3);
        }
        .sp-seller-chip.is-empty i { color: var(--text-3); }

        .sp-icon-btn-sm {
            width:32px; height:32px;
            border-radius:9px;
            border:1.5px solid var(--line-2);
            background:#fff;
            color: var(--brown-2);
            display:inline-flex; align-items:center; justify-content:center;
            cursor:pointer; font-size:.85rem;
            transition: border-color .15s, color .15s, transform .15s, background .15s;
            margin-left:auto;
            flex-shrink:0;
        }
        .sp-icon-btn-sm:hover {
            border-color: var(--gold); color: var(--gold);
            transform: translateY(-1px);
        }
        .sp-icon-btn-sm.is-danger:hover {
            border-color: var(--danger); color: var(--danger);
            background: var(--danger-bg);
        }

        /* Empty */
        .sp-empty {
            padding:56px 26px; text-align:center; color: var(--text-3);
            grid-column: 1 / -1;
        }
        .sp-empty-ico {
            width:76px; height:76px; margin:0 auto 18px;
            border-radius:50%; display:grid; place-items:center;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            color: var(--gold); font-size:1.9rem;
            border:1px solid rgba(176,100,30,.2);
            box-shadow: 0 10px 24px -12px rgba(176,100,30,.4);
        }
        .sp-empty h4 {
            font-family:'Fraunces', Georgia, serif;
            font-size:1.15rem; font-weight:600;
            color: var(--brown); margin:0 0 8px;
        }
        .sp-empty p {
            font-size:.88rem; margin:0 0 20px;
            max-width:42ch; margin-inline:auto; line-height:1.6;
        }
        .sp-empty .sp-btn {
            display:inline-flex; align-items:center; gap:7px;
            padding:10px 20px; border-radius:10px;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color:#fff; font-weight:700; font-size:.84rem;
            text-decoration:none;
            border:0; cursor:pointer; font-family:inherit;
            box-shadow: 0 3px 0 rgba(0,0,0,.12), 0 12px 22px -12px rgba(176,100,30,.7);
            transition: transform .15s, filter .15s;
        }
        .sp-empty .sp-btn:hover { transform: translateY(-1px); filter: brightness(1.04); color:#fff; }

        /* Pagination */
        .sp-pagination {
            display:flex; align-items:center; justify-content:space-between;
            gap:12px; flex-wrap:wrap;
            padding: 4px 0 0;
        }
        .sp-pagination-info {
            font-size:.78rem; color: var(--text-3); font-weight:500;
        }
        .sp-pagination-info strong { color: var(--brown); font-weight:700; }
        .sp-pagination-list {
            display:inline-flex; align-items:center; gap:4px;
        }
        .sp-page-link {
            min-width:36px; height:36px;
            padding:0 10px;
            border-radius:9px;
            border:1.5px solid var(--line-2);
            background:#fff;
            color: var(--brown-2);
            font-size:.82rem; font-weight:600;
            display:inline-grid; place-items:center;
            text-decoration:none;
            cursor:pointer;
            transition: border-color .15s, color .15s, transform .15s;
        }
        .sp-page-link:hover {
            border-color: var(--gold); color: var(--gold);
            transform: translateY(-1px);
        }
        .sp-page-link.is-active {
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            border-color: transparent; color:#fff;
            box-shadow: 0 6px 14px -6px rgba(176,100,30,.7);
        }
        .sp-page-link.is-disabled { opacity:.45; pointer-events:none; }
        .sp-page-dots { padding:0 4px; color: var(--text-3); font-weight:700; }

        /* Footnote */
        .sp-footnote {
            display:flex; align-items:center; justify-content:space-between;
            gap:16px; flex-wrap:wrap;
            padding:16px 22px;
            border-radius:16px;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            border:1px solid rgba(176,100,30,.22);
            font-size:.82rem; color: var(--text-2);
        }
        .sp-footnote-left {
            display:inline-flex; align-items:center; gap:12px;
            font-weight:500;
        }
        .sp-footnote-left i {
            width:34px; height:34px; border-radius:10px;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color:#fff; display:grid; place-items:center;
            font-size:1rem;
            box-shadow: 0 4px 10px -3px rgba(176,100,30,.6);
        }
        .sp-footnote-left strong { color: var(--brown); font-weight:700; }
        .sp-footnote-right {
            display:inline-flex; align-items:center; gap:8px;
            color: var(--gold); font-weight:700;
            text-decoration:none;
            background:none; border:0; cursor:pointer; font-family:inherit;
            font-size:.82rem;
            transition: color .15s, gap .15s;
        }
        .sp-footnote-right:hover { color: var(--gold-2); gap:12px; }

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
            overflow:hidden;
        }
        .sp-modal-backdrop.is-open { display:flex; opacity:1; }
        .sp-modal {
            background:#fff;
            border-radius:22px;
            width:100%; max-width:560px;
            max-height: calc(100vh - 40px);
            display:flex; flex-direction:column;
            overflow:hidden;
            box-shadow: 0 46px 100px -34px rgba(40,22,6,.8);
            transform: scale(.95) translateY(10px);
            transition: transform .3s cubic-bezier(.2,.7,.3,1);
            min-height:0;
        }
        .sp-modal.is-danger { max-width: 460px; }
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
            color: var(--gold-3); margin-bottom:12px;
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
        }
        .sp-modal-foot {
            padding:16px 26px 22px;
            border-top:1px solid var(--line);
            background: var(--cream-2);
            display:flex; align-items:center; justify-content:flex-end;
            gap:10px; flex-shrink:0;
            flex-wrap:wrap;
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
            font-size:.66rem; font-weight:700;
            letter-spacing:1.1px; text-transform:uppercase;
            color: var(--brown-2);
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
        }
        .sp-form-input:focus-within i { color: var(--gold); }
        .sp-form-input input,
        .sp-form-input textarea {
            flex:1; border:0; outline:0;
            background:transparent;
            padding: 11px 14px 11px 10px;
            font-size:.89rem; font-weight:500;
            color: #2A1E10; font-family:inherit;
            min-width:0; width:100%;
            -webkit-text-fill-color: #2A1E10;
        }
        .sp-form-input textarea { resize: vertical; min-height:76px; padding-top:12px; }
        .sp-form-input input::placeholder,
        .sp-form-input textarea::placeholder { color:#A9A392; font-weight:400; }
        .sp-form-hint { font-size:.7rem; color: var(--text-3); font-weight:500; padding-left:2px; }

        .sp-btn-submit,
        .sp-btn-cancel,
        .sp-btn-danger {
            display:inline-flex; align-items:center; justify-content:center;
            gap:8px; padding:12px 20px; border-radius:11px;
            font-size:.87rem; font-weight:700;
            font-family:inherit; cursor:pointer;
            transition: transform .15s, filter .15s, border-color .15s, color .15s;
        }
        .sp-btn-submit {
            border:0;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color:#fff;
            box-shadow: 0 3px 0 rgba(0,0,0,.12), 0 14px 24px -12px rgba(176,100,30,.75);
        }
        .sp-btn-submit:hover { transform: translateY(-1px); filter: brightness(1.04); }
        .sp-btn-cancel {
            border:1.5px solid var(--line-2);
            background:#fff; color: var(--brown-2);
        }
        .sp-btn-cancel:hover { border-color: var(--gold); color: var(--gold); transform: translateY(-1px); }
        .sp-btn-danger {
            border:0;
            background: linear-gradient(135deg, var(--danger), #C25A3A);
            color:#fff;
            box-shadow: 0 3px 0 rgba(0,0,0,.12), 0 14px 24px -12px rgba(162,58,26,.75);
            margin-right: auto;
        }
        .sp-btn-danger:hover { transform: translateY(-1px); filter: brightness(1.04); }

        .sp-danger-preview {
            padding:16px; border-radius:12px;
            background: var(--danger-bg);
            border:1px solid var(--danger-bd);
            display:flex; align-items:center; gap:12px;
        }
        .sp-danger-preview i { font-size:1.6rem; color: var(--danger); flex-shrink:0; }
        .sp-danger-preview strong { color: var(--brown); font-weight:700; }
        .sp-danger-preview small { display:block; color: var(--text-3); font-size:.78rem; margin-top:2px; }

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
            background: rgba(42,30,16,.5); z-index:250;
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
            .sp-panel-head { padding:14px 16px; }
            .sp-pagination { padding: 12px 16px; }
            .sp-modal-body { padding:18px 18px; }
            .sp-modal-foot { padding:12px 18px 16px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .001ms !important;
                transition-duration: .001ms !important;
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
        <div class="sp-user-avatar" title="SmartPalay">
            <div class="sp-user-avatar-img">
                <?php if ($smartPalayLogo): ?>
                    <img src="<?= $smartPalayLogo ?>" alt="SmartPalay">
                <?php else: ?>
                    <i class="bi bi-image" style="font-size:1.6rem; color: var(--text-3);"></i>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="sp-sidebar-role">
        <span class="sp-sidebar-role-chip">
            <i class="bi bi-bag-check-fill"></i> Buyer
        </span>
    </div>

    <nav class="sp-nav">
        <span class="sp-nav-label">Overview</span>
        <a href="<?= BASE_URL ?>buyer/dashboard.php" class="sp-nav-link" data-nav>
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="<?= BASE_URL ?>buyer/purchases.php" class="sp-nav-link" data-nav>
            <i class="bi bi-bag-check"></i> My Purchases
        </a>
        <a href="<?= BASE_URL ?>buyer/payments.php" class="sp-nav-link" data-nav>
            <i class="bi bi-cash-coin"></i> Payments
        </a>

        <span class="sp-nav-label">Records</span>
        <a href="<?= BASE_URL ?>buyer/sellers.php" class="sp-nav-link is-active" data-nav>
            <i class="bi bi-people"></i> Sellers
        </a>
        <a href="<?= BASE_URL ?>buyer/reports.php" class="sp-nav-link" data-nav>
            <i class="bi bi-graph-up-arrow"></i> Reports
        </a>

        <span class="sp-nav-label">Account</span>
        <a href="<?= BASE_URL ?>buyer/profile.php" class="sp-nav-link" data-nav>
            <i class="bi bi-person-circle"></i> My Profile
        </a>
        <a href="<?= BASE_URL ?>buyer/settings.php" class="sp-nav-link" data-nav>
            <i class="bi bi-gear"></i> Settings
        </a>
    </nav>

    <div class="sp-sidebar-foot">
        <a href="<?= BASE_URL ?>auth/logout.php" class="sp-nav-link sp-logout" data-nav>
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
                <h1 class="sp-page-title">Sellers</h1>
                <span class="sp-page-sub">
                    Your palay suppliers — <strong><?= number_format($totalSellers) ?></strong> total
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
                <span>Add Seller</span>
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

        <!-- Intro -->
        <section class="sp-intro">
            <div>
                <span class="sp-intro-tag">
                    <i class="bi bi-people-fill"></i>
                    Seller Directory
                </span>
                <h2>Your trusted palay <em>suppliers</em>.</h2>
                <p>
                    Manage the farmers and traders you buy palay from. Track contacts,
                    delivery volumes, and outstanding balances — all in one place.
                </p>
                <div class="sp-intro-actions">
                    <button type="button" class="sp-intro-btn is-primary" id="introAddBtn">
                        <i class="bi bi-plus-lg"></i> Add Seller
                    </button>
                    <a href="<?= BASE_URL ?>buyer/purchases.php" class="sp-intro-btn is-ghost">
                        <i class="bi bi-bag-check"></i> View Purchases
                    </a>
                </div>
            </div>

            <div class="sp-intro-mini">
                <div class="sp-intro-mini-card">
                    <div class="sp-intro-mini-label">Sellers</div>
                    <div class="sp-intro-mini-value"><?= number_format($totalSellers) ?></div>
                </div>
                <div class="sp-intro-mini-card">
                    <div class="sp-intro-mini-label">Volume</div>
                    <div class="sp-intro-mini-value"><?= number_format($totalVolume, 0) ?> kg</div>
                </div>
                <div class="sp-intro-mini-card">
                    <div class="sp-intro-mini-label">Owed</div>
                    <div class="sp-intro-mini-value"><?= peso($totalOwed) ?></div>
                </div>
            </div>
        </section>

        <!-- Stats -->
        <section class="sp-stats">
            <div class="sp-stat">
                <div class="sp-stat-ico"><i class="bi bi-people"></i></div>
                <div class="sp-stat-body">
                    <div class="sp-stat-label">Total Sellers</div>
                    <div class="sp-stat-value"><?= number_format($totalSellers) ?></div>
                </div>
            </div>
            <div class="sp-stat">
                <div class="sp-stat-ico is-info"><i class="bi bi-activity"></i></div>
                <div class="sp-stat-body">
                    <div class="sp-stat-label">Active (With Purchases)</div>
                    <div class="sp-stat-value"><?= number_format($totalWithPurch) ?></div>
                </div>
            </div>
            <div class="sp-stat">
                <div class="sp-stat-ico is-success"><i class="bi bi-box-seam"></i></div>
                <div class="sp-stat-body">
                    <div class="sp-stat-label">Total Volume</div>
                    <div class="sp-stat-value"><?= number_format($totalVolume, 0) ?><small>kg</small></div>
                </div>
            </div>
            <div class="sp-stat">
                <div class="sp-stat-ico is-warn"><i class="bi bi-hourglass-split"></i></div>
                <div class="sp-stat-body">
                    <div class="sp-stat-label">Total Owed</div>
                    <div class="sp-stat-value"><?= peso($totalOwed) ?></div>
                </div>
            </div>
        </section>

        <!-- Toolbar -->
        <form class="sp-toolbar" method="get">
            <label class="sp-search">
                <i class="bi bi-search"></i>
                <input type="search" name="q"
                       placeholder="Search seller by name, contact, or address…"
                       value="<?= htmlspecialchars($q) ?>" autocomplete="off">
            </label>

            <label class="sp-filter">
                <i class="bi bi-sort-down"></i>
                <select name="sort" onchange="this.form.submit()">
                    <option value="name"      <?= $sort === 'name'      ? 'selected' : '' ?>>Sort: Name (A–Z)</option>
                    <option value="purchases" <?= $sort === 'purchases' ? 'selected' : '' ?>>Sort: Purchases (High)</option>
                    <option value="weight"    <?= $sort === 'weight'    ? 'selected' : '' ?>>Sort: Volume (High)</option>
                    <option value="amount"    <?= $sort === 'amount'    ? 'selected' : '' ?>>Sort: Amount (High)</option>
                </select>
            </label>

            <?php if ($q !== '' || $sort !== 'name'): ?>
                <a href="<?= BASE_URL ?>buyer/sellers.php" class="sp-btn-clear"
                   style="display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:11px;
                          border:1.5px solid var(--line-2);background:#fff;color:var(--brown-2);
                          font-weight:600;font-size:.82rem;text-decoration:none;">
                    <i class="bi bi-x-lg"></i> Clear
                </a>
            <?php endif; ?>
        </form>

        <!-- Sellers grid -->
        <section class="sp-sellers-grid">
            <?php if (!empty($pageItems)): ?>
                <?php foreach ($pageItems as $s): ?>
                    <?php
                        $hasId = !empty($s['id']);
                        $purchases = (int) $s['purchase_count'];
                        $balance = (float) $s['balance'];
                    ?>
                    <article class="sp-seller-card" data-seller='<?= htmlspecialchars(json_encode([
                        'id'           => $hasId ? (int) $s['id'] : 0,
                        'name'         => $s['name'] ?? '',
                        'contact'      => $s['contact'] ?? '',
                        'address'      => $s['address'] ?? '',
                        'notes'        => $s['notes'] ?? '',
                        'purchases'    => $purchases,
                        'total_kg'     => (float) $s['total_kg'],
                        'total_amount' => (float) $s['total_amount'],
                        'balance'      => $balance,
                        'last_purchase'=> $s['last_purchase'] ?? '',
                    ]), ENT_QUOTES, 'UTF-8') ?>'>
                        <div class="sp-seller-head">
                            <div class="sp-seller-avatar"><?= htmlspecialchars(initials($s['name'])) ?></div>
                            <div class="sp-seller-info">
                                <p class="sp-seller-name"><?= sanitize($s['name']) ?></p>
                                <p class="sp-seller-sub">
                                    <?php if (!empty($s['contact'])): ?>
                                        <i class="bi bi-telephone"></i> <?= sanitize($s['contact']) ?>
                                    <?php elseif (!empty($s['address'])): ?>
                                        <i class="bi bi-geo-alt"></i> <?= sanitize($s['address']) ?>
                                    <?php else: ?>
                                        <?= $purchases ?> purchase<?= $purchases === 1 ? '' : 's' ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <div class="sp-seller-stats">
                            <div class="sp-seller-stat">
                                <div class="sp-seller-stat-val"><?= number_format($purchases) ?></div>
                                <div class="sp-seller-stat-lbl">Bought</div>
                            </div>
                            <div class="sp-seller-stat">
                                <div class="sp-seller-stat-val"><?= number_format((float) $s['total_kg'], 0) ?><small style="font-size:.65rem; font-weight:600; color:var(--text-3); margin-left:2px;">kg</small></div>
                                <div class="sp-seller-stat-lbl">Volume</div>
                            </div>
                            <div class="sp-seller-stat">
                                <div class="sp-seller-stat-val <?= $balance > 0 ? 'is-danger' : 'is-success' ?>">
                                    <?= peso($balance) ?>
                                </div>
                                <div class="sp-seller-stat-lbl">Balance</div>
                            </div>
                        </div>

                        <div class="sp-seller-actions">
                            <?php if (!empty($s['address'])): ?>
                                <span class="sp-seller-chip" title="<?= sanitize($s['address']) ?>">
                                    <i class="bi bi-geo-alt"></i> <?= sanitize(mb_strimwidth($s['address'], 0, 22, '…')) ?>
                                </span>
                            <?php elseif ($s['last_purchase']): ?>
                                <span class="sp-seller-chip">
                                    <i class="bi bi-clock-history"></i> <?= niceDate($s['last_purchase']) ?>
                                </span>
                            <?php else: ?>
                                <span class="sp-seller-chip is-empty">
                                    <i class="bi bi-info-circle"></i> No address
                                </span>
                            <?php endif; ?>

                            <?php if ($hasId): ?>
                                <button type="button"
                                        class="sp-icon-btn-sm js-edit"
                                        title="Edit seller"
                                        data-id="<?= (int) $s['id'] ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button"
                                        class="sp-icon-btn-sm is-danger js-delete"
                                        title="Delete seller"
                                        data-id="<?= (int) $s['id'] ?>"
                                        data-name="<?= sanitize($s['name']) ?>">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="sp-empty">
                    <div class="sp-empty-ico"><i class="bi bi-people"></i></div>
                    <h4>
                        <?= ($q !== '') ? 'No sellers match your search' : 'No sellers yet' ?>
                    </h4>
                    <p>
                        <?= ($q !== '')
                            ? 'Try a different name, contact, or address.'
                            : 'Add your first seller to start tracking palay deliveries.' ?>
                    </p>
                    <?php if ($q !== ''): ?>
                        <a href="<?= BASE_URL ?>buyer/sellers.php" class="sp-btn" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:10px;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;font-weight:700;font-size:.84rem;text-decoration:none;">
                            <i class="bi bi-x-lg"></i> Clear search
                        </a>
                    <?php else: ?>
                        <button type="button" class="sp-btn" data-modal="newSeller"
                                id="emptyAddBtn"
                                style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:10px;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;font-weight:700;font-size:.84rem;border:0;cursor:pointer;">
                            <i class="bi bi-plus-lg"></i> Add Seller
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="sp-pagination">
                <div class="sp-pagination-info">
                    Showing <strong><?= count($pageItems) ?></strong> of
                    <strong><?= number_format($totalSellers) ?></strong> sellers
                    · Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
                </div>
                <div class="sp-pagination-list">
                    <?php
                        $qs = $_GET; unset($qs['page']);
                        $buildUrl = function ($p) use ($qs) {
                            $qs['page'] = $p;
                            return 'sellers.php?' . http_build_query($qs);
                        };
                    ?>
                    <a class="sp-page-link <?= $page <= 1 ? 'is-disabled' : '' ?>"
                       href="<?= $page <= 1 ? '#' : htmlspecialchars($buildUrl($page - 1)) ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                    <?php
                        $start = max(1, $page - 2);
                        $end   = min($totalPages, $page + 2);
                        if ($start > 1) {
                            echo '<a class="sp-page-link" href="' . htmlspecialchars($buildUrl(1)) . '">1</a>';
                            if ($start > 2) echo '<span class="sp-page-dots">…</span>';
                        }
                        for ($i = $start; $i <= $end; $i++) {
                            $cls = $i === $page ? 'is-active' : '';
                            echo '<a class="sp-page-link ' . $cls . '" href="' . htmlspecialchars($buildUrl($i)) . '">' . $i . '</a>';
                        }
                        if ($end < $totalPages) {
                            if ($end < $totalPages - 1) echo '<span class="sp-page-dots">…</span>';
                            echo '<a class="sp-page-link" href="' . htmlspecialchars($buildUrl($totalPages)) . '">' . $totalPages . '</a>';
                        }
                    ?>
                    <a class="sp-page-link <?= $page >= $totalPages ? 'is-disabled' : '' ?>"
                       href="<?= $page >= $totalPages ? '#' : htmlspecialchars($buildUrl($page + 1)) ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Footnote -->
        <div class="sp-footnote">
            <span class="sp-footnote-left">
                <i class="bi bi-lightbulb"></i>
                <span>
                    <strong>Tip:</strong> Add contact details to your sellers so you can reach them faster.
                </span>
            </span>
            <a href="<?= BASE_URL ?>buyer/purchases.php" class="sp-footnote-right">
                View purchases <i class="bi bi-arrow-right"></i>
            </a>
        </div>

    </main>
</div>

<!-- ADD / EDIT MODAL -->
<div class="sp-modal-backdrop" id="modalSeller">
    <div class="sp-modal" role="dialog" aria-modal="true" aria-labelledby="sellerTitle">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
            <span class="sp-modal-tag" id="sellerModalTag">
                <i class="bi bi-person-plus"></i>
                <span id="sellerModalTagText">New Seller</span>
            </span>
            <h3 class="sp-modal-title" id="sellerTitle">Add a Seller</h3>
            <p class="sp-modal-sub" id="sellerSub">Save a farmer or trader you buy palay from.</p>
        </div>

        <form id="sellerForm" method="post" autocomplete="off" style="display:contents;">
            <input type="hidden" name="action" id="sellerAction" value="create">
            <input type="hidden" name="id"     id="sellerId"     value="">
            <input type="hidden" name="old_name" id="sellerOldName" value="">

            <div class="sp-modal-body">
                <div class="sp-form-row">

                    <div class="sp-form-field is-full">
                        <label for="sf_name">Full Name <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-person"></i>
                            <input type="text" id="sf_name" name="name" required
                                   placeholder="e.g. Mang Jose Santos">
                        </div>
                    </div>

                    <div class="sp-form-field">
                        <label for="sf_contact">Contact Number</label>
                        <div class="sp-form-input">
                            <i class="bi bi-telephone"></i>
                            <input type="text" id="sf_contact" name="contact"
                                   placeholder="e.g. 0917 123 4567">
                        </div>
                    </div>

                    <div class="sp-form-field">
                        <label for="sf_address">Address / Location</label>
                        <div class="sp-form-input">
                            <i class="bi bi-geo-alt"></i>
                            <input type="text" id="sf_address" name="address"
                                   placeholder="e.g. Brgy. San Isidro, Nueva Ecija">
                        </div>
                    </div>

                    <div class="sp-form-field is-full">
                        <label for="sf_notes">Notes (optional)</label>
                        <div class="sp-form-input">
                            <i class="bi bi-chat-left-text"></i>
                            <textarea id="sf_notes" name="notes"
                                      placeholder="e.g. Reliable, dry palay, delivers on Wednesdays…"></textarea>
                        </div>
                    </div>

                </div>
            </div>

            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-cancel" data-close-modal>
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="submit" class="sp-btn-submit" id="sellerSubmit">
                    <i class="bi bi-check-lg"></i> Save Seller
                </button>
            </div>
        </form>
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
            <h3 class="sp-modal-title">Delete this seller?</h3>
            <p class="sp-modal-sub">
                This removes the seller from your directory.
                Purchase records are kept (they still show the seller name).
            </p>
        </div>

        <form method="post" id="deleteForm" style="display:contents;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId" value="">

            <div class="sp-modal-body">
                <div class="sp-danger-preview">
                    <i class="bi bi-person-x"></i>
                    <div>
                        <strong id="deleteName">—</strong>
                        <small>The seller's purchases will remain in your ledger.</small>
                    </div>
                </div>
            </div>

            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-cancel" data-close-modal>
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="submit" class="sp-btn-danger">
                    <i class="bi bi-trash3"></i> Delete Seller
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

    /* Sidebar */
    const body    = document.body;
    const menuBtn = document.getElementById('menuBtn');
    const overlay = document.getElementById('sidebarOverlay');
    const closeSidebar = () => body.classList.remove('sp-sidebar-open');

    menuBtn?.addEventListener('click', () => body.classList.add('sp-sidebar-open'));
    overlay?.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });
    window.addEventListener('resize', () => { if (window.innerWidth > 900) closeSidebar(); });

    /* Hardened nav */
    document.querySelectorAll('.sp-nav-link[data-nav]').forEach(link => {
        link.addEventListener('click', (e) => {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
            const href = link.getAttribute('href');
            if (!href || href === '#') { e.preventDefault(); return; }
            e.preventDefault();
            window.location.href = href;
        });
    });

    /* Modals */
    const modalSeller = document.getElementById('modalSeller');
    const modalDelete = document.getElementById('modalDelete');

    function openModal(m) {
        if (!m) return;
        m.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        setTimeout(() => m.querySelector('input:not([type=hidden])')?.focus(), 120);
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

    /* Form */
    const sellerForm    = document.getElementById('sellerForm');
    const sellerAction  = document.getElementById('sellerAction');
    const sellerId      = document.getElementById('sellerId');
    const sellerOldName = document.getElementById('sellerOldName');
    const sf_name       = document.getElementById('sf_name');
    const sf_contact    = document.getElementById('sf_contact');
    const sf_address    = document.getElementById('sf_address');
    const sf_notes      = document.getElementById('sf_notes');

    const tagText = document.getElementById('sellerModalTagText');
    const mTitle  = document.getElementById('sellerTitle');
    const mSub    = document.getElementById('sellerSub');

    function resetSellerForm() {
        sellerForm.reset();
        sellerAction.value  = 'create';
        sellerId.value      = '';
        sellerOldName.value = '';
        tagText.textContent = 'New Seller';
        mTitle.textContent  = 'Add a Seller';
        mSub.textContent    = 'Save a farmer or trader you buy palay from.';
    }

    document.getElementById('topAddBtn')?.addEventListener('click', () => {
        resetSellerForm(); openModal(modalSeller);
    });
    document.getElementById('introAddBtn')?.addEventListener('click', () => {
        resetSellerForm(); openModal(modalSeller);
    });

    /* Edit */
    document.querySelectorAll('.js-edit').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const card = btn.closest('.sp-seller-card');
            if (!card) return;
            const data = JSON.parse(card.dataset.seller);

            sellerAction.value  = 'update';
            sellerId.value      = data.id;
            sellerOldName.value = data.name;
            sf_name.value       = data.name || '';
            sf_contact.value    = data.contact || '';
            sf_address.value    = data.address || '';
            sf_notes.value      = data.notes || '';

            tagText.textContent = 'Edit Seller';
            mTitle.textContent  = 'Edit Seller';
            mSub.textContent    = 'Update the seller details.';

            openModal(modalSeller);
        });
    });

    /* Delete */
    const deleteId   = document.getElementById('deleteId');
    const deleteName = document.getElementById('deleteName');

    document.querySelectorAll('.js-delete').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            deleteId.value = btn.dataset.id || '';
            deleteName.textContent = btn.dataset.name || '—';
            openModal(modalDelete);
        });
    });

    /* Click card → show simple toast with info (no dedicated view modal) */
    document.querySelectorAll('.sp-seller-card').forEach(card => {
        card.addEventListener('click', (e) => {
            if (e.target.closest('.js-edit') || e.target.closest('.js-delete')) return;
            const data = JSON.parse(card.dataset.seller);
            showToast(`${data.name} — ${data.purchases} purchase${data.purchases === 1 ? '' : 's'}, ${Number(data.total_kg).toLocaleString()} kg`, 'success');
        });
    });

    /* Toast */
    const toast     = document.getElementById('spToast');
    const toastIcon = document.getElementById('spToastIcon');
    const toastText = document.getElementById('spToastText');
    let toastTimer;

    function showToast(message, type = 'success') {
        toastText.textContent = message;
        toast.classList.remove('is-success', 'is-error');
        toastIcon.className = 'bi ' + (type === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
        toast.classList.add('is-' + type, 'is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 3200);
    }

    document.getElementById('notifBtn')?.addEventListener('click', () => {
        showToast('No new notifications right now.', 'success');
    });
})();
</script>
</body>
</html>