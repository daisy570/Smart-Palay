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
   Filters & pagination
-------------------------------------------------------------- */
$search       = trim((string) ($_GET['q'] ?? ''));
$methodFilter = strtolower(trim((string) ($_GET['method'] ?? 'all')));
$dateFrom     = trim((string) ($_GET['from'] ?? ''));
$dateTo       = trim((string) ($_GET['to'] ?? ''));
$sortKey      = (string) ($_GET['sort'] ?? 'paid_at');
$sortDir      = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

$allowedSorts = [
    'paid_at'      => 'paid_at',
    'reference_no' => 'reference_no',
    'amount'       => 'amount',
    'method'       => 'method',
];
if (!isset($allowedSorts[$sortKey])) $sortKey = 'paid_at';
$orderBy = $allowedSorts[$sortKey] . ' ' . $sortDir;

$perPage = 10;
$page    = max(1, (int) ($_GET['page'] ?? 1));

$where  = ['seller_id = ?'];
$params = [$userId];

if ($search !== '') {
    $where[] = '(reference_no LIKE ? OR purchase_ref LIKE ? OR notes LIKE ?)';
    $like    = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

if ($methodFilter !== 'all' && $methodFilter !== '') {
    $where[]  = 'LOWER(method) = ?';
    $params[] = $methodFilter;
}

if ($dateFrom !== '') { $where[] = 'DATE(paid_at) >= ?'; $params[] = $dateFrom; }
if ($dateTo   !== '') { $where[] = 'DATE(paid_at) <= ?'; $params[] = $dateTo; }

$whereSql = implode(' AND ', $where);

/* --------------------------------------------------------------
   Data
-------------------------------------------------------------- */
$payments   = [];
$totalRows  = 0;
$totalPages = 1;

$summary = ['total_amount'=>0,'count'=>0,'avg_payment'=>0,'this_month'=>0];
$methodCounts = ['cash'=>0,'bank'=>0,'gcash'=>0,'maya'=>0,'check'=>0];

try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(amount), 0) AS total_amount,
            COUNT(*)                 AS count,
            COALESCE(AVG(amount), 0) AS avg_payment
        FROM payments
        WHERE {$whereSql}
    ");
    $stmt->execute($params);
    if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $summary['total_amount'] = (float) $r['total_amount'];
        $summary['count']        = (int) $r['count'];
        $summary['avg_payment']  = (float) $r['avg_payment'];
    }
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM payments
        WHERE seller_id = ? AND MONTH(paid_at) = MONTH(CURRENT_DATE()) AND YEAR(paid_at) = YEAR(CURRENT_DATE())
    ");
    $stmt->execute([$userId]);
    $summary['this_month'] = (float) $stmt->fetchColumn();
} catch (Throwable $e) {}

try {
    // Method counts (unfiltered by method, but scoped to search/date)
    $countWhere  = ['seller_id = ?'];
    $countParams = [$userId];
    if ($search !== '') {
        $countWhere[] = '(reference_no LIKE ? OR purchase_ref LIKE ? OR notes LIKE ?)';
        array_push($countParams, $like, $like, $like);
    }
    if ($dateFrom !== '') { $countWhere[] = 'DATE(paid_at) >= ?'; $countParams[] = $dateFrom; }
    if ($dateTo   !== '') { $countWhere[] = 'DATE(paid_at) <= ?'; $countParams[] = $dateTo; }
    $countWhereSql = implode(' AND ', $countWhere);

    $stmt = $pdo->prepare("SELECT LOWER(method) AS m, COUNT(*) AS c FROM payments WHERE {$countWhereSql} GROUP BY LOWER(method)");
    $stmt->execute($countParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = $r['m'] ?: 'cash';
        if (!isset($methodCounts[$m])) $methodCounts[$m] = 0;
        $methodCounts[$m] += (int) $r['c'];
    }
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE {$whereSql}");
    $stmt->execute($params);
    $totalRows  = (int) $stmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT id, reference_no, purchase_ref, amount, method, notes, paid_at, created_at
        FROM payments
        WHERE {$whereSql}
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $payments = [];
}

/* --------------------------------------------------------------
   Open sales (for linking payments)
-------------------------------------------------------------- */
$openSales = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, reference_no, buyer_name, balance, total_amount
        FROM purchases
        WHERE seller_id = ? AND balance > 0
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$userId]);
    $openSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        case 'cash':          return ['Cash',          'bi-cash-coin',         'is-success'];
        case 'bank':          return ['Bank Transfer', 'bi-bank',              'is-info'];
        case 'gcash':         return ['GCash',         'bi-phone',             'is-info'];
        case 'maya':          return ['Maya',          'bi-phone-fill',        'is-info'];
        case 'check':         return ['Check',         'bi-file-earmark-text', 'is-warn'];
        default:              return [ucfirst($m),     'bi-three-dots',        ''];
    }
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
<title>Payments Received | SmartPalay</title>
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
.sp-method-tabs{display:flex;flex-wrap:wrap;gap:6px}
.sp-method-tab{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:10px;background:var(--cream-2);border:1.5px solid var(--line);color:var(--text-2);font-size:.8rem;font-weight:600;text-decoration:none;transition:border-color .18s ease,background .18s ease,color .18s ease,transform .18s ease}
.sp-method-tab:hover{border-color:var(--line-3);color:var(--brown);transform:translateY(-1px)}
.sp-method-tab.is-active{background:linear-gradient(135deg,var(--gold-soft),#fff);border-color:rgba(176,100,30,.4);color:var(--gold);font-weight:700;box-shadow:0 4px 12px -6px rgba(176,100,30,.4)}
.sp-method-tab .count{padding:1px 8px;border-radius:999px;background:var(--paper-2);color:var(--text-2);font-size:.68rem;font-weight:700;font-family:'JetBrains Mono',monospace}
.sp-method-tab.is-active .count{background:rgba(176,100,30,.15);color:var(--gold)}
.sp-filter-row{display:grid;grid-template-columns:minmax(0,1.5fr) repeat(3,minmax(0,1fr)) auto auto;gap:10px;align-items:stretch}
@media (max-width:1080px){.sp-filter-row{grid-template-columns:1fr 1fr}}
@media (max-width:620px){.sp-filter-row{grid-template-columns:1fr}}
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

/* Panel */
.sp-panel{position:relative;background:#fff;border:1px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:0 1px 0 rgba(255,255,255,.9) inset,0 14px 30px -22px rgba(74,44,16,.4)}
.sp-panel::before{content:'';position:absolute;top:0;left:0;width:56px;height:3px;background:linear-gradient(90deg,var(--gold),var(--gold-3));border-radius:0 3px 3px 0;opacity:.55}
.sp-panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 22px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,#fff,var(--cream-2));flex-wrap:wrap}
.sp-panel-title{display:flex;align-items:center;gap:11px;font-family:'Fraunces',Georgia,serif;font-size:1.06rem;font-weight:600;color:var(--brown);margin:0}
.sp-panel-title i{width:32px;height:32px;border-radius:10px;display:grid;place-items:center;background:var(--gold-soft);color:var(--gold);font-size:.95rem;border:1px solid rgba(176,100,30,.18)}
.sp-panel-meta{font-size:.74rem;color:var(--text-3);font-weight:500}
.sp-panel-meta strong{color:var(--brown);font-weight:700}
.sp-table-wrap{overflow-x:auto}
.sp-table{width:100%;border-collapse:collapse;font-size:.86rem}
.sp-table thead th{text-align:left;padding:12px 18px;font-size:.64rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3);border-bottom:1px solid var(--line);white-space:nowrap;background:var(--cream-2);position:sticky;top:0;z-index:2}
.sp-table thead th a{color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:color .15s ease}
.sp-table thead th a:hover{color:var(--gold)}
.sp-table thead th a i{font-size:.7rem;opacity:.5}
.sp-table thead th a.is-sorted i{opacity:1;color:var(--gold)}
.sp-table tbody td{padding:14px 18px;border-bottom:1px dashed var(--line-2);color:var(--text);vertical-align:middle;white-space:nowrap}
.sp-table tbody tr:last-child td{border-bottom:0}
.sp-table tbody tr{cursor:pointer;transition:background .18s ease}
.sp-table tbody tr:hover{background:var(--gold-soft)}
.sp-ref{font-family:'JetBrains Mono',monospace;font-size:.78rem;font-weight:600;color:var(--brown-2);background:var(--paper-2);border:1px solid var(--line-2);padding:3px 8px;border-radius:5px}
.sp-method-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:999px;font-size:.72rem;font-weight:700;background:var(--paper-2);color:var(--brown-2);border:1px solid var(--line-2);text-transform:capitalize}
.sp-method-chip i{font-size:.85rem;color:var(--gold)}
.sp-method-chip.is-success i{color:var(--success)}
.sp-method-chip.is-info i{color:var(--info)}
.sp-method-chip.is-warn i{color:var(--warn)}
.sp-money{font-family:'Fraunces',Georgia,serif;font-weight:700;color:var(--success);letter-spacing:-.2px;font-size:1rem}
.sp-row-actions{display:inline-flex;align-items:center;gap:4px}
.sp-row-btn{width:30px;height:30px;border-radius:8px;border:1px solid transparent;background:transparent;color:var(--text-3);font-size:.88rem;cursor:pointer;display:inline-grid;place-items:center;transition:background .15s ease,color .15s ease,border-color .15s ease}
.sp-row-btn:hover{background:#fff;border-color:var(--line-2);color:var(--gold)}
.sp-row-btn.is-danger:hover{color:var(--danger);border-color:var(--danger-bd);background:var(--danger-bg)}
.sp-empty{padding:56px 26px;text-align:center;color:var(--text-3)}
.sp-empty-ico{width:76px;height:76px;margin:0 auto 18px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,var(--gold-soft),#fff);color:var(--gold);font-size:1.9rem;border:1px solid rgba(176,100,30,.2);box-shadow:0 10px 24px -12px rgba(176,100,30,.4)}
.sp-empty h4{font-family:'Fraunces',Georgia,serif;font-size:1.15rem;font-weight:600;color:var(--brown);margin:0 0 8px}
.sp-empty p{font-size:.88rem;margin:0 0 20px;max-width:42ch;margin-inline:auto;line-height:1.6}
.sp-empty-actions{display:inline-flex;gap:10px;flex-wrap:wrap;justify-content:center}

/* Pagination */
.sp-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 18px;border-top:1px solid var(--line);background:var(--cream-2)}
.sp-pagination-info{font-size:.78rem;color:var(--text-3);font-weight:500}
.sp-pagination-info strong{color:var(--brown);font-weight:700}
.sp-pagination-list{display:inline-flex;align-items:center;gap:4px}
.sp-page-link{min-width:36px;height:36px;padding:0 10px;border-radius:9px;border:1.5px solid var(--line-2);background:#fff;color:var(--brown-2);font-size:.82rem;font-weight:600;display:inline-grid;place-items:center;text-decoration:none;cursor:pointer;transition:border-color .15s ease,color .15s ease,transform .15s ease,background .15s ease}
.sp-page-link:hover{border-color:var(--gold);color:var(--gold);transform:translateY(-1px)}
.sp-page-link.is-active{background:linear-gradient(135deg,var(--gold),var(--gold-2));border-color:transparent;color:#fff;box-shadow:0 6px 14px -6px rgba(176,100,30,.7)}
.sp-page-link.is-disabled{opacity:.45;pointer-events:none}
.sp-page-dots{padding:0 4px;color:var(--text-3);font-weight:700}

/* Modals */
.sp-modal-backdrop{position:fixed;inset:0;background:rgba(42,30,16,.6);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:400;display:none;align-items:center;justify-content:center;padding:20px;opacity:0;transition:opacity .25s ease;overflow:hidden}
.sp-modal-backdrop.is-open{display:flex;opacity:1}
.sp-modal{background:#fff;border-radius:22px;width:100%;max-width:620px;max-height:calc(100vh - 40px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 46px 100px -34px rgba(40,22,6,.8);transform:scale(.95) translateY(10px);transition:transform .3s cubic-bezier(.2,.7,.3,1);min-height:0}
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
.sp-form-input.is-select{position:relative}
.sp-form-input.is-select select{appearance:none;-webkit-appearance:none;cursor:pointer}
.sp-form-input.is-select::after{content:'';width:7px;height:7px;border-right:2px solid var(--text-3);border-bottom:2px solid var(--text-3);transform:rotate(45deg) translate(-6px,-3px);pointer-events:none;margin-right:14px}
.sp-form-hint{font-size:.7rem;color:var(--text-3);font-weight:500;padding-left:2px}
.sp-form-summary{padding:14px 16px;border-radius:12px;background:linear-gradient(135deg,var(--success-bg),#fff);border:1px dashed var(--success-bd);display:flex;align-items:center;justify-content:space-between;font-size:.86rem}
.sp-form-summary-label{display:inline-flex;align-items:center;gap:8px;color:var(--text-2);font-weight:600}
.sp-form-summary-label i{color:var(--success);font-size:1rem}
.sp-form-summary-value{font-family:'Fraunces',Georgia,serif;font-size:1.3rem;font-weight:700;color:var(--success)}
.sp-btn-submit{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 22px;border-radius:11px;border:0;background:linear-gradient(135deg,var(--gold),var(--gold-2));color:#fff;font-weight:700;font-size:.87rem;cursor:pointer;font-family:inherit;box-shadow:0 3px 0 rgba(0,0,0,.12),0 14px 24px -12px rgba(176,100,30,.75);transition:transform .15s ease,filter .15s ease}
.sp-btn-submit:hover{transform:translateY(-1px);filter:brightness(1.04)}
.sp-btn-submit:disabled{opacity:.6;cursor:not-allowed;transform:none}
.sp-btn-cancel{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:12px 20px;border-radius:11px;border:1.5px solid var(--line-2);background:#fff;color:var(--brown-2);font-weight:600;font-size:.87rem;cursor:pointer;font-family:inherit;transition:border-color .15s ease,color .15s ease}
.sp-btn-cancel:hover{border-color:var(--gold);color:var(--gold)}
.sp-btn-danger{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:11px;border:1.5px solid var(--danger-bd);background:var(--danger-bg);color:var(--danger);font-weight:700;font-size:.85rem;cursor:pointer;font-family:inherit;margin-right:auto;transition:background .15s ease,color .15s ease}
.sp-btn-danger:hover{background:var(--danger);color:#fff}

/* Detail view */
.sp-detail-hero{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 20px;border-radius:14px;background:linear-gradient(135deg,var(--success-bg),#fff);border:1px solid var(--success-bd);flex-wrap:wrap}
.sp-detail-hero-label{font-size:.64rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3);margin-bottom:4px}
.sp-detail-hero-value{font-family:'Fraunces',Georgia,serif;font-size:1.55rem;font-weight:700;color:var(--success);letter-spacing:-.5px;line-height:1.1}
.sp-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:520px){.sp-detail-grid{grid-template-columns:1fr}}
.sp-detail-item{padding:12px 14px;border-radius:12px;background:var(--cream-2);border:1px solid var(--line)}
.sp-detail-item.is-full{grid-column:1/-1}
.sp-detail-label{font-size:.62rem;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text-3);margin-bottom:4px}
.sp-detail-value{font-size:.92rem;font-weight:600;color:var(--brown);line-height:1.4;word-break:break-word}
.sp-detail-value.is-mono{font-family:'JetBrains Mono',monospace;font-size:.84rem}
.sp-detail-value.is-muted{color:var(--text-3);font-weight:500}

/* Toast */
.sp-toast{position:fixed;bottom:26px;left:50%;transform:translate(-50%,120%);background:linear-gradient(135deg,#4A2C10,#2A1E10);color:#fff;padding:14px 22px;border-radius:14px;box-shadow:0 24px 46px -20px rgba(0,0,0,.75);display:inline-flex;align-items:center;gap:11px;font-size:.88rem;font-weight:600;z-index:500;transition:transform .38s cubic-bezier(.2,.7,.3,1);border:1px solid rgba(232,176,90,.3);max-width:calc(100vw - 40px)}
.sp-toast.is-visible{transform:translate(-50%,0)}
.sp-toast i{font-size:1.2rem;color:var(--gold-3)}
.sp-toast.is-success i{color:#7DD68A}
.sp-toast.is-error i{color:#FF9B7A}

/* Mobile */
.sp-sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(42,30,16,.5);z-index:250;opacity:0;pointer-events:none;transition:opacity .25s ease}
@media (max-width:900px){.sp-sidebar{transform:translateX(-100%)}body.sp-sidebar-open .sp-sidebar{transform:translateX(0)}body.sp-sidebar-open .sp-sidebar-overlay{display:block;opacity:1;pointer-events:auto}.sp-main{margin-left:0}.sp-menu-btn{display:inline-flex}}
@media (max-width:480px){.sp-topbar{padding:12px 16px}.sp-content{padding:16px}.sp-cta span{display:none}.sp-cta{padding:10px 12px}.sp-table thead th,.sp-table tbody td{padding:10px 14px}.sp-panel-head{padding:14px 16px}.sp-modal{max-height:calc(100vh - 20px)}.sp-modal-body{padding:18px}.sp-modal-foot{padding:12px 18px 16px}}
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
        <a href="<?= BASE_URL ?>seller/payments.php" class="sp-nav-link is-active" data-nav><i class="bi bi-cash-coin"></i> Payments Received</a>
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
            <button class="sp-menu-btn" id="menuBtn" aria-label="Open menu"><i class="bi bi-list"></i></button>
            <div>
                <h1 class="sp-page-title">Payments Received</h1>
                <span class="sp-page-sub">Welcome back, <strong><?= $firstName ?></strong>!</span>
            </div>
        </div>
        <div class="sp-topbar-right">
            <button class="sp-icon-btn" id="notifBtn" aria-label="Notifications">
                <i class="bi bi-bell"></i><span class="badge-dot"></span>
            </button>
            <button type="button" class="sp-cta" data-modal="newPayment">
                <i class="bi bi-plus-lg"></i><span>Record Payment</span>
            </button>
        </div>
    </header>

    <main class="sp-content">

        <!-- Toolbar -->
        <form class="sp-toolbar" method="get" action="">
            <?php if ($sortKey !== 'paid_at' || $sortDir !== 'DESC'): ?>
                <input type="hidden" name="sort" value="<?= sanitize($sortKey) ?>">
                <input type="hidden" name="dir"  value="<?= strtolower($sortDir) ?>">
            <?php endif; ?>

            <div class="sp-method-tabs">
                <?php
                    $tabs = ['all' => ['All', array_sum($methodCounts), 'bi-grid']];
                    $commonMethods = [
                        'cash'  => ['Cash',  'bi-cash-coin'],
                        'bank'  => ['Bank',  'bi-bank'],
                        'gcash' => ['GCash', 'bi-phone'],
                        'check' => ['Check', 'bi-file-earmark-text'],
                    ];
                    foreach ($commonMethods as $k => $meta) {
                        $tabs[$k] = [$meta[0], $methodCounts[$k] ?? 0, $meta[1]];
                    }
                    foreach ($methodCounts as $k => $c) {
                        if (!isset($tabs[$k])) {
                            $tabs[$k] = [ucfirst($k), $c, 'bi-three-dots'];
                        }
                    }
                    foreach ($tabs as $key => $meta):
                        $isActive = ($methodFilter === $key || ($key === 'all' && $methodFilter === 'all'));
                ?>
                    <a href="?<?= qs(['method' => $key === 'all' ? null : $key, 'page' => 1]) ?>"
                       class="sp-method-tab <?= $isActive ? 'is-active' : '' ?>">
                        <i class="bi <?= $meta[2] ?>"></i>
                        <?= $meta[0] ?>
                        <span class="count"><?= (int) $meta[1] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="sp-filter-row">
                <div class="sp-field">
                    <i class="bi bi-search"></i>
                    <input type="search" name="q" value="<?= sanitize($search) ?>" placeholder="Search by reference, linked sale, notes…">
                </div>
                <div class="sp-field">
                    <i class="bi bi-calendar3"></i>
                    <input type="date" name="from" value="<?= sanitize($dateFrom) ?>" aria-label="From date">
                </div>
                <div class="sp-field">
                    <i class="bi bi-calendar3"></i>
                    <input type="date" name="to" value="<?= sanitize($dateTo) ?>" aria-label="To date">
                </div>
                <div class="sp-field is-select">
                    <i class="bi bi-sort-down"></i>
                    <select name="sort" aria-label="Sort by">
                        <?php
                            $sortOptions = [
                                'paid_at'      => 'Newest first',
                                'reference_no' => 'Reference',
                                'amount'       => 'Amount',
                                'method'       => 'Method',
                            ];
                            foreach ($sortOptions as $k => $label):
                        ?>
                            <option value="<?= $k ?>" <?= $sortKey === $k ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="sp-btn-primary"><i class="bi bi-funnel"></i> Apply</button>
                <a href="<?= BASE_URL ?>seller/payments.php" class="sp-btn-ghost"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
            </div>
        </form>

        <!-- Summary -->
        <section class="sp-summary-strip">
            <div class="sp-summary-card">
                <div class="sp-summary-ico is-success"><i class="bi bi-cash-stack"></i></div>
                <div class="sp-summary-body">
                    <div class="sp-summary-label">Total Received</div>
                    <div class="sp-summary-value"><?= peso($summary['total_amount']) ?></div>
                </div>
            </div>
            <div class="sp-summary-card">
                <div class="sp-summary-ico is-info"><i class="bi bi-calendar-check"></i></div>
                <div class="sp-summary-body">
                    <div class="sp-summary-label">This Month</div>
                    <div class="sp-summary-value"><?= peso($summary['this_month']) ?></div>
                </div>
            </div>
            <div class="sp-summary-card">
                <div class="sp-summary-ico"><i class="bi bi-receipt"></i></div>
                <div class="sp-summary-body">
                    <div class="sp-summary-label">Payments</div>
                    <div class="sp-summary-value"><?= number_format($summary['count']) ?></div>
                </div>
            </div>
            <div class="sp-summary-card">
                <div class="sp-summary-ico is-warn"><i class="bi bi-calculator"></i></div>
                <div class="sp-summary-body">
                    <div class="sp-summary-label">Average</div>
                    <div class="sp-summary-value"><?= peso($summary['avg_payment']) ?></div>
                </div>
            </div>
        </section>

        <!-- Payments table -->
        <section class="sp-panel">
            <div class="sp-panel-head">
                <h3 class="sp-panel-title"><i class="bi bi-list-ul"></i> Payment Records</h3>
                <span class="sp-panel-meta">
                    Showing <strong><?= count($payments) ?></strong> of <strong><?= number_format($totalRows) ?></strong>
                </span>
            </div>

            <?php if (!empty($payments)): ?>
                <div class="sp-table-wrap">
                    <table class="sp-table">
                        <thead>
                            <tr>
                                <?php
                                    $colSorts = [
                                        'reference_no' => 'Reference',
                                        'method'       => 'Method',
                                        'amount'       => 'Amount',
                                        'paid_at'      => 'Date',
                                    ];
                                    foreach ($colSorts as $k => $label):
                                        $active  = $sortKey === $k;
                                        $nextDir = ($active && $sortDir === 'ASC') ? 'desc' : 'asc';
                                        $iconCls = $active ? ($sortDir === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down') : 'bi-arrow-down-up';
                                ?>
                                    <th>
                                        <a href="?<?= qs(['sort' => $k, 'dir' => $nextDir, 'page' => 1]) ?>"
                                           class="<?= $active ? 'is-sorted' : '' ?>">
                                            <?= $label ?>
                                            <i class="bi <?= $iconCls ?>"></i>
                                        </a>
                                    </th>
                                <?php endforeach; ?>
                                <th>Linked Sale</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <?php [$mLabel, $mIco, $mTone] = methodMeta($p['method'] ?? 'cash'); ?>
                                <tr data-payment='<?= htmlspecialchars(json_encode([
                                    'id'           => (int) $p['id'],
                                    'reference_no' => $p['reference_no'] ?? '',
                                    'purchase_ref' => $p['purchase_ref'] ?? '',
                                    'amount'       => (float) $p['amount'],
                                    'method'       => strtolower($p['method'] ?? 'cash'),
                                    'notes'        => $p['notes'] ?? '',
                                    'paid_at'      => $p['paid_at'] ?? '',
                                ]), ENT_QUOTES, 'UTF-8') ?>'>
                                    <td><span class="sp-ref"><?= sanitize($p['reference_no'] ?? '—') ?></span></td>
                                    <td>
                                        <span class="sp-method-chip <?= $mTone ?>">
                                            <i class="bi <?= $mIco ?>"></i> <?= sanitize($mLabel) ?>
                                        </span>
                                    </td>
                                    <td><span class="sp-money"><?= peso($p['amount'] ?? 0) ?></span></td>
                                    <td><?= niceDate($p['paid_at'] ?? null) ?></td>
                                    <td>
                                        <?php if (!empty($p['purchase_ref'])): ?>
                                            <span class="sp-ref"><?= sanitize($p['purchase_ref']) ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--text-3);font-size:.84rem">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <div class="sp-row-actions" onclick="event.stopPropagation();">
                                            <button type="button" class="sp-row-btn" title="View details" data-action="view" data-id="<?= (int) $p['id'] ?>"><i class="bi bi-eye"></i></button>
                                            <button type="button" class="sp-row-btn" title="Edit" data-action="edit" data-id="<?= (int) $p['id'] ?>"><i class="bi bi-pencil"></i></button>
                                            <button type="button" class="sp-row-btn is-danger" title="Delete" data-action="delete" data-id="<?= (int) $p['id'] ?>"><i class="bi bi-trash3"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="sp-pagination">
                        <span class="sp-pagination-info">
                            Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
                        </span>
                        <div class="sp-pagination-list">
                            <a href="?<?= qs(['page' => max(1, $page - 1)]) ?>" class="sp-page-link <?= $page <= 1 ? 'is-disabled' : '' ?>"><i class="bi bi-chevron-left"></i></a>
                            <?php
                                $window = 2;
                                $start  = max(1, $page - $window);
                                $end    = min($totalPages, $page + $window);
                                if ($start > 1) {
                                    echo '<a href="?' . qs(['page' => 1]) . '" class="sp-page-link">1</a>';
                                    if ($start > 2) echo '<span class="sp-page-dots">…</span>';
                                }
                                for ($i = $start; $i <= $end; $i++) {
                                    $active = $i === $page ? 'is-active' : '';
                                    echo '<a href="?' . qs(['page' => $i]) . '" class="sp-page-link ' . $active . '">' . $i . '</a>';
                                }
                                if ($end < $totalPages) {
                                    if ($end < $totalPages - 1) echo '<span class="sp-page-dots">…</span>';
                                    echo '<a href="?' . qs(['page' => $totalPages]) . '" class="sp-page-link">' . $totalPages . '</a>';
                                }
                            ?>
                            <a href="?<?= qs(['page' => min($totalPages, $page + 1)]) ?>" class="sp-page-link <?= $page >= $totalPages ? 'is-disabled' : '' ?>"><i class="bi bi-chevron-right"></i></a>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="sp-empty">
                    <div class="sp-empty-ico"><i class="bi bi-receipt"></i></div>
                    <h4>
                        <?php if ($search !== '' || $methodFilter !== 'all' || $dateFrom || $dateTo): ?>
                            No payments match your filters
                        <?php else: ?>
                            No payments yet
                        <?php endif; ?>
                    </h4>
                    <p>
                        <?php if ($search !== '' || $methodFilter !== 'all' || $dateFrom || $dateTo): ?>
                            Try adjusting your search or clearing some filters.
                        <?php else: ?>
                            Payments you receive against your sales will appear here.
                        <?php endif; ?>
                    </p>
                    <div class="sp-empty-actions">
                        <?php if ($search !== '' || $methodFilter !== 'all' || $dateFrom || $dateTo): ?>
                            <a href="<?= BASE_URL ?>seller/payments.php" class="sp-btn-ghost"><i class="bi bi-arrow-counterclockwise"></i> Clear filters</a>
                        <?php endif; ?>
                        <button type="button" class="sp-btn-primary" data-modal="newPayment"><i class="bi bi-plus-lg"></i> Record Payment</button>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<!-- NEW PAYMENT MODAL -->
<div class="sp-modal-backdrop" id="modalNewPayment">
    <div class="sp-modal">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal><i class="bi bi-x-lg"></i></button>
            <span class="sp-modal-tag"><i class="bi bi-plus-circle"></i> New Payment</span>
            <h3 class="sp-modal-title">Record a Payment Received</h3>
            <p class="sp-modal-sub">Log a payment received from a buyer. Optionally link it to a sale.</p>
        </div>

        <form id="newPaymentForm" autocomplete="off" style="display:contents">
            <div class="sp-modal-body">
                <div class="sp-form-row">
                    <div class="sp-form-field is-full">
                        <label for="npSale">Link to Sale (optional)</label>
                        <div class="sp-form-input is-select">
                            <i class="bi bi-link-45deg"></i>
                            <select id="npSale" name="purchase_ref">
                                <option value="">— No linked sale —</option>
                                <?php foreach ($openSales as $op): ?>
                                    <option value="<?= sanitize($op['reference_no']) ?>" data-balance="<?= (float) $op['balance'] ?>">
                                        <?= sanitize($op['reference_no']) ?> — <?= sanitize($op['buyer_name']) ?> (bal: <?= peso($op['balance']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sp-form-hint">Only sales with an outstanding balance are listed.</div>
                    </div>

                    <div class="sp-form-field">
                        <label for="npAmount">Amount <span class="req">*</span></label>
                        <div class="sp-form-input"><i class="bi bi-cash-stack"></i><input type="number" id="npAmount" name="amount" min="0.01" step="0.01" placeholder="0.00" required></div>
                        <div class="sp-form-hint">In pesos (₱)</div>
                    </div>

                    <div class="sp-form-field">
                        <label for="npMethod">Method <span class="req">*</span></label>
                        <div class="sp-form-input is-select">
                            <i class="bi bi-credit-card-2-front"></i>
                            <select id="npMethod" name="method" required>
                                <option value="" selected disabled>— Select method —</option>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="gcash">GCash</option>
                                <option value="maya">Maya</option>
                                <option value="check">Check</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="sp-form-field">
                        <label for="npDate">Payment Date</label>
                        <div class="sp-form-input"><i class="bi bi-calendar3"></i><input type="date" id="npDate" name="paid_at"></div>
                    </div>

                    <div class="sp-form-field">
                        <label for="npRef">Reference No. (optional)</label>
                        <div class="sp-form-input"><i class="bi bi-hash"></i><input type="text" id="npRef" name="reference_no" placeholder="Auto-generated if blank"></div>
                    </div>

                    <div class="sp-form-field is-full">
                        <label for="npNotes">Notes (optional)</label>
                        <div class="sp-form-input"><i class="bi bi-chat-left-text"></i><textarea id="npNotes" name="notes" placeholder="e.g. Partial payment for last week's delivery…"></textarea></div>
                    </div>
                </div>

                <div class="sp-form-summary">
                    <span class="sp-form-summary-label"><i class="bi bi-cash-coin"></i> Payment Amount</span>
                    <span class="sp-form-summary-value" id="npTotal">₱0.00</span>
                </div>
            </div>
            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-cancel" data-close-modal><i class="bi bi-x-lg"></i> Cancel</button>
                <button type="submit" class="sp-btn-submit" id="npSubmit"><i class="bi bi-check-lg"></i> Save Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW MODAL -->
<div class="sp-modal-backdrop" id="modalView">
    <div class="sp-modal">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal><i class="bi bi-x-lg"></i></button>
            <span class="sp-modal-tag"><i class="bi bi-eye"></i> Payment Details</span>
            <h3 class="sp-modal-title" id="vwTitle">Payment Record</h3>
            <p class="sp-modal-sub" id="vwSub">Loading…</p>
        </div>
        <div class="sp-modal-body" id="vwBody"></div>
        <div class="sp-modal-foot">
            <button type="button" class="sp-btn-cancel" data-close-modal><i class="bi bi-x-lg"></i> Close</button>
            <button type="button" class="sp-btn-submit" id="vwEditBtn"><i class="bi bi-pencil"></i> Edit</button>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<div class="sp-modal-backdrop" id="modalEdit">
    <div class="sp-modal">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal><i class="bi bi-x-lg"></i></button>
            <span class="sp-modal-tag"><i class="bi bi-pencil"></i> Edit Payment</span>
            <h3 class="sp-modal-title">Update Payment</h3>
            <p class="sp-modal-sub">Adjust the details below and save your changes.</p>
        </div>

        <form id="editPaymentForm" autocomplete="off" style="display:contents">
            <input type="hidden" id="edId">
            <div class="sp-modal-body">
                <div class="sp-form-row">
                    <div class="sp-form-field is-full">
                        <label for="edSale">Link to Sale (optional)</label>
                        <div class="sp-form-input is-select">
                            <i class="bi bi-link-45deg"></i>
                            <select id="edSale" name="purchase_ref">
                                <option value="">— No linked sale —</option>
                                <?php foreach ($openSales as $op): ?>
                                    <option value="<?= sanitize($op['reference_no']) ?>"><?= sanitize($op['reference_no']) ?> — <?= sanitize($op['buyer_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="sp-form-field">
                        <label for="edAmount">Amount <span class="req">*</span></label>
                        <div class="sp-form-input"><i class="bi bi-cash-stack"></i><input type="number" id="edAmount" min="0.01" step="0.01" required></div>
                    </div>
                    <div class="sp-form-field">
                        <label for="edMethod">Method <span class="req">*</span></label>
                        <div class="sp-form-input is-select">
                            <i class="bi bi-credit-card-2-front"></i>
                            <select id="edMethod" required>
                                <option value="" disabled>— Select method —</option>
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="gcash">GCash</option>
                                <option value="maya">Maya</option>
                                <option value="check">Check</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="sp-form-field">
                        <label for="edDate">Payment Date</label>
                        <div class="sp-form-input"><i class="bi bi-calendar3"></i><input type="date" id="edDate"></div>
                    </div>
                    <div class="sp-form-field is-full">
                        <label for="edNotes">Notes</label>
                        <div class="sp-form-input"><i class="bi bi-chat-left-text"></i><textarea id="edNotes"></textarea></div>
                    </div>
                </div>
                <div class="sp-form-summary">
                    <span class="sp-form-summary-label"><i class="bi bi-cash-coin"></i> Payment Amount</span>
                    <span class="sp-form-summary-value" id="edTotal">₱0.00</span>
                </div>
            </div>
            <div class="sp-modal-foot">
                <button type="button" class="sp-btn-danger" id="edDeleteBtn"><i class="bi bi-trash3"></i> Delete</button>
                <button type="button" class="sp-btn-cancel" data-close-modal><i class="bi bi-x-lg"></i> Cancel</button>
                <button type="submit" class="sp-btn-submit" id="edSubmit"><i class="bi bi-check-lg"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="sp-modal-backdrop" id="modalDelete">
    <div class="sp-modal" style="max-width:460px">
        <div class="sp-modal-head">
            <button type="button" class="sp-modal-close" data-close-modal><i class="bi bi-x-lg"></i></button>
            <span class="sp-modal-tag" style="background:rgba(162,58,26,.2);border-color:rgba(162,58,26,.4);color:#FFB199">
                <i class="bi bi-exclamation-triangle"></i> Delete
            </span>
            <h3 class="sp-modal-title" id="dlTitle">Delete this payment?</h3>
            <p class="sp-modal-sub">This action cannot be undone.</p>
        </div>
        <div class="sp-modal-body">
            <div class="sp-detail-hero" style="background:linear-gradient(135deg,var(--danger-bg),#fff);border-color:var(--danger-bd)">
                <div>
                    <div class="sp-detail-hero-label">Reference</div>
                    <div class="sp-detail-hero-value" id="dlRef" style="font-size:1.15rem;color:var(--danger)">—</div>
                </div>
                <div style="text-align:right">
                    <div class="sp-detail-hero-label">Amount</div>
                    <div class="sp-detail-hero-value" id="dlAmount" style="font-size:1.15rem;color:var(--danger)">—</div>
                </div>
            </div>
        </div>
        <div class="sp-modal-foot">
            <button type="button" class="sp-btn-cancel" data-close-modal><i class="bi bi-x-lg"></i> Cancel</button>
            <button type="button" class="sp-btn-submit" id="dlConfirmBtn" style="background:linear-gradient(135deg,#A23A1A,#C4481F)"><i class="bi bi-trash3"></i> Delete Payment</button>
        </div>
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
    const modalNew = document.getElementById('modalNewPayment');
    const modalView = document.getElementById('modalView');
    const modalEdit = document.getElementById('modalEdit');
    const modalDelete = document.getElementById('modalDelete');
    const modals = { newPayment: modalNew, view: modalView, edit: modalEdit, delete: modalDelete };

    function openModal(m) { if (!m) return; m.classList.add('is-open'); body.style.overflow = 'hidden'; }
    function closeModal(m) {
        if (!m) return;
        m.classList.remove('is-open');
        if (!document.querySelector('.sp-modal-backdrop.is-open')) body.style.overflow = '';
    }

    document.querySelectorAll('[data-modal]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            if (btn.dataset.modal === 'newPayment') resetNewPaymentForm();
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
    function pesoFmt(n) { n = Number(n)||0; return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function esc(str) { return String(str ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
    function methodMetaJS(m) {
        m = (m || 'cash').toLowerCase();
        const map = {
            cash:  { label: 'Cash',          icon: 'bi-cash-coin',         tone: 'is-success' },
            bank:  { label: 'Bank Transfer', icon: 'bi-bank',              tone: 'is-info' },
            gcash: { label: 'GCash',         icon: 'bi-phone',             tone: 'is-info' },
            maya:  { label: 'Maya',          icon: 'bi-phone-fill',        tone: 'is-info' },
            check: { label: 'Check',         icon: 'bi-file-earmark-text', tone: 'is-warn' },
        };
        return map[m] || { label: m.charAt(0).toUpperCase() + m.slice(1), icon: 'bi-three-dots', tone: '' };
    }

    /* New Payment form */
    const npForm    = document.getElementById('newPaymentForm');
    const npSale    = document.getElementById('npSale');
    const npAmount  = document.getElementById('npAmount');
    const npMethod  = document.getElementById('npMethod');
    const npDate    = document.getElementById('npDate');
    const npRef     = document.getElementById('npRef');
    const npNotes   = document.getElementById('npNotes');
    const npTotal   = document.getElementById('npTotal');
    const npSubmit  = document.getElementById('npSubmit');

    function recalcNew() {
        const amt = parseFloat(npAmount?.value) || 0;
        npTotal.textContent = pesoFmt(amt);
    }
    npAmount?.addEventListener('input', recalcNew);

    npSale?.addEventListener('change', () => {
        const opt = npSale.options[npSale.selectedIndex];
        const balance = parseFloat(opt?.dataset?.balance);
        if (!isNaN(balance) && balance > 0 && !npAmount.value) {
            npAmount.value = balance.toFixed(2);
            recalcNew();
        }
    });

    function resetNewPaymentForm() {
        npForm?.reset();
        // Method and Payment Date are intentionally left blank on reset.
        recalcNew();
        modalNew.querySelector('.sp-modal-body').scrollTop = 0;
    }

    npForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
            purchase_ref: npSale.value || '',
            amount:       parseFloat(npAmount.value) || 0,
            method:       npMethod.value || '',
            paid_at:      npDate.value || '',
            reference_no: npRef.value.trim() || '',
            notes:        npNotes.value || '',
        };
        if (payload.amount <= 0) { showToast('Please enter a valid payment amount.', 'error'); npAmount.focus(); return; }
        if (!payload.method)    { showToast('Please select a payment method.', 'error'); npMethod.focus(); return; }

        npSubmit.disabled = true;
        const orig = npSubmit.innerHTML;
        npSubmit.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…';
        try {
            const res = await fetch('<?= BASE_URL ?>seller/save_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.success) {
                showToast('Payment recorded successfully!', 'success');
                closeModal(modalNew);
                setTimeout(() => window.location.reload(), 700);
            } else {
                showToast(data.message || 'Could not save payment.', 'error');
            }
        } catch (err) {
            showToast('Network error. Please try again.', 'error');
        } finally {
            npSubmit.disabled = false;
            npSubmit.innerHTML = orig;
        }
    });

    /* Row clicks / actions */
    let currentPayment = null;

    document.querySelectorAll('tr[data-payment]').forEach(row => {
        const data = JSON.parse(row.dataset.payment);
        row.addEventListener('click', (e) => { if (!e.target.closest('.sp-row-actions')) openView(data); });
    });

    document.querySelectorAll('[data-action]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const row = btn.closest('tr[data-payment]');
            if (!row) return;
            const data = JSON.parse(row.dataset.payment);
            const action = btn.dataset.action;
            if (action === 'view')   openView(data);
            if (action === 'edit')   openEdit(data);
            if (action === 'delete') openDelete(data);
        });
    });

    /* View modal */
    const vwTitle   = document.getElementById('vwTitle');
    const vwSub     = document.getElementById('vwSub');
    const vwBody    = document.getElementById('vwBody');
    const vwEditBtn = document.getElementById('vwEditBtn');

    function openView(p) {
        currentPayment = p;
        const mm = methodMetaJS(p.method);
        vwTitle.textContent = p.reference_no || 'Payment Record';
        vwSub.textContent = `${mm.label} · ${p.paid_at ? new Date(p.paid_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—'}`;
        vwBody.innerHTML = `
            <div class="sp-detail-hero">
                <div>
                    <div class="sp-detail-hero-label">Amount Received</div>
                    <div class="sp-detail-hero-value">${pesoFmt(p.amount)}</div>
                </div>
                <span class="sp-method-chip ${mm.tone}" style="font-size:.78rem;padding:6px 14px">
                    <i class="bi ${mm.icon}"></i> ${mm.label}
                </span>
            </div>
            <div class="sp-detail-grid">
                <div class="sp-detail-item"><div class="sp-detail-label">Reference</div><div class="sp-detail-value is-mono">${esc(p.reference_no || '—')}</div></div>
                <div class="sp-detail-item"><div class="sp-detail-label">Payment Date</div><div class="sp-detail-value">${p.paid_at ? new Date(p.paid_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—'}</div></div>
                <div class="sp-detail-item"><div class="sp-detail-label">Method</div><div class="sp-detail-value">${mm.label}</div></div>
                <div class="sp-detail-item"><div class="sp-detail-label">Linked Sale</div><div class="sp-detail-value is-mono">${esc(p.purchase_ref || '—')}</div></div>
                ${p.notes ? `<div class="sp-detail-item is-full"><div class="sp-detail-label">Notes</div><div class="sp-detail-value is-muted" style="font-weight:500;font-size:.86rem;white-space:pre-wrap">${esc(p.notes)}</div></div>` : ''}
            </div>
        `;
        openModal(modalView);
    }

    vwEditBtn?.addEventListener('click', () => {
        closeModal(modalView);
        if (currentPayment) setTimeout(() => openEdit(currentPayment), 220);
    });

    /* Edit modal */
    const edForm   = document.getElementById('editPaymentForm');
    const edId     = document.getElementById('edId');
    const edSale   = document.getElementById('edSale');
    const edAmount = document.getElementById('edAmount');
    const edMethod = document.getElementById('edMethod');
    const edDate   = document.getElementById('edDate');
    const edNotes  = document.getElementById('edNotes');
    const edTotal  = document.getElementById('edTotal');
    const edSubmit = document.getElementById('edSubmit');

    function recalcEdit() { edTotal.textContent = pesoFmt(parseFloat(edAmount?.value) || 0); }
    edAmount?.addEventListener('input', recalcEdit);

    function openEdit(p) {
        currentPayment = p;
        edId.value = p.id;
        edAmount.value = p.amount || '';
        // Method — select the saved value if present, otherwise leave blank.
        edMethod.value = (p.method || '').toLowerCase();
        // Payment Date — populate from the existing payment if present,
        // otherwise leave the field blank (no auto-filled "today").
        edDate.value = p.paid_at ? p.paid_at.slice(0,10) : '';
        edNotes.value = p.notes || '';
        if (p.purchase_ref && ![...edSale.options].some(o => o.value === p.purchase_ref)) {
            const opt = document.createElement('option');
            opt.value = p.purchase_ref;
            opt.textContent = p.purchase_ref;
            edSale.appendChild(opt);
        }
        edSale.value = p.purchase_ref || '';
        recalcEdit();
        modalEdit.querySelector('.sp-modal-body').scrollTop = 0;
        openModal(modalEdit);
    }

    edForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
            id:           parseInt(edId.value, 10),
            purchase_ref: edSale.value || '',
            amount:       parseFloat(edAmount.value) || 0,
            method:       edMethod.value || '',
            paid_at:      edDate.value || '',
            notes:        edNotes.value || '',
        };
        if (payload.amount <= 0) { showToast('Please enter a valid payment amount.', 'error'); return; }
        if (!payload.method)    { showToast('Please select a payment method.', 'error'); return; }

        edSubmit.disabled = true;
        const orig = edSubmit.innerHTML;
        edSubmit.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…';
        try {
            const res = await fetch('<?= BASE_URL ?>seller/update_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.success) {
                showToast('Payment updated!', 'success');
                closeModal(modalEdit);
                setTimeout(() => window.location.reload(), 700);
            } else {
                showToast(data.message || 'Could not update payment.', 'error');
            }
        } catch (err) {
            showToast('Network error. Please try again.', 'error');
        } finally {
            edSubmit.disabled = false;
            edSubmit.innerHTML = orig;
        }
    });

    /* Delete flow */
    const dlRef = document.getElementById('dlRef');
    const dlAmount = document.getElementById('dlAmount');
    const dlConfirmBtn = document.getElementById('dlConfirmBtn');
    const edDeleteBtn = document.getElementById('edDeleteBtn');

    function openDelete(p) {
        currentPayment = p;
        dlRef.textContent = p.reference_no || '—';
        dlAmount.textContent = pesoFmt(p.amount);
        openModal(modalDelete);
    }

    edDeleteBtn?.addEventListener('click', () => {
        closeModal(modalEdit);
        if (currentPayment) setTimeout(() => openDelete(currentPayment), 220);
    });

    dlConfirmBtn?.addEventListener('click', async () => {
        if (!currentPayment) return;
        dlConfirmBtn.disabled = true;
        const orig = dlConfirmBtn.innerHTML;
        dlConfirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Deleting…';
        try {
            const res = await fetch('<?= BASE_URL ?>seller/delete_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: currentPayment.id })
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.success) {
                showToast('Payment deleted.', 'success');
                closeModal(modalDelete);
                setTimeout(() => window.location.reload(), 700);
            } else {
                showToast(data.message || 'Could not delete payment.', 'error');
            }
        } catch (err) {
            showToast('Network error. Please try again.', 'error');
        } finally {
            dlConfirmBtn.disabled = false;
            dlConfirmBtn.innerHTML = orig;
        }
    });

    document.getElementById('notifBtn')?.addEventListener('click', () => showToast('No new notifications right now.', 'success'));
})();
</script>
</body>
</html>