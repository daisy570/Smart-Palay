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
   Filters & presets
   NOTE: Presets still drive the *effective* range server-side.
   The visible <input type="date"> values are intentionally left
   blank so the user always starts from an empty picker.
-------------------------------------------------------------- */
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo   = trim((string) ($_GET['to']   ?? ''));
$preset   = trim((string) ($_GET['preset'] ?? 'all'));

$today = date('Y-m-d');

/* Effective range used for all queries */
$effFrom = $dateFrom;
$effTo   = $dateTo;

switch ($preset) {
    case 'today':   $effFrom = $effTo = $today; break;
    case 'week':    $effFrom = date('Y-m-d', strtotime('monday this week')); $effTo = $today; break;
    case 'month':   $effFrom = date('Y-m-01'); $effTo = $today; break;
    case 'quarter': $effFrom = date('Y-m-d', strtotime('first day of -2 month')); $effTo = $today; break;
    case 'year':    $effFrom = date('Y-01-01'); $effTo = $today; break;
    case 'all':
    default:        $preset = 'all'; break;
}

/* Build WHERE for purchases */
$where  = ['seller_id = ?'];
$params = [$userId];
if ($effFrom !== '') { $where[] = 'DATE(created_at) >= ?'; $params[] = $effFrom; }
if ($effTo   !== '') { $where[] = 'DATE(created_at) <= ?'; $params[] = $effTo; }
$whereSql = implode(' AND ', $where);

/* Build WHERE for payments */
$pWhere  = ['seller_id = ?'];
$pParams = [$userId];
if ($effFrom !== '') { $pWhere[] = 'DATE(paid_at) >= ?'; $pParams[] = $effFrom; }
if ($effTo   !== '') { $pWhere[] = 'DATE(paid_at) <= ?'; $pParams[] = $effTo; }
$pWhereSql = implode(' AND ', $pWhere);

/* --------------------------------------------------------------
   Summary KPIs
-------------------------------------------------------------- */
$summary = [
    'total_sales'    => 0,
    'total_kg'       => 0,
    'total_amount'   => 0,
    'total_paid'     => 0,
    'outstanding'    => 0,
    'buyers_count'   => 0,
    'avg_price_kg'   => 0,
    'payments_count' => 0,
    'payments_total' => 0,
];

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)                          AS total_sales,
               COALESCE(SUM(weight_kg), 0)       AS total_kg,
               COALESCE(SUM(total_amount), 0)    AS total_amount,
               COALESCE(SUM(amount_paid), 0)     AS total_paid,
               COALESCE(SUM(balance), 0)         AS outstanding,
               COUNT(DISTINCT buyer_name)        AS buyers_count
        FROM purchases
        WHERE {$whereSql}
    ");
    $stmt->execute($params);
    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $summary['total_sales']  = (int) $r['total_sales'];
        $summary['total_kg']     = (float) $r['total_kg'];
        $summary['total_amount'] = (float) $r['total_amount'];
        $summary['total_paid']   = (float) $r['total_paid'];
        $summary['outstanding']  = (float) $r['outstanding'];
        $summary['buyers_count'] = (int) $r['buyers_count'];
        if ($summary['total_kg'] > 0) {
            $summary['avg_price_kg'] = $summary['total_amount'] / $summary['total_kg'];
        }
    }
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c, COALESCE(SUM(amount), 0) AS t
        FROM payments WHERE {$pWhereSql}
    ");
    $stmt->execute($pParams);
    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $summary['payments_count'] = (int) $r['c'];
        $summary['payments_total'] = (float) $r['t'];
    }
} catch (Throwable $e) {}

$totalValue = $summary['total_paid'] + $summary['outstanding'];
$paidPct    = $totalValue > 0 ? ($summary['total_paid'] / $totalValue) * 100 : 0;

/* --------------------------------------------------------------
   Monthly trend
-------------------------------------------------------------- */
$monthly = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
               DATE_FORMAT(created_at, '%b %Y') AS label,
               COUNT(*)                         AS count,
               COALESCE(SUM(weight_kg), 0)      AS kg,
               COALESCE(SUM(total_amount), 0)   AS amount
        FROM purchases
        WHERE {$whereSql}
        GROUP BY ym, label
        ORDER BY ym DESC
        LIMIT 12
    ");
    $stmt->execute($params);
    $monthly = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {}

if (empty($monthly)) {
    for ($i = 5; $i >= 0; $i--) {
        $ts = strtotime("first day of -$i month");
        $monthly[] = [
            'ym'     => date('Y-m', $ts),
            'label'  => date('M Y', $ts),
            'count'  => 0,
            'kg'     => 0,
            'amount' => 0,
        ];
    }
}
$maxKg = 0;
foreach ($monthly as $m) { $maxKg = max($maxKg, (float) $m['kg']); }

/* --------------------------------------------------------------
   Status breakdown
-------------------------------------------------------------- */
$statusBreakdown = ['paid'=>0,'partial'=>0,'unpaid'=>0,'pending'=>0];
try {
    $stmt = $pdo->prepare("
        SELECT LOWER(status) AS st, COUNT(*) AS c
        FROM purchases WHERE {$whereSql}
        GROUP BY LOWER(status)
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $st = $r['st'] ?: 'pending';
        if (!isset($statusBreakdown[$st])) $statusBreakdown[$st] = 0;
        $statusBreakdown[$st] = (int) $r['c'];
    }
} catch (Throwable $e) {}
$statusTotal = array_sum($statusBreakdown);

/* --------------------------------------------------------------
   Top buyers
-------------------------------------------------------------- */
$topBuyers = [];
try {
    $stmt = $pdo->prepare("
        SELECT buyer_name,
               COUNT(*)                       AS sales,
               COALESCE(SUM(weight_kg), 0)    AS kg,
               COALESCE(SUM(total_amount), 0) AS amount,
               COALESCE(SUM(balance), 0)      AS balance
        FROM purchases
        WHERE {$whereSql} AND buyer_name <> ''
        GROUP BY buyer_name
        ORDER BY amount DESC
        LIMIT 8
    ");
    $stmt->execute($params);
    $topBuyers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* --------------------------------------------------------------
   Payment methods breakdown
-------------------------------------------------------------- */
$methodBreakdown = [];
try {
    $stmt = $pdo->prepare("
        SELECT LOWER(method) AS m, COUNT(*) AS c, COALESCE(SUM(amount),0) AS t
        FROM payments WHERE {$pWhereSql}
        GROUP BY LOWER(method)
        ORDER BY t DESC
    ");
    $stmt->execute($pParams);
    $methodBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* --------------------------------------------------------------
   Recent activity
-------------------------------------------------------------- */
$recentSales = [];
$recentPayments = [];
try {
    $stmt = $pdo->prepare("
        SELECT reference_no, buyer_name, weight_kg, total_amount, status, created_at
        FROM purchases WHERE {$whereSql}
        ORDER BY created_at DESC LIMIT 5
    ");
    $stmt->execute($params);
    $recentSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT reference_no, amount, method, paid_at
        FROM payments WHERE {$pWhereSql}
        ORDER BY paid_at DESC LIMIT 5
    ");
    $stmt->execute($pParams);
    $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* --------------------------------------------------------------
   Helpers
-------------------------------------------------------------- */
function peso($n) { return '₱' . number_format((float) $n, 2); }
function niceDate($s) { if (!$s) return '—'; $t = strtotime($s); return $t ? date('M j, Y', $t) : '—'; }

function qs(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) unset($params[$k]);
    }
    return http_build_query($params);
}

function methodMeta(string $m): array {
    $m = strtolower($m ?: 'cash');
    switch ($m) {
        case 'cash':  return ['Cash',          'bi-cash-coin',         'is-success'];
        case 'bank':  return ['Bank Transfer', 'bi-bank',              'is-info'];
        case 'gcash': return ['GCash',         'bi-phone',             'is-info'];
        case 'maya':  return ['Maya',          'bi-phone-fill',        'is-info'];
        case 'check': return ['Check',         'bi-file-earmark-text', 'is-warn'];
        default:      return [ucfirst($m),     'bi-three-dots',        ''];
    }
}

$rangeLabel = 'All time';
if ($effFrom && $effTo) $rangeLabel = date('M j, Y', strtotime($effFrom)) . ' – ' . date('M j, Y', strtotime($effTo));
elseif ($effFrom) $rangeLabel = 'From ' . date('M j, Y', strtotime($effFrom));
elseif ($effTo)   $rangeLabel = 'Until ' . date('M j, Y', strtotime($effTo));

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
<title>Reports | SmartPalay</title>
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
.sp-preset-tabs{display:flex;flex-wrap:wrap;gap:6px}
.sp-preset-tab{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:10px;background:var(--cream-2);border:1.5px solid var(--line);color:var(--text-2);font-size:.8rem;font-weight:600;text-decoration:none;transition:border-color .18s ease,background .18s ease,color .18s ease,transform .18s ease}
.sp-preset-tab:hover{border-color:var(--line-3);color:var(--brown);transform:translateY(-1px)}
.sp-preset-tab.is-active{background:linear-gradient(135deg,var(--gold-soft),#fff);border-color:rgba(176,100,30,.4);color:var(--gold);font-weight:700;box-shadow:0 4px 12px -6px rgba(176,100,30,.4)}
.sp-filter-row{display:grid;grid-template-columns:1fr 1fr minmax(0,1.2fr) auto auto auto;gap:10px;align-items:stretch}
@media (max-width:1080px){.sp-filter-row{grid-template-columns:1fr 1fr}}
@media (max-width:620px){.sp-filter-row{grid-template-columns:1fr}}
.sp-field{display:flex;align-items:center;background:#fff;border:1.5px solid var(--line-2);border-radius:11px;transition:border-color .18s ease,box-shadow .18s ease;overflow:hidden;min-height:44px}
.sp-field:hover{border-color:var(--line-3)}
.sp-field:focus-within{border-color:var(--gold);box-shadow:0 0 0 4px rgba(176,100,30,.12)}
.sp-field i{padding:0 4px 0 13px;color:var(--text-3);font-size:.95rem}
.sp-field input,.sp-field select{flex:1;border:0;outline:0;background:transparent;padding:11px 14px 11px 9px;font-size:.87rem;font-weight:500;color:var(--text);font-family:inherit;min-width:0;width:100%}
.sp-field.is-select{position:relative}
.sp-field.is-select::after{content:'';width:7px;height:7px;border-right:2px solid var(--text-3);border-bottom:2px solid var(--text-3);transform:rotate(45deg) translate(-6px,-3px);pointer-events:none;margin-right:14px}
.sp-field.is-select select{appearance:none;-webkit-appearance:none;cursor:pointer}
.sp-btn-primary,.sp-btn-ghost{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:11px;font-size:.85rem;font-weight:700;font-family:inherit;cursor:pointer;white-space:nowrap;transition:transform .15s ease,filter .15s ease,border-color .18s ease,color .18s ease;min-height:44px}
.sp-btn-primary{border:0;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;box-shadow:0 3px 0 rgba(0,0,0,.12),0 14px 24px -12px rgba(176,100,30,.75)}
.sp-btn-primary:hover{transform:translateY(-1px);filter:brightness(1.04)}
.sp-btn-ghost{border:1.5px solid var(--line-2);background:#fff;color:var(--brown-2);text-decoration:none}
.sp-btn-ghost:hover{border-color:var(--gold);color:var(--gold);transform:translateY(-1px)}
.sp-range-note{margin:12px 0 0;font-size:.8rem;color:var(--text-3)}
.sp-range-note strong{color:var(--brown);font-weight:700}

/* KPI grid */
.sp-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:clamp(12px,1.6vw,18px)}
.sp-kpi{position:relative;background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px 22px;box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 14px 30px -22px rgba(74,44,16,.4);transition:transform .28s cubic-bezier(.2,.7,.3,1),border-color .28s ease}
.sp-kpi:hover{transform:translateY(-4px);border-color:rgba(176,100,30,.35)}
.sp-kpi::before{content:'';position:absolute;top:0;left:0;width:56px;height:3px;background:linear-gradient(90deg,var(--gold),var(--gold-3));border-radius:0 3px 3px 0;opacity:.55}
.sp-kpi-ico{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;background:var(--gold-soft);color:var(--gold);font-size:1.15rem;margin-bottom:14px;border:1px solid rgba(176,100,30,.18)}
.sp-kpi-ico.is-success{background:var(--success-bg);color:var(--success);border-color:var(--success-bd)}
.sp-kpi-ico.is-info{background:var(--info-bg);color:var(--info);border-color:#C6DCF0}
.sp-kpi-ico.is-warn{background:var(--warn-bg);color:var(--warn);border-color:var(--warn-bd)}
.sp-kpi-label{font-size:.68rem;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);margin-bottom:6px}
.sp-kpi-value{font-family:'Fraunces',Georgia,serif;font-size:clamp(1.5rem,2.3vw,1.8rem);font-weight:700;color:var(--brown);letter-spacing:-.5px;line-height:1.05}
.sp-kpi-value small{font-size:.72rem;font-weight:600;color:var(--text-3);margin-left:4px}
.sp-kpi-foot{display:flex;align-items:center;gap:6px;margin-top:10px;font-size:.74rem;font-weight:500;color:var(--text-3)}
.sp-kpi-foot i{font-size:.85rem;color:var(--gold)}

/* Panels & grid */
.sp-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:clamp(14px,1.8vw,22px);align-items:start}
@media (max-width:980px){.sp-grid{grid-template-columns:1fr}}
.sp-panel{position:relative;background:#fff;border:1px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 14px 30px -22px rgba(74,44,16,.4)}
.sp-panel::before{content:'';position:absolute;top:0;left:0;width:56px;height:3px;background:linear-gradient(90deg,var(--gold),var(--gold-3));border-radius:0 3px 3px 0;opacity:.55}
.sp-panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,#fff,var(--cream-2));flex-wrap:wrap}
.sp-panel-title{display:flex;align-items:center;gap:11px;font-family:'Fraunces',Georgia,serif;font-size:1.06rem;font-weight:600;color:var(--brown);margin:0}
.sp-panel-title i{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;background:var(--gold-soft);color:var(--gold);font-size:.95rem;border:1px solid rgba(176,100,30,.18)}
.sp-panel-meta{font-size:.74rem;color:var(--text-3);font-weight:500}
.sp-panel-meta strong{color:var(--brown);font-weight:700}

/* Chart */
.sp-chart{padding:22px 22px 24px}
.sp-chart-head{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:20px}
.sp-chart-title{font-size:.72rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3)}
.sp-chart-total{font-family:'Fraunces',Georgia,serif;font-size:1.35rem;font-weight:700;color:var(--brown);letter-spacing:-.4px}
.sp-chart-total small{font-size:.68rem;font-weight:600;color:var(--success);margin-left:6px;display:inline-flex;align-items:center;gap:2px}
.sp-chart-bars{display:flex;align-items:flex-end;gap:12px;height:200px;padding:0 2px;margin-bottom:12px}
.sp-chart-col{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:8px;height:100%;position:relative;min-width:0}
.sp-chart-col-inner{width:100%;display:flex;flex-direction:column;justify-content:flex-end;height:100%;gap:3px;position:relative}
.sp-chart-bar{width:100%;border-radius:6px 6px 3px 3px;background:linear-gradient(180deg,var(--gold-3),var(--gold));box-shadow:0 3px 8px -4px rgba(176,100,30,.5);transition:transform .2s ease,filter .2s ease;cursor:pointer;min-height:4px}
.sp-chart-bar:hover{filter:brightness(1.1);transform:scaleY(1.04)}
.sp-chart-bar.is-empty{background:linear-gradient(180deg,var(--line-2),var(--line));box-shadow:none}
.sp-chart-bar-tip{position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%) translateY(4px);background:linear-gradient(135deg,#4A2C10,#2A1E10);color:#fff;padding:8px 12px;border-radius:8px;font-size:.74rem;font-weight:600;white-space:nowrap;opacity:0;pointer-events:none;transition:opacity .18s ease,transform .18s ease;border:1px solid rgba(232,176,90,.3);box-shadow:0 10px 22px -10px rgba(0,0,0,.6);z-index:10}
.sp-chart-bar-tip::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:#2A1E10}
.sp-chart-bar:hover .sp-chart-bar-tip{opacity:1;transform:translateX(-50%) translateY(0)}
.sp-chart-bar-tip strong{color:var(--gold-3);display:block;font-family:'Fraunces',Georgia,serif;font-size:.88rem}
.sp-chart-label{font-size:.66rem;color:var(--text-3);font-weight:600;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;padding:0 2px}
.sp-chart-axis{display:flex;justify-content:space-between;padding:8px 2px 0;border-top:1px dashed var(--line-2);font-size:.66rem;color:var(--text-3);font-weight:600}

/* Status breakdown */
.sp-status-body{padding:22px;display:flex;flex-direction:column;gap:14px}
.sp-status-row{display:flex;align-items:center;gap:12px;font-size:.86rem}
.sp-status-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0;box-shadow:0 0 0 3px rgba(255,255,255,.9),0 2px 6px -2px rgba(0,0,0,.2)}
.sp-status-dot.is-paid{background:linear-gradient(135deg,var(--success),#6FBF73)}
.sp-status-dot.is-partial{background:linear-gradient(135deg,var(--gold-2),var(--gold-3))}
.sp-status-dot.is-unpaid{background:linear-gradient(135deg,var(--danger),#C4481F)}
.sp-status-dot.is-pending{background:linear-gradient(135deg,var(--info),#6A9CD9)}
.sp-status-name{flex:1;font-weight:600;color:var(--text-2)}
.sp-status-value{font-family:'Fraunces',Georgia,serif;font-weight:700;color:var(--brown);font-size:1rem}
.sp-status-bar{margin-top:4px;height:6px;border-radius:6px;background:var(--paper-2);overflow:hidden}
.sp-status-bar span{display:block;height:100%;border-radius:6px;transition:width .8s cubic-bezier(.2,.7,.3,1)}
.sp-status-bar.is-paid span{background:linear-gradient(90deg,var(--success),#6FBF73)}
.sp-status-bar.is-partial span{background:linear-gradient(90deg,var(--gold-2),var(--gold-3))}
.sp-status-bar.is-unpaid span{background:linear-gradient(90deg,var(--danger),#C4481F)}
.sp-status-bar.is-pending span{background:linear-gradient(90deg,var(--info),#6A9CD9)}

/* Top buyers */
.sp-sellers-body{padding:20px 22px;display:flex;flex-direction:column;gap:4px}
.sp-seller-row{display:flex;align-items:center;gap:12px;padding:12px 10px;border-radius:11px;transition:background .18s ease,padding-left .18s ease;cursor:pointer;text-decoration:none;color:inherit}
.sp-seller-row:hover{background:var(--gold-soft);padding-left:14px;color:inherit}
.sp-seller-rank{width:28px;height:28px;border-radius:8px;display:grid;place-items:center;background:var(--paper-2);color:var(--brown-2);font-family:'JetBrains Mono',monospace;font-weight:700;font-size:.78rem;flex-shrink:0;border:1px solid var(--line-2)}
.sp-seller-row:nth-child(1) .sp-seller-rank{background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;border-color:transparent;box-shadow:0 6px 14px -6px rgba(176,100,30,.7)}
.sp-seller-row:nth-child(2) .sp-seller-rank{background:linear-gradient(135deg,var(--gold-3),var(--gold-2));color:#fff;border-color:transparent}
.sp-seller-row:nth-child(3) .sp-seller-rank{background:linear-gradient(135deg,#CDA26E,var(--brown-3));color:#fff;border-color:transparent}
.sp-seller-info{flex:1;min-width:0}
.sp-seller-name{font-weight:600;color:var(--brown);font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
.sp-seller-meta{font-size:.72rem;color:var(--text-3);font-weight:500}
.sp-seller-amount{font-family:'Fraunces',Georgia,serif;font-weight:700;color:var(--brown);font-size:.95rem;white-space:nowrap}

/* Payment method list */
.sp-method-list{padding:20px 22px;display:flex;flex-direction:column;gap:10px}
.sp-method-row{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;background:var(--cream-2);border:1px solid var(--line);transition:transform .18s ease,border-color .18s ease}
.sp-method-row:hover{transform:translateX(3px);border-color:var(--line-3)}
.sp-method-ico{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;background:#fff;color:var(--gold);font-size:1rem;border:1px solid var(--line-2);flex-shrink:0}
.sp-method-ico.is-success{color:var(--success);border-color:var(--success-bd);background:var(--success-bg)}
.sp-method-ico.is-info{color:var(--info);border-color:#C6DCF0;background:var(--info-bg)}
.sp-method-ico.is-warn{color:var(--warn);border-color:var(--warn-bd);background:var(--warn-bg)}
.sp-method-body{flex:1;min-width:0}
.sp-method-name{font-weight:600;color:var(--brown);font-size:.88rem;margin-bottom:2px}
.sp-method-count{font-size:.72rem;color:var(--text-3);font-weight:500}
.sp-method-total{font-family:'Fraunces',Georgia,serif;font-weight:700;color:var(--brown);font-size:.95rem;white-space:nowrap}

/* Table */
.sp-table-wrap{overflow-x:auto}
.sp-table{width:100%;border-collapse:collapse;font-size:.86rem}
.sp-table thead th{text-align:left;padding:12px 18px;font-size:.64rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3);border-bottom:1px solid var(--line);white-space:nowrap;background:var(--cream-2)}
.sp-table tbody td{padding:14px 18px;border-bottom:1px dashed var(--line-2);color:var(--text);vertical-align:middle;white-space:nowrap}
.sp-table tbody tr:last-child td{border-bottom:0}
.sp-table tbody tr:hover{background:var(--gold-soft)}
.sp-ref{font-family:'JetBrains Mono',monospace;font-size:.78rem;font-weight:600;color:var(--brown-2);background:var(--paper-2);border:1px solid var(--line-2);padding:3px 8px;border-radius:5px}
.sp-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;font-size:.68rem;font-weight:700;letter-spacing:.3px;text-transform:uppercase}
.sp-badge-paid{background:var(--success-bg);color:var(--success);border:1px solid var(--success-bd)}
.sp-badge-partial{background:var(--warn-bg);color:var(--warn);border:1px solid var(--warn-bd)}
.sp-badge-unpaid{background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-bd)}
.sp-badge-pending{background:var(--info-bg);color:var(--info);border:1px solid #C6DCF0}
.sp-money{font-family:'Fraunces',Georgia,serif;font-weight:700;color:var(--brown);letter-spacing:-.2px}

/* Empty */
.sp-empty{padding:48px 26px;text-align:center;color:var(--text-3)}
.sp-empty-ico{width:68px;height:68px;margin:0 auto 16px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,var(--gold-soft),#fff);color:var(--gold);font-size:1.7rem;border:1px solid rgba(176,100,30,.2)}
.sp-empty h4{font-family:'Fraunces',Georgia,serif;font-size:1.1rem;font-weight:600;color:var(--brown);margin:0 0 6px}
.sp-empty p{font-size:.85rem;margin:0;max-width:38ch;margin-inline:auto;line-height:1.6}

/* Toast */
.sp-toast{position:fixed;bottom:26px;left:50%;transform:translate(-50%,120%);background:linear-gradient(135deg,#4A2C10,#2A1E10);color:#fff;padding:14px 22px;border-radius:14px;box-shadow:0 24px 46px -20px rgba(0,0,0,.75);display:inline-flex;align-items:center;gap:11px;font-size:.88rem;font-weight:600;z-index:500;transition:transform .38s cubic-bezier(.2,.7,.3,1);border:1px solid rgba(232,176,90,.3);max-width:calc(100vw - 40px)}
.sp-toast.is-visible{transform:translate(-50%,0)}
.sp-toast i{font-size:1.2rem;color:var(--gold-3)}
.sp-toast.is-success i{color:#7DD68A}
.sp-toast.is-error i{color:#FF9B7A}

/* Mobile */
.sp-sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(42,30,16,.5);z-index:250;opacity:0;pointer-events:none;transition:opacity .25s ease}
@media (max-width:900px){.sp-sidebar{transform:translateX(-100%)}body.sp-sidebar-open .sp-sidebar{transform:translateX(0)}body.sp-sidebar-open .sp-sidebar-overlay{display:block;opacity:1;pointer-events:auto}.sp-main{margin-left:0}.sp-menu-btn{display:inline-flex}}
@media (max-width:480px){.sp-topbar{padding:12px 16px}.sp-content{padding:16px}.sp-cta span{display:none}.sp-cta{padding:10px 12px}.sp-table thead th,.sp-table tbody td{padding:10px 14px}.sp-panel-head{padding:14px 16px}.sp-chart{padding:18px 16px}.sp-chart-bars{height:150px;gap:8px}}
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
        <a href="<?= BASE_URL ?>seller/reports.php" class="sp-nav-link is-active" data-nav><i class="bi bi-graph-up-arrow"></i> Reports</a>
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
                <h1 class="sp-page-title">Reports</h1>
                <span class="sp-page-sub">Welcome back, <strong><?= $firstName ?></strong>!</span>
            </div>
        </div>
        <div class="sp-topbar-right">
            <button class="sp-icon-btn" id="notifBtn" aria-label="Notifications">
                <i class="bi bi-bell"></i><span class="badge-dot"></span>
            </button>
            <button type="button" class="sp-cta" id="exportBtn">
                <i class="bi bi-download"></i><span>Export CSV</span>
            </button>
        </div>
    </header>

    <main class="sp-content">

        <!-- Toolbar -->
        <form class="sp-toolbar" method="get" action="">
            <div class="sp-preset-tabs">
                <?php
                    $presets = [
                        'all'     => ['All time',  'bi-infinity'],
                        'today'   => ['Today',     'bi-calendar-day'],
                        'week'    => ['This week', 'bi-calendar-week'],
                        'month'   => ['This month','bi-calendar-month'],
                        'quarter' => ['Quarter',   'bi-calendar-range'],
                        'year'    => ['This year', 'bi-calendar-check'],
                    ];
                    foreach ($presets as $k => $meta):
                        $isActive = $preset === $k;
                ?>
                    <a href="?<?= qs(['preset' => $k, 'from' => null, 'to' => null]) ?>"
                       class="sp-preset-tab <?= $isActive ? 'is-active' : '' ?>">
                        <i class="bi <?= $meta[1] ?>"></i>
                        <?= $meta[0] ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="sp-filter-row">
                <div class="sp-field"><i class="bi bi-calendar3"></i><input type="date" name="from" value="" aria-label="From date"></div>
                <div class="sp-field"><i class="bi bi-calendar3"></i><input type="date" name="to" value="" aria-label="To date"></div>
                <div class="sp-field is-select">
                    <i class="bi bi-lightning-charge"></i>
                    <select name="preset" aria-label="Quick range">
                        <?php foreach ($presets as $k => $meta): ?>
                            <option value="<?= $k ?>" <?= $preset === $k ? 'selected' : '' ?>><?= $meta[0] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="sp-btn-primary"><i class="bi bi-funnel"></i> Apply</button>
                <a href="<?= BASE_URL ?>seller/reports.php" class="sp-btn-ghost"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
                <button type="button" class="sp-btn-ghost" id="exportBtn2"><i class="bi bi-download"></i> Export</button>
            </div>
            <p class="sp-range-note">Showing: <strong><?= sanitize($rangeLabel) ?></strong></p>
        </form>

        <!-- KPI cards -->
        <section class="sp-kpi-grid">
            <article class="sp-kpi">
                <div class="sp-kpi-ico"><i class="bi bi-bag-check"></i></div>
                <div class="sp-kpi-label">Total Sales</div>
                <div class="sp-kpi-value"><?= number_format($summary['total_sales']) ?></div>
                <div class="sp-kpi-foot"><i class="bi bi-people"></i> <?= number_format($summary['buyers_count']) ?> buyer<?= $summary['buyers_count'] === 1 ? '' : 's' ?></div>
            </article>
            <article class="sp-kpi">
                <div class="sp-kpi-ico is-info"><i class="bi bi-box-seam"></i></div>
                <div class="sp-kpi-label">Palay Delivered</div>
                <div class="sp-kpi-value"><?= number_format($summary['total_kg'], 0) ?><small>kg</small></div>
                <div class="sp-kpi-foot"><i class="bi bi-tag"></i> Avg <?= peso($summary['avg_price_kg']) ?>/kg</div>
            </article>
            <article class="sp-kpi">
                <div class="sp-kpi-ico is-success"><i class="bi bi-cash-stack"></i></div>
                <div class="sp-kpi-label">Total Earned</div>
                <div class="sp-kpi-value"><?= peso($summary['total_paid']) ?></div>
                <div class="sp-kpi-foot"><i class="bi bi-receipt"></i> <?= number_format($summary['payments_count']) ?> payment<?= $summary['payments_count'] === 1 ? '' : 's' ?></div>
            </article>
            <article class="sp-kpi">
                <div class="sp-kpi-ico is-warn"><i class="bi bi-hourglass-split"></i></div>
                <div class="sp-kpi-label">Outstanding</div>
                <div class="sp-kpi-value"><?= peso($summary['outstanding']) ?></div>
                <div class="sp-kpi-foot"><i class="bi bi-exclamation-circle"></i> Needs follow-up</div>
            </article>
        </section>

        <!-- Main grid -->
        <section class="sp-grid">

            <!-- Monthly chart -->
            <div class="sp-panel">
                <div class="sp-panel-head">
                    <h3 class="sp-panel-title"><i class="bi bi-bar-chart-line"></i> Monthly Palay Volume</h3>
                    <span class="sp-panel-meta"><?= count($monthly) ?> month<?= count($monthly) === 1 ? '' : 's' ?></span>
                </div>
                <div class="sp-chart">
                    <?php $maxDisplay = max(1, $maxKg); ?>
                    <div class="sp-chart-bars">
                        <?php foreach ($monthly as $m):
                            $kgVal  = (float) $m['kg'];
                            $pct    = $maxDisplay > 0 ? ($kgVal / $maxDisplay) * 100 : 0;
                            $pct    = max(4, $pct);
                            $isEmpty = $kgVal <= 0;
                        ?>
                            <div class="sp-chart-col">
                                <div class="sp-chart-col-inner">
                                    <div class="sp-chart-bar <?= $isEmpty ? 'is-empty' : '' ?>" style="height:<?= $pct ?>%">
                                        <div class="sp-chart-bar-tip">
                                            <strong><?= number_format($kgVal, 0) ?> kg</strong>
                                            <?= peso($m['amount']) ?> · <?= (int) $m['count'] ?> sale<?= (int) $m['count'] === 1 ? '' : 's' ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="sp-chart-label"><?= sanitize($m['label']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="sp-chart-axis">
                        <span>Volume (kg)</span>
                        <span>Max: <?= number_format($maxKg, 0) ?> kg</span>
                    </div>
                </div>
            </div>

            <!-- Status breakdown -->
            <div class="sp-panel">
                <div class="sp-panel-head">
                    <h3 class="sp-panel-title"><i class="bi bi-pie-chart"></i> Status Breakdown</h3>
                    <span class="sp-panel-meta"><strong><?= number_format($statusTotal) ?></strong> total</span>
                </div>
                <div class="sp-status-body">
                    <?php
                        $statusLabels = [
                            'paid'    => ['Paid',    'is-paid'],
                            'partial' => ['Partial', 'is-partial'],
                            'unpaid'  => ['Unpaid',  'is-unpaid'],
                            'pending' => ['Pending', 'is-pending'],
                        ];
                        $totalForPct = max(1, $statusTotal);
                        foreach ($statusLabels as $key => $meta):
                            $count = $statusBreakdown[$key] ?? 0;
                            $pct   = ($count / $totalForPct) * 100;
                    ?>
                        <div>
                            <div class="sp-status-row">
                                <span class="sp-status-dot <?= $meta[1] ?>"></span>
                                <span class="sp-status-name"><?= $meta[0] ?></span>
                                <span class="sp-status-value"><?= number_format($count) ?></span>
                            </div>
                            <div class="sp-status-bar <?= $meta[1] ?>">
                                <span style="width:<?= $pct ?>%"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </section>

        <!-- Second grid: top buyers + payment methods -->
        <section class="sp-grid">

            <div class="sp-panel">
                <div class="sp-panel-head">
                    <h3 class="sp-panel-title"><i class="bi bi-people"></i> Top Buyers</h3>
                    <a href="<?= BASE_URL ?>seller/buyers.php" class="sp-panel-meta" style="text-decoration:none;color:var(--gold);font-weight:700" data-nav>Manage <i class="bi bi-arrow-right"></i></a>
                </div>
                <?php if (!empty($topBuyers)): ?>
                    <div class="sp-sellers-body">
                        <?php foreach ($topBuyers as $i => $s): ?>
                            <a href="<?= BASE_URL ?>seller/sales.php?q=<?= urlencode($s['buyer_name']) ?>" class="sp-seller-row" data-nav>
                                <span class="sp-seller-rank"><?= $i + 1 ?></span>
                                <div class="sp-seller-info">
                                    <div class="sp-seller-name"><?= sanitize($s['buyer_name']) ?></div>
                                    <div class="sp-seller-meta">
                                        <?= (int) $s['sales'] ?> sale<?= (int) $s['sales'] === 1 ? '' : 's' ?> ·
                                        <?= number_format((float) $s['kg'], 0) ?> kg
                                    </div>
                                </div>
                                <div class="sp-seller-amount"><?= peso($s['amount']) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="sp-empty" style="padding:40px 24px">
                        <div class="sp-empty-ico" style="width:56px;height:56px;font-size:1.4rem"><i class="bi bi-people"></i></div>
                        <h4>No buyers in this range</h4>
                        <p>Adjust the date range to see buyer activity.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="sp-panel">
                <div class="sp-panel-head">
                    <h3 class="sp-panel-title"><i class="bi bi-credit-card-2-front"></i> Payment Methods</h3>
                    <span class="sp-panel-meta"><strong><?= peso($summary['payments_total']) ?></strong></span>
                </div>
                <?php if (!empty($methodBreakdown)): ?>
                    <div class="sp-method-list">
                        <?php foreach ($methodBreakdown as $m):
                            [$label, $icon, $tone] = methodMeta($m['m']);
                        ?>
                            <div class="sp-method-row">
                                <div class="sp-method-ico <?= $tone ?>"><i class="bi <?= $icon ?>"></i></div>
                                <div class="sp-method-body">
                                    <div class="sp-method-name"><?= sanitize($label) ?></div>
                                    <div class="sp-method-count"><?= (int) $m['c'] ?> payment<?= (int) $m['c'] === 1 ? '' : 's' ?></div>
                                </div>
                                <div class="sp-method-total"><?= peso($m['t']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="sp-empty" style="padding:40px 24px">
                        <div class="sp-empty-ico" style="width:56px;height:56px;font-size:1.4rem"><i class="bi bi-receipt"></i></div>
                        <h4>No payments in this range</h4>
                        <p>Payments you receive will show up here by method.</p>
                    </div>
                <?php endif; ?>
            </div>

        </section>

        <!-- Recent activity -->
        <section class="sp-panel">
            <div class="sp-panel-head">
                <h3 class="sp-panel-title"><i class="bi bi-clock-history"></i> Recent Activity</h3>
                <span class="sp-panel-meta">Latest <?= count($recentSales) + count($recentPayments) ?> events</span>
            </div>

            <?php if (!empty($recentSales) || !empty($recentPayments)): ?>
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Reference</th>
                                <th>Details</th>
                                <th>Amount</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentSales as $s):
                                $st = strtolower($s['status'] ?? 'pending');
                                $b = 'sp-badge-pending'; $ic = 'bi-hourglass-split'; $lb = ucfirst($st);
                                if ($st === 'paid')    { $b = 'sp-badge-paid';    $ic = 'bi-check-circle-fill'; $lb = 'Paid'; }
                                if ($st === 'partial') { $b = 'sp-badge-partial'; $ic = 'bi-circle-half';        $lb = 'Partial'; }
                                if ($st === 'unpaid')  { $b = 'sp-badge-unpaid';  $ic = 'bi-x-circle-fill';     $lb = 'Unpaid'; }
                            ?>
                                <tr>
                                    <td><span class="sp-badge <?= $b ?>"><i class="bi <?= $ic ?>"></i> Sale</span></td>
                                    <td><span class="sp-ref"><?= sanitize($s['reference_no'] ?? '—') ?></span></td>
                                    <td><?= sanitize($s['buyer_name'] ?? '—') ?> · <?= number_format((float) $s['weight_kg'], 0) ?> kg</td>
                                    <td><span class="sp-money"><?= peso($s['total_amount'] ?? 0) ?></span></td>
                                    <td><?= niceDate($s['created_at'] ?? null) ?></td>
                                </tr>
                            <?php endforeach; ?>

                            <?php foreach ($recentPayments as $pay):
                                [$mLbl, $mIco] = methodMeta($pay['method'] ?? 'cash');
                            ?>
                                <tr>
                                    <td><span class="sp-badge sp-badge-paid"><i class="bi <?= $mIco ?>"></i> Payment</span></td>
                                    <td><span class="sp-ref"><?= sanitize($pay['reference_no'] ?? '—') ?></span></td>
                                    <td>via <?= sanitize($mLbl) ?></td>
                                    <td><span class="sp-money" style="color:var(--success)"><?= peso($pay['amount'] ?? 0) ?></span></td>
                                    <td><?= niceDate($pay['paid_at'] ?? null) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="sp-empty">
                    <div class="sp-empty-ico"><i class="bi bi-activity"></i></div>
                    <h4>No activity in this range</h4>
                    <p>Try adjusting the date range, or record a sale to get started.</p>
                </div>
            <?php endif; ?>
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
        toast.classList.add('is-'+type, 'is-visible');
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

    /* CSV export */
    function csvEscape(v) { return '"' + String(v ?? '').replace(/"/g, '""') + '"'; }
    function downloadCSV(filename, rows) {
        const csv = rows.map(r => r.map(csvEscape).join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function exportReport() {
        const rows = [];

        rows.push(['SmartPalay — Seller Report']);
        rows.push(['Generated', new Date().toLocaleString('en-US')]);
        rows.push(['Seller', '<?= addslashes($fullName) ?>']);
        rows.push(['Range', '<?= addslashes($rangeLabel) ?>']);
        rows.push([]);

        rows.push(['=== Summary ===']);
        rows.push(['Metric', 'Value']);
        rows.push(['Total Sales', '<?= (int) $summary['total_sales'] ?>']);
        rows.push(['Palay Delivered (kg)', '<?= number_format($summary['total_kg'], 2, ".", "") ?>']);
        rows.push(['Total Value (PHP)', '<?= number_format($summary['total_amount'], 2, ".", "") ?>']);
        rows.push(['Total Earned (PHP)', '<?= number_format($summary['total_paid'], 2, ".", "") ?>']);
        rows.push(['Outstanding (PHP)', '<?= number_format($summary['outstanding'], 2, ".", "") ?>']);
        rows.push(['Average Price per kg', '<?= number_format($summary['avg_price_kg'], 2, ".", "") ?>']);
        rows.push(['Active Buyers', '<?= (int) $summary['buyers_count'] ?>']);
        rows.push(['Payments Count', '<?= (int) $summary['payments_count'] ?>']);
        rows.push(['Payments Total (PHP)', '<?= number_format($summary['payments_total'], 2, ".", "") ?>']);
        rows.push([]);

        rows.push(['=== Monthly Volume ===']);
        rows.push(['Month', 'Sales', 'Volume (kg)', 'Amount (PHP)']);
        <?php foreach ($monthly as $m): ?>
            rows.push([
                '<?= addslashes($m['label']) ?>',
                '<?= (int) $m['count'] ?>',
                '<?= number_format((float) $m['kg'], 2, ".", "") ?>',
                '<?= number_format((float) $m['amount'], 2, ".", "") ?>',
            ]);
        <?php endforeach; ?>
        rows.push([]);

        rows.push(['=== Top Buyers ===']);
        rows.push(['Buyer', 'Sales', 'Volume (kg)', 'Amount (PHP)', 'Balance (PHP)']);
        <?php foreach ($topBuyers as $s): ?>
            rows.push([
                '<?= addslashes($s['buyer_name']) ?>',
                '<?= (int) $s['sales'] ?>',
                '<?= number_format((float) $s['kg'], 2, ".", "") ?>',
                '<?= number_format((float) $s['amount'], 2, ".", "") ?>',
                '<?= number_format((float) $s['balance'], 2, ".", "") ?>',
            ]);
        <?php endforeach; ?>
        rows.push([]);

        rows.push(['=== Payment Methods ===']);
        rows.push(['Method', 'Count', 'Total (PHP)']);
        <?php foreach ($methodBreakdown as $m): ?>
            rows.push([
                '<?= addslashes($m['m']) ?>',
                '<?= (int) $m['c'] ?>',
                '<?= number_format((float) $m['t'], 2, ".", "") ?>',
            ]);
        <?php endforeach; ?>

        downloadCSV('smartpalay-seller-report-' + new Date().toISOString().slice(0,10) + '.csv', rows);
        showToast('Report exported successfully.', 'success');
    }

    document.getElementById('exportBtn')?.addEventListener('click', exportReport);
    document.getElementById('exportBtn2')?.addEventListener('click', exportReport);
    document.getElementById('notifBtn')?.addEventListener('click', () => showToast('No new notifications right now.', 'success'));
})();
</script>
</body>
</html>