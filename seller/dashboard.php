<?php
require_once __DIR__ . '/../config/database.php';

/* Auth guards */
if (!isLoggedIn()) { header('Location: ' . BASE_URL . 'auth/login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'seller') { header('Location: ' . BASE_URL . 'index.php'); exit; }

$userId   = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'SmartPalay Seller';
$email    = $_SESSION['email'] ?? '';

/* Stats */
$stats = ['total_sales'=>0,'total_kg'=>0,'total_earned'=>0,'outstanding'=>0,'buyers_count'=>0];
$recentSales = []; $recentPayments = []; $buyersList = []; $totalValue = 0;

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_sales,
               COALESCE(SUM(weight_kg),0) AS total_kg,
               COALESCE(SUM(total_amount),0) AS total_earned,
               COALESCE(SUM(balance),0) AS outstanding,
               COUNT(DISTINCT buyer_name) AS buyers_count
        FROM purchases WHERE seller_id = ?
    ");
    $stmt->execute([$userId]);
    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats['total_sales']  = (int)$r['total_sales'];
        $stats['total_kg']     = (float)$r['total_kg'];
        $stats['total_earned'] = (float)$r['total_earned'];
        $stats['outstanding']  = (float)$r['outstanding'];
        $stats['buyers_count'] = (int)$r['buyers_count'];
    }
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT id, reference_no, buyer_name, weight_kg, total_amount, balance, status, created_at
        FROM purchases WHERE seller_id = ? ORDER BY created_at DESC LIMIT 6
    ");
    $stmt->execute([$userId]);
    $recentSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT id, reference_no, amount, method, paid_at
        FROM payments WHERE seller_id = ? ORDER BY paid_at DESC LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT buyer_name FROM purchases
        WHERE seller_id = ? AND buyer_name <> '' ORDER BY buyer_name
    ");
    $stmt->execute([$userId]);
    $buyersList = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

$totalValue = $stats['total_earned'] + $stats['outstanding'];
$paidPct    = $totalValue > 0 ? ($stats['total_earned'] / $totalValue) * 100 : 0;

/* Helpers */
function peso($n) { return '₱' . number_format((float)$n, 2); }
function kg($n)   { return number_format((float)$n, 2) . ' kg'; }
function niceDate($s) { if(!$s) return '—'; $t=strtotime($s); return $t?date('M j, Y',$t):'—'; }

/* Logo */
$logoFileName = '5e861afa-4a95-423a-b004-d69c59fa88dc.png';
$logoDiskPath = __DIR__ . '/../images/' . $logoFileName;
$smartPalayLogo = is_file($logoDiskPath) ? BASE_URL . 'images/' . rawurlencode($logoFileName) : '';
$firstName = sanitize(explode(' ', $fullName)[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#B0641E">
<title>Seller Dashboard | SmartPalay</title>
<?php if ($smartPalayLogo): ?><link rel="icon" type="image/png" href="<?= $smartPalayLogo ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
:root{--gold:#B0641E;--gold-2:#C97628;--gold-3:#E8B05A;--gold-4:#F4C87A;--gold-soft:#FBF1DC;--brown:#4A2C10;--brown-2:#6B4423;--brown-3:#8A5A30;--cream:#FBF6EA;--cream-2:#FDFAF1;--paper:#FAF3E5;--paper-2:#F2E7D0;--line:#EADFC8;--line-2:#DDCDA8;--line-3:#C9B68A;--ink:#2A1E10;--text:#3A2A18;--text-2:#6B5A44;--text-3:#96856E;--success:#2E7D32;--success-bg:#EAF7EC;--success-bd:#BEE0C2;--warn:#7A5A0F;--warn-bg:#FCF3D6;--warn-bd:#EBD79A;--danger:#A23A1A;--danger-bg:#FBEDE6;--danger-bd:#EFC6B0;--info:#1E5FA8;--info-bg:#E8F0FA;--sidebar-w:260px}
*,*::before,*::after{box-sizing:border-box}html,body{height:100%;margin:0}
body.sp-dash{font-family:'Inter',system-ui,-apple-system,sans-serif;color:var(--text);background:radial-gradient(circle at 100% 0%,rgba(232,176,90,.14),transparent 42%),radial-gradient(circle at 0% 100%,rgba(184,92,46,.08),transparent 42%),var(--cream-2);-webkit-font-smoothing:antialiased;line-height:1.5;min-height:100vh;display:flex}
.sp-sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-w);z-index:300;display:flex;flex-direction:column;background:radial-gradient(circle at 20% 10%,rgba(232,176,90,.2),transparent 55%),radial-gradient(circle at 80% 90%,rgba(184,92,46,.14),transparent 55%),linear-gradient(180deg,#4A2C10 0%,#2A1E10 100%);color:#fff;border-right:1px solid rgba(232,176,90,.18);overflow:hidden;transition:transform .3s cubic-bezier(.2,.7,.3,1)}
.sp-sidebar::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:repeating-linear-gradient(92deg,transparent 0px,transparent 22px,rgba(232,176,90,.04) 22px,rgba(232,176,90,.04) 24px),repeating-linear-gradient(88deg,transparent 0px,transparent 34px,rgba(232,176,90,.03) 34px,rgba(232,176,90,.03) 37px)}
.sp-sidebar>*{position:relative;z-index:1}
.sp-sidebar-brand{display:flex;align-items:center;justify-content:center;padding:26px 20px 22px;text-decoration:none;color:inherit;border-bottom:1px solid rgba(232,176,90,.15);flex-shrink:0;text-align:center}
.sp-sidebar-brand-name{font-family:'Fraunces',Georgia,serif;font-size:1.4rem;font-weight:700;letter-spacing:-.5px;margin:0;line-height:1;color:#fff}
.sp-sidebar-brand-name span{color:var(--gold-3)}
.sp-sidebar-user{padding:22px 22px 12px;border-bottom:1px solid rgba(232,176,90,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative}
.sp-sidebar-user::before{content:'';position:absolute;width:140px;height:140px;border-radius:50%;background:radial-gradient(circle,rgba(232,176,90,.28),transparent 65%);pointer-events:none}
.sp-user-avatar{position:relative;width:82px;height:82px;border-radius:50%;flex-shrink:0;padding:3px;background:linear-gradient(135deg,#F4C87A 0%,#C97628 55%,#8A5A30 100%);box-shadow:0 0 0 1px rgba(74,44,16,.35),0 10px 24px -8px rgba(0,0,0,.8),0 4px 10px -3px rgba(0,0,0,.5);transition:transform .3s ease}
.sp-user-avatar::after{content:'';position:absolute;inset:-6px;border-radius:50%;border:1px solid rgba(232,176,90,.35);animation:spAvatarPulse 3s ease-in-out infinite;pointer-events:none}
@keyframes spAvatarPulse{0%,100%{transform:scale(1);opacity:.6}50%{transform:scale(1.08);opacity:0}}
.sp-user-avatar-img{width:100%;height:100%;border-radius:50%;overflow:hidden;background:#FDFAF1;display:grid;place-items:center}
.sp-user-avatar-img img{width:100%;height:100%;object-fit:cover;display:block;border-radius:50%}

/* ✅ Seller role label — below the logo, matches buyer dashboard's style */
.sp-sidebar-role{display:flex;align-items:center;justify-content:center;padding:0 22px 18px;margin-top:-4px;border-bottom:1px solid rgba(232,176,90,.12);flex-shrink:0}
.sp-sidebar-role-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;background:rgba(232,176,90,.14);border:1px solid rgba(232,176,90,.32);color:var(--gold-3);font-size:.64rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;white-space:nowrap}
.sp-sidebar-role-chip i{font-size:.78rem}

.sp-nav{padding:16px 12px;display:flex;flex-direction:column;gap:3px;flex:1 1 auto;min-height:0;overflow-y:auto;scrollbar-width:thin;scrollbar-color:rgba(232,176,90,.35) transparent}
.sp-nav::-webkit-scrollbar{width:6px}.sp-nav::-webkit-scrollbar-thumb{background:linear-gradient(180deg,rgba(232,176,90,.45),rgba(176,100,30,.35));border-radius:4px}
.sp-nav-label{padding:12px 12px 6px;font-size:.64rem;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:rgba(255,255,255,.42)}
.sp-nav-link{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:10px;color:rgba(255,255,255,.82);text-decoration:none;font-size:.87rem;font-weight:500;transition:background .18s ease,color .18s ease;position:relative;z-index:2;flex-shrink:0;cursor:pointer}
.sp-nav-link i{font-size:1.05rem;width:20px;text-align:center;color:rgba(255,255,255,.58);transition:color .18s ease;pointer-events:none}
.sp-nav-link:hover{background:rgba(232,176,90,.12);color:#fff;text-decoration:none}
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
.sp-cta{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;font-weight:700;font-size:.85rem;text-decoration:none;border:0;cursor:pointer;font-family:inherit;box-shadow:0 3px 0 rgba(0,0,0,.12),0 12px 24px -12px rgba(176,100,30,.7);transition:transform .15s ease,filter .15s ease,box-shadow .15s ease}
.sp-cta:hover{color:#fff;transform:translateY(-1px);filter:brightness(1.04);box-shadow:0 5px 0 rgba(0,0,0,.12),0 16px 30px -12px rgba(176,100,30,.8)}
.sp-cta i{font-size:1rem}
.sp-content{padding:clamp(20px,3vw,38px);display:flex;flex-direction:column;gap:clamp(18px,2.4vh,26px)}
.sp-welcome{position:relative;overflow:hidden;padding:clamp(28px,3.6vh,42px) clamp(24px,3.4vw,44px);border-radius:24px;background:radial-gradient(circle at 82% 18%,rgba(232,176,90,.38),transparent 55%),radial-gradient(circle at 10% 90%,rgba(184,92,46,.22),transparent 50%),linear-gradient(135deg,#4A2C10 0%,#2A1E10 100%);color:#fff;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:32px;align-items:center;box-shadow:0 24px 60px -28px rgba(40,22,6,.7)}
.sp-welcome::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:repeating-linear-gradient(92deg,transparent 0px,transparent 22px,rgba(232,176,90,.06) 22px,rgba(232,176,90,.06) 24px),repeating-linear-gradient(88deg,transparent 0px,transparent 34px,rgba(232,176,90,.04) 34px,rgba(232,176,90,.04) 37px)}
.sp-welcome>*{position:relative;z-index:1}
.sp-welcome-tag{display:inline-flex;align-items:center;gap:7px;padding:6px 13px;border-radius:999px;background:rgba(232,176,90,.16);border:1px solid rgba(232,176,90,.35);font-size:.66rem;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:var(--gold-3);margin-bottom:16px}
.sp-welcome h2{font-family:'Fraunces',Georgia,serif;font-size:clamp(1.55rem,2.6vw,2.2rem);font-weight:600;line-height:1.15;letter-spacing:-.6px;margin:0 0 12px}
.sp-welcome h2 em{font-style:italic;font-weight:500;color:var(--gold-3)}
.sp-welcome p{font-size:.93rem;color:rgba(255,255,255,.78);line-height:1.65;margin:0;max-width:54ch}
.sp-welcome-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}
.sp-welcome-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:10px;font-size:.85rem;font-weight:700;text-decoration:none;border:0;cursor:pointer;font-family:inherit;transition:transform .15s ease,filter .15s ease}
.sp-welcome-btn.is-primary{background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;box-shadow:0 3px 0 rgba(0,0,0,.18),0 14px 26px -12px rgba(176,100,30,.85)}
.sp-welcome-btn.is-primary:hover{color:#fff;transform:translateY(-2px);filter:brightness(1.06)}
.sp-welcome-btn.is-ghost{background:rgba(255,255,255,.08);color:rgba(255,255,255,.92);border:1px solid rgba(255,255,255,.2)}
.sp-welcome-btn.is-ghost:hover{background:rgba(255,255,255,.16);color:#fff;transform:translateY(-2px)}
.sp-welcome-portrait{position:relative;width:160px;height:160px;flex-shrink:0;display:grid;place-items:center}
.sp-welcome-portrait-ring{position:absolute;inset:0;border-radius:50%;background:conic-gradient(from 220deg,rgba(232,176,90,.8),rgba(232,176,90,.1) 55%,rgba(232,176,90,.6));-webkit-mask:radial-gradient(circle,transparent 62%,#000 63%);mask:radial-gradient(circle,transparent 62%,#000 63%);animation:spRingSpin 18s linear infinite}
@keyframes spRingSpin{to{transform:rotate(360deg)}}
.sp-welcome-portrait-inner{width:124px;height:124px;border-radius:50%;overflow:hidden;background:#FDFAF1;box-shadow:0 0 0 4px rgba(74,44,16,.5),0 14px 34px -12px rgba(0,0,0,.8);display:grid;place-items:center}
.sp-welcome-portrait-inner img{width:100%;height:100%;object-fit:cover;display:block}
@media (max-width:860px){.sp-welcome{grid-template-columns:1fr}.sp-welcome-portrait{display:none}}
.sp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:clamp(14px,1.8vw,20px)}
.sp-stat{position:relative;padding:22px 24px 20px;border-radius:18px;background:#fff;border:1px solid var(--line);box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 14px 30px -22px rgba(74,44,16,.45);transition:transform .28s cubic-bezier(.2,.7,.3,1);overflow:hidden}
.sp-stat:hover{transform:translateY(-5px);border-color:rgba(176,100,30,.35)}
.sp-stat-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.sp-stat-ico{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font-size:1.22rem;background:var(--gold-soft);color:var(--gold);border:1px solid rgba(176,100,30,.18);transition:transform .35s cubic-bezier(.2,.7,.3,1)}
.sp-stat:hover .sp-stat-ico{transform:scale(1.1) rotate(-8deg)}
.sp-stat-ico.is-success{background:var(--success-bg);color:var(--success);border-color:var(--success-bd)}
.sp-stat-ico.is-info{background:var(--info-bg);color:var(--info);border-color:#C6DCF0}
.sp-stat-ico.is-warn{background:var(--warn-bg);color:var(--warn);border-color:var(--warn-bd)}
.sp-stat-trend{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:.68rem;font-weight:700}
.sp-stat-trend.is-up{background:var(--success-bg);color:var(--success)}
.sp-stat-trend.is-flat{background:var(--paper-2);color:var(--text-3)}
.sp-stat-label{font-size:.68rem;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);margin-bottom:8px}
.sp-stat-value{font-family:'Fraunces',Georgia,serif;font-size:clamp(1.6rem,2.6vw,2rem);font-weight:700;letter-spacing:-.6px;color:var(--brown);line-height:1.05}
.sp-stat-value small{font-size:.72rem;font-weight:600;color:var(--text-3);margin-left:4px}
.sp-stat-foot{display:flex;align-items:center;gap:6px;margin-top:12px;font-size:.74rem;font-weight:500;color:var(--text-3)}
.sp-stat-foot i{font-size:.85rem;color:var(--gold)}
.sp-stat-bar{margin-top:14px;height:5px;border-radius:5px;background:var(--paper-2);overflow:hidden}
.sp-stat-bar span{display:block;height:100%;border-radius:5px;background:linear-gradient(90deg,var(--gold),var(--gold-3))}
.sp-stat-bar.is-success span{background:linear-gradient(90deg,var(--success),#6FBF73)}
.sp-stat-bar.is-info span{background:linear-gradient(90deg,var(--info),#6A9CD9)}
.sp-stat-bar.is-warn span{background:linear-gradient(90deg,var(--warn),var(--gold-3))}
.sp-next-action{position:relative;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:18px 22px;border-radius:16px;background:linear-gradient(135deg,var(--gold-soft) 0%,#fff 100%);border:1px dashed rgba(176,100,30,.4);box-shadow:0 1px 0 rgba(255,255,255,.9) inset}
.sp-next-action::before{content:'';position:absolute;left:0;top:16px;bottom:16px;width:3px;border-radius:3px;background:linear-gradient(180deg,var(--gold),var(--gold-3))}
.sp-next-action-left{display:flex;align-items:center;gap:14px;min-width:0}
.sp-next-action-ico{width:44px;height:44px;border-radius:13px;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;display:grid;place-items:center;font-size:1.15rem;flex-shrink:0;box-shadow:0 8px 18px -8px rgba(176,100,30,.7)}
.sp-next-action-text{font-size:.88rem;color:var(--text-2);font-weight:500;line-height:1.5}
.sp-next-action-text strong{display:block;font-family:'Fraunces',Georgia,serif;font-size:1rem;font-weight:600;color:var(--brown);margin-bottom:2px}
.sp-next-action-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:9px;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;font-weight:700;font-size:.82rem;border:0;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap;box-shadow:0 3px 0 rgba(0,0,0,.14),0 12px 22px -12px rgba(176,100,30,.8);transition:transform .15s ease,filter .15s ease}
.sp-next-action-btn:hover{color:#fff;transform:translateY(-1px);filter:brightness(1.05)}
.sp-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:clamp(14px,1.8vw,22px);align-items:start}
@media (max-width:980px){.sp-grid{grid-template-columns:1fr}}
.sp-panel{position:relative;background:#fff;border:1px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 14px 30px -22px rgba(74,44,16,.4);transition:box-shadow .28s ease,border-color .28s ease}
.sp-panel:hover{border-color:rgba(176,100,30,.24)}
.sp-panel::before{content:'';position:absolute;top:0;left:0;width:56px;height:3px;background:linear-gradient(90deg,var(--gold),var(--gold-3));border-radius:0 3px 3px 0;opacity:.55;transition:opacity .25s ease,width .3s ease}
.sp-panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,#fff,var(--cream-2))}
.sp-panel-title{display:flex;align-items:center;gap:11px;font-family:'Fraunces',Georgia,serif;font-size:1.06rem;font-weight:600;color:var(--brown);margin:0}
.sp-panel-title i{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;background:var(--gold-soft);color:var(--gold);font-size:.95rem;border:1px solid rgba(176,100,30,.18)}
.sp-panel-link{font-size:.78rem;font-weight:600;color:var(--gold);text-decoration:none;display:inline-flex;align-items:center;gap:4px;background:none;border:0;cursor:pointer;font-family:inherit}
.sp-panel-link:hover{color:var(--gold-2)}
.sp-table-wrap{overflow-x:auto}
.sp-table{width:100%;border-collapse:collapse;font-size:.86rem}
.sp-table thead th{text-align:left;padding:12px 22px;font-size:.66rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3);border-bottom:1px solid var(--line);white-space:nowrap;background:var(--cream-2)}
.sp-table tbody td{padding:14px 22px;border-bottom:1px dashed var(--line-2);color:var(--text);vertical-align:middle;white-space:nowrap}
.sp-table tbody tr:last-child td{border-bottom:0}
.sp-table tbody tr:hover{background:var(--gold-soft)}
.sp-ref{font-family:'JetBrains Mono',monospace;font-size:.78rem;font-weight:600;color:var(--brown-2);background:var(--paper-2);border:1px solid var(--line-2);padding:3px 8px;border-radius:5px}
.sp-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:.68rem;font-weight:700;letter-spacing:.3px;text-transform:uppercase}
.sp-badge i{font-size:.78rem}
.sp-badge-paid{background:var(--success-bg);color:var(--success);border:1px solid var(--success-bd)}
.sp-badge-partial{background:var(--warn-bg);color:var(--warn);border:1px solid var(--warn-bd)}
.sp-badge-unpaid{background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-bd)}
.sp-badge-pending{background:var(--info-bg);color:var(--info);border:1px solid #C6DCF0}
.sp-empty{padding:48px 26px;text-align:center;color:var(--text-3)}
.sp-empty-ico{width:68px;height:68px;margin:0 auto 16px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,var(--gold-soft),#fff);color:var(--gold);font-size:1.7rem;border:1px solid rgba(176,100,30,.2)}
.sp-empty h4{font-family:'Fraunces',Georgia,serif;font-size:1.1rem;font-weight:600;color:var(--brown);margin:0 0 6px}
.sp-empty p{font-size:.85rem;margin:0 0 18px;max-width:38ch;margin-inline:auto;line-height:1.6}
.sp-pay-list{display:flex;flex-direction:column}
.sp-pay-item{display:flex;align-items:center;gap:12px;padding:14px 22px;border-bottom:1px dashed var(--line-2)}
.sp-pay-item:last-child{border-bottom:0}
.sp-pay-item:hover{background:var(--gold-soft)}
.sp-pay-ico{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;background:var(--success-bg);color:var(--success);font-size:1.05rem;border:1px solid var(--success-bd);flex-shrink:0}
.sp-pay-body{min-width:0;flex:1}
.sp-pay-ref{font-family:'JetBrains Mono',monospace;font-size:.74rem;font-weight:600;color:var(--brown-2);margin:0 0 2px}
.sp-pay-meta{font-size:.72rem;color:var(--text-3)}
.sp-pay-amount{font-family:'Fraunces',Georgia,serif;font-size:1rem;font-weight:700;color:var(--success);white-space:nowrap}
.sp-summary{padding:22px;display:flex;flex-direction:column;gap:12px}
.sp-summary-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 15px;border-radius:12px;background:var(--cream-2);border:1px solid var(--line);font-size:.86rem}
.sp-summary-row.is-total{background:linear-gradient(135deg,var(--gold-soft),#fff);border-color:rgba(176,100,30,.28)}
.sp-summary-label{display:inline-flex;align-items:center;gap:10px;color:var(--text-2);font-weight:600}
.sp-summary-label i{color:var(--gold);font-size:1rem}
.sp-summary-value{font-family:'Fraunces',Georgia,serif;font-size:1.05rem;font-weight:700;color:var(--brown)}
.sp-summary-row.is-total .sp-summary-value{font-size:1.2rem;color:var(--gold-2)}
.sp-summary-row.is-outstanding .sp-summary-value{color:var(--danger)}
.sp-quick{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px}
.sp-quick-card{position:relative;overflow:hidden;padding:20px 20px 18px;border-radius:16px;background:linear-gradient(180deg,#fff,var(--cream-2));border:1px solid var(--line);text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:12px;cursor:pointer;font-family:inherit;text-align:left;transition:transform .28s cubic-bezier(.2,.7,.3,1),border-color .28s ease,box-shadow .28s ease;box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 12px 26px -20px rgba(74,44,16,.38)}
.sp-quick-card:hover{transform:translateY(-5px);border-color:rgba(176,100,30,.4);box-shadow:0 24px 40px -22px rgba(74,44,16,.5);color:inherit}
.sp-quick-ico{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;background:linear-gradient(135deg,var(--gold-soft),#fff);color:var(--gold);font-size:1.2rem;border:1px solid rgba(176,100,30,.2);transition:transform .35s cubic-bezier(.2,.7,.3,1)}
.sp-quick-card:hover .sp-quick-ico{transform:scale(1.1) rotate(-8deg)}
.sp-quick-title{font-family:'Fraunces',Georgia,serif;font-size:1rem;font-weight:600;color:var(--brown);margin:0;line-height:1.2}
.sp-quick-desc{font-size:.78rem;color:var(--text-3);margin:0;line-height:1.5}
.sp-quick-arrow{position:absolute;top:20px;right:20px;color:var(--line-3);font-size:.95rem;transition:transform .25s ease,color .25s ease}
.sp-quick-card:hover .sp-quick-arrow{color:var(--gold);transform:translate(3px,-3px)}
.sp-timeline{padding:18px 22px 22px;position:relative}
.sp-timeline::before{content:'';position:absolute;left:32px;top:24px;bottom:28px;width:2px;background:linear-gradient(180deg,var(--line-2),transparent)}
.sp-tl-item{position:relative;padding:10px 0 16px 42px;display:flex;flex-direction:column;gap:3px}
.sp-tl-item:last-child{padding-bottom:0}
.sp-tl-dot{position:absolute;left:23px;top:14px;width:20px;height:20px;border-radius:50%;background:linear-gradient(135deg,var(--gold),var(--gold-2));border:3px solid #fff;box-shadow:0 0 0 1.5px var(--gold-3),0 4px 10px -4px rgba(176,100,30,.7)}
.sp-tl-dot.is-success{background:linear-gradient(135deg,var(--success),#6FBF73);box-shadow:0 0 0 1.5px var(--success-bd),0 4px 10px -4px rgba(46,125,50,.6)}
.sp-tl-dot.is-info{background:linear-gradient(135deg,var(--info),#6A9CD9);box-shadow:0 0 0 1.5px #C6DCF0,0 4px 10px -4px rgba(30,95,168,.6)}
.sp-tl-dot.is-warn{background:linear-gradient(135deg,var(--gold-2),var(--gold-3));box-shadow:0 0 0 1.5px var(--warn-bd),0 4px 10px -4px rgba(176,100,30,.6)}
.sp-tl-text{font-size:.86rem;color:var(--text);font-weight:500;line-height:1.5}
.sp-tl-text strong{color:var(--brown);font-weight:700}
.sp-tl-meta{font-size:.7rem;color:var(--text-3);font-weight:500;display:flex;align-items:center;gap:6px}
.sp-tl-meta i{font-size:.78rem;color:var(--gold)}
.sp-chart{padding:20px 22px 24px}
.sp-chart-head{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:18px}
.sp-chart-title{font-size:.72rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3)}
.sp-chart-total{font-family:'Fraunces',Georgia,serif;font-size:1.3rem;font-weight:700;color:var(--brown);letter-spacing:-.4px}
.sp-chart-total small{font-size:.68rem;font-weight:600;color:var(--success);margin-left:6px;display:inline-flex;align-items:center;gap:2px}
.sp-bars{display:flex;align-items:flex-end;gap:8px;height:100px;padding:0 2px}
.sp-bar-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:8px;height:100%;justify-content:flex-end}
.sp-bar{width:100%;border-radius:6px 6px 3px 3px;background:linear-gradient(180deg,var(--gold-3),var(--gold));box-shadow:0 3px 8px -4px rgba(176,100,30,.5);transition:transform .2s ease,filter .2s ease;transform-origin:bottom}
.sp-bar:hover{filter:brightness(1.1);transform:scaleY(1.06)}
.sp-bar.is-muted{background:linear-gradient(180deg,var(--line-3),var(--line-2));box-shadow:none}
.sp-bar-label{font-size:.64rem;color:var(--text-3);font-weight:600;letter-spacing:.4px}
.sp-footnote{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:16px 22px;border-radius:16px;background:linear-gradient(135deg,var(--gold-soft),#fff);border:1px solid rgba(176,100,30,.22);font-size:.82rem;color:var(--text-2)}
.sp-footnote-left{display:inline-flex;align-items:center;gap:12px;font-weight:500}
.sp-footnote-left i{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;display:grid;place-items:center;font-size:1rem}
.sp-footnote-left strong{color:var(--brown);font-weight:700}
.sp-footnote-right{display:inline-flex;align-items:center;gap:8px;color:var(--gold);font-weight:700;text-decoration:none;background:none;border:0;cursor:pointer;font-family:inherit;font-size:.82rem}
.sp-footnote-right:hover{color:var(--gold-2);gap:12px}

/* ============================================================
   MODALS
   ============================================================ */
.sp-modal-backdrop{position:fixed;inset:0;background:rgba(42,30,16,.6);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:400;display:none;align-items:center;justify-content:center;padding:20px;opacity:0;transition:opacity .25s ease;overflow:hidden}
.sp-modal-backdrop.is-open{display:flex;opacity:1}
.sp-modal{background:#fff;border-radius:22px;width:100%;max-width:580px;max-height:calc(100vh - 40px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 46px 100px -34px rgba(40,22,6,.8);transform:scale(.95) translateY(10px);transition:transform .3s cubic-bezier(.2,.7,.3,1);min-height:0}
.sp-modal.is-wide{max-width:740px}
.sp-modal-backdrop.is-open .sp-modal{transform:scale(1) translateY(0)}
.sp-modal-head{position:relative;padding:24px 26px 20px;background:radial-gradient(circle at 85% 15%,rgba(232,176,90,.35),transparent 55%),linear-gradient(135deg,#4A2C10 0%,#2A1E10 100%);color:#fff;flex-shrink:0;overflow:hidden}
.sp-modal-head::before{content:'';position:absolute;inset:0;background-image:repeating-linear-gradient(92deg,transparent 0px,transparent 22px,rgba(232,176,90,.06) 22px,rgba(232,176,90,.06) 24px),repeating-linear-gradient(88deg,transparent 0px,transparent 34px,rgba(232,176,90,.04) 34px,rgba(232,176,90,.04) 37px);pointer-events:none}
.sp-modal-head>*{position:relative;z-index:1}
.sp-modal-close{position:absolute;top:18px;right:18px;width:36px;height:36px;border-radius:10px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.1);color:#fff;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:1rem;transition:background .18s ease,transform .25s ease}
.sp-modal-close:hover{background:rgba(255,255,255,.22);transform:rotate(90deg)}
.sp-modal-tag{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:999px;background:rgba(232,176,90,.15);border:1px solid rgba(232,176,90,.3);font-size:.62rem;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--gold-3);margin-bottom:12px}
.sp-modal-title{font-family:'Fraunces',Georgia,serif;font-size:1.45rem;font-weight:600;letter-spacing:-.45px;margin:0 0 4px;line-height:1.2}
.sp-modal-sub{margin:0;font-size:.85rem;color:rgba(255,255,255,.72);line-height:1.55}
.sp-modal-body{padding:24px 26px;overflow-y:auto;flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:16px;overscroll-behavior:contain}
.sp-modal-foot{padding:16px 26px 22px;border-top:1px solid var(--line);background:var(--cream-2);display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-shrink:0;flex-wrap:wrap}
.sp-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:540px){.sp-form-row{grid-template-columns:1fr}}
.sp-form-field{display:flex;flex-direction:column;gap:6px}
.sp-form-field.is-full{grid-column:1/-1}
.sp-form-field label{font-size:.66rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--brown-2)}
.sp-form-field label .req{color:var(--danger);margin-left:2px}
.sp-form-input{display:flex;align-items:center;background:#fff;border:1.5px solid var(--line-2);border-radius:11px;transition:border-color .18s ease,box-shadow .18s ease;overflow:hidden;min-height:46px}
.sp-form-input:focus-within{border-color:var(--gold);box-shadow:0 0 0 4px rgba(176,100,30,.13)}
.sp-form-input i{padding:0 4px 0 14px;color:var(--text-3);font-size:.95rem;transition:color .18s ease}
.sp-form-input:focus-within i{color:var(--gold)}
.sp-form-input input,.sp-form-input select,.sp-form-input textarea{flex:1;border:0;outline:0;background:transparent;padding:11px 14px 11px 10px;font-size:.89rem;font-weight:500;color:var(--text);font-family:inherit;min-width:0;width:100%}
.sp-form-input textarea{resize:vertical;min-height:76px;padding-top:12px}
.sp-form-input input::placeholder,.sp-form-input textarea::placeholder{color:#A9A392;font-weight:400}
.sp-form-hint{font-size:.7rem;color:var(--text-3);font-weight:500;padding-left:2px}
.sp-form-summary{padding:14px 16px;border-radius:12px;background:linear-gradient(135deg,var(--gold-soft),#fff);border:1px dashed rgba(176,100,30,.35);display:flex;align-items:center;justify-content:space-between;font-size:.86rem}
.sp-form-summary-label{display:inline-flex;align-items:center;gap:8px;color:var(--text-2);font-weight:600}
.sp-form-summary-label i{color:var(--gold);font-size:1rem}
.sp-form-summary-value{font-family:'Fraunces',Georgia,serif;font-size:1.2rem;font-weight:700;color:var(--gold-2)}
.sp-btn-submit{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 22px;border-radius:11px;border:0;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;font-weight:700;font-size:.87rem;cursor:pointer;font-family:inherit;box-shadow:0 3px 0 rgba(0,0,0,.12),0 14px 24px -12px rgba(176,100,30,.75);transition:transform .15s ease,filter .15s ease}
.sp-btn-submit:hover{transform:translateY(-1px);filter:brightness(1.04)}
.sp-btn-submit:disabled{opacity:.6;cursor:not-allowed;transform:none}
.sp-btn-cancel{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:12px 20px;border-radius:11px;border:1.5px solid var(--line-2);background:#fff;color:var(--brown-2);font-weight:600;font-size:.87rem;cursor:pointer;font-family:inherit;transition:border-color .15s ease,color .15s ease}
.sp-btn-cancel:hover{border-color:var(--gold);color:var(--gold)}
.sp-report-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:540px){.sp-report-grid{grid-template-columns:1fr}}
.sp-report-card{padding:18px 20px;border-radius:14px;background:linear-gradient(180deg,#fff,var(--cream-2));border:1px solid var(--line);display:flex;align-items:center;gap:14px}
.sp-report-ico{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;background:var(--gold-soft);color:var(--gold);font-size:1.18rem;border:1px solid rgba(176,100,30,.18);flex-shrink:0}
.sp-report-ico.is-info{background:var(--info-bg);color:var(--info);border-color:#C6DCF0}
.sp-report-ico.is-success{background:var(--success-bg);color:var(--success);border-color:var(--success-bd)}
.sp-report-ico.is-warn{background:var(--warn-bg);color:var(--warn);border-color:var(--warn-bd)}
.sp-report-label{font-size:.66rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3);margin-bottom:3px}
.sp-report-value{font-family:'Fraunces',Georgia,serif;font-size:1.25rem;font-weight:700;color:var(--brown);letter-spacing:-.4px;line-height:1.15}
.sp-report-value small{font-size:.68rem;font-weight:600;color:var(--text-3);margin-left:3px}
.sp-report-chart{padding:20px 22px;border-radius:14px;border:1px solid var(--line);background:linear-gradient(180deg,#fff,var(--cream-2));margin-top:4px}
.sp-report-chart-title{font-size:.72rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3);margin-bottom:16px}
.sp-toast{position:fixed;bottom:26px;left:50%;transform:translate(-50%,120%);background:linear-gradient(135deg,#4A2C10,#2A1E10);color:#fff;padding:14px 22px;border-radius:14px;box-shadow:0 24px 46px -20px rgba(0,0,0,.75);display:inline-flex;align-items:center;gap:11px;font-size:.88rem;font-weight:600;z-index:500;transition:transform .38s cubic-bezier(.2,.7,.3,1);border:1px solid rgba(232,176,90,.3);max-width:calc(100vw - 40px)}
.sp-toast.is-visible{transform:translate(-50%,0)}
.sp-toast i{font-size:1.2rem;color:var(--gold-3)}
.sp-toast.is-success i{color:#7DD68A}
.sp-toast.is-error i{color:#FF9B7A}
.sp-sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(42,30,16,.5);z-index:250;opacity:0;pointer-events:none;transition:opacity .25s ease}
@media (max-width:900px){.sp-sidebar{transform:translateX(-100%)}body.sp-sidebar-open .sp-sidebar{transform:translateX(0)}body.sp-sidebar-open .sp-sidebar-overlay{display:block;opacity:1;pointer-events:auto}.sp-main{margin-left:0}.sp-menu-btn{display:inline-flex}}
@media (max-width:480px){.sp-topbar{padding:12px 16px}.sp-content{padding:16px}.sp-cta span{display:none}.sp-cta{padding:10px 12px}}
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
        <a href="<?= BASE_URL ?>seller/dashboard.php" class="sp-nav-link is-active" data-nav><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="<?= BASE_URL ?>seller/sales.php" class="sp-nav-link" data-nav><i class="bi bi-bag-check"></i> My Sales</a>
        <a href="<?= BASE_URL ?>seller/payments.php" class="sp-nav-link" data-nav><i class="bi bi-cash-coin"></i> Payments Received</a>
        <span class="sp-nav-label">Records</span>
        <a href="<?= BASE_URL ?>seller/buyers.php" class="sp-nav-link" data-nav><i class="bi bi-people"></i> Buyers</a>
        <a href="<?= BASE_URL ?>seller/reports.php" class="sp-nav-link" data-nav><i class="bi bi-graph-up-arrow"></i> Reports</a>
        <span class="sp-nav-label">Account</span>
        <a href="<?= BASE_URL ?>seller/profile.php" class="sp-nav-link" data-nav><i class="bi bi-person-circle"></i> My Profile</a>
        <a href="<?= BASE_URL ?>seller/settings.php" class="sp-nav-link" data-nav><i class="bi bi-gear"></i> Settings</a>
    </nav>
    <div class="sp-sidebar-foot">
        <a href="<?= BASE_URL ?>auth/logout.php" class="sp-nav-link sp-logout" data-nav><i class="bi bi-box-arrow-right"></i> Sign Out</a>
    </div>
</aside>

<div class="sp-main">
    <header class="sp-topbar">
        <div class="sp-topbar-left">
            <button class="sp-menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
            <div>
                <h1 class="sp-page-title">Seller Dashboard</h1>
                <span class="sp-page-sub">Welcome back, <strong><?= $firstName ?></strong>!</span>
            </div>
        </div>
        <div class="sp-topbar-right">
            <button class="sp-icon-btn" id="notifBtn"><i class="bi bi-bell"></i><span class="badge-dot"></span></button>
            <button type="button" class="sp-cta" data-modal="newSale">
                <i class="bi bi-plus-lg"></i>
                <span>New Sale</span>
            </button>
        </div>
    </header>

    <main class="sp-content">

        <section class="sp-welcome">
            <div>
                <span class="sp-welcome-tag"><i class="bi bi-flower1"></i> Seller Dashboard</span>
                <h2>Good day, <em><?= $firstName ?></em>. Ready to record today's delivery?</h2>
                <p>Your sales ledger, payments received, and buyer activity — all organized in one warm, focused space.</p>
                <div class="sp-welcome-actions">
                    <button type="button" class="sp-welcome-btn is-primary" data-modal="newSale">
                        <i class="bi bi-plus-lg"></i> Record Sale
                    </button>
                    <button type="button" class="sp-welcome-btn is-ghost" data-modal="reports">
                        <i class="bi bi-graph-up-arrow"></i> View Reports
                    </button>
                </div>
            </div>
            <?php if ($smartPalayLogo): ?>
            <div class="sp-welcome-portrait" aria-hidden="true">
                <span class="sp-welcome-portrait-ring"></span>
                <div class="sp-welcome-portrait-inner"><img src="<?= $smartPalayLogo ?>" alt="SmartPalay"></div>
            </div>
            <?php endif; ?>
        </section>

        <section class="sp-stats">
            <article class="sp-stat">
                <div class="sp-stat-head"><div class="sp-stat-ico"><i class="bi bi-bag-check"></i></div><span class="sp-stat-trend is-up"><i class="bi bi-arrow-up-right"></i> 12%</span></div>
                <div class="sp-stat-label">Total Sales</div>
                <div class="sp-stat-value"><?= number_format($stats['total_sales']) ?></div>
                <div class="sp-stat-foot"><i class="bi bi-clock-history"></i> All time</div>
                <div class="sp-stat-bar"><span style="width:<?= min(100,max(6,$stats['total_sales']*10)) ?>%"></span></div>
            </article>
            <article class="sp-stat">
                <div class="sp-stat-head"><div class="sp-stat-ico is-info"><i class="bi bi-box-seam"></i></div><span class="sp-stat-trend is-up"><i class="bi bi-arrow-up-right"></i> 8%</span></div>
                <div class="sp-stat-label">Palay Delivered</div>
                <div class="sp-stat-value"><?= number_format($stats['total_kg'],0) ?><small>kg</small></div>
                <div class="sp-stat-foot"><i class="bi bi-graph-up"></i> Total weight</div>
                <div class="sp-stat-bar is-info"><span style="width:<?= min(100,max(8,$stats['total_kg']/50)) ?>%"></span></div>
            </article>
            <article class="sp-stat">
                <div class="sp-stat-head"><div class="sp-stat-ico is-success"><i class="bi bi-cash-stack"></i></div><span class="sp-stat-trend is-up"><i class="bi bi-check2-circle"></i> Earned</span></div>
                <div class="sp-stat-label">Total Earned</div>
                <div class="sp-stat-value"><?= peso($stats['total_earned']) ?></div>
                <div class="sp-stat-foot"><i class="bi bi-check-circle"></i> Received amount</div>
                <div class="sp-stat-bar is-success"><span style="width:<?= min(100,$paidPct) ?>%"></span></div>
            </article>
            <article class="sp-stat">
                <div class="sp-stat-head"><div class="sp-stat-ico is-warn"><i class="bi bi-hourglass-split"></i></div><span class="sp-stat-trend is-flat"><i class="bi bi-dot"></i> Pending</span></div>
                <div class="sp-stat-label">Outstanding</div>
                <div class="sp-stat-value"><?= peso($stats['outstanding']) ?></div>
                <div class="sp-stat-foot"><i class="bi bi-exclamation-circle"></i> Awaiting payment</div>
                <div class="sp-stat-bar is-warn"><span style="width:<?= min(100,$totalValue>0?100-$paidPct:0) ?>%"></span></div>
            </article>
        </section>

        <?php if ($stats['outstanding'] > 0): ?>
        <div class="sp-next-action">
            <div class="sp-next-action-left">
                <div class="sp-next-action-ico"><i class="bi bi-lightning-charge-fill"></i></div>
                <div class="sp-next-action-text">
                    <strong>You have <?= peso($stats['outstanding']) ?> awaiting payment</strong>
                    Follow up with your buyers to settle outstanding balances.
                </div>
            </div>
            <a href="<?= BASE_URL ?>seller/payments.php" class="sp-next-action-btn" data-nav>
                <i class="bi bi-cash-coin"></i> View payments
            </a>
        </div>
        <?php elseif ($stats['total_sales'] === 0): ?>
        <div class="sp-next-action">
            <div class="sp-next-action-left">
                <div class="sp-next-action-ico"><i class="bi bi-rocket-takeoff"></i></div>
                <div class="sp-next-action-text">
                    <strong>Start recording your sales</strong>
                    Log your first palay delivery — takes less than a minute.
                </div>
            </div>
            <button type="button" class="sp-next-action-btn" data-modal="newSale">
                <i class="bi bi-plus-lg"></i> Record now
            </button>
        </div>
        <?php endif; ?>

        <section class="sp-quick">
            <button type="button" class="sp-quick-card" data-modal="newSale">
                <div class="sp-quick-ico"><i class="bi bi-plus-circle"></i></div>
                <h4 class="sp-quick-title">Record a Sale</h4>
                <p class="sp-quick-desc">Log a new palay delivery to a buyer.</p>
                <i class="bi bi-arrow-up-right sp-quick-arrow"></i>
            </button>
            <a href="<?= BASE_URL ?>seller/payments.php" class="sp-quick-card" data-nav>
                <div class="sp-quick-ico"><i class="bi bi-cash-coin"></i></div>
                <h4 class="sp-quick-title">Payments Received</h4>
                <p class="sp-quick-desc">Track payments you've received from buyers.</p>
                <i class="bi bi-arrow-up-right sp-quick-arrow"></i>
            </a>
            <a href="<?= BASE_URL ?>seller/buyers.php" class="sp-quick-card" data-nav>
                <div class="sp-quick-ico"><i class="bi bi-people"></i></div>
                <h4 class="sp-quick-title">Browse Buyers</h4>
                <p class="sp-quick-desc">View your trusted palay buyers and contacts.</p>
                <i class="bi bi-arrow-up-right sp-quick-arrow"></i>
            </a>
            <button type="button" class="sp-quick-card" data-modal="reports">
                <div class="sp-quick-ico"><i class="bi bi-file-earmark-text"></i></div>
                <h4 class="sp-quick-title">Generate Report</h4>
                <p class="sp-quick-desc">Export your sales history for a date range.</p>
                <i class="bi bi-arrow-up-right sp-quick-arrow"></i>
            </button>
        </section>

        <section class="sp-grid">
            <div class="sp-panel">
                <div class="sp-panel-head">
                    <h3 class="sp-panel-title"><i class="bi bi-clock-history"></i> Recent Sales</h3>
                    <a href="<?= BASE_URL ?>seller/sales.php" class="sp-panel-link" data-nav>View all <i class="bi bi-arrow-right"></i></a>
                </div>
                <?php if (!empty($recentSales)): ?>
                    <div class="sp-table-wrap">
                        <table class="sp-table">
                            <thead><tr><th>Reference</th><th>Buyer</th><th>Weight</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                                <?php foreach ($recentSales as $s):
                                    $st=strtolower($s['status']??'pending'); $b='sp-badge-pending'; $ic='bi-hourglass-split'; $lb=ucfirst($st);
                                    if($st==='paid'){$b='sp-badge-paid';$ic='bi-check-circle-fill';$lb='Paid';}
                                    if($st==='partial'){$b='sp-badge-partial';$ic='bi-circle-half';$lb='Partial';}
                                    if($st==='unpaid'){$b='sp-badge-unpaid';$ic='bi-x-circle-fill';$lb='Unpaid';}
                                ?>
                                <tr>
                                    <td><span class="sp-ref"><?= sanitize($s['reference_no']??'—') ?></span></td>
                                    <td><?= sanitize($s['buyer_name']??'—') ?></td>
                                    <td><?= kg($s['weight_kg']??0) ?></td>
                                    <td><strong><?= peso($s['total_amount']??0) ?></strong></td>
                                    <td><span class="sp-badge <?= $b ?>"><i class="bi <?= $ic ?>"></i> <?= $lb ?></span></td>
                                    <td><?= niceDate($s['created_at']??null) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="sp-empty">
                        <div class="sp-empty-ico"><i class="bi bi-bag"></i></div>
                        <h4>No sales yet</h4>
                        <p>Your recent palay sales will show up here.</p>
                        <button type="button" class="sp-next-action-btn" data-modal="newSale"><i class="bi bi-plus-lg"></i> Record First Sale</button>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display:flex;flex-direction:column;gap:clamp(14px,1.8vw,22px)">
                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title"><i class="bi bi-bar-chart-line"></i> Weekly Deliveries</h3>
                        <button type="button" class="sp-panel-link" data-modal="reports">Details <i class="bi bi-arrow-right"></i></button>
                    </div>
                    <div class="sp-chart">
                        <div class="sp-chart-head"><span class="sp-chart-title">Last 7 days</span><span class="sp-chart-total"><?= number_format($stats['total_kg'],0) ?> kg <small><i class="bi bi-arrow-up-short"></i>+8%</small></span></div>
                        <div class="sp-bars">
                            <?php $h=[42,68,55,82,74,90,60]; $d=['S','M','T','W','T','F','S']; foreach($h as $i=>$v): ?>
                                <div class="sp-bar-col"><div class="sp-bar <?= $i===5?'':'is-muted' ?>" style="height:<?= $v ?>%"></div><span class="sp-bar-label"><?= $d[$i] ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="sp-panel">
                    <div class="sp-panel-head"><h3 class="sp-panel-title"><i class="bi bi-wallet2"></i> Balance Summary</h3></div>
                    <div class="sp-summary">
                        <div class="sp-summary-row"><span class="sp-summary-label"><i class="bi bi-box-seam"></i> Total Palay</span><span class="sp-summary-value"><?= number_format($stats['total_kg'],0) ?> kg</span></div>
                        <div class="sp-summary-row"><span class="sp-summary-label"><i class="bi bi-check-circle"></i> Total Earned</span><span class="sp-summary-value"><?= peso($stats['total_earned']) ?></span></div>
                        <div class="sp-summary-row is-outstanding"><span class="sp-summary-label"><i class="bi bi-hourglass-split"></i> Outstanding</span><span class="sp-summary-value"><?= peso($stats['outstanding']) ?></span></div>
                        <div class="sp-summary-row is-total"><span class="sp-summary-label"><i class="bi bi-calculator"></i> Grand Total</span><span class="sp-summary-value"><?= peso($totalValue) ?></span></div>
                    </div>
                </div>

                <div class="sp-panel">
                    <div class="sp-panel-head"><h3 class="sp-panel-title"><i class="bi bi-activity"></i> Recent Activity</h3></div>
                    <div class="sp-timeline">
                        <?php if (!empty($recentPayments)): foreach (array_slice($recentPayments,0,3) as $pay): ?>
                            <div class="sp-tl-item"><span class="sp-tl-dot is-success"></span>
                                <div class="sp-tl-text">Payment received of <strong><?= peso($pay['amount']??0) ?></strong> via <?= sanitize(ucfirst($pay['method']??'Cash')) ?></div>
                                <div class="sp-tl-meta"><i class="bi bi-clock"></i><?= niceDate($pay['paid_at']??null) ?></div>
                            </div>
                        <?php endforeach; endif; ?>
                        <?php if (!empty($recentSales)): ?>
                            <div class="sp-tl-item"><span class="sp-tl-dot is-warn"></span>
                                <div class="sp-tl-text">New sale to <strong><?= sanitize($recentSales[0]['buyer_name']??'—') ?></strong></div>
                                <div class="sp-tl-meta"><i class="bi bi-box-seam"></i><?= kg($recentSales[0]['weight_kg']??0) ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="sp-tl-item"><span class="sp-tl-dot is-info"></span>
                            <div class="sp-tl-text">Welcome to <strong>SmartPalay</strong> Seller Dashboard</div>
                            <div class="sp-tl-meta"><i class="bi bi-stars"></i>Today</div>
                        </div>
                    </div>
                </div>

                <div class="sp-panel">
                    <div class="sp-panel-head">
                        <h3 class="sp-panel-title"><i class="bi bi-cash-coin"></i> Recent Payments</h3>
                        <a href="<?= BASE_URL ?>seller/payments.php" class="sp-panel-link" data-nav>View all <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <?php if (!empty($recentPayments)): ?>
                        <div class="sp-pay-list">
                            <?php foreach ($recentPayments as $pay): ?>
                                <div class="sp-pay-item">
                                    <div class="sp-pay-ico"><i class="bi bi-check-lg"></i></div>
                                    <div class="sp-pay-body">
                                        <p class="sp-pay-ref"><?= sanitize($pay['reference_no']??'—') ?></p>
                                        <span class="sp-pay-meta"><?= sanitize(ucfirst($pay['method']??'Cash')) ?> · <?= niceDate($pay['paid_at']??null) ?></span>
                                    </div>
                                    <div class="sp-pay-amount"><?= peso($pay['amount']??0) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="sp-empty" style="padding:32px 24px">
                            <div class="sp-empty-ico" style="width:54px;height:54px;font-size:1.35rem"><i class="bi bi-receipt"></i></div>
                            <h4>No payments yet</h4>
                            <p style="margin-bottom:0">Payments you receive will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <div class="sp-footnote">
            <span class="sp-footnote-left"><i class="bi bi-lightbulb"></i><span><strong>Pro tip:</strong> Keep your buyer contacts updated to record sales faster.</span></span>
            <a href="<?= BASE_URL ?>seller/buyers.php" class="sp-footnote-right" data-nav>Manage buyers <i class="bi bi-arrow-right"></i></a>
        </div>

    </main>
</div>

<!-- ============================================================
     NEW SALE MODAL
     ============================================================ -->
<div class="sp-modal-backdrop" id="modalNewSale">
    <div class="sp-modal">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal><i class="bi bi-x-lg"></i></button>
            <span class="sp-modal-tag"><i class="bi bi-plus-circle"></i> New Sale</span>
            <h3 class="sp-modal-title">Record a Palay Sale</h3>
            <p class="sp-modal-sub">Fill in the delivery details and we'll calculate the total automatically.</p>
        </div>

        <form id="newSaleForm" autocomplete="off" style="display:contents">
            <div class="sp-modal-body">
                <div class="sp-form-row">
                    <div class="sp-form-field is-full">
                        <label for="nsBuyer">Buyer <span class="req">*</span></label>
                        <div class="sp-form-input">
                            <i class="bi bi-person"></i>
                            <input type="text" id="nsBuyer" name="buyer_name" list="buyersDatalist" placeholder="e.g. Dula Rice Trading" required>
                        </div>
                        <datalist id="buyersDatalist">
                            <?php foreach ($buyersList as $b): ?><option value="<?= sanitize($b) ?>"></option><?php endforeach; ?>
                        </datalist>
                        <div class="sp-form-hint">Type a new name or pick from your existing buyers.</div>
                    </div>

                    <div class="sp-form-field">
                        <label for="nsWeight">Weight <span class="req">*</span></label>
                        <div class="sp-form-input"><i class="bi bi-box-seam"></i><input type="number" id="nsWeight" name="weight_kg" min="0.01" step="0.01" placeholder="0.00" required></div>
                        <div class="sp-form-hint">In kilograms (kg)</div>
                    </div>

                    <div class="sp-form-field">
                        <label for="nsPrice">Price per kg <span class="req">*</span></label>
                        <div class="sp-form-input"><i class="bi bi-tag"></i><input type="number" id="nsPrice" name="price_per_kg" min="0.01" step="0.01" placeholder="0.00" required></div>
                        <div class="sp-form-hint">In pesos (₱)</div>
                    </div>

                    <div class="sp-form-field">
                        <label for="nsPaid">Amount Received (optional)</label>
                        <div class="sp-form-input"><i class="bi bi-cash-stack"></i><input type="number" id="nsPaid" name="amount_paid" min="0" step="0.01" placeholder="0.00"></div>
                        <div class="sp-form-hint">Leave blank if unpaid</div>
                    </div>

                    <div class="sp-form-field">
                        <label for="nsDate">Delivery Date</label>
                        <div class="sp-form-input"><i class="bi bi-calendar3"></i><input type="date" id="nsDate" name="created_at"></div>
                    </div>

                    <div class="sp-form-field is-full">
                        <label for="nsNotes">Notes (optional)</label>
                        <div class="sp-form-input"><i class="bi bi-chat-left-text"></i><textarea id="nsNotes" name="notes" placeholder="e.g. Dry palay, delivered to warehouse 2…"></textarea></div>
                    </div>
                </div>

                <div class="sp-form-summary">
                    <span class="sp-form-summary-label"><i class="bi bi-calculator"></i> Total Amount</span>
                    <span class="sp-form-summary-value" id="nsTotal">₱0.00</span>
                </div>
                <div class="sp-form-summary" style="background:linear-gradient(135deg,#EAF7EC,#fff);border-color:var(--success-bd)">
                    <span class="sp-form-summary-label"><i class="bi bi-hourglass-split"></i> Balance (remaining)</span>
                    <span class="sp-form-summary-value" id="nsBalance" style="color:var(--danger)">₱0.00</span>
                </div>
            </div>

            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-cancel" data-close-modal><i class="bi bi-x-lg"></i> Cancel</button>
                <button type="submit" class="sp-btn-submit" id="nsSubmit"><i class="bi bi-check-lg"></i> Save Sale</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     REPORTS MODAL
     ============================================================ -->
<div class="sp-modal-backdrop" id="modalReports">
    <div class="sp-modal is-wide">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal><i class="bi bi-x-lg"></i></button>
            <span class="sp-modal-tag"><i class="bi bi-graph-up-arrow"></i> Reports</span>
            <h3 class="sp-modal-title">Sales Report</h3>
            <p class="sp-modal-sub">A snapshot of your palay activity — totals, balances, and trends at a glance.</p>
        </div>

        <div class="sp-modal-body">
            <div class="sp-report-grid">
                <div class="sp-report-card">
                    <div class="sp-report-ico"><i class="bi bi-bag-check"></i></div>
                    <div><div class="sp-report-label">Total Sales</div><div class="sp-report-value"><?= number_format($stats['total_sales']) ?></div></div>
                </div>
                <div class="sp-report-card">
                    <div class="sp-report-ico is-info"><i class="bi bi-box-seam"></i></div>
                    <div><div class="sp-report-label">Palay Delivered</div><div class="sp-report-value"><?= number_format($stats['total_kg'],0) ?><small>kg</small></div></div>
                </div>
                <div class="sp-report-card">
                    <div class="sp-report-ico is-success"><i class="bi bi-cash-stack"></i></div>
                    <div><div class="sp-report-label">Total Earned</div><div class="sp-report-value"><?= peso($stats['total_earned']) ?></div></div>
                </div>
                <div class="sp-report-card">
                    <div class="sp-report-ico is-warn"><i class="bi bi-hourglass-split"></i></div>
                    <div><div class="sp-report-label">Outstanding</div><div class="sp-report-value"><?= peso($stats['outstanding']) ?></div></div>
                </div>
            </div>

            <div class="sp-report-chart">
                <div class="sp-report-chart-title">Weekly Palay Volume (kg)</div>
                <div class="sp-bars">
                    <?php $h=[42,68,55,82,74,90,60]; $d=['S','M','T','W','T','F','S']; foreach($h as $i=>$v): ?>
                        <div class="sp-bar-col"><div class="sp-bar <?= $i===5?'':'is-muted' ?>" style="height:<?= $v ?>%"></div><span class="sp-bar-label"><?= $d[$i] ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sp-form-summary">
                <span class="sp-form-summary-label"><i class="bi bi-calculator"></i> Grand Total (Earned + Outstanding)</span>
                <span class="sp-form-summary-value"><?= peso($totalValue) ?></span>
            </div>
        </div>

        <div class="sp-modal-foot">
            <button type="button" class="sp-btn-cancel" data-close-modal><i class="bi bi-x-lg"></i> Close</button>
            <button type="button" class="sp-btn-submit" id="dlCsv"><i class="bi bi-download"></i> Download CSV</button>
        </div>
    </div>
</div>

<div class="sp-toast" id="spToast"><i class="bi bi-check-circle-fill" id="spToastIcon"></i><span id="spToastText">Ready</span></div>

<script>
(() => {
    'use strict';
    const body    = document.body;
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
    function showToast(msg, type='success') {
        toastText.textContent = msg;
        toast.classList.remove('is-success','is-error');
        toastIcon.className = 'bi ' + (type==='error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
        toast.classList.add('is-'+type, 'is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 3200);
    }

    /* Modal helpers */
    const modals = {
        newSale: document.getElementById('modalNewSale'),
        reports: document.getElementById('modalReports')
    };
    function openModal(m) {
        if (!m) return;
        m.classList.add('is-open');
        body.style.overflow = 'hidden';
        setTimeout(() => m.querySelector('input, select, textarea')?.focus(), 120);
    }
    function closeModal(m) {
        if (!m) return;
        m.classList.remove('is-open');
        if (!document.querySelector('.sp-modal-backdrop.is-open')) body.style.overflow = '';
    }

    /* Wire "New Sale", "Record Sale", "View Reports" — all open modals */
    document.querySelectorAll('[data-modal]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const name = btn.dataset.modal;
            if (name === 'newSale') resetNewSaleForm();
            openModal(modals[name]);
        });
    });
    document.querySelectorAll('[data-close-modal]').forEach(b => b.addEventListener('click', () => closeModal(b.closest('.sp-modal-backdrop'))));
    Object.values(modals).forEach(m => m?.addEventListener('click', e => { if (e.target === m) closeModal(m); }));
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            const open = [...document.querySelectorAll('.sp-modal-backdrop.is-open')].pop();
            if (open) closeModal(open);
        }
    });

    /* Hardened nav (real page links) */
    document.querySelectorAll('[data-nav]').forEach(link => {
        link.addEventListener('click', (e) => {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
            const href = link.getAttribute('href');
            if (!href || href === '#' || href === '') { e.preventDefault(); showToast('Not available yet.','error'); return; }
            e.preventDefault();
            window.location.href = href;
        });
    });

    /* ---------- New Sale form logic ---------- */
    const nsForm    = document.getElementById('newSaleForm');
    const nsWeight  = document.getElementById('nsWeight');
    const nsPrice   = document.getElementById('nsPrice');
    const nsPaid    = document.getElementById('nsPaid');
    const nsTotal   = document.getElementById('nsTotal');
    const nsBalance = document.getElementById('nsBalance');
    const nsSubmit  = document.getElementById('nsSubmit');

    function pesoFmt(n) { n = Number(n)||0; return '₱' + n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function recalc() {
        const w = parseFloat(nsWeight?.value)||0;
        const p = parseFloat(nsPrice?.value)||0;
        const paid = parseFloat(nsPaid?.value)||0;
        const total = w * p;
        const balance = Math.max(0, total - paid);
        nsTotal.textContent = pesoFmt(total);
        nsBalance.textContent = pesoFmt(balance);
        nsBalance.style.color = balance <= 0 ? 'var(--success)' : 'var(--danger)';
    }
    nsWeight?.addEventListener('input', recalc);
    nsPrice?.addEventListener('input', recalc);
    nsPaid?.addEventListener('input', recalc);

    function resetNewSaleForm() {
        nsForm?.reset();
        // Delivery Date is intentionally left blank on reset.
        recalc();
        modals.newSale.querySelector('.sp-modal-body').scrollTop = 0;
    }

    nsForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
            buyer_name:   document.getElementById('nsBuyer').value.trim(),
            weight_kg:    parseFloat(nsWeight.value)||0,
            price_per_kg: parseFloat(nsPrice.value)||0,
            amount_paid:  parseFloat(nsPaid.value)||0,
            created_at:   document.getElementById('nsDate').value || '',
            notes:        document.getElementById('nsNotes').value || ''
        };
        if (!payload.buyer_name) { showToast('Please enter the buyer name.','error'); return; }
        if (payload.weight_kg <= 0) { showToast('Please enter a valid weight.','error'); return; }
        if (payload.price_per_kg <= 0) { showToast('Please enter a valid price per kg.','error'); return; }

        nsSubmit.disabled = true;
        const orig = nsSubmit.innerHTML;
        nsSubmit.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…';
        try {
            const res = await fetch('<?= BASE_URL ?>seller/save_sale.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.success) {
                showToast('Sale recorded successfully!', 'success');
                closeModal(modals.newSale);
                setTimeout(() => window.location.reload(), 700);
            } else {
                showToast(data.message || 'Could not save the sale.', 'error');
            }
        } catch (err) {
            showToast('Network error. Please try again.', 'error');
        } finally {
            nsSubmit.disabled = false;
            nsSubmit.innerHTML = orig;
        }
    });

    /* ---------- Report CSV download ---------- */
    document.getElementById('dlCsv')?.addEventListener('click', () => {
        const rows = [
            ['Metric','Value'],
            ['Total Sales','<?= (int)$stats['total_sales'] ?>'],
            ['Palay Delivered (kg)','<?= number_format($stats['total_kg'],2,'.','') ?>'],
            ['Total Earned (PHP)','<?= number_format($stats['total_earned'],2,'.','') ?>'],
            ['Outstanding (PHP)','<?= number_format($stats['outstanding'],2,'.','') ?>'],
            ['Grand Total (PHP)','<?= number_format($totalValue,2,'.','') ?>']
        ];
        const csv = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
        const blob = new Blob([csv], { type:'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'smartpalay-seller-report-' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(url);
        showToast('Report downloaded.', 'success');
    });

    document.getElementById('notifBtn')?.addEventListener('click', () => showToast('No new notifications right now.', 'success'));
})();
</script>
</body>
</html>