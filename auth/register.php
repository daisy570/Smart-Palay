<?php
require_once __DIR__ . '/../config/database.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';
$fullName = '';
$email = '';
$role = '';   // Empty by default: user must explicitly choose

// Background image — verified server-side
$bgFileName = '343ae23d-8f74-4320-beb7-12a8e2896bd6.png';
$bgDiskPath = __DIR__ . '/../images/' . $bgFileName;
$bgUrl      = is_file($bgDiskPath)
    ? BASE_URL . 'images/' . rawurlencode($bgFileName)
    : '';

// Social providers (hidden automatically on InfinityFree/live if auth/oauth.php is missing)
$hasOauth = is_file(__DIR__ . '/oauth.php');
$socialEnabled = [
    'google'    => $hasOauth,
    'facebook'  => $hasOauth,
    'microsoft' => $hasOauth,
];

// Allowed roles
$allowedRoles = ['seller', 'buyer'];

// CSRF token
if (empty($_SESSION['csrf_register']) || !is_string($_SESSION['csrf_register'])) {
    $_SESSION['csrf_register'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_register'], $token)) {
        $error = 'Invalid session. Please refresh the page and try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $role     = $_POST['role'] ?? '';

        if ($fullName === '' || mb_strlen($fullName) < 2) {
            $error = 'Please enter your full name.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif ($role === '') {
            $error = 'Please select a role to continue.';
        } elseif (!in_array($role, $allowedRoles, true)) {
            $error = 'Please select a valid role.';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $error = 'An account with that email already exists.';
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $schema = getUserSchemaColumns();
                    $roleValue = ucfirst(strtolower($role));

                    $insertColumns = ['full_name', 'email', 'status'];
                    $insertValues = [$fullName, $email, 'active'];

                    if ($schema['password'] === 'password_hash') {
                        $insertColumns[] = 'password_hash';
                        $insertValues[] = $hash;
                    } else {
                        $insertColumns[] = 'password';
                        $insertValues[] = $hash;
                    }

                    if ($schema['role'] === 'user_type') {
                        $insertColumns[] = 'user_type';
                        $insertValues[] = $roleValue;
                    } else {
                        $insertColumns[] = 'role';
                        $insertValues[] = $role;
                    }

                    $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
                    $sql = 'INSERT INTO users (' . implode(', ', $insertColumns) . ') VALUES (' . $placeholders . ')';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($insertValues);

                    // ── NO auto-login. Send user to login page with a flag. ──
                    unset($_SESSION['csrf_register']);
                    $_SESSION['register_success'] = true;
                    $_SESSION['register_email']   = $email;

                    header('Location: ' . BASE_URL . 'auth/login.php?registered=1');
                    exit;

                } catch (Throwable $e) {
                    error_log('Registration failed: ' . $e->getMessage());
                    $error = 'Something went wrong. Please try again later.';
                }
            }
        }
    }
}

$csrf = $_SESSION['csrf_register'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#B0641E">
    <title>Register | SmartPalay</title>

    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23B0641E'/%3E%3Cpath d='M32 10c-4 8-4 16 0 24 4-8 4-16 0-24Z' fill='%23F4C87A'/%3E%3Cpath d='M32 34c-4 8-4 16 0 24 4-8 4-16 0-24Z' fill='%23E8B05A'/%3E%3C/svg%3E">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">

    <style>
        /* ============================================================
           SmartPalay — Split Register
           ============================================================ */
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

            --line:        #EADFC8;
            --line-2:      #DDCDA8;
            --line-3:      #C9B68A;

            --ink:         #2A1E10;
            --text:        #3A2A18;
            --text-2:      #6B5A44;
            --text-3:      #96856E;

            --danger:      #A23A1A;
            --danger-bg:   #FBEDE6;
            --danger-bd:   #EFC6B0;

            --warn:        #7A5A0F;
            --warn-bg:     #FCF3D6;
            --warn-bd:     #EBD79A;

            --success:     #2E7D32;
            --success-bg:  #EAF7EC;
            --success-bd:  #BEE0C2;

            --fb-blue:     #1877F2;

            --bg-image: <?= $bgUrl
                ? "url('{$bgUrl}')"
                : 'linear-gradient(135deg, #C99A5A 0%, #8A5A30 55%, #4A2C10 100%)' ?>;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }

        body.sp-auth {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--text);
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
            line-height: 1.5;
            overflow: hidden;
            background-color: #C99A5A;
            background-image: var(--bg-image);
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }

        body.sp-auth::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            background:
                linear-gradient(
                    180deg,
                    rgba(255, 235, 195, .18) 0%,
                    rgba(74, 44, 16, .08) 40%,
                    rgba(74, 44, 16, .22) 100%
                );
        }

        .sp-shell {
            position: relative;
            z-index: 1;
            height: 100vh;
            height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding:
                max(24px, env(safe-area-inset-top, 0px))
                clamp(16px, 4vw, 40px)
                max(24px, env(safe-area-inset-bottom, 0px));
        }

        .sp-card {
            width: 100%;
            max-width: 820px;
            height: 100%;
            max-height: 660px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: linear-gradient(180deg, #FFFFFF 0%, var(--cream-2) 100%);
            border: 1px solid rgba(255, 255, 255, .6);
            border-radius: 24px;
            overflow: hidden;
            box-shadow:
                0 1px 0 rgba(255,255,255,.95) inset,
                0 30px 70px -30px rgba(40, 22, 6, .60),
                0 10px 26px -14px rgba(40, 22, 6, .32);
        }

        /* ---------- LEFT — BRAND ART PANEL ---------- */
        .sp-art {
            position: relative;
            padding: clamp(28px, 3.6vh, 40px) clamp(24px, 3vw, 36px);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            color: #fff;
            background:
                radial-gradient(circle at 20% 15%, rgba(232, 176, 90, .35), transparent 55%),
                radial-gradient(circle at 85% 85%, rgba(176, 100, 30, .30), transparent 50%),
                linear-gradient(160deg, #4A2C10 0%, #2A1E10 100%);
        }

        .sp-art::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background-image:
                repeating-linear-gradient(92deg,
                    transparent 0px, transparent 22px,
                    rgba(232, 176, 90, .06) 22px, rgba(232, 176, 90, .06) 24px),
                repeating-linear-gradient(88deg,
                    transparent 0px, transparent 34px,
                    rgba(232, 176, 90, .04) 34px, rgba(232, 176, 90, .04) 37px);
            opacity: .9;
        }

        .sp-art::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: radial-gradient(circle at 50% 100%, rgba(232, 176, 90, .18), transparent 55%);
        }

        .sp-art > * { position: relative; z-index: 1; }

        .sp-art-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: inherit;
        }
        .sp-art-brand-mark {
            width: 38px;
            height: 38px;
            flex-shrink: 0;
            filter: drop-shadow(0 5px 12px rgba(0,0,0,.35));
        }
        .sp-art-brand-name {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.12rem;
            font-weight: 700;
            letter-spacing: -.4px;
            margin: 0;
            line-height: 1;
        }
        .sp-art-brand-name span { color: var(--gold-3); }

        .sp-art-body { margin: clamp(16px, 2.2vh, 26px) 0; }

        .sp-art-tag {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 5px 11px;
            border-radius: 999px;
            background: rgba(232, 176, 90, .16);
            border: 1px solid rgba(232, 176, 90, .35);
            font-size: .64rem;
            font-weight: 700;
            letter-spacing: 1.3px;
            text-transform: uppercase;
            color: var(--gold-3);
            margin-bottom: 16px;
        }
        .sp-art-tag i { font-size: .76rem; }

        .sp-art-headline {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(1.35rem, 2.1vw, 1.7rem);
            font-weight: 600;
            line-height: 1.2;
            letter-spacing: -.5px;
            margin: 0 0 12px;
        }
        .sp-art-headline em {
            font-style: italic;
            font-weight: 500;
            color: var(--gold-3);
        }

        .sp-art-copy {
            font-size: .82rem;
            line-height: 1.6;
            color: rgba(255, 255, 255, .72);
            max-width: 32ch;
            margin: 0;
        }

        .sp-art-steps {
            display: flex;
            flex-direction: column;
            gap: 11px;
            margin-top: clamp(16px, 2.2vh, 24px);
        }
        .sp-art-step {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .sp-art-step-num {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: rgba(232, 176, 90, .16);
            border: 1px solid rgba(232, 176, 90, .35);
            color: var(--gold-3);
            font-family: 'Fraunces', Georgia, serif;
            font-size: .78rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .sp-art-step-text {
            font-size: .78rem;
            line-height: 1.45;
            color: rgba(255, 255, 255, .82);
            padding-top: 3px;
        }
        .sp-art-step-text strong {
            color: #fff;
            font-weight: 600;
            display: block;
            margin-bottom: 1px;
        }

        .sp-art-foot {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: .68rem;
            color: rgba(255, 255, 255, .58);
            letter-spacing: .3px;
        }
        .sp-art-foot .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--gold-3);
            box-shadow: 0 0 0 3px rgba(232, 176, 90, .22);
            animation: spPulse 2.4s ease-in-out infinite;
        }
        @keyframes spPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%      { opacity: .45; transform: scale(.85); }
        }

        /* ---------- RIGHT — FORM PANEL ---------- */
        .sp-form-panel {
            position: relative;
            height: 100%;
            overflow-y: auto;
            overflow-x: hidden;
            padding: clamp(24px, 3.2vh, 34px) clamp(22px, 2.8vw, 34px);
            display: flex;
            flex-direction: column;
            scrollbar-width: thin;
            scrollbar-color: var(--line-3) transparent;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
        }

        .sp-form-panel::-webkit-scrollbar { width: 7px; }
        .sp-form-panel::-webkit-scrollbar-track {
            background: transparent;
            margin: 16px 0;
        }
        .sp-form-panel::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--line-2), var(--line-3));
            border-radius: 4px;
            border: 2px solid transparent;
            background-clip: content-box;
            transition: background .2s ease;
        }
        .sp-form-panel::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--line-3), var(--gold-3));
            background-clip: content-box;
        }

        .sp-form-inner {
            margin: auto 0;
            width: 100%;
            display: flex;
            flex-direction: column;
        }

        .sp-form-head { margin-bottom: clamp(16px, 2.2vh, 22px); }
        .sp-form-head h1 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(1.35rem, 2vw, 1.55rem);
            font-weight: 700;
            letter-spacing: -.45px;
            color: var(--brown);
            margin: 0 0 5px;
            line-height: 1.18;
        }
        .sp-form-head p {
            font-size: .8rem;
            color: var(--text-2);
            margin: 0;
            line-height: 1.5;
        }

        .sp-field { margin-bottom: 10px; }

        .sp-label {
            display: block;
            font-size: .64rem;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--brown-2);
            margin-bottom: 4px;
        }

        .sp-input {
            display: flex;
            align-items: center;
            background: #FFFFFF;
            border: 1.5px solid var(--line-2);
            border-radius: 9px;
            transition: border-color .2s ease, box-shadow .22s ease, background .2s ease;
            overflow: hidden;
            min-height: 40px;
        }
        .sp-input:hover { border-color: var(--line-3); }
        .sp-input:focus-within {
            border-color: var(--gold);
            box-shadow:
                0 0 0 4px rgba(176, 100, 30, .13),
                0 8px 20px -14px rgba(176, 100, 30, .45);
        }
        .sp-input.is-valid {
            border-color: var(--success);
            background: var(--success-bg);
        }
        .sp-input.is-valid:focus-within {
            box-shadow: 0 0 0 4px rgba(46, 125, 50, .13);
        }
        .sp-input.is-invalid {
            border-color: var(--danger);
            background: var(--danger-bg);
        }
        .sp-input.is-invalid:focus-within {
            box-shadow: 0 0 0 4px rgba(162, 58, 26, .13);
        }

        .sp-input-ico {
            display: grid;
            place-items: center;
            padding: 0 4px 0 12px;
            color: var(--text-3);
            font-size: .88rem;
            transition: color .18s ease;
        }
        .sp-input:focus-within .sp-input-ico { color: var(--gold); }
        .sp-input.is-valid .sp-input-ico  { color: var(--success); }
        .sp-input.is-invalid .sp-input-ico { color: var(--danger); }

        .sp-input input {
            flex: 1;
            border: 0;
            outline: 0;
            background: transparent;
            padding: 9px 12px 9px 8px;
            font-size: .86rem;
            font-weight: 500;
            color: var(--text);
            font-family: inherit;
            min-width: 0;
        }
        .sp-input input::placeholder { color: #A9A392; font-weight: 400; }
        .sp-input input:disabled { opacity: .55; cursor: not-allowed; }
        .sp-input input:-webkit-autofill,
        .sp-input input:-webkit-autofill:hover,
        .sp-input input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--text);
            -webkit-box-shadow: 0 0 0 1000px #fff inset;
            transition: background-color 9999s ease-in-out 0s;
        }

        /* ============================================================
           RESPONSIVE DROPDOWN — SELECT
           ============================================================ */
        .sp-select {
            position: relative;
            display: flex;
            align-items: center;
            background: #FFFFFF;
            border: 1.5px solid var(--line-2);
            border-radius: 9px;
            transition: border-color .2s ease, box-shadow .22s ease;
            overflow: hidden;
            min-height: 40px;
            width: 100%;
            max-width: 100%;
        }
        .sp-select:hover { border-color: var(--line-3); }
        .sp-select:focus-within {
            border-color: var(--gold);
            box-shadow:
                0 0 0 4px rgba(176, 100, 30, .13),
                0 8px 20px -14px rgba(176, 100, 30, .45);
        }
        .sp-select.is-valid {
            border-color: var(--success);
            background: var(--success-bg);
        }
        .sp-select.is-valid:focus-within {
            box-shadow: 0 0 0 4px rgba(46, 125, 50, .13);
        }
        .sp-select.is-invalid {
            border-color: var(--danger);
            background: var(--danger-bg);
        }
        .sp-select .sp-input-ico {
            padding: 0 4px 0 12px;
            flex: 0 0 auto;
        }
        .sp-select.is-valid .sp-input-ico  { color: var(--success); }
        .sp-select.is-invalid .sp-input-ico { color: var(--danger); }

        /* The native select — tuned for every viewport */
        .sp-select select {
            flex: 1 1 auto;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            border: 0;
            outline: 0;
            background: transparent;
            padding: 9px 36px 9px 8px;
            font-size: .86rem;
            font-weight: 500;
            color: var(--text);
            font-family: inherit;
            cursor: pointer;
            width: 100%;
            min-width: 0;             /* allows flex to shrink */
            max-width: 100%;
            text-overflow: ellipsis;  /* truncate long option text */
            white-space: nowrap;
            overflow: hidden;
            line-height: 1.35;
            display: block;
        }
        .sp-select select.is-placeholder {
            color: var(--text-3);
            font-weight: 400;
        }
        .sp-select select option {
            color: var(--text);
            background: #fff;
            font-weight: 500;
            /* Native pickers on mobile respect this for readability */
            padding: 8px 12px;
            font-size: 15px;
        }
        .sp-select select option[value=""] {
            color: var(--text-3);
            font-weight: 400;
        }
        /* Custom chevron */
        .sp-select::after {
            content: '';
            position: absolute;
            right: 14px;
            top: 50%;
            width: 6px;
            height: 6px;
            border-right: 2px solid var(--text-3);
            border-bottom: 2px solid var(--text-3);
            transform: translateY(-70%) rotate(45deg);
            pointer-events: none;
            transition: border-color .18s ease, transform .25s ease;
        }
        .sp-select:focus-within::after {
            border-color: var(--gold);
            transform: translateY(-30%) rotate(-135deg);
        }
        .sp-select.is-valid::after  { border-color: var(--success); }
        .sp-select.is-invalid::after { border-color: var(--danger); }

        .sp-hint {
            display: none;
            align-items: center;
            gap: 6px;
            margin-top: 4px;
            padding-left: 4px;
            font-size: .68rem;
            font-weight: 500;
            line-height: 1.4;
        }
        .sp-hint.is-visible { display: flex; animation: spHintIn .2s ease both; }
        .sp-hint i { font-size: .82rem; }
        .sp-hint.hint-danger  { color: var(--danger); }
        .sp-hint.hint-success { color: var(--success); }
        .sp-hint.hint-warn    { color: var(--warn); }
        @keyframes spHintIn {
            from { opacity: 0; transform: translateY(-2px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .sp-eye {
            border: 0;
            background: transparent;
            padding: 0 11px;
            height: 100%;
            color: var(--text-3);
            cursor: pointer;
            font-size: .88rem;
            display: grid;
            place-items: center;
            transition: color .18s ease, transform .15s ease;
        }
        .sp-eye:hover { color: var(--gold); transform: scale(1.05); }
        .sp-eye:focus-visible {
            outline: 2px solid var(--gold);
            outline-offset: -3px;
            border-radius: 8px;
        }

        .sp-strength {
            display: none;
            align-items: center;
            gap: 7px;
            margin-top: 5px;
            padding: 0 4px;
        }
        .sp-strength.is-visible { display: flex; }
        .sp-strength-bars { display: flex; gap: 3px; flex: 1; }
        .sp-strength-bars span {
            flex: 1;
            height: 3px;
            border-radius: 3px;
            background: var(--line);
            transition: background .25s ease;
        }
        .sp-strength-label {
            font-size: .62rem;
            font-weight: 700;
            letter-spacing: .4px;
            text-transform: uppercase;
            color: var(--text-3);
            min-width: 52px;
            text-align: right;
            transition: color .25s ease;
        }
        .sp-strength[data-level="1"] .sp-strength-bars span:nth-child(-n+1) { background: #D9534F; }
        .sp-strength[data-level="1"] .sp-strength-label { color: #D9534F; }
        .sp-strength[data-level="2"] .sp-strength-bars span:nth-child(-n+2) { background: #E0A020; }
        .sp-strength[data-level="2"] .sp-strength-label { color: #E0A020; }
        .sp-strength[data-level="3"] .sp-strength-bars span:nth-child(-n+3) { background: var(--gold); }
        .sp-strength[data-level="3"] .sp-strength-label { color: var(--gold); }
        .sp-strength[data-level="4"] .sp-strength-bars span { background: var(--success); }
        .sp-strength[data-level="4"] .sp-strength-label { color: var(--success); }

        .sp-terms {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin: 4px 0 12px;
            font-size: .74rem;
            line-height: 1.45;
            color: var(--text-2);
        }
        .sp-terms input {
            appearance: none;
            -webkit-appearance: none;
            width: 15px;
            height: 15px;
            border: 1.5px solid var(--line-3);
            border-radius: 4px;
            background: #fff;
            cursor: pointer;
            position: relative;
            flex-shrink: 0;
            margin-top: 1px;
            transition: all .15s ease;
        }
        .sp-terms input:checked {
            background: var(--gold);
            border-color: var(--gold);
        }
        .sp-terms input:checked::after {
            content: '';
            position: absolute;
            left: 3.5px; top: 1px;
            width: 4px; height: 8px;
            border: solid #fff;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        .sp-terms input:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
        .sp-terms a {
            color: var(--gold);
            font-weight: 600;
            text-decoration: none;
            border-bottom: 1px solid transparent;
            transition: border-color .15s ease;
        }
        .sp-terms a:hover { border-color: var(--gold); }

        .sp-submit {
            width: 100%;
            padding: 11px 22px;
            border: 0;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-2) 100%);
            color: #fff;
            font-weight: 700;
            font-size: .88rem;
            letter-spacing: .35px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            position: relative;
            overflow: hidden;
            box-shadow:
                0 3px 0 rgba(0, 0, 0, .14),
                0 12px 26px -12px rgba(176, 100, 30, .65);
            transition: transform .15s ease, box-shadow .18s ease, filter .18s ease;
        }
        .sp-submit::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(100deg,
                transparent 30%,
                rgba(255, 255, 255, .30) 50%,
                transparent 70%);
            transform: translateX(-100%);
            transition: transform .8s cubic-bezier(.2,.7,.3,1);
            pointer-events: none;
        }
        .sp-submit:hover:not(:disabled) {
            transform: translateY(-1px);
            filter: brightness(1.03);
            box-shadow:
                0 5px 0 rgba(0, 0, 0, .14),
                0 16px 30px -12px rgba(176, 100, 30, .78);
        }
        .sp-submit:hover:not(:disabled)::after { transform: translateX(100%); }
        .sp-submit:active:not(:disabled) {
            transform: translateY(1px);
            box-shadow: 0 1px 0 rgba(0, 0, 0, .14);
        }
        .sp-submit:focus-visible {
            outline: 3px solid rgba(176, 100, 30, .55);
            outline-offset: 3px;
        }
        .sp-submit:disabled { opacity: .55; cursor: not-allowed; box-shadow: none; }
        .sp-submit .arrow { transition: transform .25s ease; }
        .sp-submit:hover:not(:disabled) .arrow { transform: translateX(3px); }

        .sp-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--text-3);
            font-size: .64rem;
            letter-spacing: 1.3px;
            text-transform: uppercase;
            font-weight: 600;
            margin: 14px 0 10px;
        }
        .sp-divider::before,
        .sp-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--line);
        }

        .sp-social-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .sp-social {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 11px;
            background: #FFFFFF;
            border: 1.5px solid var(--line-2);
            color: var(--text);
            text-decoration: none;
            cursor: pointer;
            transition:
                border-color .2s ease,
                box-shadow .2s ease,
                transform .2s ease;
        }
        .sp-social svg { width: 18px; height: 18px; display: block; }
        .sp-social:hover {
            border-color: var(--line-3);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -12px rgba(74, 44, 16, .45);
        }
        .sp-social:focus-visible {
            outline: 3px solid rgba(176, 100, 30, .35);
            outline-offset: 3px;
        }
        .sp-social.social-facebook { color: var(--fb-blue); }
        .sp-social.social-facebook:hover { border-color: var(--fb-blue); }

        .sp-login-link {
            display: block;
            width: 100%;
            text-align: center;
            font-size: .78rem;
            font-weight: 500;
            color: var(--text-2);
            text-decoration: none;
            padding: 2px 0;
        }
        .sp-login-link strong {
            color: var(--gold);
            font-weight: 700;
            margin-left: 4px;
            text-decoration: underline;
            text-underline-offset: 3px;
            text-decoration-thickness: 1.5px;
            transition: color .18s ease;
        }
        .sp-login-link:hover strong { color: var(--gold-2); }
        .sp-login-link:focus-visible {
            outline: 3px solid rgba(176, 100, 30, .35);
            outline-offset: 4px;
            border-radius: 6px;
        }

        .sp-alert {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            padding: 9px 11px;
            border-radius: 9px;
            font-size: .76rem;
            line-height: 1.45;
            margin-bottom: 12px;
            border: 1px solid transparent;
            animation: spAlertIn .28s ease both;
        }
        .sp-alert.is-shaking { animation: spAlertShake .35s ease both; }
        @keyframes spAlertIn {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes spAlertShake {
            0%, 100% { transform: translateX(0); }
            20%      { transform: translateX(-4px); }
            40%      { transform: translateX(4px); }
            60%      { transform: translateX(-3px); }
            80%      { transform: translateX(3px); }
        }
        .sp-alert i { font-size: .9rem; margin-top: 1px; flex-shrink: 0; }
        .sp-alert-danger {
            background: var(--danger-bg);
            border-color: var(--danger-bd);
            color: var(--danger);
        }
        .sp-alert-danger i { color: var(--gold); }

        /* ============================================================
           RESPONSIVE — MOBILE FIRST
           ============================================================ */

        /* ----- Tablet & small laptop ----- */
        @media (max-width: 900px) {
            .sp-card { max-width: 780px; }
        }

        /* ----- Tablet portrait & phones: STACK, SCROLL, GROW ----- */
        @media (max-width: 780px) {
            html, body {
                height: auto;
                min-height: 100%;
            }

            body.sp-auth {
                overflow-x: hidden;
                overflow-y: auto;
                background-attachment: scroll;
            }

            .sp-shell {
                height: auto;
                min-height: 100dvh;
                display: block;
                padding:
                    max(14px, env(safe-area-inset-top, 0px))
                    14px
                    max(20px, env(safe-area-inset-bottom, 0px));
            }

            .sp-card {
                display: block;
                grid-template-columns: 1fr;
                max-width: 460px;
                width: 100%;
                height: auto;
                max-height: none;
                margin: 0 auto;
                border-radius: 20px;
            }

            .sp-art {
                display: flex;
                flex-direction: row;
                align-items: center;
                justify-content: flex-start;
                padding: 18px 20px;
                gap: 12px;
                min-height: 0;
            }

            .sp-art::after {
                background: linear-gradient(90deg,
                    transparent 0%,
                    rgba(232, 176, 90, .45) 25%,
                    rgba(232, 176, 90, .45) 75%,
                    transparent 100%);
                inset: auto 0 0 0;
                top: auto;
                width: 100%;
                height: 1px;
            }

            .sp-art-body { display: none; }
            .sp-art-foot { display: none; }

            .sp-art-brand {
                gap: 10px;
                width: 100%;
            }
            .sp-art-brand-mark { width: 40px; height: 40px; }
            .sp-art-brand-name { font-size: 1.15rem; }

            .sp-form-panel {
                height: auto;
                max-height: none;
                overflow: visible;
                display: block;
                padding: 24px 22px 26px;
            }

            .sp-form-inner { margin: 0; width: 100%; }

            /* Make the whole select row span comfortably */
            .sp-select { width: 100%; }
            .sp-select select {
                width: 100%;
                min-width: 0;
            }
        }

        /* ----- Modern phones ----- */
        @media (max-width: 480px) {
            .sp-shell {
                padding:
                    max(10px, env(safe-area-inset-top, 0px))
                    10px
                    max(16px, env(safe-area-inset-bottom, 0px));
            }

            .sp-card { border-radius: 18px; max-width: 100%; }

            .sp-art { padding: 16px 18px; gap: 10px; }
            .sp-art-brand-mark { width: 36px; height: 36px; }
            .sp-art-brand-name { font-size: 1.02rem; }

            .sp-form-panel { padding: 20px 18px 24px; }

            .sp-form-head { margin-bottom: 16px; }
            .sp-form-head h1 { font-size: 1.25rem; }
            .sp-form-head p  { font-size: .78rem; }

            .sp-label { font-size: .62rem; margin-bottom: 5px; }
            .sp-field { margin-bottom: 12px; }

            .sp-input,
            .sp-select { min-height: 48px; border-radius: 11px; }

            .sp-input input,
            .sp-select select {
                font-size: 16px;                        /* iOS: no auto-zoom */
                padding: 12px 12px 12px 10px;
                line-height: 1.3;
            }
            /* Roomier right-side padding for the chevron */
            .sp-select select {
                padding-right: 42px;
            }
            /* Native picker options readable on mobile */
            .sp-select select option {
                font-size: 16px;
                padding: 10px 12px;
            }

            .sp-input-ico,
            .sp-select .sp-input-ico { padding: 0 2px 0 12px; font-size: .95rem; }
            .sp-eye { padding: 0 12px; font-size: .95rem; }

            .sp-submit {
                padding: 14px 20px;
                font-size: .9rem;
                border-radius: 11px;
                margin-top: 4px;
            }

            .sp-social-row { gap: 10px; margin-bottom: 14px; }
            .sp-social { width: 46px; height: 46px; border-radius: 12px; }
            .sp-social svg { width: 20px; height: 20px; }

            .sp-divider { font-size: .62rem; margin: 14px 0 12px; }

            .sp-terms { font-size: .74rem; margin: 6px 0 14px; }
            .sp-terms input { width: 17px; height: 17px; margin-top: 0; }

            .sp-login-link { font-size: .8rem; padding: 4px 0; }

            .sp-alert { font-size: .78rem; padding: 10px 12px; }
            .sp-alert i { font-size: .95rem; }
        }

        /* ----- Small phones ----- */
        @media (max-width: 360px) {
            .sp-shell {
                padding:
                    max(8px, env(safe-area-inset-top, 0px))
                    8px
                    max(12px, env(safe-area-inset-bottom, 0px));
            }

            .sp-card { border-radius: 14px; }

            .sp-art { padding: 14px 14px; }
            .sp-art-brand-mark { width: 32px; height: 32px; }
            .sp-art-brand-name { font-size: .95rem; }

            .sp-form-panel { padding: 16px 14px 20px; }

            .sp-form-head h1 { font-size: 1.15rem; }
            .sp-form-head p  { font-size: .74rem; }

            .sp-input,
            .sp-select { min-height: 44px; }

            .sp-input input,
            .sp-select select {
                font-size: 15px;
                padding: 10px 10px 10px 8px;
            }
            .sp-select select {
                padding-right: 36px;
            }
            .sp-select select option { font-size: 15px; }

            .sp-submit { padding: 12px 18px; font-size: .85rem; }

            .sp-social { width: 42px; height: 42px; }
            .sp-social svg { width: 18px; height: 18px; }

            .sp-terms { font-size: .7rem; }
            .sp-login-link { font-size: .76rem; }
        }

        /* ----- Landscape phones ----- */
        @media (max-height: 560px) and (orientation: landscape) and (max-width: 900px) {
            .sp-shell { display: block; padding: 10px; }
            .sp-card {
                display: grid;
                grid-template-columns: 0.85fr 1.15fr;
                max-width: 100%;
                height: auto;
                max-height: none;
                border-radius: 18px;
            }
            .sp-art {
                flex-direction: column;
                align-items: flex-start;
                justify-content: flex-start;
                padding: 16px;
                gap: 10px;
            }
            .sp-art::after {
                inset: 0 0 0 auto;
                top: 0;
                width: 1px;
                height: 100%;
                background: linear-gradient(180deg,
                    transparent,
                    rgba(232, 176, 90, .45) 25%,
                    rgba(232, 176, 90, .45) 75%,
                    transparent);
            }
            .sp-art-brand-name { font-size: 1rem; }
            .sp-art-brand-mark { width: 36px; height: 36px; }

            .sp-form-panel {
                height: auto;
                max-height: none;
                overflow: visible;
                padding: 18px 20px;
            }

            /* Keep dropdown comfortable in landscape */
            .sp-select { min-height: 42px; }
            .sp-select select { font-size: 15px; padding: 10px 38px 10px 10px; }
        }

        /* ----- Reduced motion ----- */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .001ms !important;
                transition-duration: .001ms !important;
            }
            .sp-input:hover,
            .sp-input:focus-within,
            .sp-select:hover,
            .sp-select:focus-within,
            .sp-social:hover,
            .sp-submit:hover:not(:disabled) {
                transform: none;
            }
        }
    </style>
</head>
<body class="sp-auth">

<div class="sp-shell">
    <div class="sp-card">

        <!-- LEFT — BRAND ART PANEL -->
        <aside class="sp-art" aria-hidden="true">
            <a href="<?= BASE_URL ?>index.php" class="sp-art-brand">
                <svg class="sp-art-brand-mark" viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <linearGradient id="artWheat" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0"   stop-color="#F4C87A"/>
                            <stop offset="0.55" stop-color="#E8B05A"/>
                            <stop offset="1"   stop-color="#B0641E"/>
                        </linearGradient>
                        <linearGradient id="artArc" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="#8A5A30"/>
                            <stop offset="1" stop-color="#4A2C10"/>
                        </linearGradient>
                    </defs>
                    <path d="M6 62 C 18 50, 34 44, 46 46 C 60 48, 70 56, 74 66 L 74 74 L 6 74 Z" fill="url(#artArc)"/>
                    <path d="M40 66 C 40 52, 40 40, 40 26" stroke="#B0641E" stroke-width="2.6" stroke-linecap="round" fill="none"/>
                    <path d="M40 22 q-9 -3 -12 -11 q9 1 12 11 Z" fill="url(#artWheat)"/>
                    <path d="M40 32 q-9 -3 -12 -11 q9 1 12 11 Z" fill="url(#artWheat)"/>
                    <path d="M40 42 q-9 -3 -12 -11 q9 1 12 11 Z" fill="url(#artWheat)"/>
                    <path d="M40 52 q-9 -3 -12 -11 q9 1 12 11 Z" fill="url(#artWheat)"/>
                    <path d="M40 22 q9 -3 12 -11 q-9 1 -12 11 Z" fill="url(#artWheat)"/>
                    <path d="M40 32 q9 -3 12 -11 q-9 1 -12 11 Z" fill="url(#artWheat)"/>
                    <path d="M40 42 q9 -3 12 -11 q-9 1 -12 11 Z" fill="url(#artWheat)"/>
                    <path d="M40 52 q9 -3 12 -11 q-9 1 -12 11 Z" fill="url(#artWheat)"/>
                    <circle cx="40" cy="14" r="1.8" fill="#F4C87A"/>
                </svg>
                <h1 class="sp-art-brand-name">Smart<span>Palay</span></h1>
            </a>

            <div class="sp-art-body">
                <span class="sp-art-tag">
                    <i class="bi bi-stars"></i>
                    Join the community
                </span>

                <h2 class="sp-art-headline">
                    Start recording.<br>
                    Start <em>growing</em>.
                </h2>
                <p class="sp-art-copy">
                    Create your free SmartPalay account and bring order to
                    every palay sale, delivery, and payment.
                </p>

                <div class="sp-art-steps">
                    <div class="sp-art-step">
                        <span class="sp-art-step-num">1</span>
                        <div class="sp-art-step-text">
                            <strong>Create your account</strong>
                            Takes less than a minute
                        </div>
                    </div>
                    <div class="sp-art-step">
                        <span class="sp-art-step-num">2</span>
                        <div class="sp-art-step-text">
                            <strong>Choose your role</strong>
                            Seller or buyer — you can switch later
                        </div>
                    </div>
                    <div class="sp-art-step">
                        <span class="sp-art-step-num">3</span>
                        <div class="sp-art-step-text">
                            <strong>Start recording palay</strong>
                            Every grain, every transaction, tracked
                        </div>
                    </div>
                </div>
            </div>

            <div class="sp-art-foot">
                <span class="dot"></span>
                System online · © <?= date('Y') ?> SmartPalay
            </div>
        </aside>

        <!-- RIGHT — FORM PANEL -->
        <main class="sp-form-panel">
            <div class="sp-form-inner">

                <div class="sp-form-head">
                    <h1>Create your account</h1>
                    <p>Join SmartPalay and start managing your harvest today.</p>
                </div>

                <?php if ($error): ?>
                    <div class="sp-alert sp-alert-danger" role="alert" aria-live="assertive" data-autodismiss="6000">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <div><?= sanitize($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="on" novalidate id="registerForm">
                    <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>">

                    <!-- Full Name -->
                    <div class="sp-field">
                        <label class="sp-label" for="full_name">Full Name</label>
                        <div class="sp-input" id="nameWrap">
                            <span class="sp-input-ico" aria-hidden="true"><i class="bi bi-person"></i></span>
                            <input type="text"
                                   id="full_name"
                                   name="full_name"
                                   value="<?= sanitize($fullName) ?>"
                                   placeholder="Juan Dela Cruz"
                                   autocomplete="name"
                                   maxlength="120"
                                   required
                                   autofocus
                                   aria-describedby="nameHint">
                        </div>
                        <div class="sp-hint" id="nameHint" aria-live="polite"></div>
                    </div>

                    <!-- Email -->
                    <div class="sp-field">
                        <label class="sp-label" for="email">Email Address</label>
                        <div class="sp-input" id="emailWrap">
                            <span class="sp-input-ico" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                            <input type="email"
                                   id="email"
                                   name="email"
                                   value="<?= sanitize($email) ?>"
                                   placeholder="you@example.com"
                                   autocomplete="email"
                                   inputmode="email"
                                   spellcheck="false"
                                   autocapitalize="off"
                                   maxlength="190"
                                   required
                                   aria-describedby="emailHint">
                        </div>
                        <div class="sp-hint" id="emailHint" aria-live="polite"></div>
                    </div>

                    <!-- Role dropdown — EMPTY by default -->
                    <div class="sp-field">
                        <label class="sp-label" for="role">I am registering as</label>
                        <div class="sp-select" id="roleWrap">
                            <span class="sp-input-ico" aria-hidden="true"><i class="bi bi-person-badge"></i></span>
                            <select id="role"
                                    name="role"
                                    required
                                    class="<?= $role === '' ? 'is-placeholder' : '' ?>"
                                    aria-describedby="roleHint">
                                <option value="" disabled <?= $role === '' ? 'selected' : '' ?>>— Select a role —</option>
                                <option value="seller" <?= $role === 'seller' ? 'selected' : '' ?>>Seller - Sell palay</option>
                                <option value="buyer"  <?= $role === 'buyer'  ? 'selected' : '' ?>>Buyer - Buy palay</option>
                            </select>
                        </div>
                        <div class="sp-hint" id="roleHint" aria-live="polite"></div>
                    </div>

                    <!-- Password -->
                    <div class="sp-field">
                        <label class="sp-label" for="password">Password</label>
                        <div class="sp-input" id="passwordWrap">
                            <span class="sp-input-ico" aria-hidden="true"><i class="bi bi-lock"></i></span>
                            <input type="password"
                                   id="password"
                                   name="password"
                                   placeholder="At least 8 characters"
                                   autocomplete="new-password"
                                   minlength="8"
                                   maxlength="200"
                                   required
                                   aria-describedby="passwordHint passwordStrength">
                            <button type="button"
                                    class="sp-eye"
                                    id="togglePwd"
                                    tabindex="-1"
                                    aria-label="Show password"
                                    aria-pressed="false">
                                <i class="bi bi-eye" id="togglePwdIcon"></i>
                            </button>
                        </div>
                        <div class="sp-hint" id="passwordHint" aria-live="polite"></div>
                        <div class="sp-strength" id="passwordStrength" data-level="0" aria-hidden="true">
                            <div class="sp-strength-bars">
                                <span></span><span></span><span></span><span></span>
                            </div>
                            <div class="sp-strength-label">—</div>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="sp-field">
                        <label class="sp-label" for="confirm_password">Confirm Password</label>
                        <div class="sp-input" id="confirmWrap">
                            <span class="sp-input-ico" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
                            <input type="password"
                                   id="confirm_password"
                                   name="confirm_password"
                                   placeholder="Re-enter your password"
                                   autocomplete="new-password"
                                   minlength="8"
                                   maxlength="200"
                                   required
                                   aria-describedby="confirmHint">
                            <button type="button"
                                    class="sp-eye"
                                    id="toggleConfirm"
                                    tabindex="-1"
                                    aria-label="Show confirm password"
                                    aria-pressed="false">
                                <i class="bi bi-eye" id="toggleConfirmIcon"></i>
                            </button>
                        </div>
                        <div class="sp-hint" id="confirmHint" aria-live="polite"></div>
                    </div>

                    <!-- Terms -->
                    <label class="sp-terms">
                        <input type="checkbox" name="terms" value="1" required>
                        <span>
                            I agree to the
                            <a href="#" onclick="return false;" title="Terms of Service">Terms of Service</a>
                            and
                            <a href="#" onclick="return false;" title="Privacy Policy">Privacy Policy</a>.
                        </span>
                    </label>

                    <!-- Submit -->
                    <button type="submit" class="sp-submit" id="registerBtn">
                        <span class="spinner-border spinner-border-sm d-none" id="registerSpinner" aria-hidden="true"></span>
                        <span id="registerText">Create Account</span>
                        <i class="bi bi-arrow-right arrow" id="registerIcon" aria-hidden="true"></i>
                    </button>
                </form>

                <?php if ($socialEnabled['google'] || $socialEnabled['facebook'] || $socialEnabled['microsoft']): ?>
                    <div class="sp-divider"><span>or sign up with</span></div>

                    <div class="sp-social-row" role="group" aria-label="Sign up with a social account">
                        <?php if ($socialEnabled['google']): ?>
                        <a class="sp-social social-google"
                           href="<?= BASE_URL ?>auth/oauth.php?provider=google&intent=register"
                           aria-label="Sign up with Google" title="Sign up with Google">
                            <svg viewBox="0 0 48 48" aria-hidden="true">
                                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                            </svg>
                        </a>
                        <?php endif; ?>

                        <?php if ($socialEnabled['facebook']): ?>
                        <a class="sp-social social-facebook"
                           href="<?= BASE_URL ?>auth/oauth.php?provider=facebook&intent=register"
                           aria-label="Sign up with Facebook" title="Sign up with Facebook">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path fill="#1877F2" d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/>
                            </svg>
                        </a>
                        <?php endif; ?>

                        <?php if ($socialEnabled['microsoft']): ?>
                        <a class="sp-social social-microsoft"
                           href="<?= BASE_URL ?>auth/oauth.php?provider=microsoft&intent=register"
                           aria-label="Sign up with Microsoft" title="Sign up with Microsoft">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <rect x="1"  y="1"  width="10" height="10" fill="#F25022"/>
                                <rect x="13" y="1"  width="10" height="10" fill="#7FBA00"/>
                                <rect x="1"  y="13" width="10" height="10" fill="#00A4EF"/>
                                <rect x="13" y="13" width="10" height="10" fill="#FFB900"/>
                            </svg>
                        </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <a href="<?= BASE_URL ?>auth/login.php" class="sp-login-link">
                    Already have an account?<strong>Sign in</strong>
                </a>
            </div>
        </main>

    </div>
</div>

<script>
(() => {
    'use strict';

    function wireToggle(btnId, inputId, iconId) {
        const btn   = document.getElementById(btnId);
        const input = document.getElementById(inputId);
        const icon  = document.getElementById(iconId);
        if (!btn || !input || !icon) return;

        btn.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            input.focus();
        });
    }
    wireToggle('togglePwd', 'password', 'togglePwdIcon');
    wireToggle('toggleConfirm', 'confirm_password', 'toggleConfirmIcon');

    document.getElementById('registerForm')?.addEventListener('submit', (e) => {
        const btn = document.getElementById('registerBtn');
        if (btn.disabled) { e.preventDefault(); return; }

        const pw  = document.getElementById('password')?.value || '';
        const cpw = document.getElementById('confirm_password')?.value || '';
        if (pw !== cpw) { e.preventDefault(); return; }

        btn.disabled = true;
        document.getElementById('registerSpinner').classList.remove('d-none');
        document.getElementById('registerIcon').classList.add('d-none');
        document.getElementById('registerText').textContent = 'Creating account…';
    });

    const nameInput = document.getElementById('full_name');
    const nameWrap  = document.getElementById('nameWrap');
    const nameHint  = document.getElementById('nameHint');

    function validateName(showOk = true) {
        if (!nameInput || !nameWrap || !nameHint) return true;
        const v = nameInput.value.trim();

        nameWrap.classList.remove('is-valid', 'is-invalid');
        nameHint.classList.remove('is-visible', 'hint-danger', 'hint-success', 'hint-warn');
        nameHint.textContent = '';

        if (v === '') return true;

        if (v.length < 2) {
            nameWrap.classList.add('is-invalid');
            nameHint.classList.add('is-visible', 'hint-danger');
            nameHint.innerHTML = '<i class="bi bi-x-circle"></i> Name must be at least 2 characters.';
            return false;
        }

        if (showOk) {
            nameWrap.classList.add('is-valid');
            nameHint.classList.add('is-visible', 'hint-success');
            nameHint.innerHTML = '<i class="bi bi-check-circle"></i> Nice to meet you.';
        }
        return true;
    }

    nameInput?.addEventListener('blur', () => validateName(true));
    nameInput?.addEventListener('input', () => {
        if (nameWrap?.classList.contains('is-invalid') || nameWrap?.classList.contains('is-valid')) {
            validateName(true);
        }
    });

    const emailInput = document.getElementById('email');
    const emailWrap  = document.getElementById('emailWrap');
    const emailHint  = document.getElementById('emailHint');

    function validateEmail(showOk = true) {
        if (!emailInput || !emailWrap || !emailHint) return true;
        const v = emailInput.value.trim();

        emailWrap.classList.remove('is-valid', 'is-invalid');
        emailHint.classList.remove('is-visible', 'hint-danger', 'hint-success', 'hint-warn');
        emailHint.textContent = '';

        if (v === '') return true;

        const looksLikeEmail = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v);

        if (!looksLikeEmail) {
            emailWrap.classList.add('is-invalid');
            emailHint.classList.add('is-visible', 'hint-danger');
            emailHint.innerHTML = '<i class="bi bi-x-circle"></i> Please enter a valid email address.';
            return false;
        }

        if (showOk) {
            emailWrap.classList.add('is-valid');
            emailHint.classList.add('is-visible', 'hint-success');
            emailHint.innerHTML = '<i class="bi bi-check-circle"></i> Looks good.';
        }
        return true;
    }

    emailInput?.addEventListener('blur', () => validateEmail(true));
    emailInput?.addEventListener('input', () => {
        if (emailWrap?.classList.contains('is-invalid') || emailWrap?.classList.contains('is-valid')) {
            validateEmail(true);
        }
    });

    /* ---------- Role (defaults to empty) ---------- */
    const roleSelect = document.getElementById('role');
    const roleWrap   = document.getElementById('roleWrap');
    const roleHint   = document.getElementById('roleHint');
    const roleIcon   = roleWrap?.querySelector('.sp-input-ico i');

    function updateRoleIcon() {
        if (!roleIcon) return;
        const v = roleSelect?.value || '';
        if (v === 'seller')      roleIcon.className = 'bi bi-person-badge';
        else if (v === 'buyer')  roleIcon.className = 'bi bi-basket';
        else                     roleIcon.className = 'bi bi-person-badge';
    }

    function updateRolePlaceholder() {
        if (!roleSelect) return;
        roleSelect.classList.toggle('is-placeholder', roleSelect.value === '');
    }

    function validateRole() {
        if (!roleSelect || !roleWrap || !roleHint) return true;
        const v = roleSelect.value;

        roleWrap.classList.remove('is-valid', 'is-invalid');
        roleHint.classList.remove('is-visible', 'hint-danger', 'hint-success', 'hint-warn');
        roleHint.textContent = '';

        // Don't show hints until the user has interacted
        if (v === '') return true;

        if (v === 'seller' || v === 'buyer') {
            roleWrap.classList.add('is-valid');
            roleHint.classList.add('is-visible', 'hint-success');
            roleHint.innerHTML = '<i class="bi bi-check-circle"></i> ' +
                (v === 'seller' ? 'You\'ll record palay sales.' : 'You\'ll track your purchases.');
            return true;
        }

        roleWrap.classList.add('is-invalid');
        roleHint.classList.add('is-visible', 'hint-danger');
        roleHint.innerHTML = '<i class="bi bi-x-circle"></i> Please select a valid role.';
        return false;
    }

    roleSelect?.addEventListener('change', () => {
        updateRoleIcon();
        updateRolePlaceholder();
        validateRole();
    });

    updateRoleIcon();
    updateRolePlaceholder();

    const pwd         = document.getElementById('password');
    const strengthBox = document.getElementById('passwordStrength');
    const strengthLbl = strengthBox?.querySelector('.sp-strength-label');

    function scorePassword(p) {
        if (!p) return 0;
        let s = 0;
        if (p.length >= 8) s++;
        if (p.length >= 12) s++;
        if (/[A-Z]/.test(p) && /[a-z]/.test(p)) s++;
        if (/\d/.test(p)) s++;
        if (/[^A-Za-z0-9]/.test(p)) s++;
        return Math.min(4, s);
    }

    function labelFor(score) {
        return ['—','Weak','Fair','Good','Strong'][score] || '—';
    }

    function updateStrength() {
        if (!pwd || !strengthBox || !strengthLbl) return;
        const v = pwd.value;

        if (v === '') {
            strengthBox.classList.remove('is-visible');
            strengthBox.dataset.level = '0';
            strengthLbl.textContent = '—';
            return;
        }

        const score = scorePassword(v);
        strengthBox.classList.add('is-visible');
        strengthBox.dataset.level = String(score);
        strengthLbl.textContent = labelFor(score);
    }

    pwd?.addEventListener('input', () => {
        updateStrength();
        if (confirmInput?.value) validateConfirm(false);
    });

    const confirmInput = document.getElementById('confirm_password');
    const confirmWrap  = document.getElementById('confirmWrap');
    const confirmHint  = document.getElementById('confirmHint');

    function validateConfirm(showOk = true) {
        if (!confirmInput || !confirmWrap || !confirmHint) return true;
        const v = confirmInput.value;

        confirmWrap.classList.remove('is-valid', 'is-invalid');
        confirmHint.classList.remove('is-visible', 'hint-danger', 'hint-success', 'hint-warn');
        confirmHint.textContent = '';

        if (v === '') return true;

        if (v !== (pwd?.value || '')) {
            confirmWrap.classList.add('is-invalid');
            confirmHint.classList.add('is-visible', 'hint-danger');
            confirmHint.innerHTML = '<i class="bi bi-x-circle"></i> Passwords do not match.';
            return false;
        }

        if (showOk) {
            confirmWrap.classList.add('is-valid');
            confirmHint.classList.add('is-visible', 'hint-success');
            confirmHint.innerHTML = '<i class="bi bi-check-circle"></i> Passwords match.';
        }
        return true;
    }

    confirmInput?.addEventListener('blur', () => validateConfirm(true));
    confirmInput?.addEventListener('input', () => validateConfirm(true));

    const passwordHint = document.getElementById('passwordHint');

    function capsLockOn(e) {
        return e.getModifierState && e.getModifierState('CapsLock');
    }

    pwd?.addEventListener('keyup', (e) => {
        if (!passwordHint) return;
        if (e.key === 'CapsLock' || capsLockOn(e)) {
            passwordHint.classList.add('is-visible', 'hint-warn');
            passwordHint.innerHTML = '<i class="bi bi-capslock"></i> Caps Lock is on.';
        } else {
            passwordHint.classList.remove('is-visible', 'hint-warn');
            passwordHint.innerHTML = '';
        }
    });
    pwd?.addEventListener('blur', () => {
        if (!passwordHint) return;
        passwordHint.classList.remove('is-visible', 'hint-warn');
        passwordHint.innerHTML = '';
    });

    document.querySelectorAll('.sp-alert[data-autodismiss]').forEach(el => {
        const ms = parseInt(el.dataset.autodismiss, 10);
        if (!ms || Number.isNaN(ms)) return;
        setTimeout(() => {
            el.style.transition = 'opacity .35s ease, transform .35s ease, max-height .35s ease, margin .35s ease, padding .35s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-4px)';
            el.style.maxHeight = '0';
            el.style.marginBottom = '0';
            el.style.padding = '0 14px';
            setTimeout(() => el.remove(), 380);
        }, ms);
    });

    const err = document.querySelector('.sp-alert-danger');
    if (err) {
        err.classList.add('is-shaking');
        setTimeout(() => err.classList.remove('is-shaking'), 400);
    }

    window.addEventListener('load', () => {
        if (nameInput && nameInput.value.trim() !== '') emailInput?.focus();
    });
})();
</script>
</body>
</html>