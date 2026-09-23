<?php
require_once __DIR__ . '/config/database.php';
requireRole('buyer');

$fullName = $_SESSION['full_name'] ?? 'Buyer';
$firstName = sanitize(explode(' ', trim($fullName))[0] ?? 'Buyer');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['settings_saved'] = true;
    $_SESSION['pref_email'] = !empty($_POST['pref_email']);
    $_SESSION['pref_sms'] = !empty($_POST['pref_sms']);
    $_SESSION['pref_push'] = !empty($_POST['pref_push']);
}

$prefEmail = !empty($_SESSION['pref_email']);
$prefSms = !empty($_SESSION['pref_sms']);
$prefPush = !empty($_SESSION['pref_push']);

$logoFileName = '5e861afa-4a95-423a-b004-d69c59fa88dc.png';
$logoDiskPath = __DIR__ . '/images/' . $logoFileName;
$smartPalayLogo = is_file($logoDiskPath) ? BASE_URL . 'images/' . rawurlencode($logoFileName) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | SmartPalay</title>
    <?php if ($smartPalayLogo): ?><link rel="icon" type="image/png" href="<?= $smartPalayLogo ?>"><?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --gold:#B0641E; --gold-2:#C97628; --gold-3:#E8B05A; --gold-soft:#FBF1DC; --brown:#4A2C10; --cream:#FBF6EA; --cream-2:#FDFAF1; --line:#EADFC8; --text:#3A2A18; --text-2:#6B5A44; --text-3:#96856E; --sidebar-w:260px; }
        *{box-sizing:border-box} html,body{height:100%;margin:0} body.sp-dash{font-family:'Inter',sans-serif;color:var(--text);background:radial-gradient(circle at top right, rgba(232,176,90,.12), transparent 30%), var(--cream-2);}
        .sp-sidebar{position:fixed;inset:0 auto 0 0;width:var(--sidebar-w);background:linear-gradient(180deg,#4A2C10 0%,#2A1E10 100%);color:#fff;border-right:1px solid rgba(232,176,90,.18);display:flex;flex-direction:column;z-index:200}. .sp-sidebar-brand{padding:26px 20px 22px;border-bottom:1px solid rgba(232,176,90,.15);display:flex;justify-content:center;text-decoration:none;color:#fff}. .sp-sidebar-brand-name{font-family:'Fraunces',serif;font-size:1.42rem;font-weight:700;letter-spacing:-.5px;margin:0;line-height:1}.sp-sidebar-brand-name span{color:var(--gold-3)} .sp-nav{padding:16px 12px;display:flex;flex-direction:column;gap:3px;flex:1} .sp-nav-label{padding:12px 12px 6px;font-size:.64rem;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:rgba(255,255,255,.42)} .sp-nav-link{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:10px;color:rgba(255,255,255,.82);text-decoration:none;position:relative;font-size:.87rem;font-weight:500}. .sp-nav-link i{font-size:1.05rem;width:20px;text-align:center;color:rgba(255,255,255,.58)}.sp-nav-link:hover{background:rgba(232,176,90,.12);color:#fff}.sp-nav-link.is-active{background:linear-gradient(135deg,rgba(232,176,90,.28),rgba(176,100,30,.18));box-shadow:inset 0 0 0 1px rgba(232,176,90,.3)}.sp-nav-link.is-active::before{content:'';position:absolute;left:0;top:22%;bottom:22%;width:3px;border-radius:0 3px 3px 0;background:var(--gold-3)} .sp-sidebar-foot{padding:14px 12px 22px;border-top:1px solid rgba(232,176,90,.12)}.sp-main{margin-left:var(--sidebar-w);display:flex;flex-direction:column;min-width:0}.sp-topbar{position:sticky;top:0;z-index:100;padding:14px clamp(20px,3vw,38px);display:flex;align-items:center;justify-content:space-between;background:rgba(253,250,241,.88);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)} .sp-page-title{font-family:'Fraunces',serif;font-size:clamp(1.15rem,1.9vw,1.4rem);font-weight:700;color:var(--brown);margin:0}.sp-page-sub{display:block;font-size:.74rem;color:var(--text-3);margin-top:2px}.sp-page-sub strong{color:var(--gold);font-weight:700}.sp-content{padding:clamp(20px,3vw,38px);display:flex;flex-direction:column;gap:20px}.sp-panel{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 14px 30px -22px rgba(74,44,16,.45);overflow:hidden;max-width:820px}.sp-panel-head{padding:18px 22px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,#fff,#fffaf4);display:flex;align-items:center;justify-content:space-between}.sp-panel-title{font-family:'Fraunces',serif;font-size:1.05rem;font-weight:600;color:var(--brown);margin:0;display:flex;align-items:center;gap:10px}.sp-panel-title i{width:30px;height:30px;border-radius:10px;display:grid;place-items:center;background:var(--gold-soft);color:var(--gold);border:1px solid rgba(176,100,30,.18)}.sp-form{padding:24px}.form-check-label{font-weight:600;color:var(--text)}.form-check-input:checked{background-color:var(--gold);border-color:var(--gold)}.sp-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:10px;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;border:0;font-weight:700}.alert{border-radius:12px}.@media (max-width:980px){.sp-sidebar{transform:translateX(-100%)}.sp-main{margin-left:0}}
    </style>
</head>
<body class="sp-dash">
    <aside class="sp-sidebar">
        <a href="<?= BASE_URL ?>index.php" class="sp-sidebar-brand"><h1 class="sp-sidebar-brand-name">Smart<span>Palay</span></h1></a>
        <nav class="sp-nav">
            <span class="sp-nav-label">Overview</span>
            <a href="<?= BASE_URL ?>buyer/dashboard.php" class="sp-nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a href="<?= BASE_URL ?>buyer/purchases.php" class="sp-nav-link"><i class="bi bi-bag-check"></i> My Purchases</a>
            <a href="<?= BASE_URL ?>buyer/payments.php" class="sp-nav-link"><i class="bi bi-cash-coin"></i> Payments</a>
            <span class="sp-nav-label">Records</span>
            <a href="<?= BASE_URL ?>buyer/sellers.php" class="sp-nav-link"><i class="bi bi-people"></i> Sellers</a>
            <a href="<?= BASE_URL ?>buyer/reports.php" class="sp-nav-link"><i class="bi bi-graph-up-arrow"></i> Reports</a>
            <span class="sp-nav-label">Account</span>
            <a href="<?= BASE_URL ?>profile.php" class="sp-nav-link"><i class="bi bi-person-circle"></i> My Profile</a>
            <a href="<?= BASE_URL ?>settings.php" class="sp-nav-link is-active"><i class="bi bi-gear"></i> Settings</a>
        </nav>
        <div class="sp-sidebar-foot">
            <a href="<?= BASE_URL ?>auth/logout.php" class="sp-nav-link"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
        </div>
    </aside>
    <main class="sp-main">
        <header class="sp-topbar">
            <div>
                <h1 class="sp-page-title">Settings</h1>
                <span class="sp-page-sub">Welcome back, <strong><?= $firstName ?></strong>!</span>
            </div>
            <a href="<?= BASE_URL ?>buyer/dashboard.php" class="sp-nav-link" style="color:var(--gold);padding:10px 14px;border:1px solid var(--line);background:#fff;border-radius:10px;"><i class="bi bi-arrow-left"></i> Back to dashboard</a>
        </header>
        <div class="sp-content">
            <?php if (!empty($_SESSION['settings_saved'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    Settings saved successfully.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <section class="sp-panel">
                <div class="sp-panel-head">
                    <h3 class="sp-panel-title"><i class="bi bi-sliders"></i> Notification Preferences</h3>
                </div>
                <form method="POST" class="sp-form">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="prefEmail" name="pref_email" <?= $prefEmail ? 'checked' : '' ?>>
                        <label class="form-check-label" for="prefEmail">Email notifications</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="prefSms" name="pref_sms" <?= $prefSms ? 'checked' : '' ?>>
                        <label class="form-check-label" for="prefSms">SMS alerts</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="prefPush" name="pref_push" <?= $prefPush ? 'checked' : '' ?>>
                        <label class="form-check-label" for="prefPush">Push notifications</label>
                    </div>
                    <button type="submit" class="sp-btn"><i class="bi bi-floppy"></i> Save Preferences</button>
                </form>
            </section>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
