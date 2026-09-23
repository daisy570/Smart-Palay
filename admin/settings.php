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
   HELPERS
   ============================================================ */
function settings_all(PDO $pdo): array {
    $out = [];
    try {
        foreach ($pdo->query("SELECT key_name, key_value FROM settings")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['key_name']] = $r['key_value'];
        }
    } catch (Throwable $e) {}
    return $out;
}

function settings_set(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("
        INSERT INTO settings (key_name, key_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)
    ");
    $stmt->execute([$key, $value]);
}

/* Detect admin table columns */
$adminCols = [];
try {
    $adminCols = $pdo->query("SHOW COLUMNS FROM admins")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}
$hasAdminEmail  = in_array('email',     $adminCols, true);
$hasAdminPhone  = in_array('phone',     $adminCols, true);
$hasAdminName   = in_array('full_name', $adminCols, true);
$hasAdminAvatar = in_array('avatar',    $adminCols, true);

/* ============================================================
   POST ACTIONS
   ============================================================ */
$flash = ['type' => '', 'msg' => ''];
$activeTab = $_POST['tab'] ?? ($_GET['tab'] ?? 'general');
$activeTab = in_array($activeTab, ['general','account','password','logo','preferences'], true)
    ? $activeTab : 'general';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        /* ---------- SAVE GENERAL ---------- */
        if ($action === 'save_general') {
            $fields = [
                'site_name', 'site_tagline',
                'contact_email', 'contact_phone', 'address',
                'default_price_per_kg', 'low_stock_threshold',
            ];
            foreach ($fields as $f) {
                settings_set($pdo, $f, trim((string) ($_POST[$f] ?? '')));
            }
            $flash = ['type' => 'success', 'msg' => 'General settings saved.'];
            $activeTab = 'general';
        }

        /* ---------- SAVE PREFERENCES ---------- */
        if ($action === 'save_preferences') {
            $fields = ['currency', 'currency_symbol', 'items_per_page', 'date_format', 'timezone'];
            foreach ($fields as $f) {
                settings_set($pdo, $f, trim((string) ($_POST[$f] ?? '')));
            }
            settings_set($pdo, 'maintenance_mode',        isset($_POST['maintenance_mode']) ? '1' : '0');
            settings_set($pdo, 'allow_registration',      isset($_POST['allow_registration']) ? '1' : '0');
            settings_set($pdo, 'email_notifications',     isset($_POST['email_notifications']) ? '1' : '0');
            settings_set($pdo, 'auto_refresh_dashboard',  isset($_POST['auto_refresh_dashboard']) ? '1' : '0');
            $flash = ['type' => 'success', 'msg' => 'Preferences saved.'];
            $activeTab = 'preferences';
        }

        /* ---------- SAVE ACCOUNT ---------- */
        if ($action === 'save_account') {
            $name  = trim($_POST['full_name'] ?? '');
            $mail  = trim($_POST['email']     ?? '');
            $phone = trim($_POST['phone']     ?? '');

            if ($name === '') throw new Exception('Full name is required.');

            if ($hasAdminName) {
                $sets = ['full_name=?'];
                $vals = [$name];
                if ($hasAdminEmail) { $sets[] = 'email=?'; $vals[] = $mail; }
                if ($hasAdminPhone) { $sets[] = 'phone=?'; $vals[] = $phone; }
                $vals[] = $userId;
                $stmt = $pdo->prepare("UPDATE admins SET " . implode(', ', $sets) . " WHERE id=?");
                $stmt->execute($vals);
            } else {
                /* No admins table — save into settings for the current user */
                settings_set($pdo, 'admin_full_name', $name);
                settings_set($pdo, 'admin_email',     $mail);
                settings_set($pdo, 'admin_phone',     $phone);
            }

            $_SESSION['full_name'] = $name;
            $flash = ['type' => 'success', 'msg' => 'Account details updated.'];
            $activeTab = 'account';
        }

        /* ---------- CHANGE PASSWORD ---------- */
        if ($action === 'save_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password']     ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($current === '' || $new === '' || $confirm === '') {
                throw new Exception('All password fields are required.');
            }
            if (strlen($new) < 6) throw new Exception('New password must be at least 6 characters.');
            if ($new !== $confirm) throw new Exception('New password and confirmation do not match.');

            /* Fetch current hash */
            $hash = null;
            try {
                $stmt = $pdo->prepare("SELECT password FROM admins WHERE id = ? LIMIT 1");
                $stmt->execute([$userId]);
                $hash = $stmt->fetchColumn();
            } catch (Throwable $e) {}

            if (!$hash) throw new Exception('Admin account not found.');

            if (!password_verify($current, $hash)) {
                throw new Exception('Current password is incorrect.');
            }

            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?");
            $stmt->execute([$newHash, $userId]);

            $flash = ['type' => 'success', 'msg' => 'Password changed successfully.'];
            $activeTab = 'password';
        }

        /* ---------- UPLOAD LOGO ---------- */
        if ($action === 'upload_logo') {
            if (empty($_FILES['logo']['tmp_name']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please choose a file to upload.');
            }
            $file = $_FILES['logo'];

            if ($file['size'] > 2 * 1024 * 1024) {
                throw new Exception('File is too large (max 2 MB).');
            }

            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
            $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : ($file['type'] ?? '');
            if ($finfo) finfo_close($finfo);

            $allowed = [
                'image/png'  => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
                'image/svg+xml' => 'svg',
            ];
            if (!isset($allowed[$mime])) {
                throw new Exception('Unsupported image type. Use PNG, JPG, WEBP, GIF, or SVG.');
            }

            $ext      = $allowed[$mime];
            $filename = 'logo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;

            $uploadDir = __DIR__ . '/../images/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $dest = $uploadDir . $filename;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                throw new Exception('Could not save the uploaded file.');
            }

            settings_set($pdo, 'site_logo', $filename);
            $flash = ['type' => 'success', 'msg' => 'Logo uploaded successfully.'];
            $activeTab = 'logo';
        }

        /* ---------- REMOVE LOGO ---------- */
        if ($action === 'remove_logo') {
            settings_set($pdo, 'site_logo', '');
            $flash = ['type' => 'success', 'msg' => 'Logo removed.'];
            $activeTab = 'logo';
        }

        /* ---------- RESET DEFAULT SETTINGS ---------- */
        if ($action === 'reset_defaults') {
            $defaults = [
                'site_name'            => 'SmartPalay',
                'site_tagline'         => 'Smart Palay Management System',
                'currency'             => 'PHP',
                'currency_symbol'      => '₱',
                'default_price_per_kg' => '22.00',
                'low_stock_threshold'  => '1000',
                'items_per_page'       => '10',
                'date_format'          => 'M j, Y',
                'timezone'             => 'Asia/Manila',
                'maintenance_mode'     => '0',
                'allow_registration'   => '1',
                'email_notifications'  => '1',
                'auto_refresh_dashboard' => '1',
            ];
            foreach ($defaults as $k => $v) settings_set($pdo, $k, $v);
            $flash = ['type' => 'success', 'msg' => 'Settings reset to defaults.'];
            $activeTab = 'preferences';
        }

    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    }
}

/* ============================================================
   LOAD CURRENT VALUES
   ============================================================ */
$S = settings_all($pdo);

$defaults = [
    'site_name'            => 'SmartPalay',
    'site_tagline'         => 'Smart Palay Management System',
    'contact_email'        => '',
    'contact_phone'        => '',
    'address'              => '',
    'currency'             => 'PHP',
    'currency_symbol'      => '₱',
    'default_price_per_kg' => '22.00',
    'low_stock_threshold'  => '1000',
    'items_per_page'       => '10',
    'date_format'          => 'M j, Y',
    'timezone'             => 'Asia/Manila',
    'maintenance_mode'     => '0',
    'allow_registration'   => '1',
    'email_notifications'  => '1',
    'auto_refresh_dashboard' => '1',
    'site_logo'            => '',
];
foreach ($defaults as $k => $v) {
    if (!isset($S[$k])) $S[$k] = $v;
}

/* Admin profile */
$adminProfile = ['name' => $fullName, 'email' => '', 'phone' => ''];
try {
    if ($hasAdminName || $hasAdminEmail || $hasAdminPhone) {
        $cols = ['id'];
        if ($hasAdminName)   $cols[] = 'full_name';
        if ($hasAdminEmail)  $cols[] = 'email';
        if ($hasAdminPhone)  $cols[] = 'phone';
        $stmt = $pdo->prepare("SELECT " . implode(',', $cols) . " FROM admins WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $adminProfile['name']  = $row['full_name'] ?? $fullName;
            $adminProfile['email'] = $row['email']     ?? '';
            $adminProfile['phone'] = $row['phone']     ?? '';
        }
    }
} catch (Throwable $e) {}

/* Logo resolution: settings > fallback default logo > none */
$siteLogoFile = $S['site_logo'] ?? '';
$logoPath     = '';
if ($siteLogoFile !== '' && is_file(__DIR__ . '/../images/' . $siteLogoFile)) {
    $logoPath = BASE_URL . 'images/' . rawurlencode($siteLogoFile);
} else {
    $defaultName = '5e861afa-4a95-423a-b004-d69c59fa88dc.png';
    if (is_file(__DIR__ . '/../images/' . $defaultName)) {
        $logoPath = BASE_URL . 'images/' . rawurlencode($defaultName);
    }
}

/* Stats for a small header summary */
$stats = ['farmers' => 0, 'buyers' => 0, 'purchases' => 0, 'payments' => 0];
try {
    $stats['farmers']   = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='farmer'")->fetchColumn();
    $stats['buyers']    = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='buyer'")->fetchColumn();
    $stats['purchases'] = (int) $pdo->query("SELECT COUNT(*) FROM purchases")->fetchColumn();
    $stats['payments']  = (int) $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
} catch (Throwable $e) {}

$firstName = sanitize(explode(' ', $adminProfile['name'])[0]);
$timezones = [
    'Asia/Manila', 'Asia/Singapore', 'Asia/Tokyo', 'Asia/Hong_Kong',
    'UTC', 'America/New_York', 'America/Los_Angeles', 'Europe/London', 'Australia/Sydney',
];
$dateFormats = [
    'M j, Y'    => date('M j, Y'),
    'F j, Y'    => date('F j, Y'),
    'Y-m-d'     => date('Y-m-d'),
    'd/m/Y'     => date('d/m/Y'),
    'm/d/Y'     => date('m/d/Y'),
    'D, M j, Y' => date('D, M j, Y'),
];
$currencies = [
    'PHP' => 'PHP — Philippine Peso',
    'USD' => 'USD — US Dollar',
    'EUR' => 'EUR — Euro',
    'JPY' => 'JPY — Japanese Yen',
    'GBP' => 'GBP — British Pound',
    'AUD' => 'AUD — Australian Dollar',
];
$symbols = ['₱' => '₱ Peso', '$' => '$ Dollar', '€' => '€ Euro', '¥' => '¥ Yen', '£' => '£ Pound'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#B0641E">
    <title>Settings | SmartPalay Admin</title>

    <?php if ($logoPath): ?>
        <link rel="icon" type="image/png" href="<?= $logoPath ?>">
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">

    <style>
        /* ============================================================
           SmartPalay — Admin Settings
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

        /* Layout: sidebar tabs + panel */
        .sp-settings-grid {
            display:grid;
            grid-template-columns: 260px minmax(0, 1fr);
            gap: clamp(14px,1.8vw,22px);
            align-items: start;
        }
        @media (max-width: 900px) { .sp-settings-grid { grid-template-columns: 1fr; } }

        .sp-tabs {
            background: #fff;
            border:1px solid var(--line);
            border-radius:18px;
            padding: 12px;
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset, 0 14px 30px -22px rgba(74,44,16,.4);
            position: sticky; top: 90px;
            display:flex; flex-direction:column; gap:4px;
        }
        @media (max-width: 900px) {
            .sp-tabs {
                position: static;
                flex-direction: row;
                flex-wrap: nowrap;
                overflow-x: auto;
                padding: 8px;
                gap: 4px;
            }
            .sp-tabs::-webkit-scrollbar { height: 5px; }
        }
        .sp-tab {
            display:flex; align-items:center; gap:12px;
            padding: 12px 14px;
            border-radius: 11px;
            color: var(--brown-2);
            font-size: .88rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            background: transparent;
            border: 0;
            font-family: inherit;
            text-align: left;
            transition: background .18s, color .18s, transform .18s;
            position: relative;
            white-space: nowrap;
        }
        .sp-tab:hover {
            background: rgba(176,100,30,.07);
            color: var(--gold);
            transform: translateX(2px);
        }
        @media (max-width: 900px) {
            .sp-tab:hover { transform: none; }
        }
        .sp-tab i {
            width: 22px; text-align: center;
            font-size: 1.05rem;
            color: var(--text-3);
            transition: color .18s;
        }
        .sp-tab.is-active {
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            color: var(--brown);
            box-shadow: inset 0 0 0 1px rgba(176,100,30,.25);
        }
        .sp-tab.is-active i { color: var(--gold); }
        .sp-tab.is-active::before {
            content:''; position:absolute; left:0; top:22%; bottom:22%; width:3px;
            border-radius: 0 3px 3px 0;
            background: var(--gold);
        }
        @media (max-width: 900px) {
            .sp-tab.is-active::before {
                top: auto; bottom: 4px; left: 22%; right: 22%;
                width: auto; height: 3px;
                border-radius: 3px 3px 0 0;
            }
        }

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
        .sp-panel-sub {
            font-size: .78rem; color: var(--text-3);
            font-weight: 500; margin-top: 3px;
        }

        .sp-panel-body { padding: 24px 26px; }

        /* Form */
        .sp-section-title {
            display:flex; align-items:center; gap:10px;
            font-size:.68rem; font-weight:700; letter-spacing:1.2px;
            text-transform:uppercase; color: var(--brown-2);
            margin: 8px 0 14px;
            padding-top: 14px;
            border-top: 1px dashed var(--line-2);
        }
        .sp-section-title:first-child { border-top: 0; padding-top: 0; margin-top: 0; }
        .sp-section-title i {
            width:22px; height:22px; border-radius:7px;
            background: var(--gold-soft); color: var(--gold);
            display:grid; place-items:center; font-size:.8rem;
        }

        .sp-form-row {
            display:grid;
            grid-template-columns: 1fr 1fr;
            gap:16px;
            margin-bottom: 16px;
        }
        @media (max-width:600px) { .sp-form-row { grid-template-columns: 1fr; } }

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

        .sp-form-hint {
            font-size:.7rem; color: var(--text-3);
            font-weight:500; padding-left:2px;
        }

        /* Toggle switch */
        .sp-toggle-row {
            display:flex; align-items:center; justify-content:space-between;
            gap:16px;
            padding: 14px 16px;
            border-radius: 12px;
            background: var(--cream-2);
            border: 1px solid var(--line);
            transition: border-color .18s, background .18s;
        }
        .sp-toggle-row:hover { border-color: var(--line-3); background: #fff; }
        .sp-toggle-row + .sp-toggle-row { margin-top: 10px; }

        .sp-toggle-info { min-width: 0; }
        .sp-toggle-label {
            font-size: .88rem;
            font-weight: 600;
            color: var(--brown);
            margin: 0;
            display:flex; align-items:center; gap:8px;
        }
        .sp-toggle-label i { color: var(--gold); font-size:1rem; }
        .sp-toggle-desc {
            font-size: .74rem; color: var(--text-3);
            margin: 3px 0 0 0;
            line-height: 1.5;
        }
        .sp-switch {
            position: relative;
            width: 46px; height: 26px;
            flex-shrink: 0;
        }
        .sp-switch input {
            opacity: 0; width: 0; height: 0;
            position: absolute;
        }
        .sp-switch-label {
            position: absolute; inset: 0;
            background: var(--line-2);
            border-radius: 999px;
            cursor: pointer;
            transition: background .25s;
        }
        .sp-switch-label::after {
            content: '';
            position: absolute;
            top: 3px; left: 3px;
            width: 20px; height: 20px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,.15);
            transition: transform .25s cubic-bezier(.2,.7,.3,1);
        }
        .sp-switch input:checked + .sp-switch-label {
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
        }
        .sp-switch input:checked + .sp-switch-label::after {
            transform: translateX(20px);
        }
        .sp-switch input:focus-visible + .sp-switch-label {
            box-shadow: 0 0 0 4px rgba(176,100,30,.2);
        }

        /* Buttons */
        .sp-form-actions {
            display:flex; align-items:center; justify-content:flex-end;
            gap: 10px; flex-wrap: wrap;
            margin-top: 22px;
            padding-top: 20px;
            border-top: 1px solid var(--line);
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
            text-decoration:none;
            transition: border-color .15s, color .15s, transform .15s;
        }
        .sp-btn-cancel:hover { border-color: var(--gold); color: var(--gold); transform: translateY(-1px); }
        .sp-btn-danger {
            display:inline-flex; align-items:center; justify-content:center;
            gap:8px;
            padding:12px 22px;
            border-radius:11px;
            border:1.5px solid var(--danger-bd);
            background:#fff;
            color: var(--danger);
            font-weight:700; font-size:.87rem;
            cursor:pointer; font-family:inherit;
            transition: background .15s, transform .15s, border-color .15s;
        }
        .sp-btn-danger:hover {
            background: var(--danger-bg);
            border-color: var(--danger);
            transform: translateY(-1px);
        }

        /* Logo upload */
        .sp-logo-uploader {
            display: grid;
            grid-template-columns: 200px minmax(0, 1fr);
            gap: 22px;
            align-items: start;
        }
        @media (max-width:600px) { .sp-logo-uploader { grid-template-columns: 1fr; } }

        .sp-logo-preview {
            position: relative;
            width: 100%;
            aspect-ratio: 1 / 1;
            max-width: 200px;
            border-radius: 20px;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            border: 1.5px dashed rgba(176,100,30,.35);
            display: grid; place-items: center;
            overflow: hidden;
            box-shadow: 0 1px 0 rgba(255,255,255,.9) inset, 0 14px 30px -22px rgba(74,44,16,.4);
        }
        .sp-logo-preview img {
            width: 80%; height: 80%;
            object-fit: contain;
            display: block;
        }
        .sp-logo-preview .sp-logo-empty {
            text-align: center;
            padding: 16px;
            color: var(--text-3);
            font-size: .8rem;
            font-weight: 500;
        }
        .sp-logo-preview .sp-logo-empty i {
            display: block;
            font-size: 2.5rem;
            color: var(--gold);
            margin-bottom: 8px;
            opacity: .6;
        }

        .sp-upload-box {
            padding: 20px;
            border-radius: 14px;
            border: 1.5px dashed var(--line-2);
            background: var(--cream-2);
            transition: border-color .18s, background .18s;
            display: flex; flex-direction: column; gap: 14px;
        }
        .sp-upload-box:hover { border-color: var(--gold-3); background: #fff; }

        .sp-file-input {
            position: relative;
            display: flex; align-items: center; gap: 12px;
        }
        .sp-file-input input[type=file] { display: none; }
        .sp-file-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 18px;
            border-radius: 11px;
            border: 1.5px solid var(--line-2);
            background: #fff;
            color: var(--brown-2);
            font-weight: 600; font-size: .85rem;
            cursor: pointer; font-family: inherit;
            transition: border-color .15s, color .15s;
        }
        .sp-file-btn:hover { border-color: var(--gold); color: var(--gold); }
        .sp-file-name {
            font-size: .82rem;
            color: var(--text-3);
            font-weight: 500;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            flex: 1;
        }

        /* Account avatar */
        .sp-account-header {
            display: flex; align-items: center; gap: 20px;
            padding: 20px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--gold-soft), #fff);
            border: 1px solid rgba(176,100,30,.22);
            margin-bottom: 22px;
        }
        .sp-account-avatar {
            width: 72px; height: 72px;
            border-radius: 50%;
            display: grid; place-items: center;
            background: linear-gradient(135deg, #F4C87A 0%, #C97628 100%);
            color: #fff;
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.6rem; font-weight: 700;
            box-shadow: 0 10px 22px -10px rgba(176,100,30,.8);
            flex-shrink: 0;
            letter-spacing: 1px;
        }
        .sp-account-info { min-width: 0; }
        .sp-account-name {
            font-family:'Fraunces', Georgia, serif;
            font-size: 1.3rem; font-weight: 700;
            color: var(--brown); margin: 0 0 4px;
            letter-spacing: -.3px;
        }
        .sp-account-meta {
            font-size: .82rem; color: var(--text-3);
            font-weight: 500; margin: 0;
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        }
        .sp-account-meta span {
            display: inline-flex; align-items: center; gap: 5px;
        }
        .sp-account-meta i { color: var(--gold); font-size: .85rem; }

        .sp-role-chip {
            display:inline-flex; align-items:center; gap:5px;
            padding: 3px 10px; border-radius:999px;
            font-size:.66rem; font-weight:700;
            letter-spacing:.4px; text-transform:uppercase;
            background:#EFE3FB; color:#5B2A9A;
            border:1px solid #D9C2F5;
        }
        .sp-role-chip i { color: inherit; font-size: .8rem; }

        /* Password strength */
        .sp-strength {
            margin-top: 8px;
            display: flex; flex-direction: column; gap: 6px;
        }
        .sp-strength-bar {
            height: 5px;
            border-radius: 5px;
            background: var(--paper-2);
            overflow: hidden;
        }
        .sp-strength-fill {
            height: 100%;
            width: 0;
            border-radius: 5px;
            transition: width .28s cubic-bezier(.2,.7,.3,1), background .28s;
            background: var(--danger);
        }
        .sp-strength-text {
            font-size: .72rem;
            font-weight: 600;
            color: var(--text-3);
            display: flex; align-items: center; gap: 6px;
        }
        .sp-strength-text strong { color: var(--brown-2); font-weight: 700; }

        /* Info banner */
        .sp-info {
            display:flex; align-items:center; gap: 12px;
            padding: 14px 18px;
            border-radius: 14px;
            background: var(--info-bg);
            border: 1px solid #C6DCF0;
            color: var(--info);
            font-size: .84rem;
            font-weight: 500;
            margin-bottom: 20px;
        }
        .sp-info i { font-size: 1.1rem; flex-shrink: 0; }

        .sp-warn {
            background: var(--warn-bg);
            border-color: var(--warn-bd);
            color: var(--warn);
        }

        /* Small stat summary at top */
        .sp-mini-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            padding: 16px 22px;
            border-bottom: 1px solid var(--line);
            background: var(--cream-2);
        }
        .sp-mini-stat {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px;
            border-radius: 12px;
            background: #fff;
            border: 1px solid var(--line);
        }
        .sp-mini-ico {
            width: 34px; height: 34px;
            border-radius: 10px;
            display: grid; place-items: center;
            background: var(--gold-soft); color: var(--gold);
            font-size: .95rem;
            flex-shrink: 0;
            border: 1px solid rgba(176,100,30,.2);
        }
        .sp-mini-ico.is-info    { background: var(--info-bg);    color: var(--info);    border-color: #C6DCF0; }
        .sp-mini-ico.is-success { background: var(--success-bg); color: var(--success); border-color: var(--success-bd); }
        .sp-mini-ico.is-warn    { background: var(--warn-bg);    color: var(--warn);    border-color: var(--warn-bd); }
        .sp-mini-label {
            font-size: .64rem; font-weight: 700; letter-spacing: 1.1px;
            text-transform: uppercase; color: var(--text-3);
            margin: 0 0 2px;
        }
        .sp-mini-value {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1rem; font-weight: 700;
            color: var(--brown); line-height: 1.1;
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
            .sp-panel-body { padding: 18px 16px; }
            .sp-cta span { display:none; }
            .sp-cta { padding:10px 12px; }
            .sp-form-actions { justify-content: stretch; }
            .sp-btn-submit, .sp-btn-cancel, .sp-btn-danger { flex: 1; }
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
                <?php if ($logoPath): ?>
                    <img src="<?= $logoPath ?>" alt="SmartPalay">
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
        <a href="<?= BASE_URL ?>admin/reports.php" class="sp-nav-link">
            <i class="bi bi-graph-up-arrow"></i> Reports
        </a>
        <a href="<?= BASE_URL ?>admin/settings.php" class="sp-nav-link <?= $activeTab !== 'account' ? 'is-active' : '' ?>">
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
                <h1 class="sp-page-title">Settings</h1>
                <span class="sp-page-sub">
                    System configuration &amp; account —
                    <strong><?= sanitize($adminProfile['name']) ?></strong>
                </span>
            </div>
        </div>

        <div class="sp-topbar-right">
            <button class="sp-icon-btn" id="notifBtn" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <span class="badge-dot"></span>
            </button>
            <a href="<?= BASE_URL ?>admin/dashboard.php" class="sp-cta">
                <i class="bi bi-house-door"></i>
                <span>Dashboard</span>
            </a>
        </div>
    </header>

    <main class="sp-content">

        <?php if (!empty($flash['msg'])): ?>
            <div class="sp-flash is-<?= htmlspecialchars($flash['type']) ?>">
                <i class="bi <?= $flash['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?>"></i>
                <span><?= htmlspecialchars($flash['msg']) ?></span>
            </div>
        <?php endif; ?>

        <div class="sp-settings-grid">

            <!-- Tabs -->
            <nav class="sp-tabs" id="settingsTabs">
                <a href="?tab=general"     class="sp-tab <?= $activeTab === 'general'     ? 'is-active' : '' ?>">
                    <i class="bi bi-sliders"></i> General
                </a>
                <a href="?tab=account"     class="sp-tab <?= $activeTab === 'account'     ? 'is-active' : '' ?>">
                    <i class="bi bi-person-circle"></i> My Account
                </a>
                <a href="?tab=password"    class="sp-tab <?= $activeTab === 'password'    ? 'is-active' : '' ?>">
                    <i class="bi bi-shield-lock"></i> Password
                </a>
                <a href="?tab=logo"        class="sp-tab <?= $activeTab === 'logo'        ? 'is-active' : '' ?>">
                    <i class="bi bi-image"></i> Logo
                </a>
                <a href="?tab=preferences" class="sp-tab <?= $activeTab === 'preferences' ? 'is-active' : '' ?>">
                    <i class="bi bi-gear-wide-connected"></i> Preferences
                </a>
            </nav>

            <div>

                <!-- ============================================
                     GENERAL TAB
                     ============================================ -->
                <?php if ($activeTab === 'general'): ?>
                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <div>
                            <h3 class="sp-panel-title">
                                <i class="bi bi-sliders"></i>
                                General Settings
                            </h3>
                            <p class="sp-panel-sub">Site identity and default business values.</p>
                        </div>
                    </div>

                    <div class="sp-mini-stats">
                        <div class="sp-mini-stat">
                            <div class="sp-mini-ico is-success"><i class="bi bi-person-badge"></i></div>
                            <div>
                                <p class="sp-mini-label">Sellers</p>
                                <div class="sp-mini-value"><?= number_format($stats['farmers']) ?></div>
                            </div>
                        </div>
                        <div class="sp-mini-stat">
                            <div class="sp-mini-ico is-info"><i class="bi bi-bag-check"></i></div>
                            <div>
                                <p class="sp-mini-label">Buyers</p>
                                <div class="sp-mini-value"><?= number_format($stats['buyers']) ?></div>
                            </div>
                        </div>
                        <div class="sp-mini-stat">
                            <div class="sp-mini-ico is-warn"><i class="bi bi-receipt"></i></div>
                            <div>
                                <p class="sp-mini-label">Purchases</p>
                                <div class="sp-mini-value"><?= number_format($stats['purchases']) ?></div>
                            </div>
                        </div>
                        <div class="sp-mini-stat">
                            <div class="sp-mini-ico"><i class="bi bi-cash-coin"></i></div>
                            <div>
                                <p class="sp-mini-label">Payments</p>
                                <div class="sp-mini-value"><?= number_format($stats['payments']) ?></div>
                            </div>
                        </div>
                    </div>

                    <form method="post" class="sp-panel-body" autocomplete="off">
                        <input type="hidden" name="action" value="save_general">
                        <input type="hidden" name="tab"    value="general">

                        <div class="sp-section-title">
                            <i class="bi bi-building"></i> Site Identity
                        </div>

                        <div class="sp-form-row">
                            <div class="sp-form-field is-full">
                                <label for="gs_site_name">Site Name <span class="req">*</span></label>
                                <div class="sp-form-input">
                                    <i class="bi bi-tag"></i>
                                    <input type="text" id="gs_site_name" name="site_name" required
                                           value="<?= sanitize($S['site_name']) ?>"
                                           placeholder="e.g. SmartPalay">
                                </div>
                            </div>

                            <div class="sp-form-field is-full">
                                <label for="gs_tagline">Tagline</label>
                                <div class="sp-form-input">
                                    <i class="bi bi-chat-quote"></i>
                                    <input type="text" id="gs_tagline" name="site_tagline"
                                           value="<?= sanitize($S['site_tagline']) ?>"
                                           placeholder="e.g. Smart Palay Management System">
                                </div>
                            </div>
                        </div>

                        <div class="sp-section-title">
                            <i class="bi bi-telephone"></i> Contact Information
                        </div>

                        <div class="sp-form-row">
                            <div class="sp-form-field">
                                <label for="gs_email">Contact Email</label>
                                <div class="sp-form-input">
                                    <i class="bi bi-envelope"></i>
                                    <input type="email" id="gs_email" name="contact_email"
                                           value="<?= sanitize($S['contact_email']) ?>"
                                           placeholder="admin@example.com">
                                </div>
                            </div>

                            <div class="sp-form-field">
                                <label for="gs_phone">Contact Phone</label>
                                <div class="sp-form-input">
                                    <i class="bi bi-telephone"></i>
                                    <input type="text" id="gs_phone" name="contact_phone"
                                           value="<?= sanitize($S['contact_phone']) ?>"
                                           placeholder="+63 917 123 4567">
                                </div>
                            </div>

                            <div class="sp-form-field is-full">
                                <label for="gs_address">Business Address</label>
                                <div class="sp-form-input">
                                    <i class="bi bi-geo-alt"></i>
                                    <input type="text" id="gs_address" name="address"
                                           value="<?= sanitize($S['address']) ?>"
                                           placeholder="Street, Barangay, City, Province">
                                </div>
                            </div>
                        </div>

                        <div class="sp-section-title">
                            <i class="bi bi-calculator"></i> Business Defaults
                        </div>

                        <div class="sp-form-row">
                            <div class="sp-form-field">
                                <label for="gs_price">Default Price / kg</label>
                                <div class="sp-form-input">
                                    <i class="bi bi-tag-fill"></i>
                                    <input type="number" id="gs_price" name="default_price_per_kg"
                                           min="0" step="0.01"
                                           value="<?= sanitize($S['default_price_per_kg']) ?>"
                                           placeholder="22.00">
                                </div>
                                <div class="sp-form-hint">Used as default in new purchase forms.</div>
                            </div>

                            <div class="sp-form-field">
                                <label for="gs_threshold">Low Stock Threshold (kg)</label>
                                <div class="sp-form-input">
                                    <i class="bi bi-speedometer"></i>
                                    <input type="number" id="gs_threshold" name="low_stock_threshold"
                                           min="0" step="1"
                                           value="<?= sanitize($S['low_stock_threshold']) ?>"
                                           placeholder="1000">
                                </div>
                                <div class="sp-form-hint">Alerts trigger below this value.</div>
                            </div>
                        </div>

                        <div class="sp-form-actions">
                            <button type="submit" class="sp-btn-submit">
                                <i class="bi bi-check-lg"></i> Save General Settings
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- ============================================
                     ACCOUNT TAB
                     ============================================ -->
                <?php if ($activeTab === 'account'): ?>
                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <div>
                            <h3 class="sp-panel-title">
                                <i class="bi bi-person-circle"></i>
                                My Account
                            </h3>
                            <p class="sp-panel-sub">Update your personal details.</p>
                        </div>
                    </div>

                    <div class="sp-panel-body">

                        <div class="sp-account-header">
                            <div class="sp-account-avatar">
                                <?php
                                    $parts = preg_split('/\s+/', trim($adminProfile['name']));
                                    echo htmlspecialchars(strtoupper(
                                        mb_substr($parts[0] ?? '?', 0, 1) .
                                        (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '')
                                    ));
                                ?>
                            </div>
                            <div class="sp-account-info">
                                <h3 class="sp-account-name"><?= sanitize($adminProfile['name']) ?></h3>
                                <p class="sp-account-meta">
                                    <span class="sp-role-chip">
                                        <i class="bi bi-shield-lock"></i> Administrator
                                    </span>
                                    <?php if (!empty($adminProfile['email'])): ?>
                                        <span><i class="bi bi-envelope"></i> <?= sanitize($adminProfile['email']) ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <?php if (!$hasAdminName && !$hasAdminEmail): ?>
                            <div class="sp-info sp-warn">
                                <i class="bi bi-info-circle"></i>
                                <span>
                                    Your <code>admins</code> table doesn't have <code>full_name</code>/<code>email</code> columns.
                                    Details will be stored in the <code>settings</code> table instead.
                                </span>
                            </div>
                        <?php endif; ?>

                        <form method="post" autocomplete="off">
                            <input type="hidden" name="action" value="save_account">
                            <input type="hidden" name="tab"    value="account">

                            <div class="sp-section-title">
                                <i class="bi bi-person"></i> Personal Information
                            </div>

                            <div class="sp-form-row">
                                <div class="sp-form-field is-full">
                                    <label for="ac_name">Full Name <span class="req">*</span></label>
                                    <div class="sp-form-input">
                                        <i class="bi bi-person"></i>
                                        <input type="text" id="ac_name" name="full_name" required
                                               value="<?= sanitize($adminProfile['name']) ?>"
                                               placeholder="Your full name">
                                    </div>
                                </div>

                                <div class="sp-form-field">
                                    <label for="ac_email">Email Address</label>
                                    <div class="sp-form-input">
                                        <i class="bi bi-envelope"></i>
                                        <input type="email" id="ac_email" name="email"
                                               value="<?= sanitize($adminProfile['email']) ?>"
                                               placeholder="you@example.com">
                                    </div>
                                </div>

                                <div class="sp-form-field">
                                    <label for="ac_phone">Phone Number</label>
                                    <div class="sp-form-input">
                                        <i class="bi bi-telephone"></i>
                                        <input type="text" id="ac_phone" name="phone"
                                               value="<?= sanitize($adminProfile['phone']) ?>"
                                               placeholder="+63 917 123 4567">
                                    </div>
                                </div>
                            </div>

                            <div class="sp-form-actions">
                                <button type="submit" class="sp-btn-submit">
                                    <i class="bi bi-check-lg"></i> Save Account Details
                                </button>
                            </div>
                        </form>

                    </div>
                </div>
                <?php endif; ?>

                <!-- ============================================
                     PASSWORD TAB
                     ============================================ -->
                <?php if ($activeTab === 'password'): ?>
                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <div>
                            <h3 class="sp-panel-title">
                                <i class="bi bi-shield-lock"></i>
                                Change Password
                            </h3>
                            <p class="sp-panel-sub">Keep your admin account secure.</p>
                        </div>
                    </div>

                    <form method="post" class="sp-panel-body" autocomplete="off" id="pwdForm">
                        <input type="hidden" name="action" value="save_password">
                        <input type="hidden" name="tab"    value="password">

                        <div class="sp-info">
                            <i class="bi bi-lightbulb"></i>
                            <span>Use at least <strong>6 characters</strong>, and mix letters, numbers, and symbols for better security.</span>
                        </div>

                        <div class="sp-section-title">
                            <i class="bi bi-key"></i> Password Change
                        </div>

                        <div class="sp-form-row">
                            <div class="sp-form-field is-full">
                                <label for="pw_current">Current Password <span class="req">*</span></label>
                                <div class="sp-form-input">
                                    <i class="bi bi-lock"></i>
                                    <input type="password" id="pw_current" name="current_password" required
                                           placeholder="Enter your current password">
                                    <button type="button" class="js-toggle-pw"
                                            style="border:0;background:transparent;padding:0 14px 0 4px;cursor:pointer;color:var(--text-3);">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="sp-form-field">
                                <label for="pw_new">New Password <span class="req">*</span></label>
                                <div class="sp-form-input">
                                    <i class="bi bi-key"></i>
                                    <input type="password" id="pw_new" name="new_password" required
                                           minlength="6"
                                           placeholder="Minimum 6 characters">
                                    <button type="button" class="js-toggle-pw"
                                            style="border:0;background:transparent;padding:0 14px 0 4px;cursor:pointer;color:var(--text-3);">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="sp-strength">
                                    <div class="sp-strength-bar"><div class="sp-strength-fill" id="pwStrengthFill"></div></div>
                                    <div class="sp-strength-text" id="pwStrengthText">
                                        <i class="bi bi-dot"></i> Strength: <strong>—</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="sp-form-field">
                                <label for="pw_confirm">Confirm New Password <span class="req">*</span></label>
                                <div class="sp-form-input">
                                    <i class="bi bi-key-fill"></i>
                                    <input type="password" id="pw_confirm" name="confirm_password" required
                                           minlength="6"
                                           placeholder="Re-enter new password">
                                    <button type="button" class="js-toggle-pw"
                                            style="border:0;background:transparent;padding:0 14px 0 4px;cursor:pointer;color:var(--text-3);">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="sp-form-hint" id="pwMatchHint">&nbsp;</div>
                            </div>
                        </div>

                        <div class="sp-form-actions">
                            <button type="submit" class="sp-btn-submit">
                                <i class="bi bi-shield-check"></i> Update Password
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <!-- ============================================
                     LOGO TAB
                     ============================================ -->
                <?php if ($activeTab === 'logo'): ?>
                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <div>
                            <h3 class="sp-panel-title">
                                <i class="bi bi-image"></i>
                                Site Logo
                            </h3>
                            <p class="sp-panel-sub">Upload a logo to display across SmartPalay.</p>
                        </div>
                    </div>

                    <div class="sp-panel-body">

                        <div class="sp-logo-uploader">

                            <div class="sp-logo-preview">
                                <?php if (!empty($S['site_logo']) && is_file(__DIR__ . '/../images/' . $S['site_logo'])): ?>
                                    <img src="<?= BASE_URL ?>images/<?= rawurlencode($S['site_logo']) ?>?v=<?= time() ?>" alt="Site logo">
                                <?php elseif ($logoPath): ?>
                                    <img src="<?= $logoPath ?>" alt="Default logo">
                                <?php else: ?>
                                    <div class="sp-logo-empty">
                                        <i class="bi bi-image"></i>
                                        No logo set
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="sp-upload-box">
                                <form method="post" enctype="multipart/form-data" autocomplete="off" id="logoForm">
                                    <input type="hidden" name="action" value="upload_logo">
                                    <input type="hidden" name="tab"    value="logo">

                                    <div class="sp-section-title" style="margin-top:0;border-top:0;padding-top:0;">
                                        <i class="bi bi-cloud-upload"></i> Upload New Logo
                                    </div>

                                    <div class="sp-file-input">
                                        <label for="logoFile" class="sp-file-btn">
                                            <i class="bi bi-folder2-open"></i> Choose File
                                        </label>
                                        <input type="file" id="logoFile" name="logo"
                                               accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml">
                                        <span class="sp-file-name" id="logoFileName">No file selected</span>
                                    </div>

                                    <div class="sp-form-hint">
                                        Max size: <strong>2 MB</strong>. Accepted: PNG, JPG, WEBP, GIF, SVG.
                                    </div>

                                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;">
                                        <button type="submit" class="sp-btn-submit" id="logoSubmit" disabled>
                                            <i class="bi bi-cloud-upload"></i> Upload Logo
                                        </button>

                                        <?php if (!empty($S['site_logo'])): ?>
                                            <button type="submit" class="sp-btn-danger"
                                                    form="removeLogoForm"
                                                    onclick="return confirm('Remove the current logo?');">
                                                <i class="bi bi-trash3"></i> Remove
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </form>

                                <?php if (!empty($S['site_logo'])): ?>
                                <form method="post" id="removeLogoForm" style="display:none;">
                                    <input type="hidden" name="action" value="remove_logo">
                                    <input type="hidden" name="tab"    value="logo">
                                </form>
                                <?php endif; ?>
                            </div>

                        </div>

                    </div>
                </div>
                <?php endif; ?>

                <!-- ============================================
                     PREFERENCES TAB
                     ============================================ -->
                <?php if ($activeTab === 'preferences'): ?>
                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <div>
                            <h3 class="sp-panel-title">
                                <i class="bi bi-gear-wide-connected"></i>
                                Preferences
                            </h3>
                            <p class="sp-panel-sub">Regional settings and system behavior.</p>
                        </div>
                    </div>

                    <form method="post" class="sp-panel-body" autocomplete="off">
                        <input type="hidden" name="action" value="save_preferences">
                        <input type="hidden" name="tab"    value="preferences">

                        <div class="sp-section-title">
                            <i class="bi bi-globe"></i> Regional Format
                        </div>

                        <div class="sp-form-row">
                            <div class="sp-form-field">
                                <label for="pr_currency">Currency</label>
                                <div class="sp-form-input">
                                    <i class="bi bi-currency-exchange"></i>
                                    <select id="pr_currency" name="currency">
                                        <?php foreach ($currencies as $code => $label): ?>
                                            <option value="<?= sanitize($code) ?>" <?= $S['currency'] === $code ? 'selected' : '' ?>>
                                                <?= sanitize($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="sp-form-field">
                                <label for="pr_symbol">Currency Symbol</label>
                                <div class="sp-form-input">
                                    <i class="bi bi-coin"></i>
                                    <select id="pr_symbol" name="currency_symbol">
                                        <?php foreach ($symbols as $sym => $lbl): ?>
                                            <option value="<?= sanitize($sym) ?>" <?= $S['currency_symbol'] === $sym ? 'selected' : '' ?>>
                                                <?= sanitize($lbl) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="sp-form-field">
                                <label for="pr_date">Date Format</label>
                                <div class="sp-form-input">
                                    <i class="bi bi-calendar3"></i>
                                    <select id="pr_date" name="date_format">
                                        <?php foreach ($dateFormats as $fmt => $preview): ?>
                                            <option value="<?= sanitize($fmt) ?>" <?= $S['date_format'] === $fmt ? 'selected' : '' ?>>
                                                <?= sanitize($fmt) ?> — <?= sanitize($preview) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="sp-form-field">
                                <label for="pr_pagesize">Items per Page</label>
                                <div class="sp-form-input">
                                    <i class="bi bi-list-ol"></i>
                                    <select id="pr_pagesize" name="items_per_page">
                                        <?php foreach ([10,15,20,25,50,100] as $n): ?>
                                            <option value="<?= $n ?>" <?= (string)$S['items_per_page'] === (string)$n ? 'selected' : '' ?>>
                                                <?= $n ?> per page
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="sp-form-field is-full">
                                <label for="pr_timezone">Timezone</label>
                                <div class="sp-form-input">
                                    <i class="bi bi-clock-history"></i>
                                    <select id="pr_timezone" name="timezone">
                                        <?php foreach ($timezones as $tz): ?>
                                            <option value="<?= sanitize($tz) ?>" <?= $S['timezone'] === $tz ? 'selected' : '' ?>>
                                                <?= sanitize($tz) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="sp-section-title">
                            <i class="bi bi-toggles"></i> System Behavior
                        </div>

                        <div class="sp-toggle-row">
                            <div class="sp-toggle-info">
                                <p class="sp-toggle-label"><i class="bi bi-tools"></i> Maintenance Mode</p>
                                <p class="sp-toggle-desc">Temporarily disable public access. Only admins can log in.</p>
                            </div>
                            <label class="sp-switch">
                                <input type="checkbox" name="maintenance_mode" value="1" <?= $S['maintenance_mode'] === '1' ? 'checked' : '' ?>>
                                <span class="sp-switch-label"></span>
                            </label>
                        </div>

                        <div class="sp-toggle-row">
                            <div class="sp-toggle-info">
                                <p class="sp-toggle-label"><i class="bi bi-person-plus"></i> Allow Registration</p>
                                <p class="sp-toggle-desc">Let new buyers and sellers sign up for accounts.</p>
                            </div>
                            <label class="sp-switch">
                                <input type="checkbox" name="allow_registration" value="1" <?= $S['allow_registration'] === '1' ? 'checked' : '' ?>>
                                <span class="sp-switch-label"></span>
                            </label>
                        </div>

                        <div class="sp-toggle-row">
                            <div class="sp-toggle-info">
                                <p class="sp-toggle-label"><i class="bi bi-envelope-check"></i> Email Notifications</p>
                                <p class="sp-toggle-desc">Send email alerts for new purchases and payments.</p>
                            </div>
                            <label class="sp-switch">
                                <input type="checkbox" name="email_notifications" value="1" <?= $S['email_notifications'] === '1' ? 'checked' : '' ?>>
                                <span class="sp-switch-label"></span>
                            </label>
                        </div>

                        <div class="sp-toggle-row">
                            <div class="sp-toggle-info">
                                <p class="sp-toggle-label"><i class="bi bi-arrow-repeat"></i> Auto-refresh Dashboard</p>
                                <p class="sp-toggle-desc">Automatically refresh dashboard stats every 60 seconds.</p>
                            </div>
                            <label class="sp-switch">
                                <input type="checkbox" name="auto_refresh_dashboard" value="1" <?= $S['auto_refresh_dashboard'] === '1' ? 'checked' : '' ?>>
                                <span class="sp-switch-label"></span>
                            </label>
                        </div>

                        <div class="sp-form-actions">
                            <button type="submit" class="sp-btn-danger"
                                    form="resetForm"
                                    onclick="return confirm('Reset all preferences to defaults?');">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset to Defaults
                            </button>
                            <button type="submit" class="sp-btn-submit">
                                <i class="bi bi-check-lg"></i> Save Preferences
                            </button>
                        </div>

                    </form>

                    <form method="post" id="resetForm" style="display:none;">
                        <input type="hidden" name="action" value="reset_defaults">
                        <input type="hidden" name="tab"    value="preferences">
                    </form>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </main>
</div>

<div class="sp-toast" id="spToast">
    <i class="bi bi-check-circle-fill" id="spToastIcon"></i>
    <span id="spToastText">Ready</span>
</div>

<script>
(() => {
    'use strict';

    /* ---------- Sidebar ---------- */
    const body    = document.body;
    const menuBtn = document.getElementById('menuBtn');
    const overlay = document.getElementById('sidebarOverlay');

    menuBtn?.addEventListener('click', () => body.classList.add('sp-sidebar-open'));
    overlay?.addEventListener('click', () => body.classList.remove('sp-sidebar-open'));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') body.classList.remove('sp-sidebar-open'); });
    window.addEventListener('resize', () => { if (window.innerWidth > 900) body.classList.remove('sp-sidebar-open'); });

    /* ---------- Toast ---------- */
    const toast     = document.getElementById('spToast');
    const toastIcon = document.getElementById('spToastIcon');
    const toastText = document.getElementById('spToastText');
    let toastTimer;

    function showToast(message, type = 'success') {
        if (!toast) return;
        toastText.textContent = message;
        toast.classList.remove('is-success','is-error');
        toastIcon.className = 'bi ' + (type === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
        toast.classList.add('is-' + type, 'is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 3200);
    }

    document.getElementById('notifBtn')?.addEventListener('click', () => {
        showToast('No new system notifications.', 'success');
    });

    /* ---------- Password toggle ---------- */
    document.querySelectorAll('.js-toggle-pw').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = btn.parentElement?.querySelector('input');
            if (!input) return;
            const isPw = input.type === 'password';
            input.type = isPw ? 'text' : 'password';
            const icon = btn.querySelector('i');
            if (icon) icon.className = isPw ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    });

    /* ---------- Password strength ---------- */
    const pwNew      = document.getElementById('pw_new');
    const pwConfirm  = document.getElementById('pw_confirm');
    const strengthFill = document.getElementById('pwStrengthFill');
    const strengthText = document.getElementById('pwStrengthText');
    const matchHint    = document.getElementById('pwMatchHint');

    function scorePassword(pw) {
        let score = 0;
        if (!pw) return 0;
        if (pw.length >= 6)  score++;
        if (pw.length >= 10) score++;
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
        if (/\d/.test(pw))   score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        return Math.min(5, score);
    }

    function updateStrength() {
        if (!pwNew || !strengthFill) return;
        const s = scorePassword(pwNew.value);
        const pct = s * 20;
        strengthFill.style.width = pct + '%';

        let color = 'var(--danger)';
        let label = 'Very weak';
        let ico   = 'bi-emoji-frown';
        if (s >= 5) { color = 'var(--success)'; label = 'Very strong'; ico = 'bi-shield-fill-check'; }
        else if (s === 4) { color = 'var(--success)'; label = 'Strong'; ico = 'bi-shield-fill-check'; }
        else if (s === 3) { color = 'var(--gold-2)'; label = 'Fair'; ico = 'bi-shield-fill'; }
        else if (s === 2) { color = 'var(--warn)'; label = 'Weak'; ico = 'bi-shield-exclamation'; }

        strengthFill.style.background = color;
        strengthText.innerHTML = '<i class="bi ' + ico + '"></i> Strength: <strong style="color:' + color + '">' + label + '</strong>';
    }

    function updateMatch() {
        if (!pwConfirm || !matchHint) return;
        const a = pwNew?.value || '';
        const b = pwConfirm.value || '';
        if (b === '') {
            matchHint.innerHTML = '&nbsp;';
            matchHint.style.color = 'var(--text-3)';
            return;
        }
        if (a === b) {
            matchHint.innerHTML = '<i class="bi bi-check-circle-fill"></i> Passwords match';
            matchHint.style.color = 'var(--success)';
            matchHint.style.fontWeight = '600';
        } else {
            matchHint.innerHTML = '<i class="bi bi-x-circle-fill"></i> Passwords do not match';
            matchHint.style.color = 'var(--danger)';
            matchHint.style.fontWeight = '600';
        }
    }

    pwNew?.addEventListener('input', () => { updateStrength(); updateMatch(); });
    pwConfirm?.addEventListener('input', updateMatch);

    /* Block submit if mismatch */
    document.getElementById('pwdForm')?.addEventListener('submit', (e) => {
        const a = pwNew.value || '';
        const b = pwConfirm.value || '';
        if (a !== b) {
            e.preventDefault();
            showToast('Passwords do not match.', 'error');
            pwConfirm.focus();
        }
    });

    /* ---------- Logo upload ---------- */
    const logoInput  = document.getElementById('logoFile');
    const logoName   = document.getElementById('logoFileName');
    const logoSubmit = document.getElementById('logoSubmit');

    logoInput?.addEventListener('change', () => {
        const f = logoInput.files?.[0];
        if (f) {
            logoName.textContent = f.name + ' (' + (f.size / 1024).toFixed(1) + ' KB)';
            if (logoSubmit) logoSubmit.disabled = false;
        } else {
            logoName.textContent = 'No file selected';
            if (logoSubmit) logoSubmit.disabled = true;
        }
    });

    /* ---------- Auto-hide flash after 5s ---------- */
    const flash = document.querySelector('.sp-flash');
    if (flash) {
        setTimeout(() => {
            flash.style.transition = 'opacity .4s, transform .4s';
            flash.style.opacity = '0';
            flash.style.transform = 'translateY(-6px)';
            setTimeout(() => flash.remove(), 400);
        }, 5000);
    }
})();
</script>
</body>
</html>