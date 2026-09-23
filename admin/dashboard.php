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
$email    = $_SESSION['email'] ?? '';

/* ---------- System-wide stats ---------- */
$stats = [
    'total_users'      => 0,
    'total_farmers'    => 0,
    'total_buyers'     => 0,
    'total_purchases'  => 0,
    'total_palay_kg'   => 0,
    'total_amount'     => 0,
    'total_paid'       => 0,
    'outstanding'      => 0,
];
$recentPurchases = [];
$recentPayments  = [];
$recentUsers     = [];
$roleBreakdown   = [];

try {
    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM users");
    $stats['total_users'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='farmer'");
    $stats['total_farmers'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='buyer'");
    $stats['total_buyers'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("
        SELECT
            COUNT(*)                              AS total_purchases,
            COALESCE(SUM(weight_kg), 0)           AS total_palay_kg,
            COALESCE(SUM(total_amount), 0)        AS total_amount,
            COALESCE(SUM(total_amount - balance), 0) AS total_paid,
            COALESCE(SUM(balance), 0)             AS outstanding
        FROM purchases
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats['total_purchases'] = (int) ($row['total_purchases'] ?? 0);
    $stats['total_palay_kg']  = (float) ($row['total_palay_kg'] ?? 0);
    $stats['total_amount']    = (float) ($row['total_amount'] ?? 0);
    $stats['total_paid']      = (float) ($row['total_paid'] ?? 0);
    $stats['outstanding']     = (float) ($row['outstanding'] ?? 0);
} catch (Throwable $e) {}

try {
    $recentPurchases = $pdo->query("
        SELECT id, reference_no, buyer_name, seller_name, weight_kg, total_amount, balance, status, created_at
        FROM purchases
        ORDER BY created_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $recentPurchases = []; }

try {
    $recentPayments = $pdo->query("
        SELECT id, reference_no, buyer_name, amount, method, paid_at
        FROM payments
        ORDER BY paid_at DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $recentPayments = []; }

try {
    $recentUsers = $pdo->query("
        SELECT id, full_name, email, role, created_at
        FROM users
        ORDER BY id DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $recentUsers = []; }

try {
    $roleBreakdown = $pdo->query("
        SELECT role, COUNT(*) AS c
        FROM users
        GROUP BY role
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) { $roleBreakdown = []; }

$paidPct = $stats['total_amount'] > 0
    ? ($stats['total_paid'] / $stats['total_amount']) * 100
    : 0;

/* ---------- Helpers ---------- */
function peso($n) { return '₱' . number_format((float) $n, 2); }
function kg($n)   { return number_format((float) $n, 2) . ' kg'; }
function niceDate($s) {
    if (!$s) return '—';
    $t = strtotime($s);
    return $t ? date('M j, Y', $t) : '—';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#B0641E">
    <title>Admin Dashboard | SmartPalay</title>

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
           SmartPalay — Admin Dashboard
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
            --bottom-nav-h:66px;
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

        /* ============================================================
           SIDEBAR
           ============================================================ */
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

        /* Role chip under avatar */
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

        /* ============================================================
           MAIN
           ============================================================ */
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

        /* ============================================================
           WELCOME
           ============================================================ */
        .sp-welcome {
            position:relative; overflow:hidden;
            padding: clamp(28px,3.6vh,42px) clamp(24px,3.4vw,44px);
            border-radius:24px;
            background:
                radial-gradient(circle at 82% 18%, rgba(232,176,90,.38), transparent 55%),
                radial-gradient(circle at 10% 90%, rgba(184,92,46,.22), transparent 50%),
                linear-gradient(135deg, #4A2C10 0%, #2A1E10 100%);
            color:#fff;
            display:grid; grid-template-columns: minmax(0,1fr) auto;
            gap:32px; align-items:center;
            box-shadow: 0 24px 60px -28px rgba(40,22,6,.7), 0 1px 0 rgba(255,255,255,.06) inset;
        }
        .sp-welcome::before {
            content:''; position:absolute; inset:0; pointer-events:none;
            background-image:
                repeating-linear-gradient(92deg, transparent 0 22px, rgba(232,176,90,.06) 22px 24px),
                repeating-linear-gradient(88deg, transparent 0 34px, rgba(232,176,90,.04) 34px 37px);
        }
        .sp-welcome::after {
            content:''; position:absolute; top:-40px; right:-40px;
            width:220px; height:220px; border-radius:50%;
            background: radial-gradient(circle, rgba(232,176,90,.18), transparent 60%);
            pointer-events:none;
        }
        .sp-welcome > * { position:relative; z-index:1; }
        .sp-welcome-tag {
            display:inline-flex; align-items:center; gap:7px;
            padding:6px 13px; border-radius:999px;
            background: rgba(232,176,90,.16);
            border:1px solid rgba(232,176,90,.35);
            font-size:.66rem; font-weight:700;
            letter-spacing:1.4px; text-transform:uppercase;
            color: var(--gold-3); margin-bottom:16px;
        }
        .sp-welcome h2 {
            font-family:'Fraunces', Georgia, serif;
            font-size: clamp(1.55rem,2.6vw,2.2rem);
            font-weight:600; line-height:1.15; letter-spacing:-.6px;
            margin:0 0 12px;
        }
        .sp-welcome h2 em { font-style:italic; font-weight:500; color: var(--gold-3); }
        .sp-welcome p {
            font-size:.93rem; color: rgba(255,255,255,.78);
            line-height:1.65; margin:0; max-width:54ch;
        }
        .sp-welcome-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:22px; }
        .sp-welcome-btn {
            display:inline-flex; align-items:center; gap:8px;
            padding:11px 20px; border-radius:10px;
            font-size:.85rem; font-weight:700;
            text-decoration:none; border:0; cursor:pointer; font-family:inherit;
            transition: transform .15s, filter .15s, box-shadow .15s;
        }
        .sp-welcome-btn.is-primary {
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color:#fff;
            box-shadow: 0 3px 0 rgba(0,0,0,.18), 0 14px 26px -12px rgba(176,100,30,.85);
        }
        .sp-welcome-btn.is-primary:hover {
            color:#fff; transform: translateY(-2px); filter: brightness(1.06);
        }
        .sp-welcome-btn.is-ghost {
            background: rgba(255,255,255,.08);
            color: rgba(255,255,255,.92);
            border:1px solid rgba(255,255,255,.2);
        }
        .sp-welcome-btn.is-ghost:hover {
            background: rgba(255,255,255,.16); color:#fff; transform: translateY(-2px);
        }
        .sp-welcome-portrait {
            position:relative; width:160px; height:160px;
            flex-shrink:0; display:grid; place-items:center;
        }
        .sp-welcome-portrait-ring {
            position:absolute; inset:0; border-radius:50%;
            background: conic-gradient(from 220deg,
                rgba(232,176,90,.8),
                rgba(232,176,90,.1) 55%,
                rgba(232,176,90,.6));
            -webkit-mask: radial-gradient(circle, transparent 62%, #000 63%);
            mask: radial-gradient(circle, transparent 62%, #000 63%);
            animation: spRingSpin 18s linear infinite;
        }
        @keyframes spRingSpin { to { transform: rotate(360deg); } }
        .sp-welcome-portrait-inner {
            width:124px; height:124px; border-radius:50%; overflow:hidden;
            background:#FDFAF1;
            box-shadow: 0 0 0 4px rgba(74,44,16,.5), 0 14px 34px -12px rgba(0,0,0,.8);
            display:grid; place-items:center;
        }
        .sp-welcome-portrait-inner img { width:100%; height:100%; object-fit:cover; display:block; }
        @media (max-width:860px) {
            .sp-welcome { grid-template-columns: 1fr; }
            .sp-welcome-portrait { display:none; }
        }

        /* ============================================================
           STAT CARDS — single row of 6
           ============================================================ */
        .sp-stats {
            display:grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 14px;
        }
        @media (max-width: 1200px) { .sp-stats { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 720px)  { .sp-stats { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 420px)  { .sp-stats { grid-template-columns: 1fr; } }

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
        .sp-stat-ico.is-success { background: var(--success-bg); color: var(--success); border-color: var(--success-bd); }
        .sp-stat-ico.is-info    { background: var(--info-bg);    color: var(--info);    border-color: #C6DCF0; }
        .sp-stat-ico.is-warn    { background: var(--warn-bg);    color: var(--warn);    border-color: var(--warn-bd); }
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
            margin-top:6px;
            height:4px; border-radius:4px;
            background: var(--paper-2); overflow: hidden;
        }
        .sp-stat-bar span {
            display:block; height:100%; border-radius:4px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
            transform-origin:left;
            animation: spBarFill 1.3s cubic-bezier(.2,.7,.3,1) both;
        }
        .sp-stat-bar.is-success span { background: linear-gradient(90deg, var(--success), #6FBF73); }
        .sp-stat-bar.is-info    span { background: linear-gradient(90deg, var(--info), #6A9CD9); }
        .sp-stat-bar.is-warn    span { background: linear-gradient(90deg, var(--warn), var(--gold-3)); }
        .sp-stat-bar.is-danger  span { background: linear-gradient(90deg, var(--danger), #E07A55); }
        @keyframes spBarFill { from { transform: scaleX(0); } to { transform: scaleX(1); } }

        /* ============================================================
           PANELS / GRID
           ============================================================ */
        .sp-grid {
            display:grid;
            grid-template-columns: minmax(0,1.6fr) minmax(0,1fr);
            gap: clamp(14px,1.8vw,22px); align-items:start;
        }
        @media (max-width:980px) { .sp-grid { grid-template-columns: 1fr; } }

        .sp-panel {
            position:relative; background:#fff;
            border:1px solid var(--line); border-radius:18px;
            overflow:hidden;
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset, 0 14px 30px -22px rgba(74,44,16,.4);
            transition: box-shadow .28s, border-color .28s;
        }
        .sp-panel:hover {
            border-color: rgba(176,100,30,.24);
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset, 0 24px 46px -22px rgba(74,44,16,.5);
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
        .sp-panel-link {
            font-size:.78rem; font-weight:600; color: var(--gold);
            text-decoration:none; display:inline-flex; align-items:center; gap:4px;
            transition: color .15s, transform .15s;
            background:none; border:0; cursor:pointer; font-family:inherit;
        }
        .sp-panel-link:hover { color: var(--gold-2); transform: translateX(2px); }

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
            color: var(--text); vertical-align:middle; white-space:nowrap;
        }
        .sp-table tbody tr:last-child td { border-bottom:0; }
        .sp-table tbody tr { transition: background .18s; }
        .sp-table tbody tr:hover { background: var(--gold-soft); }

        .sp-ref {
            font-family:'JetBrains Mono', ui-monospace, monospace;
            font-size:.78rem; font-weight:600; color: var(--brown-2);
            background: var(--paper-2); border:1px solid var(--line-2);
            padding:3px 8px; border-radius:5px;
        }
        .sp-badge {
            display:inline-flex; align-items:center; gap:5px;
            padding:4px 10px; border-radius:999px;
            font-size:.68rem; font-weight:700; letter-spacing:.3px;
            text-transform:uppercase;
        }
        .sp-badge i { font-size:.78rem; }
        .sp-badge-paid    { background: var(--success-bg); color: var(--success); border:1px solid var(--success-bd); }
        .sp-badge-partial { background: var(--warn-bg);    color: var(--warn);    border:1px solid var(--warn-bd); }
        .sp-badge-unpaid  { background: var(--danger-bg);  color: var(--danger);  border:1px solid var(--danger-bd); }
        .sp-badge-pending { background: var(--info-bg);    color: var(--info);    border:1px solid #C6DCF0; }

        .sp-role {
            display:inline-flex; align-items:center; gap:5px;
            padding:4px 10px; border-radius:999px;
            font-size:.68rem; font-weight:700; letter-spacing:.3px; text-transform:uppercase;
        }
        .sp-role-admin   { background:#EFE3FB; color:#5B2A9A; border:1px solid #D9C2F5; }
        .sp-role-buyer   { background: var(--info-bg);    color: var(--info);    border:1px solid #C6DCF0; }
        .sp-role-farmer  { background: var(--success-bg); color: var(--success); border:1px solid var(--success-bd); }

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

        /* Payment list */
        .sp-pay-list { display:flex; flex-direction:column; }
        .sp-pay-item {
            display:flex; align-items:center; gap:12px;
            padding:14px 22px;
            border-bottom:1px dashed var(--line-2);
            transition: background .18s, padding-left .18s;
        }
        .sp-pay-item:last-child { border-bottom:0; }
        .sp-pay-item:hover { background: var(--gold-soft); padding-left:26px; }
        .sp-pay-ico {
            width:40px; height:40px; border-radius:11px;
            display:grid; place-items:center;
            background: var(--success-bg); color: var(--success);
            font-size:1.05rem; border:1px solid var(--success-bd);
            flex-shrink:0;
        }
        .sp-pay-body { min-width:0; flex:1; }
        .sp-pay-ref {
            font-family:'JetBrains Mono', ui-monospace, monospace;
            font-size:.74rem; font-weight:600;
            color: var(--brown-2); margin:0 0 2px;
        }
        .sp-pay-meta { font-size:.72rem; color: var(--text-3); font-weight:500; }
        .sp-pay-amount {
            font-family:'Fraunces', Georgia, serif;
            font-size:1rem; font-weight:700;
            color: var(--success); white-space:nowrap;
        }

        /* Summary rows */
        .sp-summary {
            padding:22px; display:flex; flex-direction:column; gap:12px;
        }
        .sp-summary-row {
            display:flex; align-items:center; justify-content:space-between;
            gap:12px; padding:13px 15px; border-radius:12px;
            background: var(--cream-2); border:1px solid var(--line);
            font-size:.86rem;
            transition: transform .18s, border-color .18s;
        }
        .sp-summary-row:hover { transform: translateX(3px); border-color: var(--line-3); }
        .sp-summary-row.is-total {
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            border-color: rgba(176,100,30,.28);
        }
        .sp-summary-label {
            display:inline-flex; align-items:center; gap:10px;
            color: var(--text-2); font-weight:600;
        }
        .sp-summary-label i { color: var(--gold); font-size:1rem; }
        .sp-summary-value {
            font-family:'Fraunces', Georgia, serif;
            font-size:1.05rem; font-weight:700;
            color: var(--brown);
        }
        .sp-summary-row.is-total .sp-summary-value { font-size:1.2rem; color: var(--gold-2); }
        .sp-summary-row.is-outstanding .sp-summary-value { color: var(--danger); }

        /* Role breakdown */
        .sp-role-bars { padding:22px; display:flex; flex-direction:column; gap:14px; }
        .sp-role-row { display:flex; flex-direction:column; gap:6px; }
        .sp-role-meta {
            display:flex; justify-content:space-between; align-items:baseline;
            font-size:.8rem; color: var(--text-2); font-weight:600;
        }
        .sp-role-meta strong { color: var(--brown); font-family:'Fraunces', Georgia, serif; font-size:1rem; }
        .sp-role-track {
            height:8px; border-radius:8px;
            background: var(--paper-2); overflow:hidden;
        }
        .sp-role-fill {
            height:100%; border-radius:8px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
            animation: spBarFill 1.3s cubic-bezier(.2,.7,.3,1) both;
            transform-origin:left;
        }
        .sp-role-fill.is-info    { background: linear-gradient(90deg, var(--info), #6A9CD9); }
        .sp-role-fill.is-success { background: linear-gradient(90deg, var(--success), #6FBF73); }

        /* Quick actions */
        .sp-quick {
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(190px,1fr));
            gap:14px;
        }
        .sp-quick-card {
            position:relative; overflow:hidden;
            padding:20px 20px 18px; border-radius:16px;
            background: linear-gradient(180deg, #fff, var(--cream-2));
            border:1px solid var(--line);
            text-decoration:none; color:inherit;
            display:flex; flex-direction:column; gap:12px;
            cursor:pointer; font-family:inherit; text-align:left;
            transition: transform .28s cubic-bezier(.2,.7,.3,1), border-color .28s, box-shadow .28s;
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset, 0 12px 26px -20px rgba(74,44,16,.38);
        }
        .sp-quick-card:hover {
            transform: translateY(-5px);
            border-color: rgba(176,100,30,.4);
            box-shadow: 0 24px 40px -22px rgba(74,44,16,.5);
            color:inherit;
        }
        .sp-quick-ico {
            width:44px; height:44px; border-radius:13px;
            display:grid; place-items:center;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            color: var(--gold); font-size:1.2rem;
            border:1px solid rgba(176,100,30,.2);
            transition: transform .35s cubic-bezier(.2,.7,.3,1);
        }
        .sp-quick-card:hover .sp-quick-ico { transform: scale(1.1) rotate(-8deg); }
        .sp-quick-title {
            font-family:'Fraunces', Georgia, serif;
            font-size:1rem; font-weight:600;
            color: var(--brown); margin:0; line-height:1.2;
        }
        .sp-quick-desc { font-size:.78rem; color: var(--text-3); margin:0; line-height:1.5; }
        .sp-quick-arrow {
            position:absolute; top:20px; right:20px;
            color: var(--line-3); font-size:.95rem;
            transition: transform .25s, color .25s;
        }
        .sp-quick-card:hover .sp-quick-arrow {
            color: var(--gold); transform: translate(3px,-3px);
        }

        /* Footnote */
        .sp-footnote {
            display:flex; align-items:center; justify-content:space-between;
            gap:16px; flex-wrap:wrap;
            padding:16px 22px; border-radius:16px;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            border:1px solid rgba(176,100,30,.22);
            font-size:.82rem; color: var(--text-2);
        }
        .sp-footnote-left {
            display:inline-flex; align-items:center; gap:12px; font-weight:500;
        }
        .sp-footnote-left i {
            width:34px; height:34px; border-radius:10px;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color:#fff; display:grid; place-items:center; font-size:1rem;
            box-shadow: 0 4px 10px -3px rgba(176,100,30,.6);
            flex-shrink: 0;
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

        .sp-sidebar-overlay {
            display:none; position:fixed; inset:0;
            background: rgba(42,30,16,.5); z-index:150;
            opacity:0; pointer-events:none;
            transition: opacity .25s;
        }

        /* ============================================================
           MOBILE BOTTOM NAVIGATION
           ============================================================ */
        .sp-bottom-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 300;
            background: rgba(253, 250, 241, .97);
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
            border-top: 1px solid var(--line);
            box-shadow: 0 -4px 20px -8px rgba(74, 44, 16, .25);
            padding: 6px 0 max(6px, env(safe-area-inset-bottom));
            justify-content: space-around;
            align-items: center;
            transition: transform .3s cubic-bezier(.2,.7,.3,1);
        }
        .sp-bottom-nav.is-hidden {
            transform: translateY(100%);
        }
        .sp-bottom-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            padding: 6px 12px;
            border-radius: 12px;
            text-decoration: none;
            color: var(--text-3);
            font-size: .6rem;
            font-weight: 600;
            letter-spacing: .2px;
            text-transform: uppercase;
            transition: color .2s, background .2s, transform .2s;
            position: relative;
            min-width: 56px;
            border: 0;
            background: none;
            cursor: pointer;
            font-family: inherit;
            -webkit-tap-highlight-color: transparent;
        }
        .sp-bottom-nav-item i {
            font-size: 1.2rem;
            transition: transform .2s, color .2s;
        }
        .sp-bottom-nav-item:hover,
        .sp-bottom-nav-item:active {
            color: var(--gold);
        }
        .sp-bottom-nav-item.is-active {
            color: var(--gold);
        }
        .sp-bottom-nav-item.is-active i {
            color: var(--gold);
            transform: translateY(-2px) scale(1.08);
        }
        .sp-bottom-nav-item.is-active::before {
            content: '';
            position: absolute;
            top: -6px;
            left: 50%;
            transform: translateX(-50%);
            width: 24px;
            height: 3px;
            border-radius: 0 0 3px 3px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
        }
        .sp-bottom-nav-item.is-active::after {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(232, 176, 90, .22), transparent 70%);
            pointer-events: none;
        }

        /* ============================================================
           MOBILE RESPONSIVE
           ============================================================ */

        /* ---- Tablet (≤900px) ---- */
        @media (max-width:900px) {
            .sp-sidebar { transform: translateX(-100%); box-shadow: 24px 0 60px -30px rgba(0,0,0,.7); }
            body.sp-sidebar-open .sp-sidebar { transform: translateX(0); }
            body.sp-sidebar-open .sp-sidebar-overlay { display:block; opacity:1; pointer-events:auto; }
            .sp-main { margin-left:0; }
            .sp-menu-btn { display:inline-flex; }
        }

        /* ---- Mobile (≤720px) — bottom nav visible ---- */
        @media (max-width: 720px) {
            .sp-content {
                padding: 16px;
                padding-bottom: calc(var(--bottom-nav-h) + 16px);
                gap: 16px;
            }
            .sp-bottom-nav { display: flex; }
            .sp-topbar { padding: 10px 16px; }
            .sp-page-title { font-size: 1.08rem; }
            .sp-page-sub { font-size: .7rem; }

            .sp-welcome { padding: 22px 18px; border-radius: 20px; }
            .sp-welcome-tag { font-size: .6rem; padding: 5px 11px; margin-bottom: 12px; }
            .sp-welcome h2 { font-size: 1.35rem; line-height: 1.2; }
            .sp-welcome p { font-size: .85rem; }
            .sp-welcome-actions { gap: 8px; margin-top: 18px; }
            .sp-welcome-btn { padding: 10px 16px; font-size: .82rem; }

            .sp-stat { min-height: 128px; padding: 14px; border-radius: 14px; }
            .sp-stat-ico { width: 36px; height: 36px; font-size: .95rem; border-radius: 10px; }
            .sp-stat-value { font-size: clamp(1.1rem, 3.2vw, 1.3rem); }
            .sp-stat-label { font-size: .58rem; letter-spacing: 1px; }
            .sp-stat-foot { font-size: .64rem; }

            .sp-quick { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; }
            .sp-quick-card { padding: 16px; gap: 10px; border-radius: 14px; }
            .sp-quick-ico { width: 40px; height: 40px; font-size: 1.05rem; border-radius: 11px; }
            .sp-quick-title { font-size: .95rem; }
            .sp-quick-desc { font-size: .74rem; }
            .sp-quick-arrow { top: 16px; right: 16px; font-size: .85rem; }

            .sp-panel { border-radius: 16px; }
            .sp-panel-head { padding: 14px 16px; gap: 10px; }
            .sp-panel-title { font-size: .95rem; gap: 9px; }
            .sp-panel-title i { width: 28px; height: 28px; font-size: .85rem; }
            .sp-panel-link { font-size: .74rem; }

            .sp-summary { padding: 16px; gap: 10px; }
            .sp-summary-row { padding: 11px 13px; font-size: .82rem; border-radius: 10px; }
            .sp-summary-value { font-size: .98rem; }

            .sp-role-bars { padding: 16px; gap: 12px; }
            .sp-role-meta { font-size: .76rem; }
            .sp-role-meta strong { font-size: .92rem; }

            .sp-pay-item { padding: 12px 16px; gap: 10px; }
            .sp-pay-item:hover { padding-left: 18px; }
            .sp-pay-ico { width: 36px; height: 36px; font-size: .95rem; border-radius: 10px; }
            .sp-pay-ref { font-size: .7rem; }
            .sp-pay-meta { font-size: .68rem; }
            .sp-pay-amount { font-size: .92rem; }

            .sp-footnote { padding: 14px 16px; font-size: .78rem; border-radius: 14px; }
            .sp-footnote-left i { width: 30px; height: 30px; font-size: .9rem; }
            .sp-footnote-right { font-size: .78rem; }
        }

        /* ---- Small phone (≤480px) ---- */
        @media (max-width: 480px) {
            .sp-topbar { padding: 10px 14px; }
            .sp-page-title { font-size: 1rem; }
            .sp-content { padding: 14px; padding-bottom: calc(var(--bottom-nav-h) + 14px); gap: 14px; }

            .sp-topbar-right { gap: 6px; }
            .sp-icon-btn { width: 36px; height: 36px; font-size: .92rem; border-radius: 9px; }
            .sp-icon-btn .badge-dot { top: 6px; right: 7px; width: 7px; height: 7px; }
            .sp-menu-btn { width: 36px; height: 36px; font-size: 1rem; }
            .sp-cta { padding: 9px 12px; font-size: .82rem; gap: 6px; }
            .sp-cta span { display: none; }

            .sp-welcome { padding: 20px 16px; border-radius: 18px; }
            .sp-welcome h2 { font-size: 1.2rem; }
            .sp-welcome p { font-size: .82rem; }
            .sp-welcome-btn { padding: 9px 14px; font-size: .8rem; }
            .sp-welcome-actions { flex-direction: column; align-items: stretch; }
            .sp-welcome-btn { justify-content: center; }

            /* Stats: single column with horizontal layout */
            .sp-stats { grid-template-columns: 1fr; gap: 10px; }
            .sp-stat {
                min-height: auto;
                padding: 14px;
                flex-direction: row;
                align-items: center;
                gap: 14px;
            }
            .sp-stat-head {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
                flex-shrink: 0;
            }
            .sp-stat > div:not(.sp-stat-head):not(.sp-stat-bar) {
                flex: 1;
                min-width: 0;
            }
            .sp-stat-label { margin-bottom: 3px; }
            .sp-stat-foot { margin-top: 6px; }
            .sp-stat-bar { margin-top: 8px; width: 100%; }

            .sp-quick { grid-template-columns: 1fr; gap: 10px; }

            .sp-panel-head { padding: 12px 14px; flex-wrap: wrap; }
            .sp-panel-title { font-size: .92rem; }

            /* Table → stacked cards */
            .sp-table-wrap { overflow: visible; }
            .sp-table { font-size: .82rem; }
            .sp-table thead { display: none; }
            .sp-table, .sp-table tbody, .sp-table tr, .sp-table td {
                display: block;
                width: 100%;
            }
            .sp-table tbody tr {
                padding: 12px 14px;
                border-bottom: 1px dashed var(--line-2);
                position: relative;
            }
            .sp-table tbody tr:last-child { border-bottom: 0; }
            .sp-table tbody tr:hover { background: var(--gold-soft); padding-left: 14px; }
            .sp-table tbody td {
                padding: 5px 0;
                border: 0;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                white-space: normal;
                text-align: right;
            }
            .sp-table tbody td::before {
                content: attr(data-label);
                font-size: .6rem;
                font-weight: 700;
                letter-spacing: 1px;
                text-transform: uppercase;
                color: var(--text-3);
                flex-shrink: 0;
                text-align: left;
            }

            .sp-summary { padding: 14px; gap: 8px; }
            .sp-summary-row { padding: 10px 12px; font-size: .78rem; flex-wrap: wrap; }
            .sp-summary-label i { font-size: .9rem; }
            .sp-summary-value { font-size: .92rem; }

            .sp-role-bars { padding: 14px; gap: 10px; }

            .sp-pay-item { padding: 11px 14px; gap: 9px; }
            .sp-pay-body { min-width: 0; }
            .sp-pay-ref, .sp-pay-meta {
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .sp-footnote { padding: 12px 14px; font-size: .74rem; }
            .sp-footnote-left { gap: 10px; }
            .sp-footnote-left i { width: 28px; height: 28px; font-size: .85rem; }

            .sp-bottom-nav-item { font-size: .55rem; padding: 5px 8px; min-width: 48px; }
            .sp-bottom-nav-item i { font-size: 1.1rem; }
        }

        /* ---- Very small phone (≤360px) ---- */
        @media (max-width: 360px) {
            .sp-content { padding: 12px; padding-bottom: calc(var(--bottom-nav-h) + 12px); gap: 12px; }
            .sp-topbar { padding: 8px 12px; }
            .sp-page-title { font-size: .95rem; }

            .sp-welcome { padding: 18px 14px; }
            .sp-welcome h2 { font-size: 1.1rem; }
            .sp-welcome p { font-size: .78rem; }
            .sp-welcome-btn { padding: 8px 12px; font-size: .76rem; }

            .sp-stat { padding: 12px; gap: 10px; }
            .sp-stat-ico { width: 32px; height: 32px; font-size: .85rem; }
            .sp-stat-value { font-size: 1.05rem; }

            .sp-panel-head { padding: 10px 12px; }
            .sp-panel-title { font-size: .88rem; gap: 8px; }
            .sp-panel-title i { width: 26px; height: 26px; font-size: .8rem; }

            .sp-quick-card { padding: 14px; gap: 8px; }
            .sp-quick-title { font-size: .88rem; }

            .sp-summary { padding: 12px; }
            .sp-summary-row { padding: 9px 10px; font-size: .74rem; }

            .sp-pay-item { padding: 10px 12px; }

            .sp-table tbody tr { padding: 10px 12px; }
            .sp-table tbody td { font-size: .78rem; }
            .sp-table tbody td::before { font-size: .56rem; }

            .sp-bottom-nav-item { font-size: .5rem; padding: 4px 6px; min-width: 42px; }
            .sp-bottom-nav-item i { font-size: 1rem; }
        }

        /* ---- Landscape phone (short height) ---- */
        @media (max-height: 560px) and (orientation: landscape) {
            .sp-topbar { padding: 8px 16px; }
            .sp-content {
                padding: 12px 16px;
                padding-bottom: calc(var(--bottom-nav-h) + 12px);
                gap: 12px;
            }
            .sp-welcome { padding: 18px 20px; border-radius: 18px; }
            .sp-welcome h2 { font-size: 1.2rem; }
            .sp-stats { grid-template-columns: repeat(3, 1fr); gap: 10px; }
            .sp-stat { min-height: 120px; padding: 12px; }
            .sp-quick { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }

            .sp-bottom-nav { padding: 4px 0 max(4px, env(safe-area-inset-bottom)); }
            .sp-bottom-nav-item { padding: 4px 10px; min-width: 52px; }
            .sp-bottom-nav-item i { font-size: 1rem; }
            .sp-bottom-nav-item span { font-size: .5rem; }
        }

        /* ---- Prevent iOS auto-zoom on inputs ---- */
        @media (max-width: 720px) {
            input, select, textarea, button { font-size: 16px; }
        }

        /* ---- Safe area insets ---- */
        @supports (padding: max(0px)) {
            .sp-topbar {
                padding-left: max(16px, env(safe-area-inset-left));
                padding-right: max(16px, env(safe-area-inset-right));
            }
            .sp-content {
                padding-bottom: max(16px, env(safe-area-inset-bottom));
            }
            .sp-sidebar {
                padding-top: env(safe-area-inset-top);
                padding-bottom: env(safe-area-inset-bottom);
            }
        }

        @media (max-width: 720px) and (prefers-reduced-motion: no-preference) {
            .sp-bottom-nav { transition: transform .3s cubic-bezier(.2,.7,.3,1); }
        }

        /* ---- Reduced motion ---- */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .001ms !important;
                transition-duration: .001ms !important;
            }
            .sp-bottom-nav { transition: none; }
        }

        /* ---- Print ---- */
        @media print {
            .sp-sidebar, .sp-topbar, .sp-sidebar-overlay, .sp-bottom-nav,
            .sp-quick, .sp-welcome-actions, .sp-footnote-right { display: none !important; }
            .sp-main { margin-left: 0 !important; }
            .sp-content { padding: 0 !important; }
            .sp-panel { box-shadow: none !important; border: 1px solid #ccc !important; }
            body.sp-dash { background: #fff !important; }
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
        <a href="<?= BASE_URL ?>admin/dashboard.php" class="sp-nav-link is-active">
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
                <h1 class="sp-page-title">Admin Dashboard</h1>
                <span class="sp-page-sub">Welcome back, <strong><?= $firstName ?></strong>!</span>
            </div>
        </div>

        <div class="sp-topbar-right">
            <button class="sp-icon-btn" id="notifBtn" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <span class="badge-dot"></span>
            </button>
            <a href="<?= BASE_URL ?>admin/users.php" class="sp-cta">
                <i class="bi bi-person-plus"></i>
                <span>Add User</span>
            </a>
        </div>
    </header>

    <main class="sp-content">

        <!-- Welcome -->
        <section class="sp-welcome">
            <div class="sp-welcome-text">
                <span class="sp-welcome-tag">
                    <i class="bi bi-shield-lock"></i>
                    Administrator
                </span>
                <h2>System overview, <em><?= $firstName ?></em>. Everything's under control.</h2>
                <p>
                    Manage users, monitor purchases and payments, and keep the
                    SmartPalay marketplace healthy — all from one place.
                </p>

                <div class="sp-welcome-actions">
                    <a href="<?= BASE_URL ?>admin/users.php" class="sp-welcome-btn is-primary">
                        <i class="bi bi-people"></i> Manage Users
                    </a>
                    <button type="button" class="sp-welcome-btn is-ghost" id="welcomeReportsBtn">
                        <i class="bi bi-graph-up-arrow"></i> View Reports
                    </button>
                </div>
            </div>

            <?php if ($smartPalayLogo): ?>
            <div class="sp-welcome-portrait" aria-hidden="true">
                <span class="sp-welcome-portrait-ring"></span>
                <div class="sp-welcome-portrait-inner">
                    <img src="<?= $smartPalayLogo ?>" alt="SmartPalay">
                </div>
            </div>
            <?php endif; ?>
        </section>

        <!-- STATS — single row of 6 -->
        <section class="sp-stats">
            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico"><i class="bi bi-people"></i></div>
                    <span class="sp-stat-trend is-up"><i class="bi bi-arrow-up-right"></i> Active</span>
                </div>
                <div>
                    <div class="sp-stat-label">Total Users</div>
                    <div class="sp-stat-value"><?= number_format($stats['total_users']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-person-check"></i> All roles</div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-success"><i class="bi bi-person-badge"></i></div>
                </div>
                <div>
                    <div class="sp-stat-label">Sellers</div>
                    <div class="sp-stat-value"><?= number_format($stats['total_farmers']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-tree"></i> Palay producers</div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-info"><i class="bi bi-bag-check"></i></div>
                </div>
                <div>
                    <div class="sp-stat-label">Buyers</div>
                    <div class="sp-stat-value"><?= number_format($stats['total_buyers']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-shop"></i> Purchasing accounts</div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-warn"><i class="bi bi-receipt"></i></div>
                    <span class="sp-stat-trend is-flat"><i class="bi bi-dot"></i> All time</span>
                </div>
                <div>
                    <div class="sp-stat-label">Total Purchases</div>
                    <div class="sp-stat-value"><?= number_format($stats['total_purchases']) ?></div>
                </div>
                <div class="sp-stat-foot">
                    <i class="bi bi-box-seam"></i>
                    <?= number_format($stats['total_palay_kg'], 0) ?> kg palay
                </div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-success"><i class="bi bi-cash-stack"></i></div>
                    <span class="sp-stat-trend is-up"><i class="bi bi-check2-circle"></i> Settled</span>
                </div>
                <div>
                    <div class="sp-stat-label">Total Paid</div>
                    <div class="sp-stat-value"><?= peso($stats['total_paid']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-check-circle"></i> Across all purchases</div>
                <div class="sp-stat-bar is-success">
                    <span style="width: <?= min(100, $paidPct) ?>%"></span>
                </div>
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
                <div class="sp-stat-bar is-danger">
                    <span style="width: <?= min(100, $stats['total_amount'] > 0 ? 100 - $paidPct : 0) ?>%"></span>
                </div>
            </article>
        </section>

        <!-- Quick actions -->
        <section class="sp-quick">
            <a href="<?= BASE_URL ?>admin/users.php" class="sp-quick-card">
                <div class="sp-quick-ico"><i class="bi bi-people"></i></div>
                <h4 class="sp-quick-title">Manage Users</h4>
                <p class="sp-quick-desc">View, edit, and moderate all accounts.</p>
                <i class="bi bi-arrow-up-right sp-quick-arrow"></i>
            </a>
            <a href="<?= BASE_URL ?>admin/purchases.php" class="sp-quick-card">
                <div class="sp-quick-ico"><i class="bi bi-receipt"></i></div>
                <h4 class="sp-quick-title">All Purchases</h4>
                <p class="sp-quick-desc">Audit every palay transaction in the system.</p>
                <i class="bi bi-arrow-up-right sp-quick-arrow"></i>
            </a>
            <a href="<?= BASE_URL ?>admin/payments.php" class="sp-quick-card">
                <div class="sp-quick-ico"><i class="bi bi-cash-coin"></i></div>
                <h4 class="sp-quick-title">Payments</h4>
                <p class="sp-quick-desc">Track settlements and reconcile balances.</p>
                <i class="bi bi-arrow-up-right sp-quick-arrow"></i>
            </a>
            <a href="<?= BASE_URL ?>admin/reports.php" class="sp-quick-card">
                <div class="sp-quick-ico"><i class="bi bi-graph-up-arrow"></i></div>
                <h4 class="sp-quick-title">System Reports</h4>
                <p class="sp-quick-desc">Generate analytics and export data.</p>
                <i class="bi bi-arrow-up-right sp-quick-arrow"></i>
            </a>
        </section>

        <!-- Grid -->
        <section class="sp-grid">

            <div class="sp-panel">
                <div class="sp-panel-head">
                    <h3 class="sp-panel-title">
                        <i class="bi bi-clock-history"></i>
                        Recent Purchases (All Users)
                    </h3>
                    <a href="<?= BASE_URL ?>admin/purchases.php" class="sp-panel-link">
                        View all <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <?php if (!empty($recentPurchases)): ?>
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Buyer</th>
                                    <th>Seller</th>
                                    <th>Weight</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentPurchases as $p): ?>
                                    <?php
                                        $st = strtolower($p['status'] ?? 'pending');
                                        $badge = 'sp-badge-pending';
                                        $ico   = 'bi-hourglass-split';
                                        $label = ucfirst($st);
                                        if ($st === 'paid')    { $badge = 'sp-badge-paid';    $ico = 'bi-check-circle-fill'; $label = 'Paid'; }
                                        if ($st === 'partial') { $badge = 'sp-badge-partial'; $ico = 'bi-circle-half';        $label = 'Partial'; }
                                        if ($st === 'unpaid')  { $badge = 'sp-badge-unpaid';  $ico = 'bi-x-circle-fill';     $label = 'Unpaid'; }
                                    ?>
                                    <tr>
                                        <td data-label="Reference"><span class="sp-ref"><?= sanitize($p['reference_no'] ?? '—') ?></span></td>
                                        <td data-label="Buyer"><?= sanitize($p['buyer_name']  ?? '—') ?></td>
                                        <td data-label="Seller"><?= sanitize($p['seller_name'] ?? '—') ?></td>
                                        <td data-label="Weight"><?= kg($p['weight_kg'] ?? 0) ?></td>
                                        <td data-label="Amount"><strong><?= peso($p['total_amount'] ?? 0) ?></strong></td>
                                        <td data-label="Status"><span class="sp-badge <?= $badge ?>"><i class="bi <?= $ico ?>"></i> <?= $label ?></span></td>
                                        <td data-label="Date"><?= niceDate($p['created_at'] ?? null) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="sp-empty">
                        <div class="sp-empty-ico"><i class="bi bi-receipt"></i></div>
                        <h4>No purchases in the system yet</h4>
                        <p>Once buyers start recording transactions, they'll appear here.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display:flex; flex-direction:column; gap: clamp(14px,1.8vw,22px);">

                <!-- Role breakdown -->
                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title">
                            <i class="bi bi-pie-chart"></i>
                            User Breakdown
                        </h3>
                    </div>
                    <div class="sp-role-bars">
                        <?php
                            $totalU = max(1, $stats['total_users']);
                            $rows = [
                                'admin'  => ['label'=>'Administrators','class'=>'','ico'=>'bi-shield-lock'],
                                'buyer'  => ['label'=>'Buyers',        'class'=>'is-info','ico'=>'bi-bag-check'],
                                'farmer' => ['label'=>'Sellers',       'class'=>'is-success','ico'=>'bi-person-badge'],
                            ];
                            foreach ($rows as $role => $meta):
                                $count = (int) ($roleBreakdown[$role] ?? 0);
                                $pct   = ($count / $totalU) * 100;
                        ?>
                            <div class="sp-role-row">
                                <div class="sp-role-meta">
                                    <span><i class="bi <?= $meta['ico'] ?> me-1"></i><?= $meta['label'] ?></span>
                                    <strong><?= number_format($count) ?></strong>
                                </div>
                                <div class="sp-role-track">
                                    <div class="sp-role-fill <?= $meta['class'] ?>" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Financial summary -->
                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title">
                            <i class="bi bi-wallet2"></i>
                            Financial Summary
                        </h3>
                    </div>
                    <div class="sp-summary">
                        <div class="sp-summary-row">
                            <span class="sp-summary-label"><i class="bi bi-box-seam"></i> Total Palay</span>
                            <span class="sp-summary-value"><?= number_format($stats['total_palay_kg'], 0) ?> kg</span>
                        </div>
                        <div class="sp-summary-row">
                            <span class="sp-summary-label"><i class="bi bi-cash-stack"></i> Gross Amount</span>
                            <span class="sp-summary-value"><?= peso($stats['total_amount']) ?></span>
                        </div>
                        <div class="sp-summary-row">
                            <span class="sp-summary-label"><i class="bi bi-check-circle"></i> Total Paid</span>
                            <span class="sp-summary-value"><?= peso($stats['total_paid']) ?></span>
                        </div>
                        <div class="sp-summary-row is-outstanding">
                            <span class="sp-summary-label"><i class="bi bi-hourglass-split"></i> Outstanding</span>
                            <span class="sp-summary-value"><?= peso($stats['outstanding']) ?></span>
                        </div>
                        <div class="sp-summary-row is-total">
                            <span class="sp-summary-label"><i class="bi bi-calculator"></i> Collected %</span>
                            <span class="sp-summary-value"><?= number_format($paidPct, 1) ?>%</span>
                        </div>
                    </div>
                </div>

                <!-- Recent users -->
                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title">
                            <i class="bi bi-person-plus"></i>
                            Newest Users
                        </h3>
                        <a href="<?= BASE_URL ?>admin/users.php" class="sp-panel-link">
                            View all <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <?php if (!empty($recentUsers)): ?>
                        <div class="sp-pay-list">
                            <?php foreach ($recentUsers as $u): ?>
                                <?php
                                    $role = strtolower($u['role'] ?? 'buyer');
                                    $roleCls = 'sp-role-buyer';
                                    $roleLbl = 'Buyer';
                                    if ($role === 'admin')  { $roleCls = 'sp-role-admin';  $roleLbl = 'Admin'; }
                                    if ($role === 'farmer') { $roleCls = 'sp-role-farmer'; $roleLbl = 'Seller'; }
                                ?>
                                <div class="sp-pay-item">
                                    <div class="sp-pay-ico" style="background: var(--gold-soft); color: var(--gold); border-color: rgba(176,100,30,.2);">
                                        <i class="bi bi-person"></i>
                                    </div>
                                    <div class="sp-pay-body">
                                        <p class="sp-pay-ref" style="font-family: inherit; font-size: .85rem; color: var(--brown);">
                                            <?= sanitize($u['full_name'] ?? '—') ?>
                                        </p>
                                        <span class="sp-pay-meta">
                                            <?= sanitize($u['email'] ?? '') ?> ·
                                            <?= niceDate($u['created_at'] ?? null) ?>
                                        </span>
                                    </div>
                                    <span class="sp-role <?= $roleCls ?>"><?= $roleLbl ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="sp-empty" style="padding:32px 24px;">
                            <div class="sp-empty-ico" style="width:54px;height:54px;font-size:1.35rem;">
                                <i class="bi bi-people"></i>
                            </div>
                            <h4>No users yet</h4>
                            <p style="margin-bottom:0;">Registered users will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent payments -->
                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title">
                            <i class="bi bi-cash-coin"></i>
                            Recent Payments
                        </h3>
                        <a href="<?= BASE_URL ?>admin/payments.php" class="sp-panel-link">
                            View all <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <?php if (!empty($recentPayments)): ?>
                        <div class="sp-pay-list">
                            <?php foreach ($recentPayments as $pay): ?>
                                <div class="sp-pay-item">
                                    <div class="sp-pay-ico"><i class="bi bi-check-lg"></i></div>
                                    <div class="sp-pay-body">
                                        <p class="sp-pay-ref"><?= sanitize($pay['reference_no'] ?? '—') ?></p>
                                        <span class="sp-pay-meta">
                                            <?= sanitize($pay['buyer_name'] ?? '') ?> ·
                                            <?= sanitize(ucfirst($pay['method'] ?? 'Cash')) ?> ·
                                            <?= niceDate($pay['paid_at'] ?? null) ?>
                                        </span>
                                    </div>
                                    <div class="sp-pay-amount"><?= peso($pay['amount'] ?? 0) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="sp-empty" style="padding:32px 24px;">
                            <div class="sp-empty-ico" style="width:54px;height:54px;font-size:1.35rem;">
                                <i class="bi bi-receipt"></i>
                            </div>
                            <h4>No payments recorded</h4>
                            <p style="margin-bottom:0;">Payments will appear here as they're made.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </section>

        <div class="sp-footnote">
            <span class="sp-footnote-left">
                <i class="bi bi-shield-check"></i>
                <span>
                    <strong>Admin tip:</strong> Review outstanding balances weekly and follow up with buyers.
                </span>
            </span>
            <a href="<?= BASE_URL ?>admin/reports.php" class="sp-footnote-right">
                Open reports <i class="bi bi-arrow-right"></i>
            </a>
        </div>

    </main>
</div>

<!-- ============================================================
     MOBILE BOTTOM NAVIGATION
     ============================================================ -->
<nav class="sp-bottom-nav" id="bottomNav" aria-label="Mobile navigation">
    <a href="<?= BASE_URL ?>admin/dashboard.php" class="sp-bottom-nav-item is-active">
        <i class="bi bi-speedometer2"></i>
        <span>Home</span>
    </a>
    <a href="<?= BASE_URL ?>admin/users.php" class="sp-bottom-nav-item">
        <i class="bi bi-people"></i>
        <span>Users</span>
    </a>
    <a href="<?= BASE_URL ?>admin/purchases.php" class="sp-bottom-nav-item">
        <i class="bi bi-receipt"></i>
        <span>Orders</span>
    </a>
    <a href="<?= BASE_URL ?>admin/payments.php" class="sp-bottom-nav-item">
        <i class="bi bi-cash-coin"></i>
        <span>Pay</span>
    </a>
    <a href="<?= BASE_URL ?>admin/reports.php" class="sp-bottom-nav-item">
        <i class="bi bi-graph-up-arrow"></i>
        <span>Reports</span>
    </a>
</nav>

<script>
(() => {
    'use strict';

    const body      = document.body;
    const menuBtn   = document.getElementById('menuBtn');
    const overlay   = document.getElementById('sidebarOverlay');
    const bottomNav = document.getElementById('bottomNav');

    menuBtn?.addEventListener('click', () => body.classList.add('sp-sidebar-open'));
    overlay?.addEventListener('click', () => body.classList.remove('sp-sidebar-open'));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') body.classList.remove('sp-sidebar-open'); });
    window.addEventListener('resize', () => { if (window.innerWidth > 900) body.classList.remove('sp-sidebar-open'); });

    /* ---------- Hide bottom nav on scroll down, show on scroll up ---------- */
    let lastScrollY = window.scrollY;
    let ticking = false;

    function handleScroll() {
        const currentY = window.scrollY;
        const diff = currentY - lastScrollY;

        if (window.innerWidth <= 720) {
            if (diff > 10 && currentY > 150) {
                bottomNav?.classList.add('is-hidden');
            } else if (diff < -10 || currentY <= 150) {
                bottomNav?.classList.remove('is-hidden');
            }
        } else {
            bottomNav?.classList.remove('is-hidden');
        }

        lastScrollY = currentY;
        ticking = false;
    }

    window.addEventListener('scroll', () => {
        if (!ticking) {
            window.requestAnimationFrame(handleScroll);
            ticking = true;
        }
    }, { passive: true });

    document.getElementById('welcomeReportsBtn')?.addEventListener('click', () => {
        window.location.href = '<?= BASE_URL ?>admin/reports.php';
    });
})();
</script>
</body>
</html>