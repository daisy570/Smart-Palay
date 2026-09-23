<?php
require_once __DIR__ . '/../config/database.php';

bootstrapRememberedLogin($pdo);

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';
$email = '';
$rememberChecked = false;

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

// CSRF token
if (empty($_SESSION['csrf_login']) || !is_string($_SESSION['csrf_login'])) {
    $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}

// Rate limiting (sliding 15-minute window)
$LOGIN_WINDOW_SECONDS = 900;
$MAX_ATTEMPTS = 5;

if (!isset($_SESSION['login_attempts']) || !is_array($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}
$_SESSION['login_attempts'] = array_values(array_filter(
    $_SESSION['login_attempts'],
    fn($t) => is_int($t) && $t > time() - $LOGIN_WINDOW_SECONDS
));

$attemptsUsed = count($_SESSION['login_attempts']);
$attemptsLeft = max(0, $MAX_ATTEMPTS - $attemptsUsed);
$isLocked     = $attemptsLeft <= 0;
$unlockAt     = $isLocked && $attemptsUsed > 0
    ? min($_SESSION['login_attempts']) + $LOGIN_WINDOW_SECONDS
    : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rememberChecked = !empty($_POST['remember']);

    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_login'], $token)) {
        $error = 'Invalid session. Please refresh the page and try again.';
    } elseif ($isLocked) {
        $error = 'Too many failed attempts. Please try again in 15 minutes.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $error = 'Please enter a valid email and password.';
        } else {
            $stmt = $pdo->prepare(
                "SELECT * FROM users WHERE email = ? LIMIT 1"
            );
            $stmt->execute([$email]);
            $user = normalizeUserRow($stmt->fetch());

            $hash = $user['password']
                ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
            $ok = password_verify($password, $hash);

            $loginFailed = !$user || !$ok || $user['status'] !== 'active';

            if ($loginFailed) {
                $_SESSION['login_attempts'][] = time();
                $attemptsUsed = count($_SESSION['login_attempts']);
                $attemptsLeft = max(0, $MAX_ATTEMPTS - $attemptsUsed);
                $isLocked     = $attemptsLeft <= 0;
                $unlockAt     = $isLocked
                    ? min($_SESSION['login_attempts']) + $LOGIN_WINDOW_SECONDS
                    : 0;

                if (!$user || !$ok) {
                    $error = 'Invalid email or password.';
                } else {
                    $error = 'Your account is inactive. Please contact the administrator.';
                }
            } else {
                if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                }

                session_regenerate_id(true);
                $_SESSION['user_id']   = (int) $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email']     = $user['email'];
                $_SESSION['role']      = normalizeUserRole($user['role']);

                if ($rememberChecked) {
                    setRememberMeCookie($user['id'], $user['email']);
                } else {
                    clearRememberMeCookie();
                }

                unset($_SESSION['login_attempts'], $_SESSION['csrf_login']);

                try {
                    $pdo->prepare(
                        "INSERT INTO login_logs (user_id, ip_address, user_agent, logged_in_at)
                         VALUES (?, ?, ?, NOW())"
                    )->execute([
                        $user['id'],
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    ]);
                } catch (Throwable $e) {
                    error_log('login_logs insert failed: ' . $e->getMessage());
                }

                header('Location: ' . BASE_URL . 'index.php');
                exit;
            }
        }
    }
}

$csrf = $_SESSION['csrf_login'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#4A2C10">
    <title>Sign In | SmartPalay</title>

    <link rel="icon" type="image/svg+xml"
          href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='0' y2='1'%3E%3Cstop offset='0' stop-color='%23F4C87A'/%3E%3Cstop offset='1' stop-color='%23B0641E'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='64' height='64' rx='18' fill='%234A2C10'/%3E%3Ccircle cx='32' cy='22' r='5' fill='%23FFE9A8'/%3E%3Cg fill='url(%23g)'%3E%3Cpath d='M32 34C28 30 26 24 26 18C30 22 32 28 32 34Z'/%3E%3Cpath d='M32 34C36 30 38 24 38 18C34 22 32 28 32 34Z'/%3E%3Cpath d='M32 40C29 37 28 32 28 27C31 30 32 35 32 40Z' opacity='.75'/%3E%3Cpath d='M32 40C35 37 36 32 36 27C33 30 32 35 32 40Z' opacity='.75'/%3E%3C/g%3E%3Cpath d='M10 48C20 40 28 37 32 37C36 37 44 40 54 48L54 54L10 54Z' fill='%238A5A30'/%3E%3C/svg%3E">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">

    <style>
        /* ============================================================
           SmartPalay — Split Login (Refined 2025)
           ============================================================ */
        :root {
            --gold:        #B0641E;
            --gold-2:      #C97628;
            --gold-3:      #E8B05A;
            --gold-4:      #F4C87A;
            --gold-soft:   #FBF1DC;
            --gold-glow:   rgba(232, 176, 90, .28);
            --gold-ring:   rgba(176, 100, 30, .16);

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

        /* ============================================================
           SHELL
           ============================================================ */
        .sp-shell {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding:
                max(24px, env(safe-area-inset-top, 0px))
                clamp(16px, 4vw, 40px)
                max(24px, env(safe-area-inset-bottom, 0px));
        }

        /* ============================================================
           CARD
           ============================================================ */
        .sp-card {
            width: 100%;
            max-width: 820px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: linear-gradient(180deg, #FFFFFF 0%, var(--cream-2) 100%);
            border: 1px solid rgba(255, 255, 255, .6);
            border-radius: 24px;
            overflow: hidden;
            position: relative;
            box-shadow:
                0 1px 0 rgba(255, 255, 255, .95) inset,
                0 1px 2px rgba(40, 22, 6, .04),
                0 40px 80px -40px rgba(40, 22, 6, .55),
                0 12px 28px -14px rgba(40, 22, 6, .3);
        }

        .sp-card::after {
            content: '';
            position: absolute;
            inset: 1px;
            border-radius: inherit;
            pointer-events: none;
            border: 1px solid rgba(255, 255, 255, .5);
            mix-blend-mode: overlay;
        }

        /* ============================================================
           LEFT — BRAND ART PANEL
           ============================================================ */
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
            top: 0; right: 0;
            width: 1px; height: 100%;
            background: linear-gradient(180deg,
                transparent 0%,
                rgba(232, 176, 90, .35) 20%,
                rgba(232, 176, 90, .35) 80%,
                transparent 100%);
            z-index: 2;
        }

        .sp-art > * { position: relative; z-index: 1; }

        /* ---------- Brand lockup ---------- */
        .sp-art-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }
        .sp-art-brand-mark {
            width: 52px;
            height: 52px;
            flex-shrink: 0;
            filter: drop-shadow(0 6px 14px rgba(0, 0, 0, .4));
            transition: transform .35s cubic-bezier(.4,0,.2,1);
        }
        .sp-art-brand:hover .sp-art-brand-mark {
            transform: rotate(-3deg) scale(1.04);
        }
        .sp-art-brand-lockup {
            display: flex;
            flex-direction: column;
            line-height: 1.05;
        }
        .sp-art-brand-name {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: -.5px;
            margin: 0;
            line-height: 1;
            color: #fff;
        }
        .sp-art-brand-name span {
            background: linear-gradient(135deg, var(--gold-4), var(--gold-3));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .sp-art-brand-sub {
            font-size: .58rem;
            font-weight: 700;
            letter-spacing: 2.2px;
            text-transform: uppercase;
            color: rgba(232, 176, 90, .72);
            margin-top: 4px;
        }

        .sp-art-body { margin: clamp(18px, 2.4vh, 28px) 0; }

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
            box-shadow:
                0 0 0 1px rgba(232, 176, 90, .08),
                0 8px 20px -12px rgba(232, 176, 90, .5);
        }
        .sp-art-tag i { font-size: .76rem; }

        .sp-art-headline {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(1.35rem, 2.1vw, 1.7rem);
            font-weight: 600;
            line-height: 1.2;
            letter-spacing: -.5px;
            margin: 0 0 12px;
            color: #fff;
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

        .sp-art-feats {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: clamp(18px, 2.4vh, 26px);
        }
        .sp-art-feat {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: .78rem;
            color: rgba(255, 255, 255, .88);
            font-weight: 500;
            transition: transform .2s ease, color .2s ease;
        }
        .sp-art-feat:hover {
            transform: translateX(3px);
            color: #fff;
        }
        .sp-art-feat-ico {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            display: grid;
            place-items: center;
            background: rgba(232, 176, 90, .14);
            border: 1px solid rgba(232, 176, 90, .28);
            color: var(--gold-3);
            font-size: .82rem;
            flex-shrink: 0;
            transition: background .2s ease, border-color .2s ease, transform .2s ease;
        }
        .sp-art-feat:hover .sp-art-feat-ico {
            background: rgba(232, 176, 90, .26);
            border-color: rgba(232, 176, 90, .55);
            transform: scale(1.05);
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

        /* ============================================================
           RIGHT — FORM PANEL
           ============================================================ */
        .sp-form-panel {
            padding: clamp(28px, 3.6vh, 40px) clamp(24px, 3vw, 36px);
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
        }

        .sp-form-head {
            margin-bottom: clamp(18px, 2.4vh, 24px);
        }
        .sp-form-head h1 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(1.4rem, 2.1vw, 1.65rem);
            font-weight: 700;
            letter-spacing: -.45px;
            margin: 0 0 6px;
            line-height: 1.18;
            position: relative;
            padding-bottom: 14px;
            background: linear-gradient(180deg, var(--brown) 0%, #3A2208 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .sp-form-head h1::after {
            content: '';
            position: absolute;
            left: 0; bottom: 0;
            width: 44px; height: 3px;
            border-radius: 3px;
            background: linear-gradient(90deg, var(--gold), var(--gold-3));
            box-shadow: 0 4px 10px -4px var(--gold-glow);
        }
        .sp-form-head p {
            font-size: .82rem;
            color: var(--text-2);
            margin: 0;
            line-height: 1.5;
        }

        /* Fields */
        .sp-field { margin-bottom: 14px; }

        .sp-label {
            display: block;
            font-size: .66rem;
            font-weight: 700;
            letter-spacing: 1.1px;
            text-transform: uppercase;
            color: var(--brown-2);
            margin-bottom: 6px;
        }

        .sp-input {
            position: relative;
            display: flex;
            align-items: center;
            background: #FFFFFF;
            border: 1px solid var(--line-2);
            border-radius: 12px;
            overflow: hidden;
            min-height: 46px;
            transition:
                border-color .2s ease,
                box-shadow .25s ease,
                background .2s ease,
                transform .15s ease;
        }
        .sp-input:hover {
            border-color: var(--line-3);
            transform: translateY(-1px);
        }
        .sp-input:focus-within {
            border-color: var(--gold);
            transform: translateY(-1px);
            background: #FFFDF8;
            box-shadow:
                0 0 0 4px var(--gold-ring),
                0 14px 26px -18px rgba(176, 100, 30, .55);
        }
        .sp-input.is-valid {
            border-color: var(--success);
            background: var(--success-bg);
        }
        .sp-input.is-valid:focus-within {
            background: #FFFFFF;
            box-shadow: 0 0 0 4px rgba(46, 125, 50, .14);
        }
        .sp-input.is-invalid {
            border-color: var(--danger);
            background: var(--danger-bg);
        }
        .sp-input.is-invalid:focus-within {
            background: #FFFFFF;
            box-shadow: 0 0 0 4px rgba(162, 58, 26, .14);
        }

        .sp-input::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            pointer-events: none;
            box-shadow: inset 3px 0 0 0 transparent;
            transition: box-shadow .2s ease;
        }
        .sp-input:focus-within::before {
            box-shadow: inset 3px 0 0 0 var(--gold);
        }
        .sp-input.is-valid::before {
            box-shadow: inset 3px 0 0 0 var(--success);
        }
        .sp-input.is-invalid::before {
            box-shadow: inset 3px 0 0 0 var(--danger);
        }

        .sp-input-ico {
            display: grid;
            place-items: center;
            padding: 0 4px 0 14px;
            color: var(--text-3);
            font-size: .95rem;
            transition: color .18s ease;
        }
        .sp-input:focus-within .sp-input-ico { color: var(--gold); }
        .sp-input.is-valid .sp-input-ico   { color: var(--success); }
        .sp-input.is-invalid .sp-input-ico { color: var(--danger); }

        .sp-input input {
            flex: 1;
            border: 0;
            outline: 0;
            background: transparent;
            padding: 12px 14px 12px 10px;
            font-size: .9rem;
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

        .sp-hint {
            display: none;
            align-items: center;
            gap: 6px;
            margin-top: 5px;
            padding-left: 4px;
            font-size: .72rem;
            font-weight: 500;
            line-height: 1.4;
        }
        .sp-hint.is-visible { display: flex; animation: spHintIn .2s ease both; }
        .sp-hint i { font-size: .85rem; }
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
            padding: 0 12px;
            height: 100%;
            color: var(--text-3);
            cursor: pointer;
            font-size: .95rem;
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

        /* Password strength */
        .sp-strength {
            display: none;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
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
            font-size: .66rem;
            font-weight: 700;
            letter-spacing: .4px;
            text-transform: uppercase;
            color: var(--text-3);
            min-width: 56px;
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

        /* Meta row */
        .sp-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            margin: 12px 0 clamp(16px, 2.2vh, 20px);
            font-size: .8rem;
        }
        .sp-check {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-2);
            cursor: pointer;
            user-select: none;
            transition: color .15s ease;
        }
        .sp-check:hover { color: var(--ink); }
        .sp-check input {
            appearance: none;
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            border: 1.5px solid var(--line-3);
            border-radius: 4px;
            background: #fff;
            cursor: pointer;
            position: relative;
            flex-shrink: 0;
            transition: all .15s ease;
        }
        .sp-check input:checked {
            background: linear-gradient(135deg, var(--gold), var(--gold-2));
            border-color: var(--gold);
            box-shadow: 0 0 0 3px var(--gold-ring);
        }
        .sp-check input:checked::after {
            content: '';
            position: absolute;
            left: 4px; top: 1px;
            width: 4px; height: 8px;
            border: solid #fff;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        .sp-check input:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
        .sp-check input:disabled { opacity: .5; cursor: not-allowed; }

        .sp-forgot {
            color: var(--gold);
            font-weight: 600;
            text-decoration: none;
            position: relative;
            padding-bottom: 1px;
            transition: color .15s ease;
        }
        .sp-forgot::after {
            content: '';
            position: absolute;
            left: 0; bottom: 0;
            height: 1px; width: 0;
            background: currentColor;
            transition: width .25s ease;
        }
        .sp-forgot:hover { color: var(--gold-2); }
        .sp-forgot:hover::after { width: 100%; }

        /* Submit */
        .sp-submit {
            width: 100%;
            padding: 13px 22px;
            border: 0;
            border-radius: 12px;
            background: linear-gradient(180deg,
                #C97628 0%,
                var(--gold) 55%,
                #985417 100%);
            color: #fff;
            font-weight: 700;
            font-size: .9rem;
            letter-spacing: .5px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
            box-shadow:
                0 1px 0 rgba(255, 255, 255, .35) inset,
                0 -2px 0 rgba(0, 0, 0, .14) inset,
                0 3px 0 rgba(74, 44, 16, .45),
                0 14px 30px -14px rgba(176, 100, 30, .7);
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
                0 1px 0 rgba(255, 255, 255, .4) inset,
                0 -2px 0 rgba(0, 0, 0, .14) inset,
                0 4px 0 rgba(74, 44, 16, .45),
                0 18px 34px -14px rgba(176, 100, 30, .82);
        }
        .sp-submit:hover:not(:disabled)::after { transform: translateX(100%); }
        .sp-submit:active:not(:disabled) {
            transform: translateY(2px);
            box-shadow:
                0 1px 0 rgba(255, 255, 255, .3) inset,
                0 -1px 0 rgba(0, 0, 0, .12) inset,
                0 1px 0 rgba(74, 44, 16, .45);
        }
        .sp-submit:focus-visible {
            outline: 3px solid rgba(176, 100, 30, .55);
            outline-offset: 3px;
        }
        .sp-submit:disabled { opacity: .55; cursor: not-allowed; box-shadow: none; }
        .sp-submit .arrow { transition: transform .25s ease; }
        .sp-submit:hover:not(:disabled) .arrow { transform: translateX(3px); }

        /* Divider */
        .sp-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--text-3);
            font-size: .66rem;
            letter-spacing: 1.3px;
            text-transform: uppercase;
            font-weight: 600;
            margin: clamp(16px, 2.2vh, 20px) 0 clamp(12px, 1.8vh, 16px);
        }
        .sp-divider::before,
        .sp-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg,
                transparent, var(--line), transparent);
        }

        /* Social buttons */
        .sp-social-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin-bottom: clamp(14px, 2vh, 18px);
        }
        .sp-social {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: #FFFFFF;
            border: 1.5px solid var(--line-2);
            color: var(--text);
            text-decoration: none;
            cursor: pointer;
            overflow: hidden;
            transition:
                border-color .2s ease,
                box-shadow .2s ease,
                transform .2s ease,
                background .2s ease;
        }
        .sp-social::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: radial-gradient(circle at 50% 120%,
                rgba(176, 100, 30, .12), transparent 65%);
            opacity: 0;
            transition: opacity .25s ease;
        }
        .sp-social svg { position: relative; z-index: 1; width: 19px; height: 19px; display: block; }
        .sp-social:hover {
            border-color: var(--gold);
            transform: translateY(-2px);
            box-shadow:
                0 0 0 4px var(--gold-ring),
                0 10px 22px -14px rgba(74, 44, 16, .5);
        }
        .sp-social:hover::before { opacity: 1; }
        .sp-social:focus-visible {
            outline: 3px solid rgba(176, 100, 30, .35);
            outline-offset: 3px;
        }
        .sp-social.social-facebook { color: var(--fb-blue); }
        .sp-social.social-facebook:hover { border-color: var(--fb-blue); }

        /* Register link */
        .sp-register {
            display: block;
            width: 100%;
            text-align: center;
            font-size: .82rem;
            font-weight: 500;
            color: var(--text-2);
            text-decoration: none;
            padding: 2px 0;
        }
        .sp-register strong {
            color: var(--gold);
            font-weight: 700;
            margin-left: 4px;
            text-decoration: underline;
            text-underline-offset: 3px;
            text-decoration-thickness: 1.5px;
            transition: color .18s ease;
        }
        .sp-register:hover strong { color: var(--gold-2); }
        .sp-register:focus-visible {
            outline: 3px solid rgba(176, 100, 30, .35);
            outline-offset: 4px;
            border-radius: 6px;
        }

        /* Alerts */
        .sp-alert {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            padding: 10px 12px;
            border-radius: 12px;
            font-size: .8rem;
            line-height: 1.45;
            margin-bottom: 14px;
            border: 1px solid transparent;
            box-shadow: 0 1px 0 rgba(255, 255, 255, .5) inset;
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
        .sp-alert i { font-size: .95rem; margin-top: 1px; flex-shrink: 0; }
        .sp-alert-danger {
            background: var(--danger-bg);
            border-color: var(--danger-bd);
            color: var(--danger);
        }
        .sp-alert-danger i { color: var(--gold); }
        .sp-alert-warn {
            background: var(--warn-bg);
            border-color: var(--warn-bd);
            color: var(--warn);
        }
        .sp-alert-warn i { color: #A8801A; }

        /* ============================================================
           Responsive — Mobile First Fix
           ============================================================ */

        /* ------ Tablet & small laptop ------ */
        @media (max-width: 900px) {
            .sp-card { max-width: 780px; }
        }

        /* ------ Tablet portrait & large phones (stacked) ------ */
        @media (max-width: 780px) {
            body.sp-auth {
                overflow-y: auto;
                overflow-x: hidden;
                background-attachment: scroll;
                height: auto;
                min-height: 100vh;
            }

            .sp-shell {
                align-items: flex-start;
                min-height: 100dvh;
                padding:
                    max(16px, env(safe-area-inset-top, 0px))
                    16px
                    max(24px, env(safe-area-inset-bottom, 0px));
            }

            .sp-card {
                grid-template-columns: 1fr;
                max-width: 440px;
                width: 100%;
                border-radius: 20px;
            }

            /* Brand panel — compact banner-style header */
            .sp-art {
                padding: 22px 22px 20px;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                gap: 14px;
            }

            .sp-art::after {
                /* Move hairline divider from vertical to horizontal */
                top: auto;
                bottom: 0;
                right: 0;
                width: 100%;
                height: 1px;
                background: linear-gradient(90deg,
                    transparent 0%,
                    rgba(232, 176, 90, .35) 30%,
                    rgba(232, 176, 90, .35) 70%,
                    transparent 100%);
            }

            /* Hide the long-form storytelling on mobile */
            .sp-art-body  { display: none; }
            .sp-art-foot  { display: none; }
            .sp-art-feats { display: none; }

            /* Keep brand lockup visible */
            .sp-art-brand        { gap: 10px; }
            .sp-art-brand-mark   { width: 40px; height: 40px; }
            .sp-art-brand-name   { font-size: 1.1rem; }
            .sp-art-brand-sub    { font-size: .52rem; letter-spacing: 1.8px; }

            /* Form panel */
            .sp-form-panel {
                padding: 24px 22px 26px;
            }
            .sp-form-head { margin-bottom: 18px; }
            .sp-form-head h1 { font-size: 1.35rem; }
            .sp-form-head p  { font-size: .8rem; }
        }

        /* ------ Modern phones (iPhone 12–15, most Androids) ------ */
        @media (max-width: 480px) {
            .sp-shell { padding: 12px 12px 20px; }

            .sp-card {
                border-radius: 18px;
                max-width: 100%;
            }

            .sp-art {
                padding: 18px 18px 16px;
                gap: 10px;
            }
            .sp-art-brand-mark { width: 36px; height: 36px; }
            .sp-art-brand-name { font-size: 1rem; }
            .sp-art-brand-sub  { font-size: .48rem; letter-spacing: 1.6px; }

            .sp-form-panel {
                padding: 20px 18px 22px;
            }

            .sp-form-head { margin-bottom: 16px; }
            .sp-form-head h1 {
                font-size: 1.25rem;
                padding-bottom: 12px;
            }
            .sp-form-head h1::after {
                width: 36px;
                height: 2.5px;
            }
            .sp-form-head p { font-size: .78rem; }

            /* Inputs — taller for thumbs */
            .sp-input      { min-height: 48px; border-radius: 11px; }
            .sp-input input { font-size: 16px; padding: 12px 12px 12px 8px; }
            .sp-input-ico  { padding: 0 2px 0 12px; font-size: .9rem; }
            .sp-eye        { padding: 0 10px; font-size: .9rem; }

            .sp-label { font-size: .62rem; margin-bottom: 5px; }
            .sp-field { margin-bottom: 12px; }

            /* Meta row — keep on one line but tighter */
            .sp-meta {
                font-size: .76rem;
                margin: 10px 0 16px;
                gap: 10px;
            }

            /* Submit — full width, comfortable tap target */
            .sp-submit {
                padding: 14px 20px;
                font-size: .88rem;
                border-radius: 11px;
            }

            /* Social buttons — bigger tap targets, tighter gap */
            .sp-social-row { gap: 10px; }
            .sp-social {
                width: 44px;
                height: 44px;
                border-radius: 11px;
            }
            .sp-social svg { width: 18px; height: 18px; }

            .sp-divider { font-size: .62rem; margin: 14px 0 12px; }

            .sp-register { font-size: .78rem; }

            /* Alerts */
            .sp-alert { font-size: .76rem; padding: 9px 11px; }
            .sp-alert i { font-size: .9rem; }
        }

        /* ------ Small phones (iPhone SE, older Androids) ------ */
        @media (max-width: 360px) {
            .sp-shell { padding: 8px 8px 16px; }

            .sp-card { border-radius: 16px; }

            .sp-art { padding: 14px 14px 12px; }
            .sp-art-brand-mark { width: 32px; height: 32px; }
            .sp-art-brand-name { font-size: .92rem; }
            .sp-art-brand-sub  { display: none; } /* too tight */

            .sp-form-panel { padding: 16px 14px 18px; }

            .sp-form-head h1 { font-size: 1.12rem; }
            .sp-form-head p  { font-size: .74rem; }

            .sp-input      { min-height: 44px; }
            .sp-input input { padding: 10px 10px 10px 6px; }

            .sp-submit { padding: 12px 18px; font-size: .84rem; }

            .sp-social { width: 40px; height: 40px; }
            .sp-social svg { width: 16px; height: 16px; }

            .sp-meta { font-size: .72rem; }
            .sp-check input { width: 15px; height: 15px; }

            .sp-register { font-size: .74rem; }
        }

        /* ------ Landscape phones (short height) ------ */
        @media (max-height: 560px) and (orientation: landscape) {
            .sp-shell {
                align-items: flex-start;
                padding: 12px;
            }
            .sp-card {
                grid-template-columns: 1fr 1.15fr;
                max-width: 100%;
            }
            .sp-art {
                flex-direction: column;
                justify-content: center;
                padding: 16px;
            }
            .sp-art::after {
                /* back to vertical divider in landscape */
                top: 0;
                right: 0;
                bottom: auto;
                width: 1px;
                height: 100%;
                background: linear-gradient(180deg,
                    transparent,
                    rgba(232, 176, 90, .35) 30%,
                    rgba(232, 176, 90, .35) 70%,
                    transparent);
            }
            .sp-art-body { display: none; }
            .sp-art-foot { display: none; }
            .sp-form-panel { padding: 18px 20px; }
        }

        /* ------ Prevent iOS auto-zoom on input focus ------ */
        @media (max-width: 480px) {
            .sp-input input,
            .sp-input input[type="email"],
            .sp-input input[type="password"] {
                font-size: 16px; /* iOS won't zoom at ≥16px */
            }
        }

        /* ------ Reduced motion ------ */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .001ms !important;
                transition-duration: .001ms !important;
            }
            .sp-input:hover,
            .sp-input:focus-within,
            .sp-art-feat:hover,
            .sp-art-brand:hover .sp-art-brand-mark {
                transform: none;
            }
        }
    </style>
</head>
<body class="sp-auth">

<div class="sp-shell">
    <div class="sp-card">

        <!-- ============================================================
             LEFT — BRAND ART PANEL
             ============================================================ -->
        <aside class="sp-art" aria-hidden="true">

            <a href="<?= BASE_URL ?>index.php" class="sp-art-brand">
                <!-- NEW LOGO: rice grains + sun + field rows -->
                <svg class="sp-art-brand-mark" viewBox="0 0 80 80"
                     xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <defs>
                        <linearGradient id="artGold" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0"    stop-color="#F4C87A"/>
                            <stop offset="0.55" stop-color="#E8B05A"/>
                            <stop offset="1"    stop-color="#B0641E"/>
                        </linearGradient>
                        <linearGradient id="artEarth" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="#8A5A30"/>
                            <stop offset="1" stop-color="#4A2C10"/>
                        </linearGradient>
                        <linearGradient id="artSun" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0" stop-color="#FFE9A8"/>
                            <stop offset="1" stop-color="#E8B05A"/>
                        </linearGradient>
                        <radialGradient id="artSunGlow" cx="0.5" cy="0.5" r="0.5">
                            <stop offset="0" stop-color="#FFE9A8" stop-opacity=".55"/>
                            <stop offset="1" stop-color="#FFE9A8" stop-opacity="0"/>
                        </radialGradient>
                    </defs>

                    <!-- Rounded emblem frame -->
                    <rect x="4" y="4" width="72" height="72" rx="22" fill="url(#artEarth)"/>
                    <rect x="5" y="5" width="70" height="70" rx="21"
                          fill="none" stroke="rgba(244,200,122,.4)" stroke-width="1"/>

                    <!-- Sun with soft glow -->
                    <circle cx="40" cy="26" r="16" fill="url(#artSunGlow)"/>
                    <circle cx="40" cy="26" r="8.5" fill="url(#artSun)"/>
                    <circle cx="40" cy="26" r="8.5" fill="none"
                            stroke="rgba(255,255,255,.45)" stroke-width=".9"/>

                    <!-- Rice grains (layered, fanning upward) -->
                    <g fill="url(#artGold)">
                        <path d="M40 40 C 35 35, 33 28, 33 21 C 38 25, 40 32, 40 40 Z"/>
                        <path d="M40 40 C 45 35, 47 28, 47 21 C 42 25, 40 32, 40 40 Z"/>
                        <path d="M40 46 C 35 42, 33 36, 33 30 C 38 34, 40 40, 40 46 Z" opacity=".8"/>
                        <path d="M40 46 C 45 42, 47 36, 47 30 C 42 34, 40 40, 40 46 Z" opacity=".8"/>
                        <path d="M40 52 C 36 49, 34 44, 34 39 C 38 42, 40 47, 40 52 Z" opacity=".6"/>
                        <path d="M40 52 C 44 49, 46 44, 46 39 C 42 42, 40 47, 40 52 Z" opacity=".6"/>
                    </g>

                    <!-- Field rows -->
                    <path d="M12 62 C 22 54, 32 50, 40 50 C 48 50, 58 54, 68 62
                             L 68 68 L 12 68 Z"
                          fill="url(#artEarth)" opacity=".95"/>
                    <path d="M15 64 C 24 57, 33 54, 40 54 C 47 54, 56 57, 65 64"
                          stroke="url(#artGold)" stroke-width="1.4"
                          stroke-linecap="round" fill="none" opacity=".7"/>
                    <path d="M18 67 C 26 61, 34 59, 40 59 C 46 59, 54 61, 62 67"
                          stroke="url(#artGold)" stroke-width="1.4"
                          stroke-linecap="round" fill="none" opacity=".4"/>
                </svg>

                <span class="sp-art-brand-lockup">
                    <h1 class="sp-art-brand-name">Smart<span>Palay</span></h1>
                    <span class="sp-art-brand-sub">Harvest Records</span>
                </span>
            </a>

            <div class="sp-art-body">
                <span class="sp-art-tag">
                    <i class="bi bi-tree-fill"></i>
                    Harvest Season <?= date('Y') ?>
                </span>

                <h2 class="sp-art-headline">
                    Better Records.<br>
                    Greater <em>Harvests</em>.
                </h2>
                <p class="sp-art-copy">
                    A web-based palay sales and weight record management system
                    built for farmers, traders, and millers.
                </p>

                <div class="sp-art-feats">
                    <div class="sp-art-feat">
                        <span class="sp-art-feat-ico"><i class="bi bi-clipboard-data"></i></span>
                        Accurate weight recording
                    </div>
                    <div class="sp-art-feat">
                        <span class="sp-art-feat-ico"><i class="bi bi-people"></i></span>
                        Sellers, buyers &amp; payments
                    </div>
                    <div class="sp-art-feat">
                        <span class="sp-art-feat-ico"><i class="bi bi-graph-up-arrow"></i></span>
                        Reports &amp; real-time insights
                    </div>
                </div>
            </div>

            <div class="sp-art-foot">
                <span class="dot"></span>
                System online · &copy; <?= date('Y') ?> SmartPalay
            </div>
        </aside>

        <!-- ============================================================
             RIGHT — FORM PANEL
             ============================================================ -->
        <main class="sp-form-panel">

            <div class="sp-form-head">
                <h1>Welcome back</h1>
                <p>Sign in to your SmartPalay account to continue.</p>
            </div>

            <?php if ($error): ?>
                <div class="sp-alert sp-alert-danger" role="alert" aria-live="assertive" data-autodismiss="6000">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div><?= sanitize($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!$error && $attemptsLeft > 0 && $attemptsLeft <= 2): ?>
                <div class="sp-alert sp-alert-warn" role="alert" aria-live="polite" data-autodismiss="8000">
                    <i class="bi bi-shield-exclamation"></i>
                    <div>
                        Warning: <strong><?= (int) $attemptsLeft ?></strong>
                        attempt<?= $attemptsLeft === 1 ? '' : 's' ?> remaining before temporary lockout.
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="on" novalidate id="loginForm">
                <input type="hidden" name="csrf_token" value="<?= sanitize($csrf) ?>">

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
                               autocomplete="username"
                               inputmode="email"
                               spellcheck="false"
                               autocapitalize="off"
                               maxlength="190"
                               required
                               autofocus
                               aria-describedby="emailHint"
                               <?= $isLocked ? 'disabled' : '' ?>>
                    </div>
                    <div class="sp-hint" id="emailHint" aria-live="polite"></div>
                </div>

                <!-- Password -->
                <div class="sp-field">
                    <label class="sp-label" for="password">Password</label>
                    <div class="sp-input" id="passwordWrap">
                        <span class="sp-input-ico" aria-hidden="true"><i class="bi bi-lock"></i></span>
                        <input type="password"
                               id="password"
                               name="password"
                               placeholder="Enter your password"
                               autocomplete="current-password"
                               maxlength="200"
                               required
                               aria-describedby="passwordHint passwordStrength"
                               <?= $isLocked ? 'disabled' : '' ?>>
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

                <!-- Meta row -->
                <div class="sp-meta">
                    <label class="sp-check">
                        <input type="checkbox"
                               name="remember"
                               value="1"
                               <?= $rememberChecked ? 'checked' : '' ?>
                               <?= $isLocked ? 'disabled' : '' ?>>
                        <span>Remember me</span>
                    </label>
                    <a href="#" class="sp-forgot" onclick="return false;" title="Contact administrator">
                        Forgot password?
                    </a>
                </div>

                <!-- Submit -->
                <button type="submit"
                        class="sp-submit"
                        id="loginBtn"
                        <?= $isLocked ? 'disabled' : '' ?>>
                    <span class="spinner-border spinner-border-sm d-none" id="loginSpinner" aria-hidden="true"></span>
                    <span id="loginText">Sign In</span>
                    <i class="bi bi-arrow-right arrow" id="loginIcon" aria-hidden="true"></i>
                </button>
            </form>

            <?php if ($socialEnabled['google'] || $socialEnabled['facebook'] || $socialEnabled['microsoft']): ?>
                <div class="sp-divider"><span>or continue with</span></div>

                <div class="sp-social-row" role="group" aria-label="Sign in with a social account">
                    <?php if ($socialEnabled['google']): ?>
                    <a class="sp-social social-google"
                       href="<?= BASE_URL ?>auth/oauth.php?provider=google"
                       aria-label="Sign in with Google" title="Sign in with Google">
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
                       href="<?= BASE_URL ?>auth/oauth.php?provider=facebook"
                       aria-label="Sign in with Facebook" title="Sign in with Facebook">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="#1877F2" d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/>
                        </svg>
                    </a>
                    <?php endif; ?>

                    <?php if ($socialEnabled['microsoft']): ?>
                    <a class="sp-social social-microsoft"
                       href="<?= BASE_URL ?>auth/oauth.php?provider=microsoft"
                       aria-label="Sign in with Microsoft" title="Sign in with Microsoft">
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

            <a href="<?= BASE_URL ?>auth/register.php" class="sp-register">
                Don't have an account?<strong>Register now</strong>
            </a>
        </main>

    </div>
</div>

<script>
(() => {
    'use strict';

    /* ---------- Password toggle ---------- */
    const toggle = document.getElementById('togglePwd');
    const pwd    = document.getElementById('password');
    const icon   = document.getElementById('togglePwdIcon');

    toggle?.addEventListener('click', () => {
        const show = pwd.type === 'password';
        pwd.type = show ? 'text' : 'password';
        icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        pwd.focus();
    });

    /* ---------- Submit loading state ---------- */
    document.getElementById('loginForm')?.addEventListener('submit', () => {
        const btn = document.getElementById('loginBtn');
        if (btn.disabled) return;
        btn.disabled = true;
        document.getElementById('loginSpinner').classList.remove('d-none');
        document.getElementById('loginIcon').classList.add('d-none');
        document.getElementById('loginText').textContent = 'Signing in…';
    });

    /* ---------- Autofocus password if email prefilled ---------- */
    window.addEventListener('load', () => {
        const email = document.getElementById('email');
        if (email && email.value !== '') pwd?.focus();
    });

    /* ---------- Inline email validation ---------- */
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
        if (emailWrap?.classList.contains('is-invalid')) validateEmail(true);
        else if (emailWrap?.classList.contains('is-valid')) validateEmail(true);
    });

    /* ---------- Password strength ---------- */
    const strengthBox = document.getElementById('passwordStrength');
    const strengthLbl = strengthBox?.querySelector('.sp-strength-label');
    const passwordHint = document.getElementById('passwordHint');

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

    pwd?.addEventListener('input', updateStrength);

    /* ---------- Caps Lock warning ---------- */
    function showCapsWarning(on) {
        if (!passwordHint) return;
        if (passwordHint.dataset.error === '1') return;
        if (on) {
            passwordHint.classList.add('is-visible', 'hint-warn');
            passwordHint.innerHTML = '<i class="bi bi-capslock"></i> Caps Lock is on.';
        } else {
            passwordHint.classList.remove('is-visible', 'hint-warn');
            if (!passwordHint.dataset.error) passwordHint.innerHTML = '';
        }
    }

    function capsLockOn(e) {
        return e.getModifierState && e.getModifierState('CapsLock');
    }

    pwd?.addEventListener('keyup', (e) => {
        if (e.key === 'CapsLock') { showCapsWarning(true); return; }
        showCapsWarning(capsLockOn(e));
    });
    pwd?.addEventListener('blur', () => showCapsWarning(false));

    /* ---------- Auto-dismiss alerts ---------- */
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

    /* ---------- Shake error alert on paint ---------- */
    const err = document.querySelector('.sp-alert-danger');
    if (err) {
        err.classList.add('is-shaking');
        setTimeout(() => err.classList.remove('is-shaking'), 400);
    }

    /* ---------- Lockout countdown ---------- */
    <?php if ($isLocked && $unlockAt > time()): ?>
    (function lockoutCountdown(){
        let remaining = <?= (int) max(0, $unlockAt - time()) ?>;
        const label = document.getElementById('loginText');
        const tick = () => {
            if (remaining <= 0) { location.reload(); return; }
            const m = Math.floor(remaining / 60), s = remaining % 60;
            label.textContent = `Try again in ${m}:${String(s).padStart(2, '0')}`;
            remaining--;
            setTimeout(tick, 1000);
        };
        tick();
    })();
    <?php endif; ?>
})();
</script>
</body>
</html>