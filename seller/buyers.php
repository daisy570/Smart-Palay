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

$userId   = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'SmartPalay Seller';

/* --------------------------------------------------------------
   Filters
-------------------------------------------------------------- */
$search   = trim((string) ($_GET['q'] ?? ''));
$sortKey  = (string) ($_GET['sort'] ?? 'total_amount');
$sortDir  = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$allowedSorts = [
    'buyer_name'   => 'buyer_name',
    'total_amount' => 'total_amount',
    'sales_count'  => 'sales_count',
    'total_kg'     => 'total_kg',
    'outstanding'  => 'outstanding',
    'last_sale'    => 'last_sale',
];
if (!isset($allowedSorts[$sortKey])) $sortKey = 'total_amount';
$orderBy = $allowedSorts[$sortKey] . ' ' . $sortDir;

$where  = ["seller_id = ?", "buyer_name IS NOT NULL", "buyer_name <> ''"];
$params = [$userId];

if ($search !== '') {
    $where[]  = 'buyer_name LIKE ?';
    $params[] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

/* --------------------------------------------------------------
   Buyers aggregated list
-------------------------------------------------------------- */
$buyers = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            buyer_name                         AS name,
            COUNT(*)                           AS sales_count,
            COALESCE(SUM(weight_kg), 0)        AS total_kg,
            COALESCE(SUM(total_amount), 0)     AS total_amount,
            COALESCE(SUM(amount_paid), 0)      AS total_paid,
            COALESCE(SUM(balance), 0)          AS outstanding,
            MAX(created_at)                    AS last_sale,
            MIN(created_at)                    AS first_sale
        FROM purchases
        WHERE {$whereSql}
        GROUP BY buyer_name
        ORDER BY {$orderBy}
    ");
    $stmt->execute($params);
    $buyers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $buyers = [];
}

/* Overall summary */
$summary = ['buyers'=>0,'total_kg'=>0,'total_amount'=>0,'total_paid'=>0,'outstanding'=>0];
foreach ($buyers as $b) {
    $summary['buyers']++;
    $summary['total_kg']     += (float) $b['total_kg'];
    $summary['total_amount'] += (float) $b['total_amount'];
    $summary['total_paid']   += (float) $b['total_paid'];
    $summary['outstanding']  += (float) $b['outstanding'];
}

/* Top 3 buyers by amount */
$topThree = array_slice($buyers, 0, 3);

/* --------------------------------------------------------------
   Helpers
-------------------------------------------------------------- */
function peso($n) { return '₱' . number_format((float) $n, 2); }
function kg($n)   { return number_format((float) $n, 2) . ' kg'; }
function niceDate($s) { if (!$s) return '—'; $t = strtotime($s); return $t ? date('M j, Y', $t) : '—'; }
function initial($s) { return strtoupper(mb_substr(trim((string) $s), 0, 1)); }

function qs(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) unset($params[$k]);
    }
    return http_build_query($params);
}

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
<title>My Buyers | SmartPalay</title>
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

/* Toolbar */
.sp-toolbar{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 14px 30px -22px rgba(74,44,16,.4);padding:16px 18px;display:flex;flex-direction:column;gap:14px}
.sp-filter-row{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr) auto auto;gap:10px;align-items:stretch}
@media (max-width:720px){.sp-filter-row{grid-template-columns:1fr 1fr}}
@media (max-width:520px){.sp-filter-row{grid-template-columns:1fr}}
.sp-field{display:flex;align-items:center;background:#fff;border:1.5px solid var(--line-2);border-radius:11px;transition:border-color .18s ease,box-shadow .18s ease;overflow:hidden;min-height:44px}
.sp-field:hover{border-color:var(--line-3)}
.sp-field:focus-within{border-color:var(--gold);box-shadow:0 0 0 4px rgba(176,100,30,.12)}
.sp-field i{padding:0 4px 0 13px;color:var(--text-3);font-size:.95rem;transition:color .18s ease}
.sp-field:focus-within i{color:var(--gold)}
.sp-field input,.sp-field select{flex:1;border:0;outline:0;background:transparent;padding:11px 14px 11px 9px;font-size:.87rem;font-weight:500;color:var(--text);font-family:inherit;min-width:0;width:100%}
.sp-field.is-select{position:relative}
.sp-field.is-select::after{content:'';width:7px;height:7px;border-right:2px solid var(--text-3);border-bottom:2px solid var(--text-3);transform:rotate(45deg) translate(-6px,-3px);pointer-events:none;margin-right:14px}
.sp-field.is-select select{appearance:none;-webkit-appearance:none;cursor:pointer}
.sp-btn-primary,.sp-btn-ghost{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:11px;font-size:.85rem;font-weight:700;font-family:inherit;cursor:pointer;white-space:nowrap;transition:transform .15s ease,filter .15s ease,border-color .18s ease,color .18s ease;min-height:44px}
.sp-btn-primary{border:0;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;box-shadow:0 3px 0 rgba(0,0,0,.12),0 14px 24px -12px rgba(176,100,30,.75)}
.sp-btn-primary:hover{transform:translateY(-1px);filter:brightness(1.04)}
.sp-btn-ghost{border:1.5px solid var(--line-2);background:#fff;color:var(--brown-2);text-decoration:none}
.sp-btn-ghost:hover{border-color:var(--gold);color:var(--gold);transform:translateY(-1px)}

/* Summary strip */
.sp-summary-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.sp-summary-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 10px 22px -18px rgba(74,44,16,.4);transition:transform .22s ease,border-color .22s ease}
.sp-summary-card:hover{transform:translateY(-3px);border-color:rgba(176,100,30,.3)}
.sp-summary-ico{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;background:var(--gold-soft);color:var(--gold);font-size:1.05rem;border:1px solid rgba(176,100,30,.18);flex-shrink:0}
.sp-summary-ico.is-info{background:var(--info-bg);color:var(--info);border-color:#C6DCF0}
.sp-summary-ico.is-success{background:var(--success-bg);color:var(--success);border-color:var(--success-bd)}
.sp-summary-ico.is-warn{background:var(--warn-bg);color:var(--warn);border-color:var(--warn-bd)}
.sp-summary-body{min-width:0}
.sp-summary-label{font-size:.62rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3);margin-bottom:3px}
.sp-summary-value{font-family:'Fraunces',Georgia,serif;font-size:1.1rem;font-weight:700;color:var(--brown);letter-spacing:-.3px;line-height:1.15}
.sp-summary-value small{font-size:.7rem;font-weight:600;color:var(--text-3);margin-left:3px}

/* Buyer grid */
.sp-buyers-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}
.sp-buyer-card{position:relative;background:#fff;border:1px solid var(--line);border-radius:18px;padding:20px;cursor:pointer;transition:transform .28s cubic-bezier(.2,.7,.3,1),border-color .28s ease,box-shadow .28s ease;box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 14px 30px -22px rgba(74,44,16,.4);overflow:hidden}
.sp-buyer-card::before{content:'';position:absolute;top:0;left:0;width:56px;height:3px;background:linear-gradient(90deg,var(--gold),var(--gold-3));border-radius:0 3px 3px 0;opacity:.55;transition:opacity .25s ease,width .3s ease}
.sp-buyer-card:hover{transform:translateY(-4px);border-color:rgba(176,100,30,.35);box-shadow:0 24px 46px -22px rgba(74,44,16,.5)}
.sp-buyer-card:hover::before{opacity:1;width:84px}
.sp-buyer-head{display:flex;align-items:center;gap:14px;margin-bottom:16px}
.sp-buyer-avatar{width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#F4C87A,#C97628 55%,#8A5A30);color:#fff;display:grid;place-items:center;font-family:'Fraunces',Georgia,serif;font-size:1.35rem;font-weight:700;flex-shrink:0;box-shadow:0 6px 16px -6px rgba(176,100,30,.7)}
.sp-buyer-meta{min-width:0;flex:1}
.sp-buyer-name{font-family:'Fraunces',Georgia,serif;font-size:1.05rem;font-weight:600;color:var(--brown);margin:0 0 3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sp-buyer-sub{font-size:.74rem;color:var(--text-3);font-weight:500}
.sp-buyer-rows{display:flex;flex-direction:column;gap:8px;padding-top:14px;border-top:1px dashed var(--line-2)}
.sp-buyer-row{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:.85rem}
.sp-buyer-row span:first-child{color:var(--text-3);font-weight:500;display:inline-flex;align-items:center;gap:7px}
.sp-buyer-row span:first-child i{font-size:.85rem;color:var(--gold)}
.sp-buyer-row span:last-child{font-weight:700;color:var(--brown);font-family:'Fraunces',Georgia,serif}
.sp-buyer-row span:last-child.is-danger{color:var(--danger)}
.sp-buyer-row span:last-child.is-success{color:var(--success)}
.sp-buyer-tag{position:absolute;top:16px;right:16px;padding:3px 9px;border-radius:999px;font-size:.62rem;font-weight:700;letter-spacing:.4px;text-transform:uppercase;display:inline-flex;align-items:center;gap:4px}
.sp-buyer-tag.is-top{background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;box-shadow:0 4px 10px -3px rgba(176,100,30,.6)}
.sp-buyer-tag.is-good{background:var(--success-bg);color:var(--success);border:1px solid var(--success-bd)}
.sp-buyer-tag.is-owed{background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-bd)}

/* Empty state */
.sp-empty{padding:56px 26px;text-align:center;color:var(--text-3);background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 14px 30px -22px rgba(74,44,16,.4)}
.sp-empty-ico{width:76px;height:76px;margin:0 auto 18px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,var(--gold-soft),#fff);color:var(--gold);font-size:1.9rem;border:1px solid rgba(176,100,30,.2);box-shadow:0 10px 24px -12px rgba(176,100,30,.4)}
.sp-empty h4{font-family:'Fraunces',Georgia,serif;font-size:1.15rem;font-weight:600;color:var(--brown);margin:0 0 8px}
.sp-empty p{font-size:.88rem;margin:0 0 20px;max-width:42ch;margin-inline:auto;line-height:1.6}
.sp-empty-actions{display:inline-flex;gap:10px;flex-wrap:wrap;justify-content:center}

/* Modals */
.sp-modal-backdrop{position:fixed;inset:0;background:rgba(42,30,16,.6);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:400;display:none;align-items:center;justify-content:center;padding:20px;opacity:0;transition:opacity .25s ease;overflow:hidden}
.sp-modal-backdrop.is-open{display:flex;opacity:1}
.sp-modal{background:#fff;border-radius:22px;width:100%;max-width:640px;max-height:calc(100vh - 40px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 46px 100px -34px rgba(40,22,6,.8);transform:scale(.95) translateY(10px);transition:transform .3s cubic-bezier(.2,.7,.3,1);min-height:0}
.sp-modal.is-wide{max-width:760px}
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
.sp-form-input i{padding:0 4px 0 14px;color:var(--text-3);font-size:.95rem}
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

/* Buyer detail hero */
.sp-detail-hero{display:flex;align-items:center;gap:16px;padding:18px 20px;border-radius:16px;background:radial-gradient(circle at 85% 15%,rgba(232,176,90,.35),transparent 55%),linear-gradient(135deg,#4A2C10 0%,#2A1E10 100%);color:#fff;flex-wrap:wrap}
.sp-detail-hero .sp-buyer-avatar{width:60px;height:60px;font-size:1.6rem;background:linear-gradient(135deg,#F4C87A,#C97628);box-shadow:0 8px 18px -6px rgba(0,0,0,.6)}
.sp-detail-hero-text{min-width:0;flex:1}
.sp-detail-hero-name{font-family:'Fraunces',Georgia,serif;font-size:1.35rem;font-weight:600;margin:0 0 3px;color:#fff;letter-spacing:-.4px}
.sp-detail-hero-sub{font-size:.8rem;color:rgba(255,255,255,.72);margin:0}

.sp-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:520px){.sp-detail-grid{grid-template-columns:1fr}}
.sp-detail-item{padding:14px 16px;border-radius:12px;background:var(--cream-2);border:1px solid var(--line)}
.sp-detail-item.is-full{grid-column:1/-1}
.sp-detail-label{font-size:.62rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3);margin-bottom:4px}
.sp-detail-value{font-size:.95rem;font-weight:600;color:var(--brown);line-height:1.4;word-break:break-word}
.sp-detail-value.is-big{font-family:'Fraunces',Georgia,serif;font-size:1.35rem;font-weight:700;letter-spacing:-.4px}
.sp-detail-value.is-danger{color:var(--danger)}
.sp-detail-value.is-success{color:var(--success)}

/* Sales history table */
.sp-hist-table{width:100%;border-collapse:collapse;font-size:.84rem;border:1px solid var(--line);border-radius:12px;overflow:hidden}
.sp-hist-table thead th{text-align:left;padding:10px 14px;font-size:.62rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3);background:var(--cream-2);border-bottom:1px solid var(--line);white-space:nowrap}
.sp-hist-table tbody td{padding:12px 14px;border-bottom:1px dashed var(--line-2);white-space:nowrap}
.sp-hist-table tbody tr:last-child td{border-bottom:0}
.sp-hist-table tbody tr:hover{background:var(--gold-soft)}
.sp-ref{font-family:'JetBrains Mono',monospace;font-size:.74rem;font-weight:600;color:var(--brown-2);background:var(--paper-2);border:1px solid var(--line-2);padding:2px 7px;border-radius:5px}
.sp-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:.66rem;font-weight:700;letter-spacing:.3px;text-transform:uppercase}
.sp-badge-paid{background:var(--success-bg);color:var(--success);border:1px solid var(--success-bd)}
.sp-badge-partial{background:var(--warn-bg);color:var(--warn);border:1px solid var(--warn-bd)}
.sp-badge-unpaid{background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-bd)}
.sp-badge-pending{background:var(--info-bg);color:var(--info);border:1px solid #C6DCF0}

/* Toast */
.sp-toast{position:fixed;bottom:26px;left:50%;transform:translate(-50%,120%);background:linear-gradient(135deg,#4A2C10,#2A1E10);color:#fff;padding:14px 22px;border-radius:14px;box-shadow:0 24px 46px -20px rgba(0,0,0,.75);display:inline-flex;align-items:center;gap:11px;font-size:.88rem;font-weight:600;z-index:500;transition:transform .38s cubic-bezier(.2,.7,.3,1);border:1px solid rgba(232,176,90,.3);max-width:calc(100vw - 40px)}
.sp-toast.is-visible{transform:translate(-50%,0)}
.sp-toast i{font-size:1.2rem;color:var(--gold-3)}
.sp-toast.is-success i{color:#7DD68A}
.sp-toast.is-error i{color:#FF9B7A}

/* Mobile */
.sp-sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(42,30,16,.5);z-index:250;opacity:0;pointer-events:none;transition:opacity .25s ease}
@media (max-width:900px){.sp-sidebar{transform:translateX(-100%)}body.sp-sidebar-open .sp-sidebar{transform:translateX(0)}body.sp-sidebar-open .sp-sidebar-overlay{display:block;opacity:1;pointer-events:auto}.sp-main{margin-left:0}.sp-menu-btn{display:inline-flex}}
@media (max-width:480px){.sp-topbar{padding:12px 16px}.sp-content{padding:16px}.sp-cta span{display:none}.sp-cta{padding:10px 12px}.sp-modal{max-height:calc(100vh - 20px)}.sp-modal-body{padding:18px}.sp-modal-foot{padding:12px 18px 16px}}
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
        <a href="<?= BASE_URL ?>seller/buyers.php" class="sp-nav-link is-active" data-nav><i class="bi bi-people"></i> Buyers</a>
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
            <button class="sp-menu-btn" id="menuBtn" aria-label="Open menu"><i class="bi bi-list"></i></button>
            <div>
                <h1 class="sp-page-title">My Buyers</h1>
                <span class="sp-page-sub">Welcome back, <strong><?= $firstName ?></strong>!</span>
            </div>
        </div>
        <div class="sp-topbar-right">
            <button class="sp-icon-btn" id="notifBtn" aria-label="Notifications">
                <i class="bi bi-bell"></i><span class="badge-dot"></span>
            </button>
            <button type="button" class="sp-cta" data-modal="newBuyer">
                <i class="bi bi-person-plus"></i><span>Add Buyer</span>
            </button>
        </div>
    </header>

    <main class="sp-content">

        <!-- Toolbar -->
        <form class="sp-toolbar" method="get" action="">
            <?php if ($sortKey !== 'total_amount' || $sortDir !== 'DESC'): ?>
                <input type="hidden" name="sort" value="<?= sanitize($sortKey) ?>">
                <input type="hidden" name="dir"  value="<?= strtolower($sortDir) ?>">
            <?php endif; ?>

            <div class="sp-filter-row">
                <div class="sp-field">
                    <i class="bi bi-search"></i>
                    <input type="search" name="q" value="<?= sanitize($search) ?>" placeholder="Search buyers by name…">
                </div>

                <div class="sp-field is-select">
                    <i class="bi bi-sort-down"></i>
                    <select name="sort" aria-label="Sort by">
                        <?php
                            $sortOptions = [
                                'total_amount' => 'Top by amount',
                                'sales_count'  => 'Most sales',
                                'total_kg'     => 'Most volume',
                                'outstanding'  => 'Highest owed',
                                'buyer_name'   => 'Name (A–Z)',
                                'last_sale'    => 'Recent activity',
                            ];
                            foreach ($sortOptions as $k => $label):
                        ?>
                            <option value="<?= $k ?>" <?= $sortKey === $k ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="sp-btn-primary"><i class="bi bi-funnel"></i> Apply</button>
                <a href="<?= BASE_URL ?>seller/buyers.php" class="sp-btn-ghost"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
            </div>
        </form>

        <!-- Summary -->
        <section class="sp-summary-strip">
            <div class="sp-summary-card">
                <div class="sp-summary-ico"><i class="bi bi-people"></i></div>
                <div class="sp-summary-body">
                    <div class="sp-summary-label">Total Buyers</div>
                    <div class="sp-summary-value"><?= number_format($summary['buyers']) ?></div>
                </div>
            </div>
            <div class="sp-summary-card">
                <div class="sp-summary-ico is-info"><i class="bi bi-box-seam"></i></div>
                <div class="sp-summary-body">
                    <div class="sp-summary-label">Total Volume</div>
                    <div class="sp-summary-value"><?= number_format($summary['total_kg'], 0) ?><small>kg</small></div>
                </div>
            </div>
            <div class="sp-summary-card">
                <div class="sp-summary-ico is-success"><i class="bi bi-cash-stack"></i></div>
                <div class="sp-summary-body">
                    <div class="sp-summary-label">Total Value</div>
                    <div class="sp-summary-value"><?= peso($summary['total_amount']) ?></div>
                </div>
            </div>
            <div class="sp-summary-card">
                <div class="sp-summary-ico is-warn"><i class="bi bi-hourglass-split"></i></div>
                <div class="sp-summary-body">
                    <div class="sp-summary-label">Outstanding</div>
                    <div class="sp-summary-value"><?= peso($summary['outstanding']) ?></div>
                </div>
            </div>
        </section>

        <!-- Buyers grid -->
        <?php if (!empty($buyers)): ?>
            <section class="sp-buyers-grid">
                <?php foreach ($buyers as $idx => $b): ?>
                    <?php
                        $owed = (float) $b['outstanding'];
                        $tagCls = 'is-good';
                        $tagTxt = 'Clear';
                        $tagIco = 'bi-check-circle';
                        if ($owed > 0) { $tagCls = 'is-owed'; $tagTxt = 'Has balance'; $tagIco = 'bi-exclamation-circle'; }
                        if ($idx < 3 && $sortKey === 'total_amount' && $sortDir === 'DESC' && !$search) {
                            $tagCls = 'is-top'; $tagTxt = 'Top ' . ($idx + 1); $tagIco = 'bi-award';
                        }
                    ?>
                    <article class="sp-buyer-card"
                             data-buyer='<?= htmlspecialchars(json_encode([
                                 'name'         => $b['name'],
                                 'sales_count'  => (int) $b['sales_count'],
                                 'total_kg'     => (float) $b['total_kg'],
                                 'total_amount' => (float) $b['total_amount'],
                                 'total_paid'   => (float) $b['total_paid'],
                                 'outstanding'  => (float) $b['outstanding'],
                                 'first_sale'   => $b['first_sale'] ?? '',
                                 'last_sale'    => $b['last_sale'] ?? '',
                             ]), ENT_QUOTES, 'UTF-8') ?>'>
                        <span class="sp-buyer-tag <?= $tagCls ?>">
                            <i class="bi <?= $tagIco ?>"></i> <?= $tagTxt ?>
                        </span>
                        <div class="sp-buyer-head">
                            <div class="sp-buyer-avatar"><?= sanitize(initial($b['name'])) ?></div>
                            <div class="sp-buyer-meta">
                                <h4 class="sp-buyer-name"><?= sanitize($b['name']) ?></h4>
                                <p class="sp-buyer-sub">
                                    <?= number_format((int) $b['sales_count']) ?> sale<?= (int) $b['sales_count'] === 1 ? '' : 's' ?>
                                    · Last: <?= niceDate($b['last_sale']) ?>
                                </p>
                            </div>
                        </div>
                        <div class="sp-buyer-rows">
                            <div class="sp-buyer-row">
                                <span><i class="bi bi-box-seam"></i> Volume</span>
                                <span><?= number_format((float) $b['total_kg'], 0) ?> kg</span>
                            </div>
                            <div class="sp-buyer-row">
                                <span><i class="bi bi-receipt"></i> Total value</span>
                                <span><?= peso($b['total_amount']) ?></span>
                            </div>
                            <div class="sp-buyer-row">
                                <span><i class="bi bi-check-circle"></i> Paid</span>
                                <span class="is-success"><?= peso($b['total_paid']) ?></span>
                            </div>
                            <div class="sp-buyer-row">
                                <span><i class="bi bi-hourglass-split"></i> Outstanding</span>
                                <span class="<?= $owed > 0 ? 'is-danger' : '' ?>"><?= peso($b['outstanding']) ?></span>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <div class="sp-empty">
                <div class="sp-empty-ico"><i class="bi bi-people"></i></div>
                <h4><?= $search !== '' ? 'No buyers match your search' : 'No buyers yet' ?></h4>
                <p>
                    <?php if ($search !== ''): ?>
                        Try a different name, or clear the search to see all buyers.
                    <?php else: ?>
                        Buyers will appear here automatically once you record your first sale to them.
                    <?php endif; ?>
                </p>
                <div class="sp-empty-actions">
                    <?php if ($search !== ''): ?>
                        <a href="<?= BASE_URL ?>seller/buyers.php" class="sp-btn-ghost"><i class="bi bi-arrow-counterclockwise"></i> Clear search</a>
                    <?php endif; ?>
                    <button type="button" class="sp-btn-primary" data-modal="newBuyer">
                        <i class="bi bi-person-plus"></i> Record First Sale
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- ============================================================
     BUYER DETAIL MODAL
     ============================================================ -->
<div class="sp-modal-backdrop" id="modalDetail">
    <div class="sp-modal is-wide">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal><i class="bi bi-x-lg"></i></button>
            <span class="sp-modal-tag"><i class="bi bi-person-circle"></i> Buyer Details</span>
            <h3 class="sp-modal-title" id="dtTitle">Buyer</h3>
            <p class="sp-modal-sub" id="dtSub">Loading…</p>
        </div>
        <div class="sp-modal-body" id="dtBody">
            <div class="sp-empty" style="padding:24px">
                <div class="sp-empty-ico"><i class="bi bi-hourglass-split"></i></div>
                <h4>Loading buyer data…</h4>
            </div>
        </div>
        <div class="sp-modal-foot">
            <button type="button" class="sp-btn-cancel" data-close-modal><i class="bi bi-x-lg"></i> Close</button>
            <button type="button" class="sp-btn-submit" id="dtNewSaleBtn"><i class="bi bi-plus-lg"></i> New Sale</button>
        </div>
    </div>
</div>

<!-- ============================================================
     NEW BUYER MODAL (records first sale)
     ============================================================ -->
<div class="sp-modal-backdrop" id="modalNewBuyer">
    <div class="sp-modal">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal><i class="bi bi-x-lg"></i></button>
            <span class="sp-modal-tag"><i class="bi bi-person-plus"></i> New Buyer</span>
            <h3 class="sp-modal-title">Record a Sale to a New Buyer</h3>
            <p class="sp-modal-sub">Enter the buyer's name and the delivery details to add them to your list.</p>
        </div>
        <form id="newBuyerForm" autocomplete="off" style="display:contents">
            <div class="sp-modal-body">
                <div class="sp-form-row">
                    <div class="sp-form-field is-full">
                        <label for="nbBuyer">Buyer Name <span class="req">*</span></label>
                        <div class="sp-form-input"><i class="bi bi-person"></i><input type="text" id="nbBuyer" placeholder="e.g. Dula Rice Trading" required></div>
                        <div class="sp-form-hint">A new buyer record will be created automatically.</div>
                    </div>
                    <div class="sp-form-field">
                        <label for="nbWeight">Weight <span class="req">*</span></label>
                        <div class="sp-form-input"><i class="bi bi-box-seam"></i><input type="number" id="nbWeight" min="0.01" step="0.01" placeholder="0.00" required></div>
                        <div class="sp-form-hint">In kilograms (kg)</div>
                    </div>
                    <div class="sp-form-field">
                        <label for="nbPrice">Price per kg <span class="req">*</span></label>
                        <div class="sp-form-input"><i class="bi bi-tag"></i><input type="number" id="nbPrice" min="0.01" step="0.01" placeholder="0.00" required></div>
                        <div class="sp-form-hint">In pesos (₱)</div>
                    </div>
                    <div class="sp-form-field">
                        <label for="nbPaid">Amount Received (optional)</label>
                        <div class="sp-form-input"><i class="bi bi-cash-stack"></i><input type="number" id="nbPaid" min="0" step="0.01" placeholder="0.00"></div>
                    </div>
                    <div class="sp-form-field">
                        <label for="nbDate">Delivery Date</label>
                        <div class="sp-form-input"><i class="bi bi-calendar3"></i><input type="date" id="nbDate"></div>
                    </div>
                    <div class="sp-form-field is-full">
                        <label for="nbNotes">Notes (optional)</label>
                        <div class="sp-form-input"><i class="bi bi-chat-left-text"></i><textarea id="nbNotes" placeholder="e.g. First delivery, dry palay…"></textarea></div>
                    </div>
                </div>
                <div class="sp-form-summary">
                    <span class="sp-form-summary-label"><i class="bi bi-calculator"></i> Total Amount</span>
                    <span class="sp-form-summary-value" id="nbTotal">₱0.00</span>
                </div>
                <div class="sp-form-summary" style="background:linear-gradient(135deg,#EAF7EC,#fff);border-color:var(--success-bd)">
                    <span class="sp-form-summary-label"><i class="bi bi-hourglass-split"></i> Balance (remaining)</span>
                    <span class="sp-form-summary-value" id="nbBalance" style="color:var(--danger)">₱0.00</span>
                </div>
            </div>
            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-cancel" data-close-modal><i class="bi bi-x-lg"></i> Cancel</button>
                <button type="submit" class="sp-btn-submit" id="nbSubmit"><i class="bi bi-check-lg"></i> Save &amp; Add Buyer</button>
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
        toast.classList.add('is-'+type, 'is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 3200);
    }

    /* Modals */
    const modalDetail = document.getElementById('modalDetail');
    const modalNewBuyer = document.getElementById('modalNewBuyer');
    const modals = { detail: modalDetail, newBuyer: modalNewBuyer };

    function openModal(m) { if (!m) return; m.classList.add('is-open'); body.style.overflow = 'hidden'; }
    function closeModal(m) {
        if (!m) return;
        m.classList.remove('is-open');
        if (!document.querySelector('.sp-modal-backdrop.is-open')) body.style.overflow = '';
    }

    document.querySelectorAll('[data-modal]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            if (btn.dataset.modal === 'newBuyer') resetNewBuyerForm();
            openModal(modals[btn.dataset.modal]);
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

    /* Helpers */
    function pesoFmt(n) { n = Number(n)||0; return '₱' + n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function esc(str) { return String(str ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
    function statusLabel(st) {
        st = (st || 'pending').toLowerCase();
        const map = {
            paid:    ['sp-badge-paid',    'bi-check-circle-fill', 'Paid'],
            partial: ['sp-badge-partial', 'bi-circle-half',       'Partial'],
            unpaid:  ['sp-badge-unpaid',  'bi-x-circle-fill',     'Unpaid'],
            pending: ['sp-badge-pending', 'bi-hourglass-split',   'Pending'],
        };
        return map[st] || map.pending;
    }
    function initialOf(str) { return String(str || '?').trim().charAt(0).toUpperCase() || '?'; }

    /* Card click → fetch sales history and open detail modal */
    document.querySelectorAll('.sp-buyer-card').forEach(card => {
        const buyer = JSON.parse(card.dataset.buyer || '{}');
        card.addEventListener('click', () => openDetail(buyer));
    });

    const dtTitle = document.getElementById('dtTitle');
    const dtSub = document.getElementById('dtSub');
    const dtBody = document.getElementById('dtBody');
    const dtNewSaleBtn = document.getElementById('dtNewSaleBtn');
    let currentBuyerName = '';

    async function openDetail(buyer) {
        currentBuyerName = buyer.name || '';
        dtTitle.textContent = buyer.name || 'Buyer';
        dtSub.textContent = `${buyer.sales_count} sale${buyer.sales_count === 1 ? '' : 's'} · since ${buyer.first_sale ? new Date(buyer.first_sale).toLocaleDateString('en-US',{month:'short',year:'numeric'}) : '—'}`;
        dtBody.innerHTML = `
            <div class="sp-detail-hero">
                <div class="sp-buyer-avatar">${esc(initialOf(buyer.name))}</div>
                <div class="sp-detail-hero-text">
                    <h4 class="sp-detail-hero-name">${esc(buyer.name)}</h4>
                    <p class="sp-detail-hero-sub">First sale ${buyer.first_sale ? new Date(buyer.first_sale).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—'} · Last sale ${buyer.last_sale ? new Date(buyer.last_sale).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—'}</p>
                </div>
            </div>

            <div class="sp-detail-grid">
                <div class="sp-detail-item">
                    <div class="sp-detail-label">Total Volume</div>
                    <div class="sp-detail-value is-big">${Number(buyer.total_kg).toLocaleString('en-PH',{minimumFractionDigits:0,maximumFractionDigits:0})}<small style="font-size:.72rem;font-weight:600;color:var(--text-3);margin-left:4px">kg</small></div>
                </div>
                <div class="sp-detail-item">
                    <div class="sp-detail-label">Total Value</div>
                    <div class="sp-detail-value is-big">${pesoFmt(buyer.total_amount)}</div>
                </div>
                <div class="sp-detail-item">
                    <div class="sp-detail-label">Total Paid</div>
                    <div class="sp-detail-value is-success" style="font-family:'Fraunces',Georgia,serif;font-size:1.2rem;font-weight:700">${pesoFmt(buyer.total_paid)}</div>
                </div>
                <div class="sp-detail-item">
                    <div class="sp-detail-label">Outstanding</div>
                    <div class="sp-detail-value ${buyer.outstanding > 0 ? 'is-danger' : ''}" style="font-family:'Fraunces',Georgia,serif;font-size:1.2rem;font-weight:700">${pesoFmt(buyer.outstanding)}</div>
                </div>
            </div>

            <div>
                <div class="sp-detail-label" style="margin-bottom:8px">Sales History</div>
                <div id="dtHistory">
                    <div class="sp-empty" style="padding:20px">
                        <div class="sp-empty-ico" style="width:52px;height:52px;font-size:1.3rem"><i class="bi bi-hourglass-split"></i></div>
                        <h4 style="font-size:.95rem">Loading sales…</h4>
                    </div>
                </div>
            </div>
        `;

        openModal(modalDetail);

        /* Fetch this buyer's sales */
        try {
            const url = '<?= BASE_URL ?>seller/get_buyer_sales.php?name=' + encodeURIComponent(buyer.name);
            const res = await fetch(url);
            const data = await res.json().catch(() => ({}));
            const list = data.sales || [];

            if (!list.length) {
                document.getElementById('dtHistory').innerHTML = `
                    <div class="sp-empty" style="padding:20px">
                        <div class="sp-empty-ico" style="width:52px;height:52px;font-size:1.3rem"><i class="bi bi-inbox"></i></div>
                        <h4 style="font-size:.95rem">No individual sales on file</h4>
                    </div>`;
                return;
            }

            const rows = list.map(s => {
                const [bc, bi, bl] = statusLabel(s.status);
                return `
                    <tr>
                        <td><span class="sp-ref">${esc(s.reference_no)}</span></td>
                        <td>${Number(s.weight_kg).toLocaleString('en-PH',{minimumFractionDigits:0,maximumFractionDigits:0})} kg</td>
                        <td><strong>${pesoFmt(s.total_amount)}</strong></td>
                        <td>${pesoFmt(s.balance)}</td>
                        <td><span class="sp-badge ${bc}"><i class="bi ${bi}"></i> ${bl}</span></td>
                        <td>${s.created_at ? new Date(s.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—'}</td>
                    </tr>`;
            }).join('');

            document.getElementById('dtHistory').innerHTML = `
                <table class="sp-hist-table">
                    <thead>
                        <tr><th>Reference</th><th>Weight</th><th>Amount</th><th>Balance</th><th>Status</th><th>Date</th></tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            `;
        } catch (err) {
            document.getElementById('dtHistory').innerHTML = `
                <div class="sp-empty" style="padding:20px">
                    <div class="sp-empty-ico" style="width:52px;height:52px;font-size:1.3rem;color:var(--danger)"><i class="bi bi-exclamation-triangle"></i></div>
                    <h4 style="font-size:.95rem">Could not load sales</h4>
                </div>`;
        }
    }

    dtNewSaleBtn?.addEventListener('click', () => {
        closeModal(modalDetail);
        document.getElementById('nbBuyer').value = currentBuyerName;
        resetNewBuyerForm(true);
        document.getElementById('nbBuyer').value = currentBuyerName;
        openModal(modalNewBuyer);
    });

    /* ---------- New Buyer form ---------- */
    const nbForm    = document.getElementById('newBuyerForm');
    const nbBuyer   = document.getElementById('nbBuyer');
    const nbWeight  = document.getElementById('nbWeight');
    const nbPrice   = document.getElementById('nbPrice');
    const nbPaid    = document.getElementById('nbPaid');
    const nbDate    = document.getElementById('nbDate');
    const nbNotes   = document.getElementById('nbNotes');
    const nbTotal   = document.getElementById('nbTotal');
    const nbBalance = document.getElementById('nbBalance');
    const nbSubmit  = document.getElementById('nbSubmit');

    function recalcNewBuyer() {
        const w = parseFloat(nbWeight?.value) || 0;
        const p = parseFloat(nbPrice?.value)  || 0;
        const paid = parseFloat(nbPaid?.value) || 0;
        const total = w * p;
        const balance = Math.max(0, total - paid);
        nbTotal.textContent = pesoFmt(total);
        nbBalance.textContent = pesoFmt(balance);
        nbBalance.style.color = balance <= 0 ? 'var(--success)' : 'var(--danger)';
    }
    nbWeight?.addEventListener('input', recalcNewBuyer);
    nbPrice?.addEventListener('input', recalcNewBuyer);
    nbPaid?.addEventListener('input', recalcNewBuyer);

    function resetNewBuyerForm(keepBuyer = false) {
        const keepName = keepBuyer ? nbBuyer.value : '';
        nbForm?.reset();
        // Delivery Date is intentionally left blank on reset.
        if (keepName) nbBuyer.value = keepName;
        recalcNewBuyer();
        modalNewBuyer.querySelector('.sp-modal-body').scrollTop = 0;
    }

    nbForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
            buyer_name:   nbBuyer.value.trim(),
            weight_kg:    parseFloat(nbWeight.value) || 0,
            price_per_kg: parseFloat(nbPrice.value)  || 0,
            amount_paid:  parseFloat(nbPaid.value)   || 0,
            created_at:   nbDate.value || '',
            notes:        nbNotes.value || ''
        };
        if (!payload.buyer_name)     { showToast('Please enter the buyer name.', 'error'); return; }
        if (payload.weight_kg <= 0)  { showToast('Please enter a valid weight.', 'error'); return; }
        if (payload.price_per_kg <= 0){ showToast('Please enter a valid price per kg.', 'error'); return; }

        nbSubmit.disabled = true;
        const orig = nbSubmit.innerHTML;
        nbSubmit.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…';
        try {
            const res = await fetch('<?= BASE_URL ?>seller/save_sale.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.success) {
                showToast('Buyer added & sale recorded!', 'success');
                closeModal(modalNewBuyer);
                setTimeout(() => window.location.reload(), 700);
            } else {
                showToast(data.message || 'Could not save the sale.', 'error');
            }
        } catch (err) {
            showToast('Network error. Please try again.', 'error');
        } finally {
            nbSubmit.disabled = false;
            nbSubmit.innerHTML = orig;
        }
    });

    document.getElementById('notifBtn')?.addEventListener('click', () => showToast('No new notifications right now.', 'success'));
})();
</script>
</body>
</html>