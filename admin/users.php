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

/* ============================================================
   HELPERS
   ============================================================ */
function detectUserImageColumn(PDO $pdo): ?string {
    static $col = null;
    static $checked = false;
    if ($checked) return $col;
    $checked = true;

    $candidates = ['profile_image','profile_picture','avatar','image','photo','user_image','picture'];
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($candidates as $c) {
            if (in_array($c, $cols, true)) { $col = $c; return $col; }
        }
    } catch (Throwable $e) {}
    return $col;
}

function userAvatarUrl($filename) {
    if (empty($filename)) return '';
    if (preg_match('~^https?://~i', $filename)) return $filename;

    $filename = ltrim(str_replace('\\', '/', $filename), '/');

    $folders = [
        'uploads/avatars/',
        'uploads/users/',
        'uploads/profiles/',
        'uploads/profile/',
        'images/users/',
        'images/profiles/',
        'images/avatars/',
        'assets/uploads/',
        'uploads/',
        'images/',
    ];

    $diskDirect = __DIR__ . '/../' . $filename;
    if (is_file($diskDirect)) {
        return BASE_URL . $filename;
    }

    foreach ($folders as $folder) {
        $disk = __DIR__ . '/../' . $folder . $filename;
        if (is_file($disk)) {
            return BASE_URL . $folder . rawurlencode($filename);
        }
    }
    return '';
}

function handleAvatarUpload($file) {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Image upload failed.');
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new Exception('Only JPG, PNG, WEBP, or GIF images are allowed.');
    }
    if ($file['size'] > 3 * 1024 * 1024) {
        throw new Exception('Image must be smaller than 3MB.');
    }
    $dir = __DIR__ . '/../uploads/avatars';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $name = 'u_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new Exception('Could not save uploaded image.');
    }
    return $name;
}

$imageCol = detectUserImageColumn($pdo);

/* ============================================================
   HANDLE ACTIONS (POST)
   ============================================================ */
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        /* ---------- CREATE ---------- */
        if ($action === 'create') {
            $name  = trim($_POST['full_name'] ?? '');
            $mail  = trim($_POST['email'] ?? '');
            $role  = $_POST['role'] ?? '';
            $pass  = $_POST['password'] ?? '';
            $contact = trim($_POST['contact'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($name === '' || $mail === '' || $pass === '') {
                throw new Exception('Name, email and password are required.');
            }
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }
            /* Only buyer and farmer can be created; admin accounts are managed separately */
            if (!in_array($role, ['buyer','farmer'], true)) {
                throw new Exception('Please select a valid role (Buyer or Seller).');
            }

            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $chk->execute([$mail]);
            if ($chk->fetchColumn()) {
                throw new Exception('That email is already registered.');
            }

            $hash = password_hash($pass, PASSWORD_DEFAULT);

            $profileImage = null;
            if (!empty($_FILES['profile_image']['name'])) {
                $profileImage = handleAvatarUpload($_FILES['profile_image']);
            }

            if ($imageCol) {
                $stmt = $pdo->prepare("
                    INSERT INTO users (full_name, email, password, role, phone, address, `{$imageCol}`, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$name, $mail, $hash, $role, $contact, $address, $profileImage]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO users (full_name, email, password, role, phone, address, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$name, $mail, $hash, $role, $contact, $address]);
            }

            $flash = ['type' => 'success', 'msg' => "User “{$name}” created successfully."];
        }

        /* ---------- UPDATE ---------- */
        if ($action === 'update') {
            $id    = (int) ($_POST['id'] ?? 0);
            $name  = trim($_POST['full_name'] ?? '');
            $mail  = trim($_POST['email'] ?? '');
            $role  = $_POST['role'] ?? '';
            $pass  = $_POST['password'] ?? '';
            $contact = trim($_POST['contact'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($id <= 0) throw new Exception('Invalid user ID.');
            if ($name === '' || $mail === '') throw new Exception('Name and email are required.');
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) throw new Exception('Please enter a valid email address.');

            /* Fetch current user to know their existing role */
            $curRow = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $curRow->execute([$id]);
            $currentRole = $curRow->fetchColumn();
            if (!$currentRole) throw new Exception('User not found.');

            /* Admin accounts: role cannot be changed */
            if ($currentRole === 'admin') {
                $role = 'admin';
            } else {
                if (!in_array($role, ['buyer','farmer'], true)) {
                    throw new Exception('Please select a valid role (Buyer or Seller).');
                }
            }

            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
            $chk->execute([$mail, $id]);
            if ($chk->fetchColumn()) throw new Exception('That email is already used by another user.');

            /* Existing image */
            $existingImage = null;
            if ($imageCol) {
                $cur = $pdo->prepare("SELECT `{$imageCol}` FROM users WHERE id = ? LIMIT 1");
                $cur->execute([$id]);
                $existingImage = $cur->fetchColumn() ?: null;
            }

            $newImage = $existingImage;
            if (!empty($_FILES['profile_image']['name'])) {
                $newImage = handleAvatarUpload($_FILES['profile_image']);
            }

            if ($pass !== '') {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                if ($imageCol) {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET full_name=?, email=?, role=?, phone=?, address=?, `{$imageCol}`=?, password=?
                        WHERE id=?
                    ");
                    $stmt->execute([$name, $mail, $role, $contact, $address, $newImage, $hash, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET full_name=?, email=?, role=?, phone=?, address=?, password=?
                        WHERE id=?
                    ");
                    $stmt->execute([$name, $mail, $role, $contact, $address, $hash, $id]);
                }
            } else {
                if ($imageCol) {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET full_name=?, email=?, role=?, phone=?, address=?, `{$imageCol}`=?
                        WHERE id=?
                    ");
                    $stmt->execute([$name, $mail, $role, $contact, $address, $newImage, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET full_name=?, email=?, role=?, phone=?, address=?
                        WHERE id=?
                    ");
                    $stmt->execute([$name, $mail, $role, $contact, $address, $id]);
                }
            }

            $flash = ['type' => 'success', 'msg' => "User “{$name}” updated."];
        }

        /* ---------- DELETE ---------- */
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid user ID.');
            if ($id === $userId) throw new Exception('You cannot delete your own account.');

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);

            $flash = ['type' => 'success', 'msg' => 'User deleted successfully.'];
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => $e->getMessage()];
    }
}

/* ============================================================
   FILTERS + PAGINATION
   ============================================================ */
$q       = trim($_GET['q'] ?? '');
$role    = $_GET['role'] ?? '';
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($q !== '') {
    $where[]  = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $like     = "%{$q}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if (in_array($role, ['admin','buyer','farmer'], true)) {
    $where[]  = "role = ?";
    $params[] = $role;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalUsers = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users {$whereSql}");
    $stmt->execute($params);
    $totalUsers = (int) $stmt->fetchColumn();
} catch (Throwable $e) { $totalUsers = 0; }

$totalPages = max(1, (int) ceil($totalUsers / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$selectImage = $imageCol ? ", `{$imageCol}` AS profile_image" : ", NULL AS profile_image";
$users = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, role, phone AS contact, address, created_at
               {$selectImage}
        FROM users
        {$whereSql}
        ORDER BY id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $users = []; }

$roleCounts = ['admin' => 0, 'buyer' => 0, 'farmer' => 0];
try {
    $rows = $pdo->query("SELECT role, COUNT(*) c FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($roleCounts as $r => $_) $roleCounts[$r] = (int) ($rows[$r] ?? 0);
} catch (Throwable $e) {}

function niceDate($s) {
    if (!$s) return '—';
    $t = strtotime($s);
    return $t ? date('M j, Y', $t) : '—';
}
function initials($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= strtoupper(mb_substr($p, 0, 1));
    }
    return $out ?: '?';
}

/* Current admin's own avatar */
$myAvatarFile = '';
try {
    if ($imageCol) {
        $s = $pdo->prepare("SELECT `{$imageCol}` FROM users WHERE id = ? LIMIT 1");
        $s->execute([$userId]);
        $myAvatarFile = (string) ($s->fetchColumn() ?: '');
    }
} catch (Throwable $e) {}
$myAvatarUrl = userAvatarUrl($myAvatarFile);

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
    <title>Users | SmartPalay Admin</title>

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

        .sp-flash {
            display:flex; align-items:center; gap:12px;
            padding:14px 18px;
            border-radius:14px;
            font-size:.88rem; font-weight:500;
            border:1px solid;
            animation: spFlashIn .35s ease both;
        }
        @keyframes spFlashIn {
            from { opacity:0; transform: translateY(-6px); }
            to   { opacity:1; transform: translateY(0); }
        }
        .sp-flash.is-success {
            background: var(--success-bg);
            border-color: var(--success-bd);
            color: var(--success);
        }
        .sp-flash.is-error {
            background: var(--danger-bg);
            border-color: var(--danger-bd);
            color: var(--danger);
        }
        .sp-flash i { font-size:1.2rem; }
        .sp-flash strong { font-weight:700; }

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
        .sp-stat-ico.is-success { background: var(--success-bg); color: var(--success); border-color: var(--success-bd); }
        .sp-stat-ico.is-info    { background: var(--info-bg);    color: var(--info);    border-color: #C6DCF0; }
        .sp-stat-ico.is-warn    { background: var(--warn-bg);    color: var(--warn);    border-color: var(--warn-bd); }

        .sp-stat-label {
            font-size:.62rem; font-weight:700; letter-spacing:1.1px;
            text-transform:uppercase; color: var(--text-3); margin-bottom:4px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sp-stat-value {
            font-family:'Fraunces', Georgia, serif;
            font-size: clamp(1.35rem, 1.9vw, 1.7rem);
            font-weight:700; letter-spacing:-.5px;
            color: var(--brown); line-height:1.05;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sp-stat-foot {
            display:flex; align-items:center; gap:5px;
            margin-top:auto;
            font-size:.68rem; font-weight:500; color: var(--text-3);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sp-stat-foot i { font-size:.78rem; color: var(--gold); flex-shrink: 0; }

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
            display:flex; align-items:center; gap:12px;
            flex-wrap: wrap;
        }
        .sp-search {
            display:flex; align-items:center;
            background:#fff;
            border:1.5px solid var(--line-2);
            border-radius:11px;
            transition: border-color .18s, box-shadow .18s;
            overflow:hidden;
            min-height:42px;
            flex: 1 1 240px;
            max-width: 340px;
        }
        .sp-search:focus-within {
            border-color: var(--gold);
            box-shadow: 0 0 0 4px rgba(176,100,30,.13);
        }
        .sp-search i {
            padding: 0 4px 0 14px;
            color: var(--text-3);
            font-size:.95rem;
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
            position: relative;
            display: inline-flex;
            align-items: center;
            background:#fff;
            border:1.5px solid var(--line-2);
            border-radius: 11px;
            min-height: 42px;
            padding-right: 12px;
            transition: border-color .18s, box-shadow .18s;
        }
        .sp-filter:focus-within {
            border-color: var(--gold);
            box-shadow: 0 0 0 4px rgba(176,100,30,.13);
        }
        .sp-filter i {
            padding: 0 4px 0 14px;
            color: var(--text-3);
            font-size:.95rem;
        }
        .sp-filter select {
            border:0; outline:0; background:transparent;
            padding: 10px 6px;
            font-size:.86rem; font-weight:500;
            color: #2A1E10; font-family:inherit;
            cursor:pointer;
            appearance: none;
            padding-right: 22px;
            -webkit-text-fill-color: #2A1E10;
        }
        .sp-filter select option {
            color: #2A1E10;
            background: #fff;
        }

        .sp-btn-clear {
            display:inline-flex; align-items:center; gap:6px;
            padding:9px 14px;
            border-radius:11px;
            border:1.5px solid var(--line-2);
            background:#fff;
            color: var(--brown-2);
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

        .sp-user-cell {
            display:flex; align-items:center; gap:12px;
        }
        .sp-avatar {
            width:40px; height:40px; border-radius:50%;
            display:grid; place-items:center;
            background: linear-gradient(135deg, #F4C87A 0%, #C97628 100%);
            color:#fff; font-weight:700; font-size:.8rem;
            letter-spacing:.5px;
            box-shadow: 0 3px 8px -3px rgba(176,100,30,.7);
            flex-shrink: 0;
            font-family:'Fraunces', Georgia, serif;
            overflow: hidden;
        }
        .sp-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
        }
        .sp-user-meta { min-width:0; }
        .sp-user-name {
            font-weight:600; color: var(--brown);
            font-size:.9rem; margin:0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 240px;
        }
        .sp-user-email {
            font-size:.74rem; color: var(--text-3);
            margin:0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 240px;
        }

        .sp-role {
            display:inline-flex; align-items:center; gap:5px;
            padding:4px 10px; border-radius:999px;
            font-size:.68rem; font-weight:700; letter-spacing:.3px; text-transform:uppercase;
        }
        .sp-role-admin  { background:#EFE3FB; color:#5B2A9A; border:1px solid #D9C2F5; }
        .sp-role-buyer  { background: var(--info-bg);    color: var(--info);    border:1px solid #C6DCF0; }
        .sp-role-farmer { background: var(--success-bg); color: var(--success); border:1px solid var(--success-bd); }

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
            border-color: var(--gold);
            color: var(--gold);
            transform: translateY(-1px);
        }
        .sp-action-btn.is-danger:hover {
            border-color: var(--danger);
            color: var(--danger);
            background: var(--danger-bg);
        }
        .sp-action-btn:disabled { opacity:.4; cursor:not-allowed; }

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
        .sp-pages {
            display:flex; align-items:center; gap:5px;
        }
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
            border-color: transparent;
            color:#fff;
            box-shadow: 0 3px 8px -3px rgba(176,100,30,.7);
        }
        .sp-page-btn:disabled {
            opacity:.45; cursor:not-allowed;
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
            width:100%; max-width:640px;
            max-height: calc(100vh - 40px);
            display:flex; flex-direction:column;
            overflow:hidden;
            box-shadow: 0 46px 100px -34px rgba(40,22,6,.8);
            transform: scale(.95) translateY(10px);
            transition: transform .3s cubic-bezier(.2,.7,.3,1);
        }
        .sp-modal.is-danger { max-width: 480px; }
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
            display:flex; flex-direction:column;
            gap:16px;
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
            overflow:hidden;
            min-height:46px;
        }
        .sp-form-input:focus-within {
            border-color: var(--gold);
            box-shadow: 0 0 0 4px rgba(176,100,30,.13);
        }
        .sp-form-input i {
            padding: 0 4px 0 14px;
            color: var(--text-3);
            font-size:.95rem;
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
            color: #2A1E10; font-family:inherit;
            min-width:0; width:100%;
            -webkit-text-fill-color: #2A1E10;
        }

        .sp-form-input select,
        .sp-form-input select:focus,
        .sp-form-input select:active {
            color: #2A1E10 !important;
            -webkit-text-fill-color: #2A1E10 !important;
            background-color: #fff;
        }
        .sp-form-input select option {
            color: #2A1E10;
            background-color: #fff;
        }
        .sp-form-input select option[value=""] {
            color: #A9A392;
        }

        .sp-form-input textarea { resize: vertical; min-height:76px; padding-top:12px; }
        .sp-form-input input::placeholder,
        .sp-form-input textarea::placeholder { color:#A9A392; font-weight:400; }
        .sp-form-hint {
            font-size:.7rem; color: var(--text-3);
            font-weight:500; padding-left:2px;
        }

        /* Locked role display when editing an admin */
        .sp-role-locked {
            display:flex; align-items:center; gap:10px;
            background:#EFE3FB;
            border:1.5px solid #D9C2F5;
            border-radius:11px;
            padding: 11px 14px;
            min-height:46px;
        }
        .sp-role-locked i {
            color:#5B2A9A; font-size:1rem; padding:0;
        }
        .sp-role-locked .sp-role-locked-label {
            font-size:.85rem; font-weight:700;
            color:#5B2A9A; letter-spacing:.3px;
        }
        .sp-role-locked .sp-role-locked-note {
            margin-left:auto;
            font-size:.68rem; font-weight:600;
            color:#8E6FBF;
            text-transform:uppercase; letter-spacing:.6px;
        }

        .sp-form-input input[type="file"] {
            padding: 8px 14px 8px 10px;
            font-size: .82rem;
            color: var(--text-2);
            cursor: pointer;
        }
        .sp-form-input input[type="file"]::file-selector-button {
            border: 1.5px solid var(--line-2);
            background: var(--cream-2);
            color: var(--brown-2);
            font-weight: 600;
            font-size: .78rem;
            padding: 6px 12px;
            border-radius: 8px;
            margin-right: 12px;
            cursor: pointer;
            font-family: inherit;
            transition: border-color .15s, color .15s;
        }
        .sp-form-input input[type="file"]::file-selector-button:hover {
            border-color: var(--gold);
            color: var(--gold);
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
        .sp-btn-submit:disabled { opacity:.6; cursor:not-allowed; transform:none; }
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
            font-size: 1.6rem;
            color: var(--danger);
            flex-shrink: 0;
        }
        .sp-danger-preview strong { color: var(--brown); font-weight: 700; }
        .sp-danger-preview small {
            display: block; color: var(--text-3);
            font-size: .78rem; margin-top: 2px;
        }

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
        <div class="sp-user-avatar" title="<?= sanitize($fullName) ?>">
            <div class="sp-user-avatar-img">
                <?php if ($myAvatarUrl): ?>
                    <img src="<?= htmlspecialchars($myAvatarUrl) ?>" alt="<?= sanitize($fullName) ?>">
                <?php elseif ($smartPalayLogo): ?>
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
        <a href="<?= BASE_URL ?>admin/users.php" class="sp-nav-link is-active">
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
                <h1 class="sp-page-title">Users</h1>
                <span class="sp-page-sub">Manage all accounts — <strong><?= number_format($totalUsers) ?></strong> total</span>
            </div>
        </div>

        <div class="sp-topbar-right">
            <button class="sp-icon-btn" id="notifBtn" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <span class="badge-dot"></span>
            </button>
            <button type="button" class="sp-cta" id="topAddBtn">
                <i class="bi bi-person-plus"></i>
                <span>Add User</span>
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
                    <div class="sp-stat-ico"><i class="bi bi-people"></i></div>
                </div>
                <div>
                    <div class="sp-stat-label">Total Users</div>
                    <div class="sp-stat-value"><?= number_format(array_sum($roleCounts)) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-person-check"></i> All accounts</div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-success"><i class="bi bi-person-badge"></i></div>
                </div>
                <div>
                    <div class="sp-stat-label">Sellers</div>
                    <div class="sp-stat-value"><?= number_format($roleCounts['farmer']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-tree"></i> Palay producers</div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-info"><i class="bi bi-bag-check"></i></div>
                </div>
                <div>
                    <div class="sp-stat-label">Buyers</div>
                    <div class="sp-stat-value"><?= number_format($roleCounts['buyer']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-shop"></i> Purchasing accounts</div>
            </article>

            <article class="sp-stat">
                <div class="sp-stat-head">
                    <div class="sp-stat-ico is-warn"><i class="bi bi-shield-lock"></i></div>
                </div>
                <div>
                    <div class="sp-stat-label">Administrators</div>
                    <div class="sp-stat-value"><?= number_format($roleCounts['admin']) ?></div>
                </div>
                <div class="sp-stat-foot"><i class="bi bi-key"></i> System access</div>
            </article>
        </section>

        <section class="sp-panel">

            <div class="sp-panel-head">
                <h3 class="sp-panel-title">
                    <i class="bi bi-people"></i>
                    All Users
                </h3>

                <form method="get" class="sp-toolbar" id="filterForm">
                    <label class="sp-search">
                        <i class="bi bi-search"></i>
                        <input type="search" name="q" placeholder="Search name, email, or contact…"
                               value="<?= htmlspecialchars($q) ?>" autocomplete="off">
                    </label>
                    <label class="sp-filter">
                        <i class="bi bi-funnel"></i>
                        <select name="role" onchange="document.getElementById('filterForm').submit()">
                            <option value="">All roles</option>
                            <option value="farmer" <?= $role === 'farmer' ? 'selected' : '' ?>>Sellers</option>
                            <option value="buyer"  <?= $role === 'buyer'  ? 'selected' : '' ?>>Buyers</option>
                            <option value="admin"  <?= $role === 'admin'  ? 'selected' : '' ?>>Admins</option>
                        </select>
                    </label>
                    <?php if ($q !== '' || $role !== ''): ?>
                        <a href="users.php" class="sp-btn-clear">
                            <i class="bi bi-x-lg"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (!empty($users)): ?>
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Contact</th>
                                <th>Joined</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <?php
                                    $r = strtolower($u['role'] ?? 'buyer');
                                    $roleCls = $r === 'admin' ? 'sp-role-admin' : ($r === 'farmer' ? 'sp-role-farmer' : 'sp-role-buyer');
                                    $roleIco = $r === 'admin' ? 'bi-shield-lock' : ($r === 'farmer' ? 'bi-person-badge' : 'bi-bag-check');
                                    $roleLbl = $r === 'farmer' ? 'Seller' : ucfirst($r);
                                    $isSelf  = ((int)$u['id'] === $userId);
                                    $avatarUrl = userAvatarUrl($u['profile_image'] ?? '');
                                ?>
                                <tr>
                                    <td>
                                        <div class="sp-user-cell">
                                            <div class="sp-avatar">
                                                <?php if ($avatarUrl): ?>
                                                    <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= sanitize($u['full_name']) ?>">
                                                <?php else: ?>
                                                    <?= htmlspecialchars(initials($u['full_name'])) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="sp-user-meta">
                                                <p class="sp-user-name">
                                                    <?= sanitize($u['full_name'] ?? '—') ?>
                                                    <?php if ($isSelf): ?>
                                                        <span class="sp-role sp-role-admin" style="margin-left:6px;">You</span>
                                                    <?php endif; ?>
                                                </p>
                                                <p class="sp-user-email"><?= sanitize($u['email'] ?? '') ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="sp-role <?= $roleCls ?>">
                                            <i class="bi <?= $roleIco ?>"></i> <?= $roleLbl ?>
                                        </span>
                                    </td>
                                    <td><?= sanitize($u['contact'] ?? '—') ?></td>
                                    <td><?= niceDate($u['created_at'] ?? null) ?></td>
                                    <td>
                                        <div class="sp-actions">
                                            <button type="button" class="sp-action-btn js-edit"
                                                title="Edit user"
                                                data-id="<?= (int) $u['id'] ?>"
                                                data-name="<?= sanitize($u['full_name'] ?? '') ?>"
                                                data-email="<?= sanitize($u['email'] ?? '') ?>"
                                                data-role="<?= sanitize($r) ?>"
                                                data-contact="<?= sanitize($u['contact'] ?? '') ?>"
                                                data-address="<?= sanitize($u['address'] ?? '') ?>">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="sp-action-btn is-danger js-delete"
                                                title="Delete user"
                                                data-id="<?= (int) $u['id'] ?>"
                                                data-name="<?= sanitize($u['full_name'] ?? '') ?>"
                                                data-email="<?= sanitize($u['email'] ?? '') ?>"
                                                <?= $isSelf ? 'disabled' : '' ?>>
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
                        Showing <strong><?= count($users) ?></strong> of
                        <strong><?= number_format($totalUsers) ?></strong> users
                        · Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
                    </div>

                    <div class="sp-pages">
                        <?php
                            $qs = $_GET; unset($qs['page']);
                            $buildUrl = function ($p) use ($qs) {
                                $qs['page'] = $p;
                                return 'users.php?' . http_build_query($qs);
                            };
                        ?>
                        <a class="sp-page-btn" href="<?= $page <= 1 ? '#' : htmlspecialchars($buildUrl($page - 1)) ?>"
                           <?= $page <= 1 ? 'aria-disabled="true" style="pointer-events:none;opacity:.45;"' : '' ?>>
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
                           <?= $page >= $totalPages ? 'aria-disabled="true" style="pointer-events:none;opacity:.45;"' : '' ?>>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <div class="sp-empty">
                    <div class="sp-empty-ico"><i class="bi bi-person-x"></i></div>
                    <h4><?= ($q !== '' || $role !== '') ? 'No users match your filters' : 'No users yet' ?></h4>
                    <p>
                        <?= ($q !== '' || $role !== '')
                            ? 'Try adjusting your search or clearing the filters.'
                            : 'Add your first user to get started.' ?>
                    </p>
                    <?php if ($q !== '' || $role !== ''): ?>
                        <a href="users.php" class="sp-btn-cancel">
                            <i class="bi bi-x-lg"></i> Clear filters
                        </a>
                    <?php else: ?>
                        <button type="button" class="sp-btn-submit" id="emptyAddBtn">
                            <i class="bi bi-person-plus"></i> Add User
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </section>

        <div class="sp-footnote" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:16px 22px;border-radius:16px;background:linear-gradient(135deg,var(--gold-soft),#fff);border:1px solid rgba(176,100,30,.22);font-size:.82rem;color:var(--text-2);">
            <span style="display:inline-flex;align-items:center;gap:12px;font-weight:500;">
                <i class="bi bi-shield-check" style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;display:grid;place-items:center;font-size:1rem;"></i>
                <span><strong style="color:var(--brown);font-weight:700;">Security tip:</strong> Review admin accounts regularly and remove inactive ones.</span>
            </span>
            <a href="<?= BASE_URL ?>admin/settings.php" style="display:inline-flex;align-items:center;gap:8px;color:var(--gold);font-weight:700;text-decoration:none;">
                Security settings <i class="bi bi-arrow-right"></i>
            </a>
        </div>

    </main>
</div>

<!-- ADD / EDIT MODAL -->
<div class="sp-modal-backdrop" id="modalUser">
    <div class="sp-modal" role="dialog" aria-modal="true">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
            <span class="sp-modal-tag" id="userModalTag">
                <i class="bi bi-person-plus"></i>
                <span id="userModalTagText">New User</span>
            </span>
            <h3 class="sp-modal-title" id="userModalTitle">Add a User</h3>
            <p class="sp-modal-sub" id="userModalSub">Create a new account and assign a role.</p>
        </div>

        <form id="userForm" method="post" autocomplete="off" enctype="multipart/form-data" style="display:contents;">
            <input type="hidden" name="action" id="userAction" value="create">
            <input type="hidden" name="id" id="userId" value="">

            <div class="sp-modal-body">
                <div class="sp-form-row">

                    <div class="sp-form-field is-full">
                        <label for="uf_name">Full Name <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-person"></i>
                            <input type="text" id="uf_name" name="full_name" required placeholder="e.g. Juan Dela Cruz">
                        </div>
                    </div>

                    <div class="sp-form-field">
                        <label for="uf_email">Email <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-envelope"></i>
                            <input type="email" id="uf_email" name="email" required placeholder="name@example.com">
                        </div>
                    </div>

                    <!-- Role: dropdown (for create / buyer / farmer) -->
                    <div class="sp-form-field" id="roleFieldSelect">
                        <label for="uf_role">Role <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-shield-lock"></i>
                            <select id="uf_role" name="role" required>
                                <option value="" disabled selected>Select a role…</option>
                                <option value="buyer">Buyer</option>
                                <option value="farmer">Seller</option>
                            </select>
                        </div>
                    </div>

                    <!-- Role: locked badge (for existing admins) -->
                    <div class="sp-form-field" id="roleFieldLocked" style="display:none;">
                        <label>Role</label>
                        <div class="sp-role-locked">
                            <i class="bi bi-shield-lock-fill"></i>
                            <span class="sp-role-locked-label">Administrator</span>
                            <span class="sp-role-locked-note">Locked</span>
                        </div>
                        <div class="sp-form-hint">Administrator role cannot be changed.</div>
                        <input type="hidden" name="role" id="uf_role_locked" value="admin" disabled>
                    </div>

                    <div class="sp-form-field">
                        <label for="uf_password">Password <span class="req" id="pwReq">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-key"></i>
                            <input type="password" id="uf_password" name="password" placeholder="••••••••">
                        </div>
                        <div class="sp-form-hint" id="pwHint">Minimum 6 characters.</div>
                    </div>

                    <div class="sp-form-field">
                        <label for="uf_contact">Contact Number</label>
                        <div class="sp-form-input">
                            <i class="bi bi-telephone"></i>
                            <input type="text" id="uf_contact" name="contact" placeholder="e.g. 0917 123 4567">
                        </div>
                    </div>

                    <div class="sp-form-field is-full">
                        <label for="uf_address">Address</label>
                        <div class="sp-form-input">
                            <i class="bi bi-geo-alt"></i>
                            <input type="text" id="uf_address" name="address" placeholder="e.g. Brgy. San Isidro, Nueva Ecija">
                        </div>
                    </div>

                    <div class="sp-form-field is-full">
                        <label for="uf_image">Profile Picture</label>
                        <div class="sp-form-input">
                            <i class="bi bi-image"></i>
                            <input type="file" id="uf_image" name="profile_image" accept="image/png,image/jpeg,image/webp,image/gif">
                        </div>
                        <div class="sp-form-hint">JPG, PNG, WEBP or GIF. Max 3MB. Leave blank to keep existing photo.</div>
                    </div>

                </div>
            </div>

            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-cancel" data-close-modal>
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="submit" class="sp-btn-submit" id="userSubmit">
                    <i class="bi bi-check-lg"></i> Save User
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
            <h3 class="sp-modal-title">Delete this user?</h3>
            <p class="sp-modal-sub">This action cannot be undone. All related records may be affected.</p>
        </div>

        <form method="post" id="deleteForm" style="display:contents;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId" value="">

            <div class="sp-modal-body">
                <div class="sp-danger-preview">
                    <i class="bi bi-person-x"></i>
                    <div>
                        <strong id="deleteName">—</strong>
                        <small id="deleteEmail">—</small>
                    </div>
                </div>
            </div>

            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-cancel" data-close-modal>
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="submit" class="sp-btn-danger">
                    <i class="bi bi-trash3"></i> Delete User
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
    const modalUser   = document.getElementById('modalUser');
    const modalDelete = document.getElementById('modalDelete');

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

    /* Elements */
    const userForm     = document.getElementById('userForm');
    const userAction   = document.getElementById('userAction');
    const userId       = document.getElementById('userId');
    const uf_name      = document.getElementById('uf_name');
    const uf_email     = document.getElementById('uf_email');
    const uf_role      = document.getElementById('uf_role');
    const uf_roleLocked= document.getElementById('uf_role_locked');
    const uf_password  = document.getElementById('uf_password');
    const uf_contact   = document.getElementById('uf_contact');
    const uf_address   = document.getElementById('uf_address');
    const uf_image     = document.getElementById('uf_image');
    const pwReq        = document.getElementById('pwReq');
    const pwHint       = document.getElementById('pwHint');
    const userModalTagText = document.getElementById('userModalTagText');
    const userModalTitle   = document.getElementById('userModalTitle');
    const userModalSub     = document.getElementById('userModalSub');
    const roleFieldSelect  = document.getElementById('roleFieldSelect');
    const roleFieldLocked  = document.getElementById('roleFieldLocked');

    function showRoleSelect() {
        roleFieldSelect.style.display = '';
        roleFieldLocked.style.display = 'none';
        uf_role.disabled = false;
        uf_role.required = true;
        uf_roleLocked.disabled = true;
    }
    function showRoleLocked() {
        roleFieldSelect.style.display = 'none';
        roleFieldLocked.style.display = '';
        uf_role.disabled = true;
        uf_role.required = false;
        uf_roleLocked.disabled = false; // submit "admin" via hidden input
    }

    function resetUserForm() {
        userForm.reset();
        userAction.value = 'create';
        userId.value = '';
        pwReq.style.display = '';
        pwHint.textContent = 'Minimum 6 characters.';
        uf_password.placeholder = '••••••••';
        uf_password.required = true;
        userModalTagText.textContent = 'New User';
        userModalTitle.textContent = 'Add a User';
        userModalSub.textContent = 'Create a new account and assign a role.';
        uf_role.value = '';   // empty by default
        if (uf_image) uf_image.value = '';
        showRoleSelect();
    }

    document.getElementById('topAddBtn')?.addEventListener('click', () => {
        resetUserForm();
        openModal(modalUser);
    });
    document.getElementById('emptyAddBtn')?.addEventListener('click', () => {
        resetUserForm();
        openModal(modalUser);
    });

    /* Edit user */
    document.querySelectorAll('.js-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            const d = btn.dataset;
            const wantedRole = (d.role || '').toLowerCase();

            userAction.value = 'update';
            userId.value     = d.id || '';
            uf_name.value    = d.name || '';
            uf_email.value   = d.email || '';
            uf_contact.value = d.contact || '';
            uf_address.value = d.address || '';
            uf_password.value = '';
            if (uf_image) uf_image.value = '';

            pwReq.style.display = 'none';
            uf_password.required = false;
            uf_password.placeholder = 'Leave blank to keep current';
            pwHint.textContent = 'Leave blank to keep the current password.';

            if (wantedRole === 'admin') {
                /* Admin role is locked — show badge, submit hidden admin value */
                showRoleLocked();
                userModalTagText.textContent = 'Edit Admin';
                userModalTitle.textContent = 'Edit Administrator';
                userModalSub.textContent = 'Update account details. The administrator role cannot be changed.';
            } else {
                showRoleSelect();
                const roleExists = [...uf_role.options].some(o => o.value === wantedRole);
                uf_role.value = roleExists ? wantedRole : '';
                userModalTagText.textContent = 'Edit User';
                userModalTitle.textContent = 'Edit User';
                userModalSub.textContent = 'Update account details. Leave the password blank to keep it unchanged.';
            }

            openModal(modalUser);
        });
    });

    /* Delete user */
    const deleteId    = document.getElementById('deleteId');
    const deleteName  = document.getElementById('deleteName');
    const deleteEmail = document.getElementById('deleteEmail');

    document.querySelectorAll('.js-delete').forEach(btn => {
        btn.addEventListener('click', () => {
            deleteId.value = btn.dataset.id || '';
            deleteName.textContent  = btn.dataset.name || '—';
            deleteEmail.textContent = btn.dataset.email || '';
            openModal(modalDelete);
        });
    });

    /* Toast */
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

    /* Auto-submit search on typing (debounced) */
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