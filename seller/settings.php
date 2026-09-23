<?php
require_once __DIR__ . '/../config/database.php';

/* --------------------------------------------------------------
   Auth guards
-------------------------------------------------------------- */
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'seller') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$userId       = (int) $_SESSION['user_id'];
$fullName     = $_SESSION['full_name'] ?? 'SmartPalay Seller';
$email        = $_SESSION['email'] ?? '';
$role         = $_SESSION['role'] ?? 'seller';

$schema         = getUserSchemaColumns();
$userIdColumn   = $schema['id'];
$passwordColumn = $schema['password'] ?? 'password';

/* --------------------------------------------------------------
   Load current user + preferences
-------------------------------------------------------------- */
$user = [
    'id'                  => $userId,
    'full_name'           => $fullName,
    'email'               => $email,
    'phone'               => '',
    'address'             => '',
    'role'                => $role,
    'created_at'          => null,
    'notify_email'        => 1,
    'notify_sms'          => 0,
    'notify_payments'     => 1,
    'notify_purchases'    => 1,
    'language'            => 'en',
    'timezone'            => 'Asia/Manila',
    'theme'               => 'warm',
];

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE {$userIdColumn} = ? LIMIT 1");
    $stmt->execute([$userId]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $user = array_merge($user, $row);
    }
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    if ($prefs = $stmt->fetch(PDO::FETCH_ASSOC)) {
        foreach (['notify_email','notify_sms','notify_payments','notify_purchases','language','timezone','theme'] as $k) {
            if (array_key_exists($k, $prefs)) $user[$k] = $prefs[$k];
        }
    }
} catch (Throwable $e) {}

$fullName  = $user['full_name'] ?: 'SmartPalay Seller';
$firstName = sanitize(explode(' ', trim($fullName))[0]);

/* --------------------------------------------------------------
   Handle POST
-------------------------------------------------------------- */
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ---------- Change password ---------- */
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($current === '' || $new === '' || $confirm === '') {
            $flash = ['type' => 'error', 'msg' => 'All password fields are required.'];
        } elseif (strlen($new) < 6) {
            $flash = ['type' => 'error', 'msg' => 'New password must be at least 6 characters.'];
        } elseif ($new !== $confirm) {
            $flash = ['type' => 'error', 'msg' => 'New passwords do not match.'];
        } else {
            try {
                $stmt = $pdo->prepare("SELECT {$passwordColumn} FROM users WHERE {$userIdColumn} = ? LIMIT 1");
                $stmt->execute([$userId]);
                $hash = $stmt->fetchColumn();

                if (!$hash || !password_verify($current, $hash)) {
                    $flash = ['type' => 'error', 'msg' => 'Current password is incorrect.'];
                } else {
                    $pdo->prepare("UPDATE users SET {$passwordColumn} = ? WHERE {$userIdColumn} = ?")
                        ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
                    $flash = ['type' => 'success', 'msg' => 'Password changed successfully.'];
                }
            } catch (Throwable $e) {
                $flash = ['type' => 'error', 'msg' => 'Could not change password.'];
            }
        }
    }

    /* ---------- Save preferences ---------- */
    if ($action === 'save_preferences') {
        $notify_email     = isset($_POST['notify_email'])     ? 1 : 0;
        $notify_sms       = isset($_POST['notify_sms'])       ? 1 : 0;
        $notify_payments  = isset($_POST['notify_payments'])  ? 1 : 0;
        $notify_purchases = isset($_POST['notify_purchases']) ? 1 : 0;
        $language         = in_array($_POST['language'] ?? 'en', ['en','tl'], true) ? $_POST['language'] : 'en';
        $timezone         = trim($_POST['timezone'] ?? 'Asia/Manila');
        $theme            = in_array($_POST['theme'] ?? 'warm', ['warm','light','dark'], true) ? $_POST['theme'] : 'warm';

        $user['notify_email']     = $notify_email;
        $user['notify_sms']       = $notify_sms;
        $user['notify_payments']  = $notify_payments;
        $user['notify_purchases'] = $notify_purchases;
        $user['language']         = $language;
        $user['timezone']         = $timezone ?: 'Asia/Manila';
        $user['theme']            = $theme;

        try {
            $pdo->prepare("
                INSERT INTO user_preferences
                    (user_id, notify_email, notify_sms, notify_payments, notify_purchases, language, timezone, theme)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    notify_email     = VALUES(notify_email),
                    notify_sms       = VALUES(notify_sms),
                    notify_payments  = VALUES(notify_payments),
                    notify_purchases = VALUES(notify_purchases),
                    language         = VALUES(language),
                    timezone         = VALUES(timezone),
                    theme            = VALUES(theme)
            ")->execute([
                $userId, $notify_email, $notify_sms, $notify_payments, $notify_purchases,
                $language, $user['timezone'], $theme,
            ]);
            $flash = ['type' => 'success', 'msg' => 'Preferences saved successfully.'];
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'msg' => 'Could not save preferences (table user_preferences may be missing).'];
        }
    }
}

/* --------------------------------------------------------------
   Helpers
-------------------------------------------------------------- */
function niceDate($s) { if (!$s) return '—'; $t = strtotime($s); return $t ? date('M j, Y', $t) : '—'; }

/* Logo */
$logoFileName = '5e861afa-4a95-423a-b004-d69c59fa88dc.png';
$logoDiskPath = __DIR__ . '/../images/' . $logoFileName;
$smartPalayLogo = is_file($logoDiskPath) ? BASE_URL . 'images/' . rawurlencode($logoFileName) : '';

$initial = strtoupper(mb_substr($fullName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#B0641E">
<title>Settings | SmartPalay</title>
<?php if ($smartPalayLogo): ?><link rel="icon" type="image/png" href="<?= $smartPalayLogo ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
:root{--gold:#B0641E;--gold-2:#C97628;--gold-3:#E8B05A;--gold-4:#F4C87A;--gold-soft:#FBF1DC;--brown:#4A2C10;--brown-2:#6B4423;--brown-3:#8A5A30;--cream:#FBF6EA;--cream-2:#FDFAF1;--paper:#FAF3E5;--paper-2:#F2E7D0;--line:#EADFC8;--line-2:#DDCDA8;--line-3:#C9B68A;--ink:#2A1E10;--text:#3A2A18;--text-2:#6B5A44;--text-3:#96856E;--success:#2E7D32;--success-bg:#EAF7EC;--success-bd:#BEE0C2;--warn:#7A5A0F;--warn-bg:#FCF3D6;--warn-bd:#EBD79A;--danger:#A23A1A;--danger-bg:#FBEDE6;--danger-bd:#EFC6B0;--info:#1E5FA8;--info-bg:#E8F0FA;--sidebar-w:260px}
*,*::before,*::after{box-sizing:border-box}html,body{height:100%;margin:0}
body.sp-dash{font-family:'Inter',system-ui,sans-serif;color:var(--text);background:radial-gradient(circle at 100% 0%,rgba(232,176,90,.14),transparent 42%),radial-gradient(circle at 0% 100%,rgba(184,92,46,.08),transparent 42%),var(--cream-2);-webkit-font-smoothing:antialiased;line-height:1.5;min-height:100vh;display:flex}
.sp-sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-w);z-index:300;display:flex;flex-direction:column;background:radial-gradient(circle at 20% 10%,rgba(232,176,90,.2),transparent 55%),radial-gradient(circle at 80% 90%,rgba(184,92,46,.14),transparent 55%),linear-gradient(180deg,#4A2C10 0%,#2A1E10 100%);color:#fff;border-right:1px solid rgba(232,176,90,.18);overflow:hidden;transition:transform .3s cubic-bezier(.2,.7,.3,1)}
.sp-sidebar::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:repeating-linear-gradient(92deg,transparent 0px,transparent 22px,rgba(232,176,90,.04) 22px,rgba(232,176,90,.04) 24px),repeating-linear-gradient(88deg,transparent 0px,transparent 34px,rgba(232,176,90,.03) 34px,rgba(232,176,90,.03) 37px)}
.sp-sidebar>*{position:relative;z-index:1}
.sp-sidebar-brand{display:flex;align-items:center;justify-content:center;padding:26px 20px 22px;text-decoration:none;color:inherit;border-bottom:1px solid rgba(232,176,90,.15);flex-shrink:0;text-align:center}
.sp-sidebar-brand-name{font-family:'Fraunces',Georgia,serif;font-size:1.4rem;font-weight:700;letter-spacing:-.5px;margin:0;line-height:1;color:#fff}
.sp-sidebar-brand-name span{color:var(--gold-3)}

/* ✅ sp-sidebar-user — matched to seller dashboard */
.sp-sidebar-user{padding:22px 22px 12px;border-bottom:1px solid rgba(232,176,90,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative}
.sp-sidebar-user::before{content:'';position:absolute;width:140px;height:140px;border-radius:50%;background:radial-gradient(circle,rgba(232,176,90,.28),transparent 65%);pointer-events:none}
.sp-user-avatar{position:relative;width:82px;height:82px;border-radius:50%;flex-shrink:0;padding:3px;background:linear-gradient(135deg,#F4C87A 0%,#C97628 55%,#8A5A30 100%);box-shadow:0 0 0 1px rgba(74,44,16,.35),0 10px 24px -8px rgba(0,0,0,.8),0 4px 10px -3px rgba(0,0,0,.5);transition:transform .3s ease}
.sp-user-avatar::after{content:'';position:absolute;inset:-6px;border-radius:50%;border:1px solid rgba(232,176,90,.35);animation:spAvatarPulse 3s ease-in-out infinite;pointer-events:none}
@keyframes spAvatarPulse{0%,100%{transform:scale(1);opacity:.6}50%{transform:scale(1.08);opacity:0}}
.sp-user-avatar-img{width:100%;height:100%;border-radius:50%;overflow:hidden;background:#FDFAF1;display:grid;place-items:center}
.sp-user-avatar-img img{width:100%;height:100%;object-fit:cover;display:block;border-radius:50%}

/* ✅ Seller role label — below the logo */
.sp-sidebar-role{display:flex;align-items:center;justify-content:center;padding:0 22px 18px;margin-top:-4px;border-bottom:1px solid rgba(232,176,90,.12);flex-shrink:0}
.sp-sidebar-role-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;background:rgba(232,176,90,.14);border:1px solid rgba(232,176,90,.32);color:var(--gold-3);font-size:.64rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;white-space:nowrap}
.sp-sidebar-role-chip i{font-size:.78rem}

.sp-nav{padding:16px 12px;display:flex;flex-direction:column;gap:3px;flex:1 1 auto;min-height:0;overflow-y:auto;scrollbar-width:thin;scrollbar-color:rgba(232,176,90,.35) transparent}
.sp-nav::-webkit-scrollbar{width:6px}.sp-nav::-webkit-scrollbar-thumb{background:linear-gradient(180deg,rgba(232,176,90,.45),rgba(176,100,30,.35));border-radius:4px}
.sp-nav-label{padding:12px 12px 6px;font-size:.64rem;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:rgba(255,255,255,.42)}
.sp-nav-link{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:10px;color:rgba(255,255,255,.82);text-decoration:none;font-size:.87rem;font-weight:500;transition:background .18s ease,color .18s ease;position:relative;z-index:2;flex-shrink:0;cursor:pointer}
.sp-nav-link i{font-size:1.05rem;width:20px;text-align:center;color:rgba(255,255,255,.58);transition:color .18s ease;pointer-events:none}
.sp-nav-link:hover{background:rgba(232,176,90,.12);color:#fff}
.sp-nav-link:hover i{color:var(--gold-3)}
.sp-nav-link.is-active{background:linear-gradient(135deg,rgba(232,176,90,.28),rgba(176,100,30,.18));color:#fff;font-weight:600;box-shadow:inset 0 0 0 1px rgba(232,176,90,.3)}
.sp-nav-link.is-active i{color:var(--gold-3)}
.sp-nav-link.is-active::before{content:'';position:absolute;left:0;top:22%;bottom:22%;width:3px;border-radius:0 3px 3px 0;background:var(--gold-3)}
.sp-sidebar-foot{padding:14px 12px 22px;border-top:1px solid rgba(232,176,90,.12);flex-shrink:0;position:relative;z-index:2}
.sp-nav-link.sp-logout{color:rgba(255,200,180,.92)}
.sp-nav-link.sp-logout:hover{background:rgba(162,58,26,.25);color:#fff}
.sp-nav-link.sp-logout:hover i{color:#FFB199}
.sp-main{flex:1;min-width:0;margin-left:var(--sidebar-w);display:flex;flex-direction:column;position:relative;z-index:1}
.sp-topbar{position:sticky;top:0;z-index:100;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px clamp(20px,3vw,38px);background:rgba(253,250,241,.88);backdrop-filter:blur(16px) saturate(150%);-webkit-backdrop-filter:blur(16px) saturate(150%);border-bottom:1px solid var(--line)}
.sp-topbar-left{display:flex;align-items:center;gap:14px;min-width:0}
.sp-menu-btn{display:none;width:40px;height:40px;border:1.5px solid var(--line-2);background:#fff;border-radius:10px;color:var(--brown-2);font-size:1.1rem;cursor:pointer;align-items:center;justify-content:center}
.sp-page-title{font-family:'Fraunces',Georgia,serif;font-size:clamp(1.15rem,1.9vw,1.4rem);font-weight:700;letter-spacing:-.4px;color:var(--brown);margin:0;line-height:1.2}
.sp-page-sub{display:block;font-size:.74rem;font-weight:500;color:var(--text-3);margin-top:2px}
.sp-page-sub strong{color:var(--gold);font-weight:700}
.sp-topbar-right{display:flex;align-items:center;gap:10px}
.sp-icon-btn{position:relative;width:40px;height:40px;border:1.5px solid var(--line-2);background:#fff;border-radius:10px;color:var(--brown-2);font-size:1rem;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:border-color .18s ease,color .18s ease,transform .18s ease}
.sp-icon-btn:hover{border-color:var(--gold);color:var(--gold);transform:translateY(-1px)}
.sp-icon-btn .badge-dot{position:absolute;top:8px;right:9px;width:8px;height:8px;border-radius:50%;background:var(--danger);box-shadow:0 0 0 2px #fff}
.sp-cta{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;font-weight:700;font-size:.85rem;text-decoration:none;border:0;cursor:pointer;font-family:inherit;box-shadow:0 3px 0 rgba(0,0,0,.12),0 12px 24px -12px rgba(176,100,30,.7);transition:transform .15s ease,filter .15s ease}
.sp-cta:hover{color:#fff;transform:translateY(-1px);filter:brightness(1.04)}
.sp-content{padding:clamp(20px,3vw,38px);display:flex;flex-direction:column;gap:clamp(18px,2.4vh,26px)}

/* Flash */
.sp-flash{display:flex;align-items:center;gap:12px;padding:14px 20px;border-radius:14px;font-size:.88rem;font-weight:600}
.sp-flash.is-success{background:linear-gradient(135deg,var(--success-bg),#fff);border:1px solid var(--success-bd);color:var(--success)}
.sp-flash.is-error{background:linear-gradient(135deg,var(--danger-bg),#fff);border:1px solid var(--danger-bd);color:var(--danger)}
.sp-flash i{font-size:1.2rem}

/* Hero */
.sp-welcome{position:relative;overflow:hidden;padding:clamp(28px,3.6vh,42px) clamp(24px,3.4vw,44px);border-radius:24px;background:radial-gradient(circle at 82% 18%,rgba(232,176,90,.38),transparent 55%),radial-gradient(circle at 10% 90%,rgba(184,92,46,.22),transparent 50%),linear-gradient(135deg,#4A2C10 0%,#2A1E10 100%);color:#fff;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:32px;align-items:center;box-shadow:0 24px 60px -28px rgba(40,22,6,.7)}
.sp-welcome::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:repeating-linear-gradient(92deg,transparent 0px,transparent 22px,rgba(232,176,90,.06) 22px,rgba(232,176,90,.06) 24px),repeating-linear-gradient(88deg,transparent 0px,transparent 34px,rgba(232,176,90,.04) 34px,rgba(232,176,90,.04) 37px)}
.sp-welcome>*{position:relative;z-index:1}
.sp-welcome-tag{display:inline-flex;align-items:center;gap:7px;padding:6px 13px;border-radius:999px;background:rgba(232,176,90,.16);border:1px solid rgba(232,176,90,.35);font-size:.66rem;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--gold-3);margin-bottom:16px}
.sp-welcome h2{font-family:'Fraunces',Georgia,serif;font-size:clamp(1.55rem,2.6vw,2.2rem);font-weight:600;line-height:1.15;letter-spacing:-.6px;margin:0 0 12px}
.sp-welcome h2 em{font-style:italic;font-weight:500;color:var(--gold-3)}
.sp-welcome p{font-size:.93rem;color:rgba(255,255,255,.78);line-height:1.65;margin:0;max-width:54ch}
.sp-welcome-portrait{position:relative;width:160px;height:160px;flex-shrink:0;display:grid;place-items:center}
.sp-welcome-portrait-ring{position:absolute;inset:0;border-radius:50%;background:conic-gradient(from 220deg,rgba(232,176,90,.8),rgba(232,176,90,.1) 55%,rgba(232,176,90,.6));-webkit-mask:radial-gradient(circle,transparent 62%,#000 63%);mask:radial-gradient(circle,transparent 62%,#000 63%);animation:spRingSpin 18s linear infinite}
@keyframes spRingSpin{to{transform:rotate(360deg)}}
.sp-welcome-portrait-inner{width:124px;height:124px;border-radius:50%;overflow:hidden;background:#FDFAF1;box-shadow:0 0 0 4px rgba(74,44,16,.5),0 14px 34px -12px rgba(0,0,0,.8);display:grid;place-items:center;font-family:'Fraunces',Georgia,serif;font-size:3rem;font-weight:700;color:var(--gold);letter-spacing:-1px}
.sp-welcome-portrait-inner img{width:100%;height:100%;object-fit:cover;display:block}
@media (max-width:860px){.sp-welcome{grid-template-columns:1fr}.sp-welcome-portrait{display:none}}

/* Grid & panels */
.sp-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:clamp(14px,1.8vw,22px);align-items:start}
@media (max-width:980px){.sp-grid{grid-template-columns:1fr}}
.sp-panel{position:relative;background:#fff;border:1px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 14px 30px -22px rgba(74,44,16,.4)}
.sp-panel::before{content:'';position:absolute;top:0;left:0;width:56px;height:3px;background:linear-gradient(90deg,var(--gold),var(--gold-3));border-radius:0 3px 3px 0;opacity:.55}
.sp-panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,#fff,var(--cream-2));flex-wrap:wrap}
.sp-panel-title{display:flex;align-items:center;gap:11px;font-family:'Fraunces',Georgia,serif;font-size:1.06rem;font-weight:600;color:var(--brown);margin:0}
.sp-panel-title i{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;background:var(--gold-soft);color:var(--gold);font-size:.95rem;border:1px solid rgba(176,100,30,.18)}
.sp-panel-sub{font-size:.74rem;color:var(--text-3);font-weight:500}
.sp-panel-body{padding:22px;display:flex;flex-direction:column;gap:16px}

/* Form */
.sp-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:540px){.sp-form-row{grid-template-columns:1fr}}
.sp-form-field{display:flex;flex-direction:column;gap:6px}
.sp-form-field.is-full{grid-column:1/-1}
.sp-form-field label{font-size:.66rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--brown-2)}
.sp-form-field label .req{color:var(--danger);margin-left:2px}
.sp-form-input{display:flex;align-items:center;background:#fff;border:1.5px solid var(--line-2);border-radius:11px;transition:border-color .18s ease,box-shadow .18s ease;overflow:hidden;min-height:46px}
.sp-form-input:hover{border-color:var(--line-3)}
.sp-form-input:focus-within{border-color:var(--gold);box-shadow:0 0 0 4px rgba(176,100,30,.13)}
.sp-form-input i{padding:0 4px 0 14px;color:var(--text-3);font-size:.95rem}
.sp-form-input:focus-within i{color:var(--gold)}
.sp-form-input input,.sp-form-input select,.sp-form-input textarea{flex:1;border:0;outline:0;background:transparent;padding:11px 14px 11px 10px;font-size:.89rem;font-weight:500;color:var(--text);font-family:inherit;min-width:0;width:100%}
.sp-form-input textarea{resize:vertical;min-height:76px;padding-top:12px}
.sp-form-input input::placeholder,.sp-form-input textarea::placeholder{color:#A9A392;font-weight:400}
.sp-form-input.is-select{position:relative}
.sp-form-input.is-select select{appearance:none;-webkit-appearance:none;cursor:pointer}
.sp-form-input.is-select::after{content:'';width:7px;height:7px;border-right:2px solid var(--text-3);border-bottom:2px solid var(--text-3);transform:rotate(45deg) translate(-6px,-3px);pointer-events:none;margin-right:14px}
.sp-form-hint{font-size:.7rem;color:var(--text-3);font-weight:500;padding-left:2px}

/* Toggle */
.sp-toggle-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 16px;border-radius:12px;background:var(--cream-2);border:1px solid var(--line);transition:border-color .18s ease,background .18s ease}
.sp-toggle-row:hover{border-color:var(--line-3);background:#fff}
.sp-toggle-info{min-width:0}
.sp-toggle-label{font-size:.88rem;font-weight:600;color:var(--brown);margin:0 0 2px}
.sp-toggle-desc{font-size:.74rem;color:var(--text-3);margin:0;line-height:1.5}
.sp-switch{position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0;cursor:pointer}
.sp-switch input{opacity:0;width:0;height:0;position:absolute}
.sp-switch-track{position:absolute;inset:0;background:var(--line-2);border-radius:999px;transition:background .22s ease}
.sp-switch-track::after{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.15);transition:transform .22s cubic-bezier(.2,.7,.3,1)}
.sp-switch input:checked + .sp-switch-track{background:linear-gradient(135deg,var(--gold),var(--gold-2))}
.sp-switch input:checked + .sp-switch-track::after{transform:translateX(20px)}

/* Buttons */
.sp-btn-submit,.sp-btn-cancel,.sp-btn-danger{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 22px;border-radius:11px;font-size:.86rem;font-weight:700;font-family:inherit;cursor:pointer;text-decoration:none;transition:transform .15s ease,filter .15s ease,border-color .18s ease,color .18s ease,background .15s ease}
.sp-btn-submit{border:0;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;box-shadow:0 3px 0 rgba(0,0,0,.12),0 14px 24px -12px rgba(176,100,30,.75)}
.sp-btn-submit:hover{transform:translateY(-1px);filter:brightness(1.04)}
.sp-btn-cancel{border:1.5px solid var(--line-2);background:#fff;color:var(--brown-2)}
.sp-btn-cancel:hover{border-color:var(--gold);color:var(--gold);transform:translateY(-1px)}
.sp-btn-danger{border:1.5px solid var(--danger-bd);background:var(--danger-bg);color:var(--danger)}
.sp-btn-danger:hover{background:var(--danger);color:#fff;transform:translateY(-1px)}

.sp-form-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:18px;border-top:1px dashed var(--line-2);flex-wrap:wrap}

/* Info list */
.sp-info-list{padding:22px;display:flex;flex-direction:column;gap:12px}
.sp-info-row{display:flex;align-items:center;gap:12px;padding:13px 15px;border-radius:12px;background:var(--cream-2);border:1px solid var(--line);font-size:.86rem;transition:transform .18s ease,border-color .18s ease}
.sp-info-row:hover{transform:translateX(3px);border-color:var(--line-3)}
.sp-info-ico{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;background:#fff;color:var(--gold);font-size:1rem;border:1px solid var(--line-2);flex-shrink:0}
.sp-info-ico.is-info{color:var(--info);border-color:#C6DCF0;background:var(--info-bg)}
.sp-info-ico.is-success{color:var(--success);border-color:var(--success-bd);background:var(--success-bg)}
.sp-info-body{min-width:0;flex:1}
.sp-info-label{font-size:.66rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3);margin-bottom:2px}
.sp-info-value{font-size:.88rem;font-weight:600;color:var(--brown);line-height:1.35;word-break:break-word}
.sp-info-value.is-empty{color:var(--text-3);font-weight:500;font-style:italic}

.sp-security-note{margin:0 0 14px;font-size:.86rem;color:var(--text-2);line-height:1.6}
.sp-danger-text{font-size:.86rem;color:var(--text-2);line-height:1.55;margin:0 0 14px}

/* Toast */
.sp-toast{position:fixed;bottom:26px;left:50%;transform:translate(-50%,120%);background:linear-gradient(135deg,#4A2C10,#2A1E10);color:#fff;padding:14px 22px;border-radius:14px;box-shadow:0 24px 46px -20px rgba(0,0,0,.75);display:inline-flex;align-items:center;gap:11px;font-size:.88rem;font-weight:600;z-index:500;transition:transform .38s cubic-bezier(.2,.7,.3,1);border:1px solid rgba(232,176,90,.3);max-width:calc(100vw - 40px)}
.sp-toast.is-visible{transform:translate(-50%,0)}
.sp-toast i{font-size:1.2rem;color:var(--gold-3)}
.sp-toast.is-success i{color:#7DD68A}
.sp-toast.is-error i{color:#FF9B7A}

/* Mobile */
.sp-sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(42,30,16,.5);z-index:250;opacity:0;pointer-events:none;transition:opacity .25s ease}
@media (max-width:900px){.sp-sidebar{transform:translateX(-100%)}body.sp-sidebar-open .sp-sidebar{transform:translateX(0)}body.sp-sidebar-open .sp-sidebar-overlay{display:block;opacity:1;pointer-events:auto}.sp-main{margin-left:0}.sp-menu-btn{display:inline-flex}}
@media (max-width:480px){.sp-topbar{padding:12px 16px}.sp-content{padding:16px}.sp-cta span{display:none}.sp-cta{padding:10px 12px}.sp-panel-head{padding:14px 16px}.sp-panel-body{padding:18px 16px}.sp-info-list{padding:18px 16px}}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.001ms!important;transition-duration:.001ms!important}}
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
                <?php if ($smartPalayLogo): ?><img src="<?= $smartPalayLogo ?>" alt="SmartPalay">
                <?php else: ?><i class="bi bi-image" style="font-size:1.6rem;color:var(--text-3)"></i><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ✅ Seller role label — directly below the logo -->
    <div class="sp-sidebar-role">
        <span class="sp-sidebar-role-chip">
            <i class="bi bi-flower1"></i> Seller
        </span>
    </div>

    <nav class="sp-nav">
        <span class="sp-nav-label">Overview</span>
        <a href="<?= BASE_URL ?>seller/dashboard.php" class="sp-nav-link" data-nav><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="<?= BASE_URL ?>seller/sales.php" class="sp-nav-link" data-nav><i class="bi bi-bag-check"></i> My Sales</a>
        <a href="<?= BASE_URL ?>seller/payments.php" class="sp-nav-link" data-nav><i class="bi bi-cash-coin"></i> Payments Received</a>
        <span class="sp-nav-label">Records</span>
        <a href="<?= BASE_URL ?>seller/buyers.php" class="sp-nav-link" data-nav><i class="bi bi-people"></i> Buyers</a>
        <a href="<?= BASE_URL ?>seller/reports.php" class="sp-nav-link" data-nav><i class="bi bi-graph-up-arrow"></i> Reports</a>
        <span class="sp-nav-label">Account</span>
        <a href="<?= BASE_URL ?>seller/profile.php" class="sp-nav-link" data-nav><i class="bi bi-person-circle"></i> My Profile</a>
        <a href="<?= BASE_URL ?>seller/settings.php" class="sp-nav-link is-active" data-nav><i class="bi bi-gear"></i> Settings</a>
    </nav>
    <div class="sp-sidebar-foot">
        <a href="<?= BASE_URL ?>auth/logout.php" class="sp-nav-link sp-logout" data-nav><i class="bi bi-box-arrow-right"></i> Sign Out</a>
    </div>
</aside>

<div class="sp-main">
    <header class="sp-topbar">
        <div class="sp-topbar-left">
            <button class="sp-menu-btn" id="menuBtn" aria-label="Open menu"><i class="bi bi-list"></i></button>
            <div>
                <h1 class="sp-page-title">Settings</h1>
                <span class="sp-page-sub">Welcome back, <strong><?= $firstName ?></strong>!</span>
            </div>
        </div>
        <div class="sp-topbar-right">
            <button class="sp-icon-btn" id="notifBtn" aria-label="Notifications">
                <i class="bi bi-bell"></i><span class="badge-dot"></span>
            </button>
            <a href="<?= BASE_URL ?>seller/profile.php" class="sp-cta" data-nav>
                <i class="bi bi-person-circle"></i><span>My Profile</span>
            </a>
        </div>
    </header>

    <main class="sp-content">

        <?php if (!empty($flash['msg'])): ?>
            <div class="sp-flash is-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>">
                <i class="bi <?= $flash['type'] === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill' ?>"></i>
                <span><?= sanitize($flash['msg']) ?></span>
            </div>
        <?php endif; ?>

        <!-- Hero -->
        <section class="sp-welcome">
            <div>
                <span class="sp-welcome-tag"><i class="bi bi-gear"></i> Account Settings</span>
                <h2>Fine-tune your <em>SmartPalay</em> experience.</h2>
                <p>Change your password, manage notifications, and choose how SmartPalay looks and speaks to you.</p>
            </div>
            <?php if ($smartPalayLogo): ?>
            <div class="sp-welcome-portrait" aria-hidden="true">
                <span class="sp-welcome-portrait-ring"></span>
                <div class="sp-welcome-portrait-inner"><img src="<?= $smartPalayLogo ?>" alt="SmartPalay"></div>
            </div>
            <?php endif; ?>
        </section>

        <!-- Grid -->
        <section class="sp-grid">

            <!-- LEFT: Password + Preferences -->
            <div style="display:flex;flex-direction:column;gap:clamp(14px,1.8vw,22px)">

                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title"><i class="bi bi-shield-lock"></i> Change Password</h3>
                    </div>
                    <form method="post" class="sp-panel-body" autocomplete="off">
                        <input type="hidden" name="action" value="change_password">
                        <div class="sp-form-row">
                            <div class="sp-form-field is-full">
                                <label for="current_password">Current Password <span class="req">*</span></label>
                                <div class="sp-form-input"><i class="bi bi-lock"></i><input type="password" id="current_password" name="current_password" required></div>
                            </div>
                            <div class="sp-form-field">
                                <label for="new_password">New Password <span class="req">*</span></label>
                                <div class="sp-form-input"><i class="bi bi-key"></i><input type="password" id="new_password" name="new_password" minlength="6" required></div>
                                <div class="sp-form-hint">At least 6 characters</div>
                            </div>
                            <div class="sp-form-field">
                                <label for="confirm_password">Confirm Password <span class="req">*</span></label>
                                <div class="sp-form-input"><i class="bi bi-key-fill"></i><input type="password" id="confirm_password" name="confirm_password" minlength="6" required></div>
                            </div>
                        </div>
                        <div class="sp-form-actions">
                            <button type="submit" class="sp-btn-submit"><i class="bi bi-shield-check"></i> Update Password</button>
                        </div>
                    </form>
                </div>

                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title"><i class="bi bi-sliders"></i> Preferences</h3>
                    </div>
                    <form method="post" class="sp-panel-body" autocomplete="off">
                        <input type="hidden" name="action" value="save_preferences">

                        <div class="sp-toggle-row">
                            <div class="sp-toggle-info">
                                <p class="sp-toggle-label">Email Notifications</p>
                                <p class="sp-toggle-desc">Receive sales and payment summaries by email.</p>
                            </div>
                            <label class="sp-switch">
                                <input type="checkbox" name="notify_email" value="1" <?= !empty($user['notify_email']) ? 'checked' : '' ?>>
                                <span class="sp-switch-track"></span>
                            </label>
                        </div>

                        <div class="sp-toggle-row">
                            <div class="sp-toggle-info">
                                <p class="sp-toggle-label">SMS Notifications</p>
                                <p class="sp-toggle-desc">Get payment reminders via text message.</p>
                            </div>
                            <label class="sp-switch">
                                <input type="checkbox" name="notify_sms" value="1" <?= !empty($user['notify_sms']) ? 'checked' : '' ?>>
                                <span class="sp-switch-track"></span>
                            </label>
                        </div>

                        <div class="sp-toggle-row">
                            <div class="sp-toggle-info">
                                <p class="sp-toggle-label">Payment Alerts</p>
                                <p class="sp-toggle-desc">Notify me when a payment is received.</p>
                            </div>
                            <label class="sp-switch">
                                <input type="checkbox" name="notify_payments" value="1" <?= !empty($user['notify_payments']) ? 'checked' : '' ?>>
                                <span class="sp-switch-track"></span>
                            </label>
                        </div>

                        <div class="sp-toggle-row">
                            <div class="sp-toggle-info">
                                <p class="sp-toggle-label">Sale Alerts</p>
                                <p class="sp-toggle-desc">Notify me each time a sale is recorded.</p>
                            </div>
                            <label class="sp-switch">
                                <input type="checkbox" name="notify_purchases" value="1" <?= !empty($user['notify_purchases']) ? 'checked' : '' ?>>
                                <span class="sp-switch-track"></span>
                            </label>
                        </div>

                        <div class="sp-form-row" style="margin-top:6px">
                            <div class="sp-form-field">
                                <label for="language">Language</label>
                                <div class="sp-form-input is-select">
                                    <i class="bi bi-translate"></i>
                                    <select id="language" name="language">
                                        <option value="en" <?= ($user['language'] ?? 'en') === 'en' ? 'selected' : '' ?>>English</option>
                                        <option value="tl" <?= ($user['language'] ?? '') === 'tl' ? 'selected' : '' ?>>Filipino</option>
                                    </select>
                                </div>
                            </div>
                            <div class="sp-form-field">
                                <label for="timezone">Timezone</label>
                                <div class="sp-form-input is-select">
                                    <i class="bi bi-clock"></i>
                                    <select id="timezone" name="timezone">
                                        <?php
                                            $zones = ['Asia/Manila','Asia/Singapore','Asia/Tokyo','UTC'];
                                            $cur   = $user['timezone'] ?? 'Asia/Manila';
                                            if (!in_array($cur, $zones, true)) $zones[] = $cur;
                                            foreach ($zones as $z):
                                        ?>
                                            <option value="<?= sanitize($z) ?>" <?= $cur === $z ? 'selected' : '' ?>><?= sanitize($z) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="sp-form-field is-full">
                                <label for="theme">Appearance</label>
                                <div class="sp-form-input is-select">
                                    <i class="bi bi-palette"></i>
                                    <select id="theme" name="theme">
                                        <option value="warm"  <?= ($user['theme'] ?? 'warm') === 'warm'  ? 'selected' : '' ?>>Warm (default)</option>
                                        <option value="light" <?= ($user['theme'] ?? '') === 'light' ? 'selected' : '' ?>>Light</option>
                                        <option value="dark"  <?= ($user['theme'] ?? '') === 'dark'  ? 'selected' : '' ?>>Dark</option>
                                    </select>
                                </div>
                                <div class="sp-form-hint">Theme applies to your account on this device.</div>
                            </div>
                        </div>

                        <div class="sp-form-actions">
                            <button type="submit" class="sp-btn-submit"><i class="bi bi-check-lg"></i> Save Preferences</button>
                        </div>
                    </form>
                </div>

            </div>

            <!-- RIGHT: Account info + security + danger zone -->
            <div style="display:flex;flex-direction:column;gap:clamp(14px,1.8vw,22px)">

                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title"><i class="bi bi-info-circle"></i> Account Details</h3>
                    </div>
                    <div class="sp-info-list">
                        <div class="sp-info-row">
                            <div class="sp-info-ico"><i class="bi bi-hash"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">User ID</div>
                                <div class="sp-info-value" style="font-family:'JetBrains Mono', monospace; font-size:.82rem">
                                    #<?= str_pad((string) ($user[$userIdColumn] ?? $userId), 6, '0', STR_PAD_LEFT) ?>
                                </div>
                            </div>
                        </div>
                        <div class="sp-info-row">
                            <div class="sp-info-ico is-success"><i class="bi bi-person-badge"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Role</div>
                                <div class="sp-info-value">Seller</div>
                            </div>
                        </div>
                        <div class="sp-info-row">
                            <div class="sp-info-ico is-info"><i class="bi bi-calendar3"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Member Since</div>
                                <div class="sp-info-value"><?= niceDate($user['created_at'] ?? null) ?></div>
                            </div>
                        </div>
                        <div class="sp-info-row">
                            <div class="sp-info-ico"><i class="bi bi-envelope"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Email</div>
                                <div class="sp-info-value <?= empty($user['email']) ? 'is-empty' : '' ?>">
                                    <?= sanitize($user['email'] ?: 'Not set') ?>
                                </div>
                            </div>
                        </div>
                        <div class="sp-info-row">
                            <div class="sp-info-ico"><i class="bi bi-telephone"></i></div>
                            <div class="sp-info-body">
                                <div class="sp-info-label">Phone</div>
                                <div class="sp-info-value <?= empty($user['phone']) ? 'is-empty' : '' ?>">
                                    <?= sanitize($user['phone'] ?: 'Not set') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title"><i class="bi bi-shield-check"></i> Security Tips</h3>
                    </div>
                    <div class="sp-panel-body">
                        <p class="sp-security-note">Keep your password private and update it regularly. Use a unique password you don't reuse on other sites.</p>
                        <a href="<?= BASE_URL ?>seller/profile.php" class="sp-btn-submit" style="display:inline-flex" data-nav>
                            <i class="bi bi-person-circle"></i> Back to Profile
                        </a>
                    </div>
                </div>

                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title"><i class="bi bi-exclamation-octagon"></i> Danger Zone</h3>
                    </div>
                    <div class="sp-panel-body">
                        <p class="sp-danger-text">Signing out ends your current session. You can sign back in anytime with your email and password.</p>
                        <a href="<?= BASE_URL ?>auth/logout.php" class="sp-btn-danger" data-nav>
                            <i class="bi bi-box-arrow-right"></i> Sign Out
                        </a>
                    </div>
                </div>

            </div>

        </section>
    </main>
</div>

<div class="sp-toast" id="spToast">
    <i class="bi bi-check-circle-fill" id="spToastIcon"></i>
    <span id="spToastText">Ready</span>
</div>

<script>
(() => {
    'use strict';

    /* Sidebar */
    const body = document.body;
    const menuBtn = document.getElementById('menuBtn');
    const overlay = document.getElementById('sidebarOverlay');
    const openSidebar  = () => body.classList.add('sp-sidebar-open');
    const closeSidebar = () => body.classList.remove('sp-sidebar-open');
    menuBtn?.addEventListener('click', openSidebar);
    overlay?.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });
    window.addEventListener('resize', () => { if (window.innerWidth > 900) closeSidebar(); });

    /* Toast */
    const toast = document.getElementById('spToast');
    const toastIcon = document.getElementById('spToastIcon');
    const toastText = document.getElementById('spToastText');
    let toastTimer;
    function showToast(msg, type = 'success') {
        toastText.textContent = msg;
        toast.classList.remove('is-success','is-error');
        toastIcon.className = 'bi ' + (type === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
        toast.classList.add('is-' + type, 'is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 3200);
    }

    /* Hardened nav */
    document.querySelectorAll('[data-nav]').forEach(link => {
        link.addEventListener('click', (e) => {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
            const href = link.getAttribute('href');
            if (!href || href === '#' || href === '') { e.preventDefault(); showToast('Not available yet.','error'); return; }
            e.preventDefault();
            window.location.href = href;
        });
    });

    /* Client-side password match check */
    document.querySelectorAll('form').forEach(f => {
        f.addEventListener('submit', (e) => {
            const newPw     = f.querySelector('#new_password');
            const confirmPw = f.querySelector('#confirm_password');
            if (newPw && confirmPw && newPw.value && confirmPw.value && newPw.value !== confirmPw.value) {
                e.preventDefault();
                showToast('New passwords do not match.', 'error');
                confirmPw.focus();
            }
        });
    });

    /* Flash via toast */
    <?php if (!empty($flash['msg'])): ?>
    window.addEventListener('load', () => {
        showToast(<?= json_encode($flash['msg']) ?>, <?= json_encode($flash['type'] === 'error' ? 'error' : 'success') ?>);
    });
    <?php endif; ?>

    document.getElementById('notifBtn')?.addEventListener('click', () => showToast('No new notifications right now.', 'success'));
})();
</script>
</body>
</html>