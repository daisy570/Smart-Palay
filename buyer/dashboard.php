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

/* ============================================================
   HANDLE "NEW PURCHASE" POST
   ============================================================ */
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_purchase') {
    try {
        $seller   = trim($_POST['seller_name'] ?? '');
        $weight   = (float) ($_POST['weight_kg'] ?? 0);
        $price    = (float) ($_POST['price_per_kg'] ?? 0);
        $paid     = (float) ($_POST['amount_paid'] ?? 0);
        $date     = trim($_POST['created_at'] ?? '');
        $notes    = trim($_POST['notes'] ?? '');

        if ($seller === '')   throw new Exception('Please enter or select a seller.');
        if ($weight <= 0)     throw new Exception('Please enter a valid weight.');
        if ($price <= 0)      throw new Exception('Please enter a valid price per kg.');

        if ($date === '') {
            $date = date('Y-m-d');
        }

        $total   = $weight * $price;
        $balance = max(0, $total - $paid);

        if ($balance <= 0)      $status = 'paid';
        elseif ($paid > 0)      $status = 'partial';
        else                    $status = 'unpaid';

        $ref = 'PUR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        $stmt = $pdo->prepare("
            INSERT INTO purchases
                (reference_no, buyer_id, seller_name, weight_kg, price_per_kg,
                 total_amount, balance, status, created_at, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ref, $userId, $seller, $weight, $price,
            $total, $balance, $status, $date . ' ' . date('H:i:s'), $notes
        ]);

        $flash = ['type' => 'success', 'msg' => "Purchase {$ref} recorded."];
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    }
}

/* ============================================================
   LOAD STATS + RECENT DATA
   ============================================================ */
$stats = [
    'total_purchases'     => 0,
    'total_palay_kg'      => 0,
    'total_paid'          => 0,
    'outstanding_balance' => 0,
];
$recentPurchases = [];
$recentPayments  = [];
$sellersList     = [];
$totalOwed       = 0;

try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                         AS total_purchases,
            COALESCE(SUM(weight_kg), 0)      AS total_palay_kg,
            COALESCE(SUM(total_amount - balance), 0) AS total_paid,
            COALESCE(SUM(balance), 0)        AS outstanding_balance
        FROM purchases
        WHERE buyer_id = ?
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $stats['total_purchases']     = (int) $row['total_purchases'];
        $stats['total_palay_kg']      = (float) $row['total_palay_kg'];
        $stats['total_paid']          = (float) $row['total_paid'];
        $stats['outstanding_balance'] = (float) $row['outstanding_balance'];
    }
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT id, reference_no, seller_name, weight_kg, total_amount, balance, status, created_at
        FROM purchases
        WHERE buyer_id = ?
        ORDER BY created_at DESC
        LIMIT 6
    ");
    $stmt->execute([$userId]);
    $recentPurchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $recentPurchases = []; }

try {
    $stmt = $pdo->prepare("
        SELECT id, reference_no, amount, method, paid_at
        FROM payments
        WHERE buyer_id = ?
        ORDER BY paid_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $recentPayments = []; }

try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT seller_name AS name
        FROM purchases
        WHERE buyer_id = ? AND seller_name IS NOT NULL AND seller_name <> ''
        ORDER BY seller_name ASC
    ");
    $stmt->execute([$userId]);
    $sellersList = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $sellersList = []; }

$totalOwed = $stats['total_paid'] + $stats['outstanding_balance'];
$paidPct   = $totalOwed > 0 ? ($stats['total_paid'] / $totalOwed) * 100 : 0;

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#B0641E">
    <title>Buyer Dashboard | SmartPalay</title>

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
            --gold:        #B0641E;
            --gold-2:      #C97628;
            --gold-3:      #E8B05A;
            --gold-4:      #F4C87A;
            --gold-soft:   #FBF1DC;
            --brown:       #4A2C10;
            --brown-2:     #6B4423;
            --brown-3:     #8A5A30;
            --cream:       #FBF6EA;
            --cream-2:     #FDFAF1;
            --paper:       #FAF3E5;
            --paper-2:     #F2E7D0;
            --line:        #EADFC8;
            --line-2:      #DDCDA8;
            --line-3:      #C9B68A;
            --ink:         #2A1E10;
            --text:        #3A2A18;
            --text-2:      #6B5A44;
            --text-3:      #96856E;
            --success:     #2E7D32;
            --success-bg:  #EAF7EC;
            --success-bd:  #BEE0C2;
            --warn:        #7A5A0F;
            --warn-bg:     #FCF3D6;
            --warn-bd:     #EBD79A;
            --danger:      #A23A1A;
            --danger-bg:   #FBEDE6;
            --danger-bd:   #EFC6B0;
            --info:        #1E5FA8;
            --info-bg:     #E8F0FA;
            --sidebar-w:   260px;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }

        body.sp-dash {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 100% 0%, rgba(232, 176, 90, .14), transparent 42%),
                radial-gradient(circle at 0% 100%, rgba(184, 92, 46, .08), transparent 42%),
                var(--cream-2);
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
        }

        /* Sidebar */
        .sp-sidebar {
            position: fixed; top: 0; left: 0; bottom: 0;
            width: var(--sidebar-w); z-index: 200;
            display: flex; flex-direction: column;
            background:
                radial-gradient(circle at 20% 10%, rgba(232, 176, 90, .2), transparent 55%),
                radial-gradient(circle at 80% 90%, rgba(184, 92, 46, .14), transparent 55%),
                linear-gradient(180deg, #4A2C10 0%, #2A1E10 100%);
            color: #fff;
            border-right: 1px solid rgba(232, 176, 90, .18);
            overflow: hidden;
            transition: transform .3s cubic-bezier(.2,.7,.3,1);
        }
        .sp-sidebar::before {
            content: ''; position: absolute; inset: 0; pointer-events: none;
            background-image:
                repeating-linear-gradient(92deg, transparent 0px, transparent 22px, rgba(232, 176, 90, .04) 22px, rgba(232, 176, 90, .04) 24px),
                repeating-linear-gradient(88deg, transparent 0px, transparent 34px, rgba(232, 176, 90, .03) 34px, rgba(232, 176, 90, .03) 37px);
        }
        .sp-sidebar > * { position: relative; z-index: 1; }

        .sp-sidebar-brand {
            position: relative;
            display: flex; align-items: center; justify-content: center;
            padding: 26px 20px 22px;
            text-decoration: none; color: inherit;
            border-bottom: 1px solid rgba(232, 176, 90, .15);
            flex-shrink: 0; text-align: center;
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
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.4rem; font-weight: 700;
            letter-spacing: -.5px; margin: 0; line-height: 1; color: #fff;
        }
        .sp-sidebar-brand-name span { color: var(--gold-3); transition: color .25s ease; }
        .sp-sidebar-brand:hover .sp-sidebar-brand-name span { color: var(--gold-4); }

        .sp-sidebar-user {
            padding: 22px 22px 12px;
            border-bottom: 1px solid rgba(232, 176, 90, .12);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; position: relative;
        }
        .sp-sidebar-user::before {
            content: ''; position: absolute;
            width: 140px; height: 140px; border-radius: 50%;
            background: radial-gradient(circle, rgba(232, 176, 90, .28), transparent 65%);
            pointer-events: none;
        }
        .sp-user-avatar {
            position: relative; width: 82px; height: 82px;
            border-radius: 50%; flex-shrink: 0; padding: 3px;
            background: linear-gradient(135deg, #F4C87A 0%, #C97628 55%, #8A5A30 100%);
            box-shadow:
                0 0 0 1px rgba(74, 44, 16, .35),
                0 10px 24px -8px rgba(0, 0, 0, .8),
                0 4px 10px -3px rgba(0, 0, 0, .5);
            transition: transform .3s ease, box-shadow .3s ease;
        }
        .sp-user-avatar:hover {
            transform: translateY(-3px) scale(1.03);
            box-shadow:
                0 0 0 1px rgba(74, 44, 16, .4),
                0 16px 30px -8px rgba(0, 0, 0, .85),
                0 6px 14px -3px rgba(0, 0, 0, .55);
        }
        .sp-user-avatar::after {
            content: ''; position: absolute; inset: -6px;
            border-radius: 50%;
            border: 1px solid rgba(232, 176, 90, .35);
            animation: spAvatarPulse 3s ease-in-out infinite;
            pointer-events: none;
        }
        @keyframes spAvatarPulse {
            0%, 100% { transform: scale(1); opacity: .6; }
            50%      { transform: scale(1.08); opacity: 0; }
        }
        .sp-user-avatar-img {
            width: 100%; height: 100%;
            border-radius: 50%; overflow: hidden;
            background: #FDFAF1;
            display: grid; place-items: center;
        }
        .sp-user-avatar-img img {
            width: 100%; height: 100%;
            object-fit: cover; object-position: center;
            display: block; border-radius: 50%;
        }

        /* Role chip under avatar */
        .sp-sidebar-role {
            display: flex; align-items: center; justify-content: center;
            padding: 0 22px 18px;
            margin-top: -4px;
            border-bottom: 1px solid rgba(232, 176, 90, .12);
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
            padding: 16px 12px;
            display: flex; flex-direction: column; gap: 3px;
            flex: 1 1 auto; min-height: 0;
            overflow-y: auto; overflow-x: hidden;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: rgba(232, 176, 90, .35) transparent;
        }
        .sp-nav::-webkit-scrollbar { width: 6px; }
        .sp-nav::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, rgba(232, 176, 90, .45), rgba(176, 100, 30, .35));
            border-radius: 4px; border: 1.5px solid transparent;
            background-clip: content-box;
        }
        .sp-nav::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, rgba(232, 176, 90, .75), rgba(176, 100, 30, .65));
            background-clip: content-box;
        }
        .sp-nav-label {
            padding: 12px 12px 6px;
            font-size: .64rem; font-weight: 700;
            letter-spacing: 1.4px; text-transform: uppercase;
            color: rgba(255, 255, 255, .42);
        }
        .sp-nav-link {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 14px; border-radius: 10px;
            color: rgba(255, 255, 255, .82);
            text-decoration: none;
            font-size: .87rem; font-weight: 500;
            transition: background .18s ease, color .18s ease, transform .18s ease;
            position: relative; flex-shrink: 0;
        }
        .sp-nav-link i {
            font-size: 1.05rem; width: 20px; text-align: center;
            color: rgba(255, 255, 255, .58);
            transition: color .18s ease;
        }
        .sp-nav-link:hover {
            background: rgba(232, 176, 90, .12);
            color: #fff; transform: translateX(2px);
        }
        .sp-nav-link:hover i { color: var(--gold-3); }

        /* Hover dot */
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
            background: linear-gradient(135deg, rgba(232, 176, 90, .28), rgba(176, 100, 30, .18));
            color: #fff; font-weight: 600;
            box-shadow: inset 0 0 0 1px rgba(232, 176, 90, .3);
            animation: spNavGlow 4s ease-in-out infinite;
        }
        @keyframes spNavGlow {
            0%, 100% { box-shadow: inset 0 0 0 1px rgba(232, 176, 90, .3); }
            50%      { box-shadow: inset 0 0 0 1px rgba(232, 176, 90, .5), 0 0 18px -6px rgba(232, 176, 90, .4); }
        }
        .sp-nav-link.is-active i { color: var(--gold-3); }
        .sp-nav-link.is-active::before {
            content: ''; position: absolute; left: 0;
            top: 22%; bottom: 22%; width: 3px;
            border-radius: 0 3px 3px 0;
            background: var(--gold-3);
        }
        .sp-nav-link.is-active::after { display: none; }

        .sp-sidebar-foot {
            padding: 14px 12px 18px;
            border-top: 1px solid rgba(232, 176, 90, .12);
            flex-shrink: 0;
        }
        .sp-nav-link.sp-logout { color: rgba(255, 200, 180, .92); }
        .sp-nav-link.sp-logout:hover {
            background: rgba(162, 58, 26, .25); color: #fff;
        }
        .sp-nav-link.sp-logout:hover i {
            color: #FFB199;
            transform: translateX(2px);
            transition: transform .2s ease;
        }
        .sp-sidebar-tag {
            padding: 10px 22px 0;
            text-align: center;
            font-size: .62rem;
            color: rgba(255, 255, 255, .32);
            font-weight: 500;
            letter-spacing: .4px;
        }

        /* Main */
        .sp-main {
            flex: 1; min-width: 0;
            margin-left: var(--sidebar-w);
            display: flex; flex-direction: column;
        }

        .sp-topbar {
            position: sticky; top: 0; z-index: 100;
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px;
            padding: 14px clamp(20px, 3vw, 38px);
            background: rgba(253, 250, 241, .88);
            backdrop-filter: blur(16px) saturate(150%);
            -webkit-backdrop-filter: blur(16px) saturate(150%);
            border-bottom: 1px solid var(--line);
        }
        .sp-topbar-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .sp-menu-btn {
            display: none;
            width: 40px; height: 40px;
            border: 1.5px solid var(--line-2);
            background: #fff; border-radius: 10px;
            color: var(--brown-2); font-size: 1.1rem;
            cursor: pointer;
            align-items: center; justify-content: center;
            transition: border-color .18s ease, color .18s ease;
        }
        .sp-menu-btn:hover { border-color: var(--gold); color: var(--gold); }
        .sp-page-title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(1.15rem, 1.9vw, 1.4rem);
            font-weight: 700; letter-spacing: -.4px;
            color: var(--brown); margin: 0; line-height: 1.2;
        }
        .sp-page-sub {
            display: block; font-size: .74rem; font-weight: 500;
            color: var(--text-3); margin-top: 2px; letter-spacing: .2px;
        }
        .sp-page-sub strong { color: var(--gold); font-weight: 700; }

        .sp-topbar-right { display: flex; align-items: center; gap: 10px; }
        .sp-icon-btn {
            position: relative;
            width: 40px; height: 40px;
            border: 1.5px solid var(--line-2);
            background: #fff; border-radius: 10px;
            color: var(--brown-2); font-size: 1rem;
            cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            transition: border-color .18s ease, color .18s ease, transform .18s ease;
        }
        .sp-icon-btn:hover {
            border-color: var(--gold); color: var(--gold);
            transform: translateY(-1px);
        }
        .sp-icon-btn .badge-dot {
            position: absolute; top: 8px; right: 9px;
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--danger);
            box-shadow: 0 0 0 2px #fff;
        }
        .sp-cta {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; border-radius: 10px;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color: #fff; font-weight: 700; font-size: .85rem;
            text-decoration: none;
            border: 0; cursor: pointer; font-family: inherit;
            box-shadow: 0 3px 0 rgba(0,0,0,.12), 0 12px 24px -12px rgba(176,100,30,.7);
            transition: transform .15s ease, filter .15s ease, box-shadow .15s ease;
        }
        .sp-cta:hover {
            color: #fff; transform: translateY(-1px);
            filter: brightness(1.04);
            box-shadow: 0 5px 0 rgba(0,0,0,.12), 0 16px 30px -12px rgba(176,100,30,.8);
        }
        .sp-cta i { font-size: 1rem; }

        .sp-content {
            padding: clamp(20px, 3vw, 38px);
            display: flex; flex-direction: column;
            gap: clamp(18px, 2.4vh, 26px);
        }

        /* Flash */
        .sp-flash {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 18px;
            border-radius: 14px;
            font-size: .88rem; font-weight: 500;
            border: 1px solid;
            animation: spFlashIn .35s ease both;
        }
        @keyframes spFlashIn {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .sp-flash.is-success { background: var(--success-bg); border-color: var(--success-bd); color: var(--success); }
        .sp-flash.is-error   { background: var(--danger-bg);  border-color: var(--danger-bd);  color: var(--danger); }
        .sp-flash i { font-size: 1.2rem; }

        /* Hero welcome */
        .sp-welcome {
            position: relative; overflow: hidden;
            padding: clamp(28px, 3.6vh, 42px) clamp(24px, 3.4vw, 44px);
            border-radius: 24px;
            background:
                radial-gradient(circle at 82% 18%, rgba(232, 176, 90, .38), transparent 55%),
                radial-gradient(circle at 10% 90%, rgba(184, 92, 46, .22), transparent 50%),
                linear-gradient(135deg, #4A2C10 0%, #2A1E10 100%);
            color: #fff;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 32px;
            align-items: center;
            box-shadow:
                0 24px 60px -28px rgba(40, 22, 6, .7),
                0 1px 0 rgba(255,255,255,.06) inset;
        }
        .sp-welcome::before {
            content: ''; position: absolute; inset: 0;
            pointer-events: none;
            background-image:
                repeating-linear-gradient(92deg, transparent 0px, transparent 22px, rgba(232, 176, 90, .06) 22px, rgba(232, 176, 90, .06) 24px),
                repeating-linear-gradient(88deg, transparent 0px, transparent 34px, rgba(232, 176, 90, .04) 34px, rgba(232, 176, 90, .04) 37px);
        }
        .sp-welcome::after {
            content: ''; position: absolute;
            top: -40px; right: -40px;
            width: 220px; height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(232, 176, 90, .18), transparent 60%);
            pointer-events: none;
        }
        .sp-welcome > * { position: relative; z-index: 1; }
        .sp-welcome-text { min-width: 0; }

        .sp-welcome-tag {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 6px 13px; border-radius: 999px;
            background: rgba(232, 176, 90, .16);
            border: 1px solid rgba(232, 176, 90, .35);
            font-size: .66rem; font-weight: 700;
            letter-spacing: 1.4px; text-transform: uppercase;
            color: var(--gold-3); margin-bottom: 16px;
        }
        .sp-welcome h2 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(1.55rem, 2.6vw, 2.2rem);
            font-weight: 600; line-height: 1.15;
            letter-spacing: -.6px; margin: 0 0 12px;
        }
        .sp-welcome h2 em {
            font-style: italic; font-weight: 500; color: var(--gold-3);
        }
        .sp-welcome p {
            font-size: .93rem; color: rgba(255, 255, 255, .78);
            line-height: 1.65; margin: 0; max-width: 54ch;
        }
        .sp-welcome-actions {
            display: flex; flex-wrap: wrap; gap: 10px;
            margin-top: 22px;
        }
        .sp-welcome-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 20px;
            border-radius: 10px;
            font-size: .85rem; font-weight: 700;
            text-decoration: none;
            border: 0; cursor: pointer; font-family: inherit;
            transition: transform .15s ease, filter .15s ease, box-shadow .15s ease;
        }
        .sp-welcome-btn.is-primary {
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color: #fff;
            box-shadow: 0 3px 0 rgba(0,0,0,.18), 0 14px 26px -12px rgba(176,100,30,.85);
        }
        .sp-welcome-btn.is-primary:hover {
            color: #fff; transform: translateY(-2px);
            filter: brightness(1.06);
            box-shadow: 0 6px 0 rgba(0,0,0,.18), 0 20px 34px -14px rgba(176,100,30,.9);
        }
        .sp-welcome-btn.is-ghost {
            background: rgba(255, 255, 255, .08);
            color: rgba(255, 255, 255, .92);
            border: 1px solid rgba(255, 255, 255, .2);
        }
        .sp-welcome-btn.is-ghost:hover {
            background: rgba(255, 255, 255, .16);
            color: #fff; transform: translateY(-2px);
        }

        .sp-welcome-portrait {
            position: relative;
            width: 160px; height: 160px;
            flex-shrink: 0;
            display: grid; place-items: center;
        }
        .sp-welcome-portrait-ring {
            position: absolute; inset: 0;
            border-radius: 50%;
            background: conic-gradient(
                from 220deg,
                rgba(232, 176, 90, .8),
                rgba(232, 176, 90, .1) 55%,
                rgba(232, 176, 90, .6)
            );
            -webkit-mask: radial-gradient(circle, transparent 62%, #000 63%);
            mask: radial-gradient(circle, transparent 62%, #000 63%);
            animation: spRingSpin 18s linear infinite;
        }
        @keyframes spRingSpin { to { transform: rotate(360deg); } }
        .sp-welcome-portrait-inner {
            width: 124px; height: 124px;
            border-radius: 50%;
            overflow: hidden;
            background: #FDFAF1;
            box-shadow:
                0 0 0 4px rgba(74, 44, 16, .5),
                0 14px 34px -12px rgba(0, 0, 0, .8);
            display: grid; place-items: center;
        }
        .sp-welcome-portrait-inner img {
            width: 100%; height: 100%;
            object-fit: cover; display: block;
        }
        @media (max-width: 860px) {
            .sp-welcome { grid-template-columns: 1fr; }
            .sp-welcome-portrait { display: none; }
        }

        /* Stats */
        .sp-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: clamp(14px, 1.8vw, 20px);
        }
        .sp-stat {
            position: relative;
            padding: 22px 24px 20px;
            border-radius: 18px;
            background: #fff;
            border: 1px solid var(--line);
            box-shadow:
                0 1px 0 rgba(255,255,255,.9) inset,
                0 14px 30px -22px rgba(74, 44, 16, .45);
            transition: transform .28s cubic-bezier(.2,.7,.3,1),
                        box-shadow .28s ease,
                        border-color .28s ease;
            overflow: hidden;
        }
        .sp-stat::before {
            content: ''; position: absolute;
            top: 0; left: 0; width: 100%; height: 4px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
            transform: scaleX(0); transform-origin: left;
            transition: transform .4s cubic-bezier(.2,.7,.3,1);
        }
        .sp-stat:hover {
            transform: translateY(-5px);
            border-color: rgba(176, 100, 30, .35);
            box-shadow: 0 24px 46px -22px rgba(74, 44, 16, .5);
        }
        .sp-stat:hover::before { transform: scaleX(1); }
        .sp-stat-head {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 16px;
        }
        .sp-stat-ico {
            width: 46px; height: 46px;
            border-radius: 14px;
            display: grid; place-items: center;
            font-size: 1.22rem;
            background: var(--gold-soft);
            color: var(--gold);
            border: 1px solid rgba(176, 100, 30, .18);
            transition: transform .35s cubic-bezier(.2,.7,.3,1);
        }
        .sp-stat:hover .sp-stat-ico { transform: scale(1.1) rotate(-8deg); }
        .sp-stat-ico.is-success { background: var(--success-bg); color: var(--success); border-color: var(--success-bd); }
        .sp-stat-ico.is-info    { background: var(--info-bg);    color: var(--info);    border-color: #C6DCF0; }
        .sp-stat-ico.is-warn    { background: var(--warn-bg);    color: var(--warn);    border-color: var(--warn-bd); }
        .sp-stat-trend {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 999px;
            font-size: .68rem; font-weight: 700;
            letter-spacing: .3px;
        }
        .sp-stat-trend.is-up { background: var(--success-bg); color: var(--success); }
        .sp-stat-trend.is-flat { background: var(--paper-2); color: var(--text-3); }
        .sp-stat-trend i { font-size: .78rem; }
        .sp-stat-label {
            font-size: .68rem; font-weight: 700;
            letter-spacing: 1.2px; text-transform: uppercase;
            color: var(--text-3); margin-bottom: 8px;
        }
        .sp-stat-value {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(1.6rem, 2.6vw, 2rem);
            font-weight: 700; letter-spacing: -.6px;
            color: var(--brown); line-height: 1.05;
        }
        .sp-stat-value small {
            font-size: .72rem; font-weight: 600;
            color: var(--text-3); margin-left: 4px; letter-spacing: 0;
        }
        .sp-stat-foot {
            display: flex; align-items: center; gap: 6px;
            margin-top: 12px;
            font-size: .74rem; font-weight: 500;
            color: var(--text-3);
        }
        .sp-stat-foot i { font-size: .85rem; color: var(--gold); }
        .sp-stat-bar {
            margin-top: 14px;
            height: 5px; border-radius: 5px;
            background: var(--paper-2); overflow: hidden;
        }
        .sp-stat-bar span {
            display: block; height: 100%;
            border-radius: 5px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
            transform-origin: left;
            animation: spBarFill 1.3s cubic-bezier(.2,.7,.3,1) both;
        }
        .sp-stat-bar.is-success span { background: linear-gradient(90deg, var(--success), #6FBF73); }
        .sp-stat-bar.is-info    span { background: linear-gradient(90deg, var(--info), #6A9CD9); }
        .sp-stat-bar.is-warn    span { background: linear-gradient(90deg, var(--warn), var(--gold-3)); }
        @keyframes spBarFill { from { transform: scaleX(0); } to { transform: scaleX(1); } }

        /* Next action */
        .sp-next-action {
            position: relative;
            display: flex; align-items: center; justify-content: space-between;
            gap: 20px;
            padding: 18px 22px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--gold-soft) 0%, #fff 100%);
            border: 1px dashed rgba(176, 100, 30, .4);
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset;
        }
        .sp-next-action::before {
            content: ''; position: absolute;
            left: 0; top: 16px; bottom: 16px;
            width: 3px; border-radius: 3px;
            background: linear-gradient(180deg, var(--gold), var(--gold-3));
        }
        .sp-next-action-left {
            display: flex; align-items: center; gap: 14px;
            min-width: 0;
        }
        .sp-next-action-ico {
            width: 44px; height: 44px;
            border-radius: 13px;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color: #fff;
            display: grid; place-items: center;
            font-size: 1.15rem;
            flex-shrink: 0;
            box-shadow: 0 8px 18px -8px rgba(176, 100, 30, .7);
        }
        .sp-next-action-text {
            font-size: .88rem;
            color: var(--text-2);
            font-weight: 500;
            line-height: 1.5;
        }
        .sp-next-action-text strong {
            display: block;
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1rem;
            font-weight: 600;
            color: var(--brown);
            margin-bottom: 2px;
        }
        .sp-next-action-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 16px;
            border-radius: 9px;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color: #fff;
            font-weight: 700; font-size: .82rem;
            border: 0; cursor: pointer; font-family: inherit;
            text-decoration: none;
            white-space: nowrap;
            box-shadow: 0 3px 0 rgba(0,0,0,.14), 0 12px 22px -12px rgba(176,100,30,.8);
            transition: transform .15s ease, filter .15s ease;
        }
        .sp-next-action-btn:hover {
            color: #fff; transform: translateY(-1px);
            filter: brightness(1.05);
        }
        .sp-next-action-btn i { font-size: .9rem; }
        @media (max-width: 620px) {
            .sp-next-action { flex-direction: column; align-items: flex-start; }
        }

        /* Grid & panels */
        .sp-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
            gap: clamp(14px, 1.8vw, 22px);
            align-items: start;
        }
        @media (max-width: 980px) { .sp-grid { grid-template-columns: 1fr; } }

        .sp-panel {
            position: relative;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 18px;
            overflow: hidden;
            box-shadow:
                0 1px 0 rgba(255,255,255,.9) inset,
                0 14px 30px -22px rgba(74, 44, 16, .4);
            transition: box-shadow .28s ease, border-color .28s ease;
        }
        .sp-panel:hover {
            border-color: rgba(176, 100, 30, .24);
            box-shadow:
                0 1px 0 rgba(255,255,255,.9) inset,
                0 24px 46px -22px rgba(74, 44, 16, .5);
        }
        .sp-panel::before {
            content: ''; position: absolute;
            top: 0; left: 0;
            width: 56px; height: 3px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
            border-radius: 0 3px 3px 0;
            opacity: .55;
            transition: opacity .25s ease, width .3s ease;
        }
        .sp-panel:hover::before { opacity: 1; width: 84px; }

        .sp-panel-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px;
            padding: 18px 22px;
            border-bottom: 1px solid var(--line);
            background: linear-gradient(180deg, #fff, var(--cream-2));
        }
        .sp-panel-title {
            display: flex; align-items: center; gap: 11px;
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.06rem; font-weight: 600;
            color: var(--brown); margin: 0;
        }
        .sp-panel-title i {
            width: 32px; height: 32px;
            border-radius: 10px;
            display: grid; place-items: center;
            background: var(--gold-soft);
            color: var(--gold);
            font-size: .95rem;
            border: 1px solid rgba(176, 100, 30, .18);
        }
        .sp-panel-link {
            font-size: .78rem; font-weight: 600;
            color: var(--gold); text-decoration: none;
            display: inline-flex; align-items: center; gap: 4px;
            transition: color .15s ease, transform .15s ease;
            background: none; border: 0; cursor: pointer; font-family: inherit;
        }
        .sp-panel-link:hover { color: var(--gold-2); transform: translateX(2px); }

        /* Table */
        .sp-table-wrap { overflow-x: auto; }
        .sp-table { width: 100%; border-collapse: collapse; font-size: .86rem; }
        .sp-table thead th {
            text-align: left; padding: 12px 22px;
            font-size: .66rem; font-weight: 700;
            letter-spacing: 1.1px; text-transform: uppercase;
            color: var(--text-3);
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
            background: var(--cream-2);
        }
        .sp-table tbody td {
            padding: 14px 22px;
            border-bottom: 1px dashed var(--line-2);
            color: var(--text);
            vertical-align: middle; white-space: nowrap;
        }
        .sp-table tbody tr:last-child td { border-bottom: 0; }
        .sp-table tbody tr {
            transition: background .18s ease;
            position: relative;
        }
        .sp-table tbody tr:hover { background: var(--gold-soft); }

        .sp-ref {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: .78rem; font-weight: 600;
            color: var(--brown-2);
            background: var(--paper-2);
            border: 1px solid var(--line-2);
            padding: 3px 8px; border-radius: 5px;
        }
        .sp-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 999px;
            font-size: .68rem; font-weight: 700;
            letter-spacing: .3px; text-transform: uppercase;
        }
        .sp-badge i { font-size: .78rem; }
        .sp-badge-paid    { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-bd); }
        .sp-badge-partial { background: var(--warn-bg);    color: var(--warn);    border: 1px solid var(--warn-bd); }
        .sp-badge-unpaid  { background: var(--danger-bg);  color: var(--danger);  border: 1px solid var(--danger-bd); }
        .sp-badge-pending { background: var(--info-bg);    color: var(--info);    border: 1px solid #C6DCF0; }

        /* Empty */
        .sp-empty { padding: 48px 26px; text-align: center; color: var(--text-3); }
        .sp-empty-ico {
            width: 68px; height: 68px;
            margin: 0 auto 16px;
            border-radius: 50%;
            display: grid; place-items: center;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            color: var(--gold);
            font-size: 1.7rem;
            border: 1px solid rgba(176, 100, 30, .2);
            box-shadow: 0 10px 24px -12px rgba(176, 100, 30, .4);
        }
        .sp-empty h4 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.1rem; font-weight: 600;
            color: var(--brown); margin: 0 0 6px;
        }
        .sp-empty p {
            font-size: .85rem; margin: 0 0 18px;
            max-width: 38ch; margin-inline: auto; line-height: 1.6;
        }
        .sp-empty .sp-btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 10px 20px; border-radius: 10px;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color: #fff; font-weight: 700; font-size: .84rem;
            text-decoration: none;
            border: 0; cursor: pointer; font-family: inherit;
            box-shadow: 0 3px 0 rgba(0,0,0,.12), 0 12px 22px -12px rgba(176,100,30,.7);
            transition: transform .15s ease, filter .15s ease;
        }
        .sp-empty .sp-btn:hover { transform: translateY(-1px); filter: brightness(1.04); color: #fff; }

        /* Payments list */
        .sp-pay-list { display: flex; flex-direction: column; }
        .sp-pay-item {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 22px;
            border-bottom: 1px dashed var(--line-2);
            transition: background .18s ease, padding-left .18s ease;
        }
        .sp-pay-item:last-child { border-bottom: 0; }
        .sp-pay-item:hover { background: var(--gold-soft); padding-left: 26px; }
        .sp-pay-ico {
            width: 40px; height: 40px;
            border-radius: 11px;
            display: grid; place-items: center;
            background: var(--success-bg);
            color: var(--success);
            font-size: 1.05rem;
            border: 1px solid var(--success-bd);
            flex-shrink: 0;
        }
        .sp-pay-body { min-width: 0; flex: 1; }
        .sp-pay-ref {
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-size: .74rem; font-weight: 600;
            color: var(--brown-2); margin: 0 0 2px;
        }
        .sp-pay-meta { font-size: .72rem; color: var(--text-3); font-weight: 500; }
        .sp-pay-amount {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1rem; font-weight: 700;
            color: var(--success); white-space: nowrap;
        }

        /* Summary */
        .sp-summary {
            padding: 22px;
            display: flex; flex-direction: column;
            gap: 12px;
        }
        .sp-summary-row {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; padding: 13px 15px;
            border-radius: 12px;
            background: var(--cream-2);
            border: 1px solid var(--line);
            font-size: .86rem;
            transition: transform .18s ease, border-color .18s ease, background .18s ease;
        }
        .sp-summary-row:hover {
            transform: translateX(3px);
            border-color: var(--line-3);
        }
        .sp-summary-row.is-total {
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            border-color: rgba(176, 100, 30, .28);
        }
        .sp-summary-label {
            display: inline-flex; align-items: center; gap: 10px;
            color: var(--text-2); font-weight: 600;
        }
        .sp-summary-label i { color: var(--gold); font-size: 1rem; }
        .sp-summary-value {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.05rem; font-weight: 700;
            color: var(--brown);
        }
        .sp-summary-row.is-total .sp-summary-value { font-size: 1.2rem; color: var(--gold-2); }
        .sp-summary-row.is-outstanding .sp-summary-value { color: var(--danger); }

        /* Quick actions */
        .sp-quick {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
        }
        .sp-quick-card {
            position: relative;
            overflow: hidden;
            padding: 20px 20px 18px;
            border-radius: 16px;
            background: linear-gradient(180deg, #fff, var(--cream-2));
            border: 1px solid var(--line);
            text-decoration: none;
            color: inherit;
            display: flex; flex-direction: column; gap: 12px;
            cursor: pointer; font-family: inherit; text-align: left;
            transition: transform .28s cubic-bezier(.2,.7,.3,1),
                        border-color .28s ease,
                        box-shadow .28s ease;
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset,
                        0 12px 26px -20px rgba(74, 44, 16, .38);
        }
        .sp-quick-card::before {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(circle at 100% 0%, rgba(232, 176, 90, .18), transparent 60%);
            opacity: 0;
            transition: opacity .3s ease;
            pointer-events: none;
        }
        .sp-quick-card:hover {
            transform: translateY(-5px);
            border-color: rgba(176, 100, 30, .4);
            box-shadow: 0 24px 40px -22px rgba(74, 44, 16, .5);
            color: inherit;
        }
        .sp-quick-card:hover::before { opacity: 1; }
        .sp-quick-ico {
            width: 44px; height: 44px;
            border-radius: 13px;
            display: grid; place-items: center;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            color: var(--gold);
            font-size: 1.2rem;
            border: 1px solid rgba(176, 100, 30, .2);
            transition: transform .35s cubic-bezier(.2,.7,.3,1);
        }
        .sp-quick-card:hover .sp-quick-ico { transform: scale(1.1) rotate(-8deg); }
        .sp-quick-title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1rem; font-weight: 600;
            color: var(--brown); margin: 0; line-height: 1.2;
        }
        .sp-quick-desc {
            font-size: .78rem; color: var(--text-3);
            margin: 0; line-height: 1.5;
        }
        .sp-quick-arrow {
            position: absolute;
            top: 20px; right: 20px;
            color: var(--line-3);
            font-size: .95rem;
            transition: transform .25s ease, color .25s ease;
        }
        .sp-quick-card:hover .sp-quick-arrow {
            color: var(--gold);
            transform: translate(3px, -3px);
        }

        /* Timeline */
        .sp-timeline { padding: 18px 22px 22px; position: relative; }
        .sp-timeline::before {
            content: ''; position: absolute;
            left: 32px; top: 24px; bottom: 28px;
            width: 2px;
            background: linear-gradient(180deg, var(--line-2), transparent);
        }
        .sp-tl-item {
            position: relative;
            padding: 10px 0 16px 42px;
            display: flex; flex-direction: column; gap: 3px;
        }
        .sp-tl-item:last-child { padding-bottom: 0; }
        .sp-tl-dot {
            position: absolute;
            left: 23px; top: 14px;
            width: 20px; height: 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            border: 3px solid #fff;
            box-shadow: 0 0 0 1.5px var(--gold-3),
                        0 4px 10px -4px rgba(176, 100, 30, .7);
        }
        .sp-tl-dot.is-success { background: linear-gradient(135deg, var(--success), #6FBF73); box-shadow: 0 0 0 1.5px var(--success-bd), 0 4px 10px -4px rgba(46,125,50,.6); }
        .sp-tl-dot.is-info    { background: linear-gradient(135deg, var(--info), #6A9CD9);    box-shadow: 0 0 0 1.5px #C6DCF0, 0 4px 10px -4px rgba(30,95,168,.6); }
        .sp-tl-dot.is-warn    { background: linear-gradient(135deg, var(--gold-2), var(--gold-3)); box-shadow: 0 0 0 1.5px var(--warn-bd), 0 4px 10px -4px rgba(176,100,30,.6); }
        .sp-tl-text {
            font-size: .86rem;
            color: var(--text);
            font-weight: 500;
            line-height: 1.5;
        }
        .sp-tl-text strong { color: var(--brown); font-weight: 700; }
        .sp-tl-meta {
            font-size: .7rem;
            color: var(--text-3);
            font-weight: 500;
            letter-spacing: .2px;
            display: flex; align-items: center; gap: 6px;
        }
        .sp-tl-meta i { font-size: .78rem; color: var(--gold); }

        /* Chart */
        .sp-chart { padding: 20px 22px 24px; }
        .sp-chart-head {
            display: flex; align-items: baseline; justify-content: space-between;
            margin-bottom: 18px;
        }
        .sp-chart-title {
            font-size: .72rem; font-weight: 700;
            letter-spacing: 1.1px; text-transform: uppercase;
            color: var(--text-3);
        }
        .sp-chart-total {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.3rem; font-weight: 700;
            color: var(--brown);
            letter-spacing: -.4px;
        }
        .sp-chart-total small {
            font-size: .68rem; font-weight: 600;
            color: var(--success);
            margin-left: 6px;
            letter-spacing: 0;
            display: inline-flex; align-items: center; gap: 2px;
        }
        .sp-bars {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            height: 100px;
            padding: 0 2px;
        }
        .sp-bar-col {
            flex: 1;
            display: flex; flex-direction: column;
            align-items: center;
            gap: 8px;
            height: 100%;
            justify-content: flex-end;
        }
        .sp-bar {
            width: 100%;
            border-radius: 6px 6px 3px 3px;
            background: linear-gradient(180deg, var(--gold-3), var(--gold));
            box-shadow: 0 3px 8px -4px rgba(176, 100, 30, .5);
            transition: transform .2s ease, filter .2s ease;
            animation: spBarRise .9s cubic-bezier(.2,.7,.3,1) both;
            transform-origin: bottom;
            position: relative;
        }
        .sp-bar:hover { filter: brightness(1.1); transform: scaleY(1.06); }
        .sp-bar.is-muted { background: linear-gradient(180deg, var(--line-3), var(--line-2)); box-shadow: none; }
        .sp-bar-label {
            font-size: .64rem;
            color: var(--text-3);
            font-weight: 600;
            letter-spacing: .4px;
        }
        @keyframes spBarRise { from { transform: scaleY(.05); } to { transform: scaleY(1); } }

        /* Footnote */
        .sp-footnote {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; flex-wrap: wrap;
            padding: 16px 22px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            border: 1px solid rgba(176, 100, 30, .22);
            font-size: .82rem;
            color: var(--text-2);
        }
        .sp-footnote-left {
            display: inline-flex; align-items: center; gap: 12px;
            font-weight: 500;
        }
        .sp-footnote-left i {
            width: 34px; height: 34px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color: #fff;
            display: grid; place-items: center;
            font-size: 1rem;
            box-shadow: 0 4px 10px -3px rgba(176, 100, 30, .6);
        }
        .sp-footnote-left strong { color: var(--brown); font-weight: 700; }
        .sp-footnote-right {
            display: inline-flex; align-items: center; gap: 8px;
            color: var(--gold); font-weight: 700;
            text-decoration: none;
            background: none; border: 0; cursor: pointer; font-family: inherit;
            font-size: .82rem;
            transition: color .15s ease, gap .15s ease;
        }
        .sp-footnote-right:hover { color: var(--gold-2); gap: 12px; }

        /* Modal */
        .sp-modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(42, 30, 16, .6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 400;
            display: none;
            align-items: center; justify-content: center;
            padding: 20px;
            opacity: 0;
            transition: opacity .25s ease;
            overflow: hidden;
        }
        .sp-modal-backdrop.is-open { display: flex; opacity: 1; }
        .sp-modal {
            background: #fff;
            border-radius: 22px;
            width: 100%;
            max-width: 580px;
            max-height: calc(100vh - 40px);
            height: auto;
            display: flex; flex-direction: column;
            overflow: hidden;
            box-shadow: 0 46px 100px -34px rgba(40, 22, 6, .8);
            transform: scale(.95) translateY(10px);
            transition: transform .3s cubic-bezier(.2,.7,.3,1);
            min-height: 0;
        }
        .sp-modal.is-wide { max-width: 740px; }
        .sp-modal-backdrop.is-open .sp-modal { transform: scale(1) translateY(0); }

        .sp-modal-head {
            position: relative;
            padding: 24px 26px 20px;
            background:
                radial-gradient(circle at 85% 15%, rgba(232, 176, 90, .35), transparent 55%),
                linear-gradient(135deg, #4A2C10 0%, #2A1E10 100%);
            color: #fff;
            flex-shrink: 0; overflow: hidden;
        }
        .sp-modal-head::before {
            content: ''; position: absolute; inset: 0;
            background-image:
                repeating-linear-gradient(92deg, transparent 0px, transparent 22px, rgba(232, 176, 90, .06) 22px, rgba(232, 176, 90, .06) 24px),
                repeating-linear-gradient(88deg, transparent 0px, transparent 34px, rgba(232, 176, 90, .04) 34px, rgba(232, 176, 90, .04) 37px);
            pointer-events: none;
        }
        .sp-modal-head > * { position: relative; z-index: 1; }
        .sp-modal-close {
            position: absolute;
            top: 18px; right: 18px;
            width: 36px; height: 36px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, .2);
            background: rgba(255, 255, 255, .1);
            color: #fff;
            cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1rem;
            transition: background .18s ease, transform .25s ease;
        }
        .sp-modal-close:hover {
            background: rgba(255, 255, 255, .22);
            transform: rotate(90deg);
        }
        .sp-modal-tag {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 11px;
            border-radius: 999px;
            background: rgba(232, 176, 90, .15);
            border: 1px solid rgba(232, 176, 90, .3);
            font-size: .62rem; font-weight: 700;
            letter-spacing: 1.2px; text-transform: uppercase;
            color: var(--gold-3);
            margin-bottom: 12px;
        }
        .sp-modal-title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.45rem; font-weight: 600;
            letter-spacing: -.45px;
            margin: 0 0 4px;
            line-height: 1.2;
        }
        .sp-modal-sub {
            margin: 0;
            font-size: .85rem;
            color: rgba(255, 255, 255, .72);
            line-height: 1.55;
        }
        .sp-modal-body {
            padding: 24px 26px;
            overflow-y: auto;
            overflow-x: hidden;
            flex: 1 1 auto;
            min-height: 0;
            display: flex; flex-direction: column;
            gap: 16px;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: var(--line-3) transparent;
        }
        .sp-modal-body::-webkit-scrollbar { width: 8px; }
        .sp-modal-body::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--line-2), var(--line-3));
            border-radius: 4px;
            border: 2px solid transparent;
            background-clip: content-box;
        }

        .sp-modal-foot {
            padding: 16px 26px 22px;
            border-top: 1px solid var(--line);
            background: var(--cream-2);
            display: flex; align-items: center; justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
        }

        .sp-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        @media (max-width: 540px) { .sp-form-row { grid-template-columns: 1fr; } }
        .sp-form-field { display: flex; flex-direction: column; gap: 6px; }
        .sp-form-field.is-full { grid-column: 1 / -1; }
        .sp-form-field label {
            font-size: .66rem; font-weight: 700;
            letter-spacing: 1.1px; text-transform: uppercase;
            color: var(--brown-2);
        }
        .sp-form-field label .req { color: var(--danger); margin-left: 2px; }

        .sp-form-input {
            display: flex; align-items: center;
            background: #fff;
            border: 1.5px solid var(--line-2);
            border-radius: 11px;
            transition: border-color .18s ease, box-shadow .18s ease;
            overflow: hidden;
            min-height: 46px;
        }
        .sp-form-input:hover { border-color: var(--line-3); }
        .sp-form-input:focus-within {
            border-color: var(--gold);
            box-shadow: 0 0 0 4px rgba(176, 100, 30, .13);
        }
        .sp-form-input i {
            padding: 0 4px 0 14px;
            color: var(--text-3);
            font-size: .95rem;
            transition: color .18s ease;
        }
        .sp-form-input:focus-within i { color: var(--gold); }
        .sp-form-input input,
        .sp-form-input select,
        .sp-form-input textarea {
            flex: 1; border: 0; outline: 0;
            background: transparent;
            padding: 11px 14px 11px 10px;
            font-size: .89rem; font-weight: 500;
            color: #2A1E10; font-family: inherit;
            min-width: 0; width: 100%;
            -webkit-text-fill-color: #2A1E10;
        }
        .sp-form-input select:focus,
        .sp-form-input select:active {
            color: #2A1E10 !important;
            -webkit-text-fill-color: #2A1E10 !important;
        }
        .sp-form-input select option { color: #2A1E10; background: #fff; }
        .sp-form-input select option[value=""] { color: #A9A392; }
        .sp-form-input textarea {
            resize: vertical; min-height: 76px;
            padding-top: 12px;
        }
        .sp-form-input input::placeholder,
        .sp-form-input textarea::placeholder { color: #A9A392; font-weight: 400; }

        .sp-form-hint {
            font-size: .7rem; color: var(--text-3);
            font-weight: 500; padding-left: 2px;
        }
        .sp-form-summary {
            padding: 14px 16px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            border: 1px dashed rgba(176, 100, 30, .35);
            display: flex; align-items: center; justify-content: space-between;
            font-size: .86rem;
        }
        .sp-form-summary-label {
            display: inline-flex; align-items: center; gap: 8px;
            color: var(--text-2); font-weight: 600;
        }
        .sp-form-summary-label i { color: var(--gold); font-size: 1rem; }
        .sp-form-summary-value {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.2rem; font-weight: 700;
            color: var(--gold-2);
        }
        .sp-btn-submit {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px;
            padding: 12px 22px;
            border-radius: 11px;
            border: 0;
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            color: #fff; font-weight: 700; font-size: .87rem;
            cursor: pointer; font-family: inherit;
            box-shadow: 0 3px 0 rgba(0,0,0,.12), 0 14px 24px -12px rgba(176,100,30,.75);
            transition: transform .15s ease, filter .15s ease, box-shadow .15s ease;
        }
        .sp-btn-submit:hover {
            transform: translateY(-1px);
            filter: brightness(1.04);
            box-shadow: 0 5px 0 rgba(0,0,0,.12), 0 18px 30px -12px rgba(176,100,30,.85);
        }
        .sp-btn-submit:disabled {
            opacity: .6; cursor: not-allowed; transform: none;
        }
        .sp-btn-cancel {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 7px;
            padding: 12px 20px;
            border-radius: 11px;
            border: 1.5px solid var(--line-2);
            background: #fff;
            color: var(--brown-2);
            font-weight: 600; font-size: .87rem;
            cursor: pointer; font-family: inherit;
            transition: border-color .15s ease, color .15s ease, transform .15s ease;
        }
        .sp-btn-cancel:hover {
            border-color: var(--gold); color: var(--gold);
            transform: translateY(-1px);
        }

        .sp-report-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        @media (max-width: 540px) { .sp-report-grid { grid-template-columns: 1fr; } }
        .sp-report-card {
            padding: 18px 20px;
            border-radius: 14px;
            background: linear-gradient(180deg, #fff, var(--cream-2));
            border: 1px solid var(--line);
            display: flex; align-items: center; gap: 14px;
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset,
                        0 10px 20px -18px rgba(74, 44, 16, .4);
            transition: transform .22s ease, border-color .22s ease;
        }
        .sp-report-card:hover {
            transform: translateY(-3px);
            border-color: rgba(176, 100, 30, .35);
        }
        .sp-report-ico {
            width: 46px; height: 46px;
            border-radius: 13px;
            display: grid; place-items: center;
            background: var(--gold-soft);
            color: var(--gold); font-size: 1.18rem;
            border: 1px solid rgba(176, 100, 30, .18);
            flex-shrink: 0;
        }
        .sp-report-ico.is-info    { background: var(--info-bg);    color: var(--info);    border-color: #C6DCF0; }
        .sp-report-ico.is-success { background: var(--success-bg); color: var(--success); border-color: var(--success-bd); }
        .sp-report-ico.is-warn    { background: var(--warn-bg);    color: var(--warn);    border-color: var(--warn-bd); }
        .sp-report-body { min-width: 0; }
        .sp-report-label {
            font-size: .66rem; font-weight: 700;
            letter-spacing: 1.1px; text-transform: uppercase;
            color: var(--text-3); margin-bottom: 3px;
        }
        .sp-report-value {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.25rem; font-weight: 700;
            color: var(--brown); letter-spacing: -.4px;
            line-height: 1.15;
        }
        .sp-report-value small {
            font-size: .68rem; font-weight: 600;
            color: var(--text-3); margin-left: 3px;
        }
        .sp-report-chart {
            padding: 20px 22px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: linear-gradient(180deg, #fff, var(--cream-2));
            margin-top: 4px;
        }
        .sp-report-chart-title {
            font-size: .72rem; font-weight: 700;
            letter-spacing: 1.1px; text-transform: uppercase;
            color: var(--text-3);
            margin-bottom: 16px;
        }

        /* Toast */
        .sp-toast {
            position: fixed;
            bottom: 26px; left: 50%;
            transform: translate(-50%, 120%);
            background: linear-gradient(135deg, #4A2C10, #2A1E10);
            color: #fff;
            padding: 14px 22px;
            border-radius: 14px;
            box-shadow: 0 24px 46px -20px rgba(0,0,0,.75);
            display: inline-flex; align-items: center; gap: 11px;
            font-size: .88rem; font-weight: 600;
            z-index: 500;
            transition: transform .38s cubic-bezier(.2,.7,.3,1);
            border: 1px solid rgba(232, 176, 90, .3);
            max-width: calc(100vw - 40px);
        }
        .sp-toast.is-visible { transform: translate(-50%, 0); }
        .sp-toast i { font-size: 1.2rem; color: var(--gold-3); }
        .sp-toast.is-success i { color: #7DD68A; }
        .sp-toast.is-error i { color: #FF9B7A; }

        /* Sidebar mobile overlay */
        .sp-sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(42, 30, 16, .5);
            z-index: 150;
            opacity: 0; pointer-events: none;
            transition: opacity .25s ease;
        }
        @media (max-width: 900px) {
            .sp-sidebar {
                transform: translateX(-100%);
                box-shadow: 24px 0 60px -30px rgba(0,0,0,.7);
            }
            body.sp-sidebar-open .sp-sidebar { transform: translateX(0); }
            body.sp-sidebar-open .sp-sidebar-overlay {
                display: block; opacity: 1; pointer-events: auto;
            }
            .sp-main { margin-left: 0; }
            .sp-menu-btn { display: inline-flex; }
        }
        @media (max-width: 480px) {
            .sp-topbar { padding: 12px 16px; }
            .sp-content { padding: 16px; }
            .sp-cta span { display: none; }
            .sp-cta { padding: 10px 12px; }
            .sp-table thead th,
            .sp-table tbody td { padding: 10px 14px; }
            .sp-panel-head { padding: 14px 16px; }
            .sp-pay-item { padding: 12px 16px; }
            .sp-summary { padding: 16px; }
            .sp-modal { max-height: calc(100vh - 20px); }
            .sp-modal-body { padding: 18px 18px; }
            .sp-modal-foot { padding: 12px 18px 16px; }
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
                    <i class="bi bi-image" style="font-size: 1.6rem; color: var(--text-3);"></i>
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
        <a href="<?= BASE_URL ?>buyer/dashboard.php" class="sp-nav-link is-active">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="<?= BASE_URL ?>buyer/purchases.php" class="sp-nav-link">
            <i class="bi bi-bag-check"></i> My Purchases
        </a>
        <a href="<?= BASE_URL ?>buyer/payments.php" class="sp-nav-link">
            <i class="bi bi-cash-coin"></i> Payments
        </a>

        <span class="sp-nav-label">Records</span>
        <a href="<?= BASE_URL ?>buyer/sellers.php" class="sp-nav-link">
            <i class="bi bi-people"></i> Sellers
        </a>
        <a href="<?= BASE_URL ?>buyer/reports.php" class="sp-nav-link">
            <i class="bi bi-graph-up-arrow"></i> Reports
        </a>

        <span class="sp-nav-label">Account</span>
        <a href="<?= BASE_URL ?>buyer/profile.php" class="sp-nav-link">
            <i class="bi bi-person-circle"></i> My Profile
        </a>
        <a href="<?= BASE_URL ?>buyer/settings.php" class="sp-nav-link">
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
                <h1 class="sp-page-title">Dashboard</h1>
                <span class="sp-page-sub">Welcome back, <strong><?= $firstName ?></strong>!</span>
            </div>
        </div>

        <div class="sp-topbar-right">
            <button class="sp-icon-btn" id="notifBtn" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <span class="badge-dot"></span>
            </button>
            <button type="button" class="sp-cta" id="topRecordBtn">
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

        <section class="sp-welcome">
            <div class="sp-welcome-text">
                <span class="sp-welcome-tag">
                    <i class="bi bi-sun"></i>
                    Buyer Dashboard
                </span>
                <h2>Good day, <em><?= $firstName ?></em>. Ready to record today's harvest?</h2>
                <p>
                    Your purchase ledger, balances, and seller activity — all
                    organized in one warm, focused space.
                </p>

                <div class="sp-welcome-actions">
                    <button type="button" class="sp-welcome-btn is-primary" id="welcomeRecordBtn">
                        <i class="bi bi-plus-lg"></i> Record Purchase
                    </button>
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

        <section class="sp-stats">
            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico"><i class="bi bi-bag-check"></i></div>
                    <span class="sp-stat-trend is-up">
                        <i class="bi bi-arrow-up-right"></i> 12%
                    </span>
                </div>
                <div class="sp-stat-label">Total Purchases</div>
                <div class="sp-stat-value"><?= number_format($stats['total_purchases']) ?></div>
                <div class="sp-stat-foot"><i class="bi bi-clock-history"></i> All time</div>
                <div class="sp-stat-bar"><span style="width: <?= min(100, max(6, $stats['total_purchases'] * 10)) ?>%"></span></div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-info"><i class="bi bi-box-seam"></i></div>
                    <span class="sp-stat-trend is-up">
                        <i class="bi bi-arrow-up-right"></i> 8%
                    </span>
                </div>
                <div class="sp-stat-label">Palay Received</div>
                <div class="sp-stat-value"><?= number_format($stats['total_palay_kg'], 0) ?><small>kg</small></div>
                <div class="sp-stat-foot"><i class="bi bi-graph-up"></i> Total weight</div>
                <div class="sp-stat-bar is-info"><span style="width: <?= min(100, max(8, $stats['total_palay_kg'] / 50)) ?>%"></span></div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-success"><i class="bi bi-cash-stack"></i></div>
                    <span class="sp-stat-trend is-up">
                        <i class="bi bi-check2-circle"></i> Settled
                    </span>
                </div>
                <div class="sp-stat-label">Total Paid</div>
                <div class="sp-stat-value"><?= peso($stats['total_paid']) ?></div>
                <div class="sp-stat-foot"><i class="bi bi-check-circle"></i> Settled amount</div>
                <div class="sp-stat-bar is-success"><span style="width: <?= min(100, $paidPct) ?>%"></span></div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-warn"><i class="bi bi-hourglass-split"></i></div>
                    <span class="sp-stat-trend is-flat"><i class="bi bi-dot"></i> Pending</span>
                </div>
                <div class="sp-stat-label">Outstanding Balance</div>
                <div class="sp-stat-value"><?= peso($stats['outstanding_balance']) ?></div>
                <div class="sp-stat-foot"><i class="bi bi-exclamation-circle"></i> Needs settlement</div>
                <div class="sp-stat-bar is-warn"><span style="width: <?= min(100, $totalOwed > 0 ? 100 - $paidPct : 0) ?>%"></span></div>
            </article>
        </section>

        <?php if ($stats['outstanding_balance'] > 0): ?>
        <div class="sp-next-action">
            <div class="sp-next-action-left">
                <div class="sp-next-action-ico"><i class="bi bi-lightning-charge-fill"></i></div>
                <div class="sp-next-action-text">
                    <strong>You have <?= peso($stats['outstanding_balance']) ?> outstanding</strong>
                    Settle balances with your sellers to keep your ledger clean.
                </div>
            </div>
            <a href="<?= BASE_URL ?>buyer/payments.php" class="sp-next-action-btn">
                <i class="bi bi-credit-card-2-front"></i> Settle now
            </a>
        </div>
        <?php elseif ($stats['total_purchases'] === 0): ?>
        <div class="sp-next-action">
            <div class="sp-next-action-left">
                <div class="sp-next-action-ico"><i class="bi bi-rocket-takeoff"></i></div>
                <div class="sp-next-action-text">
                    <strong>Start your first purchase record</strong>
                    Log your first palay delivery — takes less than a minute.
                </div>
            </div>
            <button type="button" class="sp-next-action-btn" data-modal="newPurchase">
                <i class="bi bi-plus-lg"></i> Record now
            </button>
        </div>
        <?php endif; ?>

        <section class="sp-quick">
            <button type="button" class="sp-quick-card" data-modal="newPurchase">
                <div class="sp-quick-ico"><i class="bi bi-plus-circle"></i></div>
                <h4 class="sp-quick-title">Record a Purchase</h4>
                <p class="sp-quick-desc">Log a new palay delivery from a seller.</p>
                <i class="bi bi-arrow-up-right sp-quick-arrow"></i>
            </button>
            <a href="<?= BASE_URL ?>buyer/payments.php" class="sp-quick-card">
                <div class="sp-quick-ico"><i class="bi bi-credit-card-2-front"></i></div>
                <h4 class="sp-quick-title">Make a Payment</h4>
                <p class="sp-quick-desc">Settle an outstanding balance with a seller.</p>
                <i class="bi bi-arrow-up-right sp-quick-arrow"></i>
            </a>
            <a href="<?= BASE_URL ?>buyer/sellers.php" class="sp-quick-card">
                <div class="sp-quick-ico"><i class="bi bi-people"></i></div>
                <h4 class="sp-quick-title">Browse Sellers</h4>
                <p class="sp-quick-desc">View your trusted palay suppliers and contacts.</p>
                <i class="bi bi-arrow-up-right sp-quick-arrow"></i>
            </a>
            <button type="button" class="sp-quick-card" data-modal="reports">
                <div class="sp-quick-ico"><i class="bi bi-file-earmark-text"></i></div>
                <h4 class="sp-quick-title">Generate Report</h4>
                <p class="sp-quick-desc">Export your purchase history for a date range.</p>
                <i class="bi bi-arrow-up-right sp-quick-arrow"></i>
            </button>
        </section>

        <section class="sp-grid">

            <div class="sp-panel">
                <div class="sp-panel-head">
                    <h3 class="sp-panel-title">
                        <i class="bi bi-clock-history"></i>
                        Recent Purchases
                    </h3>
                    <a href="<?= BASE_URL ?>buyer/purchases.php" class="sp-panel-link">
                        View all <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <?php if (!empty($recentPurchases)): ?>
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
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
                                        <td><span class="sp-ref"><?= sanitize($p['reference_no'] ?? '—') ?></span></td>
                                        <td><?= sanitize($p['seller_name'] ?? '—') ?></td>
                                        <td><?= kg($p['weight_kg'] ?? 0) ?></td>
                                        <td><strong><?= peso($p['total_amount'] ?? 0) ?></strong></td>
                                        <td><span class="sp-badge <?= $badge ?>"><i class="bi <?= $ico ?>"></i> <?= $label ?></span></td>
                                        <td><?= niceDate($p['created_at'] ?? null) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="sp-empty">
                        <div class="sp-empty-ico"><i class="bi bi-bag"></i></div>
                        <h4>No purchases yet</h4>
                        <p>Your recent palay purchases will show up here. Start recording your first purchase to see it listed.</p>
                        <button type="button" class="sp-btn" data-modal="newPurchase">
                            <i class="bi bi-plus-lg"></i> Record First Purchase
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display:flex; flex-direction:column; gap: clamp(14px, 1.8vw, 22px);">

                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title">
                            <i class="bi bi-bar-chart-line"></i>
                            Weekly Palay
                        </h3>
                        <button type="button" class="sp-panel-link" data-modal="reports">
                            Details <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                    <div class="sp-chart">
                        <div class="sp-chart-head">
                            <span class="sp-chart-title">Last 7 days</span>
                            <span class="sp-chart-total">
                                <?= number_format($stats['total_palay_kg'], 0) ?> kg
                                <small><i class="bi bi-arrow-up-short"></i>+8%</small>
                            </span>
                        </div>
                        <div class="sp-bars">
                            <?php
                                $heights = [42, 68, 55, 82, 74, 90, 60];
                                $days    = ['S','M','T','W','T','F','S'];
                                foreach ($heights as $i => $h):
                            ?>
                                <div class="sp-bar-col">
                                    <div class="sp-bar <?= $i === 5 ? '' : 'is-muted' ?>" style="height: <?= $h ?>%"></div>
                                    <span class="sp-bar-label"><?= $days[$i] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title">
                            <i class="bi bi-wallet2"></i>
                            Balance Summary
                        </h3>
                    </div>
                    <div class="sp-summary">
                        <div class="sp-summary-row">
                            <span class="sp-summary-label"><i class="bi bi-box-seam"></i> Total Palay</span>
                            <span class="sp-summary-value"><?= number_format($stats['total_palay_kg'], 0) ?> kg</span>
                        </div>
                        <div class="sp-summary-row">
                            <span class="sp-summary-label"><i class="bi bi-check-circle"></i> Total Paid</span>
                            <span class="sp-summary-value"><?= peso($stats['total_paid']) ?></span>
                        </div>
                        <div class="sp-summary-row is-outstanding">
                            <span class="sp-summary-label"><i class="bi bi-hourglass-split"></i> Outstanding</span>
                            <span class="sp-summary-value"><?= peso($stats['outstanding_balance']) ?></span>
                        </div>
                        <div class="sp-summary-row is-total">
                            <span class="sp-summary-label"><i class="bi bi-calculator"></i> Grand Total</span>
                            <span class="sp-summary-value"><?= peso($totalOwed) ?></span>
                        </div>
                    </div>
                </div>

                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title">
                            <i class="bi bi-activity"></i>
                            Recent Activity
                        </h3>
                    </div>
                    <div class="sp-timeline">
                        <?php if (!empty($recentPayments)): ?>
                            <?php foreach (array_slice($recentPayments, 0, 3) as $pay): ?>
                                <div class="sp-tl-item">
                                    <span class="sp-tl-dot is-success"></span>
                                    <div class="sp-tl-text">
                                        Payment of <strong><?= peso($pay['amount'] ?? 0) ?></strong>
                                        via <?= sanitize(ucfirst($pay['method'] ?? 'Cash')) ?>
                                    </div>
                                    <div class="sp-tl-meta">
                                        <i class="bi bi-clock"></i>
                                        <?= niceDate($pay['paid_at'] ?? null) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($recentPurchases)): ?>
                            <div class="sp-tl-item">
                                <span class="sp-tl-dot is-warn"></span>
                                <div class="sp-tl-text">
                                    New purchase from <strong><?= sanitize($recentPurchases[0]['seller_name'] ?? '—') ?></strong>
                                </div>
                                <div class="sp-tl-meta">
                                    <i class="bi bi-box-seam"></i>
                                    <?= kg($recentPurchases[0]['weight_kg'] ?? 0) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="sp-tl-item">
                            <span class="sp-tl-dot is-info"></span>
                            <div class="sp-tl-text">
                                Welcome to <strong>SmartPalay</strong> Buyer Dashboard
                            </div>
                            <div class="sp-tl-meta">
                                <i class="bi bi-stars"></i>
                                Today
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title">
                            <i class="bi bi-cash-coin"></i>
                            Recent Payments
                        </h3>
                        <a href="<?= BASE_URL ?>buyer/payments.php" class="sp-panel-link">
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
                                            <?= sanitize(ucfirst($pay['method'] ?? 'Cash')) ?> ·
                                            <?= niceDate($pay['paid_at'] ?? null) ?>
                                        </span>
                                    </div>
                                    <div class="sp-pay-amount"><?= peso($pay['amount'] ?? 0) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="sp-empty" style="padding: 32px 24px;">
                            <div class="sp-empty-ico" style="width:54px;height:54px;font-size:1.35rem;">
                                <i class="bi bi-receipt"></i>
                            </div>
                            <h4>No payments yet</h4>
                            <p style="margin-bottom:0;">Payments you make will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </section>

        <div class="sp-footnote">
            <span class="sp-footnote-left">
                <i class="bi bi-lightbulb"></i>
                <span>
                    <strong>Pro tip:</strong> Keep your seller contacts updated to record purchases faster.
                </span>
            </span>
            <a href="<?= BASE_URL ?>buyer/sellers.php" class="sp-footnote-right">
                Manage sellers <i class="bi bi-arrow-right"></i>
            </a>
        </div>

    </main>
</div>

<!-- NEW PURCHASE MODAL -->
<div class="sp-modal-backdrop" id="modalNewPurchase">
    <div class="sp-modal" role="dialog" aria-modal="true" aria-labelledby="npTitle">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
            <span class="sp-modal-tag">
                <i class="bi bi-plus-circle"></i>
                New Purchase
            </span>
            <h3 class="sp-modal-title" id="npTitle">Record a Palay Purchase</h3>
            <p class="sp-modal-sub">Fill in the delivery details and we'll calculate the total automatically.</p>
        </div>

        <form id="newPurchaseForm" method="post" autocomplete="off" style="display: contents;">
            <input type="hidden" name="action" value="create_purchase">

            <div class="sp-modal-body">

                <div class="sp-form-row">

                    <div class="sp-form-field is-full">
                        <label for="npSeller">Seller <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-person"></i>
                            <select id="npSeller" name="seller_name" required>
                                <option value="" disabled selected>— Select a seller —</option>
                                <?php foreach ($sellersList as $seller): ?>
                                    <option value="<?= sanitize($seller) ?>"><?= sanitize($seller) ?></option>
                                <?php endforeach; ?>
                                <option value="__new__">+ Add new seller…</option>
                            </select>
                        </div>
                        <div class="sp-form-hint">Choose an existing seller or add a new one.</div>
                    </div>

                    <div class="sp-form-field is-full" id="npNewSellerWrap" style="display:none;">
                        <label for="npNewSeller">New Seller Name <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-person-plus"></i>
                            <input type="text" id="npNewSeller" placeholder="e.g. Mang Jose">
                        </div>
                    </div>

                    <div class="sp-form-field">
                        <label for="npWeight">Weight <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-box-seam"></i>
                            <input type="number" id="npWeight" name="weight_kg" min="0.01" step="0.01" placeholder="0.00" required>
                        </div>
                        <div class="sp-form-hint">In kilograms (kg)</div>
                    </div>

                    <div class="sp-form-field">
                        <label for="npPrice">Price per kg <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-tag"></i>
                            <input type="number" id="npPrice" name="price_per_kg" min="0.01" step="0.01" placeholder="0.00" required>
                        </div>
                        <div class="sp-form-hint">In pesos (₱)</div>
                    </div>

                    <div class="sp-form-field">
                        <label for="npPaid">Amount Paid (optional)</label>
                        <div class="sp-form-input">
                            <i class="bi bi-cash-stack"></i>
                            <input type="number" id="npPaid" name="amount_paid" min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="sp-form-hint">Leave blank if unpaid</div>
                    </div>

                    <div class="sp-form-field">
                        <label for="npDate">Delivery Date</label>
                        <div class="sp-form-input">
                            <i class="bi bi-calendar3"></i>
                            <input type="date" id="npDate" name="created_at">
                        </div>
                        <div class="sp-form-hint">Leave blank to use today.</div>
                    </div>

                    <div class="sp-form-field is-full">
                        <label for="npNotes">Notes (optional)</label>
                        <div class="sp-form-input">
                            <i class="bi bi-chat-left-text"></i>
                            <textarea id="npNotes" name="notes" placeholder="e.g. Delivered to warehouse 2, dry palay…"></textarea>
                        </div>
                    </div>

                </div>

                <div class="sp-form-summary">
                    <span class="sp-form-summary-label"><i class="bi bi-calculator"></i> Total Amount</span>
                    <span class="sp-form-summary-value" id="npTotal">₱0.00</span>
                </div>

                <div class="sp-form-summary" style="background: linear-gradient(135deg, #EAF7EC, #fff); border-color: var(--success-bd);">
                    <span class="sp-form-summary-label"><i class="bi bi-hourglass-split"></i> Balance (remaining)</span>
                    <span class="sp-form-summary-value" id="npBalance" style="color: var(--danger);">₱0.00</span>
                </div>

            </div>

            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-cancel" data-close-modal>
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="submit" class="sp-btn-submit" id="npSubmit">
                    <i class="bi bi-check-lg"></i> Save Purchase
                </button>
            </div>
        </form>
    </div>
</div>

<!-- REPORTS MODAL -->
<div class="sp-modal-backdrop" id="modalReports">
    <div class="sp-modal is-wide" role="dialog" aria-modal="true" aria-labelledby="rpTitle">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
            <span class="sp-modal-tag">
                <i class="bi bi-graph-up-arrow"></i>
                Reports
            </span>
            <h3 class="sp-modal-title" id="rpTitle">Purchase Reports</h3>
            <p class="sp-modal-sub">A snapshot of your palay activity — totals, balances, and trends at a glance.</p>
        </div>

        <div class="sp-modal-body">

            <div class="sp-report-grid">
                <div class="sp-report-card">
                    <div class="sp-report-ico"><i class="bi bi-bag-check"></i></div>
                    <div class="sp-report-body">
                        <div class="sp-report-label">Total Purchases</div>
                        <div class="sp-report-value"><?= number_format($stats['total_purchases']) ?></div>
                    </div>
                </div>

                <div class="sp-report-card">
                    <div class="sp-report-ico is-info"><i class="bi bi-box-seam"></i></div>
                    <div class="sp-report-body">
                        <div class="sp-report-label">Palay Received</div>
                        <div class="sp-report-value"><?= number_format($stats['total_palay_kg'], 0) ?><small>kg</small></div>
                    </div>
                </div>

                <div class="sp-report-card">
                    <div class="sp-report-ico is-success"><i class="bi bi-cash-stack"></i></div>
                    <div class="sp-report-body">
                        <div class="sp-report-label">Total Paid</div>
                        <div class="sp-report-value"><?= peso($stats['total_paid']) ?></div>
                    </div>
                </div>

                <div class="sp-report-card">
                    <div class="sp-report-ico is-warn"><i class="bi bi-hourglass-split"></i></div>
                    <div class="sp-report-body">
                        <div class="sp-report-label">Outstanding</div>
                        <div class="sp-report-value"><?= peso($stats['outstanding_balance']) ?></div>
                    </div>
                </div>
            </div>

            <div class="sp-report-chart">
                <div class="sp-report-chart-title">Weekly Palay Volume (kg)</div>
                <div class="sp-bars">
                    <?php
                        $heights = [42, 68, 55, 82, 74, 90, 60];
                        $days    = ['S','M','T','W','T','F','S'];
                        foreach ($heights as $i => $h):
                    ?>
                        <div class="sp-bar-col">
                            <div class="sp-bar <?= $i === 5 ? '' : 'is-muted' ?>" style="height: <?= $h ?>%"></div>
                            <span class="sp-bar-label"><?= $days[$i] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sp-form-summary" style="background: linear-gradient(135deg, var(--gold-soft), #fff);">
                <span class="sp-form-summary-label">
                    <i class="bi bi-calculator"></i>
                    Grand Total (Paid + Outstanding)
                </span>
                <span class="sp-form-summary-value">
                    <?= peso($totalOwed) ?>
                </span>
            </div>

        </div>

        <div class="sp-modal-foot">
            <button type="button" class="sp-btn-cancel" data-close-modal>
                <i class="bi bi-x-lg"></i> Close
            </button>
            <button type="button" class="sp-btn-submit" id="rpDownload">
                <i class="bi bi-download"></i> Download CSV
            </button>
        </div>
    </div>
</div>

<div class="sp-toast" id="spToast">
    <i class="bi bi-check-circle-fill" id="spToastIcon"></i>
    <span id="spToastText">Saved</span>
</div>

<script>
(() => {
    'use strict';

    const body    = document.body;
    const menuBtn = document.getElementById('menuBtn');
    const overlay = document.getElementById('sidebarOverlay');

    function openSidebar()  { body.classList.add('sp-sidebar-open'); }
    function closeSidebar() { body.classList.remove('sp-sidebar-open'); }

    menuBtn?.addEventListener('click', openSidebar);
    overlay?.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });
    window.addEventListener('resize', () => { if (window.innerWidth > 900) closeSidebar(); });

    const modalNewPurchase = document.getElementById('modalNewPurchase');
    const modalReports     = document.getElementById('modalReports');
    const modals = { newPurchase: modalNewPurchase, reports: modalReports };

    function resetNewPurchaseForm() {
        const form = document.getElementById('newPurchaseForm');
        form?.reset();
        document.getElementById('npNewSellerWrap').style.display = 'none';
        document.getElementById('npDate').value = '';
        recalcTotals();
        const bodyEl = modalNewPurchase.querySelector('.sp-modal-body');
        if (bodyEl) bodyEl.scrollTop = 0;
    }

    function openModal(name) {
        const m = modals[name];
        if (!m) return;
        m.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
            const firstInput = m.querySelector('input:not([type=hidden]), select, textarea');
            firstInput?.focus();
        }, 120);
    }
    function closeModal(m) {
        if (!m) return;
        m.classList.remove('is-open');
        if (!document.querySelector('.sp-modal-backdrop.is-open')) {
            document.body.style.overflow = '';
        }
    }

    document.querySelectorAll('[data-modal]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const name = btn.dataset.modal;
            if (name === 'newPurchase') resetNewPurchaseForm();
            openModal(name);
        });
    });

    document.getElementById('topRecordBtn')?.addEventListener('click', () => { resetNewPurchaseForm(); openModal('newPurchase'); });
    document.getElementById('welcomeRecordBtn')?.addEventListener('click', () => { resetNewPurchaseForm(); openModal('newPurchase'); });
    document.getElementById('welcomeReportsBtn')?.addEventListener('click', () => openModal('reports'));
    document.getElementById('notifBtn')?.addEventListener('click', () => {
        showToast('No new notifications right now.', 'success');
    });

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

    const npForm         = document.getElementById('newPurchaseForm');
    const npSeller       = document.getElementById('npSeller');
    const npNewSellerWrap= document.getElementById('npNewSellerWrap');
    const npNewSeller    = document.getElementById('npNewSeller');
    const npWeight       = document.getElementById('npWeight');
    const npPrice        = document.getElementById('npPrice');
    const npPaid         = document.getElementById('npPaid');
    const npTotal        = document.getElementById('npTotal');
    const npBalance      = document.getElementById('npBalance');

    npSeller?.addEventListener('change', () => {
        const isNew = npSeller.value === '__new__';
        npNewSellerWrap.style.display = isNew ? '' : 'none';
        if (isNew) {
            npNewSeller.focus();
        } else {
            npNewSeller.value = '';
        }
    });

    function pesoFmt(n) {
        n = Number(n) || 0;
        return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function recalcTotals() {
        const w    = parseFloat(npWeight?.value) || 0;
        const p    = parseFloat(npPrice?.value)  || 0;
        const paid = parseFloat(npPaid?.value)   || 0;
        const total = w * p;
        const balance = Math.max(0, total - paid);
        npTotal.textContent   = pesoFmt(total);
        npBalance.textContent = pesoFmt(balance);
        npBalance.style.color = balance <= 0 ? 'var(--success)' : 'var(--danger)';
    }
    npWeight?.addEventListener('input', recalcTotals);
    npPrice?.addEventListener('input', recalcTotals);
    npPaid?.addEventListener('input', recalcTotals);

    npForm?.addEventListener('submit', (e) => {
        if (npSeller.value === '__new__') {
            const newName = (npNewSeller.value || '').trim();
            if (!newName) {
                e.preventDefault();
                showToast('Please enter the new seller name.', 'error');
                npNewSeller.focus();
                return;
            }
            let opt = [...npSeller.options].find(o => o.value === newName);
            if (!opt) {
                opt = document.createElement('option');
                opt.value = newName;
                opt.textContent = newName;
                npSeller.appendChild(opt);
            }
            npSeller.value = newName;
        }
    });

    document.getElementById('rpDownload')?.addEventListener('click', () => {
        const rows = [
            ['Metric', 'Value'],
            ['Total Purchases', '<?= (int) $stats['total_purchases'] ?>'],
            ['Palay Received (kg)', '<?= number_format($stats['total_palay_kg'], 2, ".", "") ?>'],
            ['Total Paid (PHP)', '<?= number_format($stats['total_paid'], 2, ".", "") ?>'],
            ['Outstanding (PHP)', '<?= number_format($stats['outstanding_balance'], 2, ".", "") ?>'],
            ['Grand Total (PHP)', '<?= number_format($totalOwed, 2, ".", "") ?>'],
        ];
        const csv = rows.map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'smartpalay-report-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        showToast('Report downloaded.', 'success');
    });

    const toast     = document.getElementById('spToast');
    const toastIcon = document.getElementById('spToastIcon');
    const toastText = document.getElementById('spToastText');
    let toastTimer;

    function showToast(message, type = 'success') {
        toastText.textContent = message;
        toast.classList.remove('is-success', 'is-error');
        toastIcon.className = 'bi ' + (type === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
        toast.classList.add('is-' + type);
        toast.classList.add('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 3200);
    }

    window.showToast = showToast;

    <?php if (!empty($flash['msg'])): ?>
    window.addEventListener('DOMContentLoaded', () => {
        showToast(<?= json_encode($flash['msg']) ?>, <?= json_encode($flash['type'] === 'success' ? 'success' : 'error') ?>);
    });
    <?php endif; ?>
})();
</script>
</body>
</html>