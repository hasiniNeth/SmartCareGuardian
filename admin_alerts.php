<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php"); exit();
}
include 'db_connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/SmartCareGuardian/includes/ai_service.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_alert'])) {
    $stmt = $conn->prepare("UPDATE alerts SET resolved=1 WHERE alert_id=?");
    $stmt->bind_param("i", $_POST['alert_id']);
    $stmt->execute();
    header("Location: admin_alerts.php"); exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_all'])) {
    $conn->query("UPDATE alerts SET resolved=1 WHERE resolved=0");
    header("Location: admin_alerts.php"); exit();
}

$res_stmt = $conn->query("
    SELECT u.user_id, u.full_name, u.email, u.status,
           hl.blood_pressure_systolic, hl.blood_pressure_diastolic,
           hl.blood_sugar, hl.pulse, hl.weight,
           hl.temperature, hl.oxygen_saturation, hl.logged_at AS last_log,
           r.phone, r.gender, r.dob, r.blood_type,
           r.emergency_contact, r.medical_conditions, r.allergies,
           r.dietary_restrictions, r.primary_physician,
           cu.full_name AS caregiver_name, c.phone AS caregiver_phone
    FROM users u
    LEFT JOIN (
        SELECT resident_id,
               blood_pressure_systolic, blood_pressure_diastolic,
               blood_sugar, pulse, weight, temperature, oxygen_saturation, logged_at,
               ROW_NUMBER() OVER (PARTITION BY resident_id ORDER BY logged_at DESC) AS rn
        FROM health_logs
    ) hl ON hl.resident_id = u.user_id AND hl.rn = 1
    LEFT JOIN residents r   ON r.user_id  = u.user_id
    LEFT JOIN (SELECT resident_id, caregiver_id FROM caregiver_assignments GROUP BY resident_id) ca
              ON ca.resident_id = u.user_id
    LEFT JOIN users cu      ON cu.user_id = ca.caregiver_id
    LEFT JOIN caregivers c  ON c.user_id  = ca.caregiver_id
    WHERE u.role = 'resident' AND u.status = 'active'
    GROUP BY u.user_id ORDER BY u.full_name
");
$residents_data = $res_stmt->fetch_all(MYSQLI_ASSOC);

$alerts_stmt = $conn->query("
    SELECT a.*, u.full_name AS resident_name,
           DATE_FORMAT(a.created_at,'%Y-%m-%d %h:%i %p') AS formatted_time
    FROM alerts a JOIN users u ON a.resident_id = u.user_id
    ORDER BY a.created_at DESC
");
$db_alerts  = $alerts_stmt->fetch_all(MYSQLI_ASSOC);
$unresolved = array_filter($db_alerts, fn($a) => !$a['resolved']);
$alert_residents = [];
foreach ($db_alerts as $al) $alert_residents[$al['resident_id']] = $al['resident_name'];

$ai        = new AIService($conn);
$ai_online = $ai->checkStatus()['success'] ?? false;
$predictions = [];

foreach ($residents_data as $r) {
    $rid = $r['user_id'];
    if (!$r['last_log']) continue;
    $vitals = [
        'blood_pressure_systolic'  => !empty($r['blood_pressure_systolic'])  ? (float)$r['blood_pressure_systolic']  : 120.0,
        'blood_pressure_diastolic' => !empty($r['blood_pressure_diastolic']) ? (float)$r['blood_pressure_diastolic'] : 80.0,
        'blood_sugar'              => !empty($r['blood_sugar'])               ? (float)$r['blood_sugar']               : 100.0,
        'pulse'                    => !empty($r['pulse'])                     ? (float)$r['pulse']                     : 72.0,
        'weight'                   => !empty($r['weight'])                    ? (float)$r['weight']                    : 65.0,
        'temperature'              => !empty($r['temperature'])               ? (float)$r['temperature']               : 36.6,
        'oxygen_saturation'        => !empty($r['oxygen_saturation'])         ? (float)$r['oxygen_saturation']         : 98.0,
        'logged_at'                => $r['last_log'],
    ];
    if ($ai_online) {
        $resp = $ai->getPrediction($rid, $vitals);
        if ($resp['success'] && isset($resp['data']['data']['prediction'])) {
            $predictions[$rid] = $resp['data']['data'];
            $ai->savePrediction($rid, $resp['data']['data']['prediction'], $resp['data']['data']['alerts'],
                $vitals, false, $resp['data']['data']['model_used'] ?? 'AI Model');
        } else {
            $predictions[$rid] = buildFallback($vitals);
            $ai->savePrediction($rid, $predictions[$rid]['prediction'], $predictions[$rid]['alerts'], $vitals, true, 'Rule-Based Fallback');
        }
    } else {
        $predictions[$rid] = buildFallback($vitals);
        $ai->savePrediction($rid, $predictions[$rid]['prediction'], $predictions[$rid]['alerts'], $vitals, true, 'Rule-Based Fallback');
    }
}

function buildFallback(array $v): array {
    $score = 0; $al = [];
    if ($v['blood_pressure_systolic'] > 140)    { $score+=2; $al[]=['vital_sign'=>'BP Systolic','value'=>$v['blood_pressure_systolic'],'unit'=>'mmHg','status'=>'HIGH','normal_range'=>'90-140 mmHg']; }
    elseif ($v['blood_pressure_systolic'] < 90) { $score+=2; $al[]=['vital_sign'=>'BP Systolic','value'=>$v['blood_pressure_systolic'],'unit'=>'mmHg','status'=>'LOW', 'normal_range'=>'90-140 mmHg']; }
    if ($v['blood_sugar'] > 180)     { $score+=2; $al[]=['vital_sign'=>'Blood Sugar','value'=>$v['blood_sugar'],'unit'=>'mg/dL','status'=>'HIGH','normal_range'=>'70-180 mg/dL']; }
    elseif ($v['blood_sugar'] < 70)  { $score+=2; $al[]=['vital_sign'=>'Blood Sugar','value'=>$v['blood_sugar'],'unit'=>'mg/dL','status'=>'LOW', 'normal_range'=>'70-180 mg/dL']; }
    if ($v['temperature'] > 37.8)    { $score+=1; $al[]=['vital_sign'=>'Temperature','value'=>$v['temperature'],'unit'=>'°C','status'=>'HIGH','normal_range'=>'36-37.8°C']; }
    elseif ($v['temperature'] < 36)  { $score+=1; $al[]=['vital_sign'=>'Temperature','value'=>$v['temperature'],'unit'=>'°C','status'=>'LOW', 'normal_range'=>'36-37.8°C']; }
    if ($v['oxygen_saturation'] < 95){ $score+=2; $al[]=['vital_sign'=>'Oxygen Sat.','value'=>$v['oxygen_saturation'],'unit'=>'%','status'=>'LOW','normal_range'=>'95-100%']; }
    $pct   = min($score * 20, 95);
    $level = $pct >= 60 ? 'high' : ($pct >= 30 ? 'medium' : 'low');
    return [
        'prediction' => ['risk_level'=>$level,'risk_percentage'=>$pct,'risk_prediction'=>(int)($level!=='low'),'risk_probability'=>$pct/100],
        'alerts'     => ['total_alerts'=>count($al),'alerts'=>$al,'requires_attention'=>count($al)>0],
    ];
}

$risk_counts  = ['high'=>0,'medium'=>0,'low'=>0,'no_data'=>0];
$trends       = [];
$resident_ids = array_column($residents_data, 'user_id');
foreach ($residents_data as $r) {
    $p = $predictions[$r['user_id']] ?? null;
    $risk_counts[$p ? $p['prediction']['risk_level'] : 'no_data']++;
}
foreach ($resident_ids as $rid) {
    $trends[$rid] = $ai->getTrend((int)$rid, 14);
}

$profile_js_data = [];
foreach ($residents_data as $r) {
    $rid  = $r['user_id'];
    $pred = $predictions[$rid] ?? null;
    $profile_js_data[$rid] = [
        'name' => $r['full_name'], 'email' => $r['email'] ?? '--', 'phone' => $r['phone'] ?? '--',
        'gender' => $r['gender'] ? ucfirst($r['gender']) : '--',
        'dob' => (!empty($r['dob']) && $r['dob']!=='0000-00-00') ? date('d M Y',strtotime($r['dob'])) : '--',
        'blood_type' => $r['blood_type'] ?? '--', 'emergency_contact' => $r['emergency_contact'] ?? '--',
        'medical_conditions' => $r['medical_conditions'] ?? '--', 'allergies' => $r['allergies'] ?? '--',
        'dietary' => $r['dietary_restrictions'] ?? '--', 'physician' => $r['primary_physician'] ?? '--',
        'caregiver_name' => $r['caregiver_name'] ?? null, 'caregiver_phone' => $r['caregiver_phone'] ?? '--',
        'last_log' => $r['last_log'] ? date('d M Y, H:i',strtotime($r['last_log'])) : null,
        'bp' => ($r['blood_pressure_systolic']&&$r['blood_pressure_diastolic']) ? $r['blood_pressure_systolic'].'/'.$r['blood_pressure_diastolic'] : '--',
        'sugar' => $r['blood_sugar']??'--', 'pulse' => $r['pulse']??'--',
        'temp' => $r['temperature']??'--', 'o2' => $r['oxygen_saturation']??'--', 'weight' => $r['weight']??'--',
        'risk_level' => $pred ? $pred['prediction']['risk_level'] : 'no_data',
        'risk_pct'   => $pred ? $pred['prediction']['risk_percentage'] : 0,
        'alerts'     => $pred ? $pred['alerts']['alerts'] : [],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Health Alerts — SmartCare Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════
   SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
   Health Alerts · Professional scale (15px base)
═══════════════════════════════════════════════════════════ */
:root{
    --s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;
    --s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;
    --s600:#4A6E30;--s700:#365220;--s800:#243816;
    --w50:#FDFAF5;--w100:#F7F1E5;
    --st300:#B8B0A4;--st500:#7A7268;--st700:#4A4540;
    --green-bg:#DDEFD8;--green-text:#3A6830;
    --amber-bg:#FDF3DC;--amber-text:#7A5520;
    --red-bg:#F5DADA;--red-text:#6A2020;
    --blue-bg:#DCE8F5;--blue-text:#1A3A5C;
    --radius-sm:8px;--radius-md:12px;--radius-lg:20px;
    --shadow-soft:0 2px 12px rgba(36,56,22,.07),0 1px 3px rgba(36,56,22,.05);
    --shadow-card:0 4px 24px rgba(36,56,22,.09),0 1px 4px rgba(36,56,22,.06);
}
*,*::before,*::after{box-sizing:border-box;}
body{
    font-family:'Outfit',sans-serif;font-size:15px;line-height:1.6;
    background:var(--w50);
    background-image:
        radial-gradient(ellipse 70% 50% at 90% 0%,rgba(157,192,126,.09) 0%,transparent 55%),
        radial-gradient(ellipse 50% 40% at 0% 100%,rgba(122,166,88,.06) 0%,transparent 50%);
    color:var(--st700);min-height:100vh;margin:0;padding:0;
}
h1,h2,h3,h4,h5,h6{font-family:'Cormorant Garamond',serif;color:var(--s800);margin:0;}

/* ── Sidebar ── */
.sidebar{width:240px;height:100vh;position:fixed;background:var(--s800);display:flex;flex-direction:column;z-index:1000;overflow:hidden;}
.sidebar::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:radial-gradient(ellipse 120% 60% at 50% -10%,rgba(157,192,126,.18) 0%,transparent 60%),radial-gradient(ellipse 80% 80% at 110% 110%,rgba(94,138,64,.15) 0%,transparent 55%);}
.sb-hdr{padding:22px 18px 16px;border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0;position:relative;}
.brand-mark{display:flex;align-items:center;gap:9px;margin-bottom:4px;}
.brand-icon{width:32px;height:32px;background:linear-gradient(135deg,var(--s300),var(--s500));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;color:white;box-shadow:0 3px 10px rgba(0,0,0,.25);flex-shrink:0;}
.sb-hdr h4{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:white;line-height:1.15;}
.sb-hdr small{font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.08em;text-transform:uppercase;font-weight:500;display:block;margin-left:41px;margin-top:1px;}
.sb-nav{flex:1;overflow-y:auto;padding:8px 0;position:relative;}
.sb-nav::-webkit-scrollbar{width:4px;}.sb-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:3px;}
.sidebar a{display:flex;align-items:center;gap:9px;padding:10px 10px 10px 20px;color:rgba(255,255,255,.6);text-decoration:none;font-size:13.5px;font-weight:500;transition:all .2s;margin:1px 8px;border-radius:var(--radius-sm);position:relative;min-height:42px;}
.sidebar a:hover{background:rgba(255,255,255,.10);color:white;}
.sidebar a.active{background:rgba(157,192,126,.2);color:#C8E6A0;}
.sidebar a.active::before{content:'';position:absolute;left:-8px;top:20%;bottom:20%;width:3px;background:var(--s300);border-radius:0 3px 3px 0;}
.sidebar i{width:18px;text-align:center;font-size:13px;opacity:.85;flex-shrink:0;}
.sb-ftr{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 8px;position:relative;}
.sb-ftr a{margin:0;color:rgba(255,255,255,.5)!important;font-size:13.5px;}
.sb-ftr a:hover{color:rgba(255,255,255,.8)!important;}

/* ── Layout ── */
.content{margin-left:240px;padding:24px;min-height:100vh;}

/* ── Topbar ── */
.topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);margin-bottom:22px;border:1px solid rgba(196,217,180,.3);}
.topbar h4{font-family:'Outfit',sans-serif;font-weight:700;font-size:18px;color:var(--s800);margin-bottom:3px;}
.topbar p{font-size:13px;color:var(--st300);margin:0;}
.logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;}
.logout-btn:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(139,58,58,.35);}

/* ── Stats grid ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{background:white;border-radius:var(--radius-lg);padding:18px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.25);transition:transform .2s;text-align:center;}
.stat-card:hover{transform:translateY(-3px);}
.stat-icon{width:48px;height:48px;border-radius:var(--radius-md);margin:0 auto 11px;display:flex;align-items:center;justify-content:center;font-size:18px;}
.ic-total  {background:linear-gradient(135deg,var(--s500),var(--s800));color:white;}
.ic-high   {background:linear-gradient(135deg,#C05050,#7A1A1A);color:white;}
.ic-medium {background:linear-gradient(135deg,#C89040,#7A5520);color:white;}
.ic-low    {background:linear-gradient(135deg,var(--s400),var(--s700));color:white;}
.ic-pending{background:linear-gradient(135deg,#C07070,#8B3A3A);color:white;}
.ic-res    {background:linear-gradient(135deg,var(--s300),var(--s600));color:white;}
.stat-number{font-size:28px;font-weight:800;color:var(--s800);line-height:1;font-family:'Outfit',sans-serif;}
.stat-label{color:var(--st500);font-weight:600;font-size:12px;margin-top:3px;text-transform:uppercase;letter-spacing:.04em;}

/* ── Section cards ── */
.sc{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);margin-bottom:24px;overflow:hidden;border:1px solid rgba(196,217,180,.3);}
.sc-hdr{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:15px 22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;position:relative;overflow:hidden;}
.sc-hdr::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
.sc-hdr h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;position:relative;}
.sc-hdr span{position:relative;}
.sc-body{padding:22px;}

/* ── Filter bar ── */
.filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:20px;}
.ftab{padding:6px 16px;border-radius:var(--radius-md);font-size:12px;font-weight:700;cursor:pointer;border:2px solid transparent;transition:all .2s;background:white;font-family:'Outfit',sans-serif;}
.ftab[data-risk="all"]    {border-color:var(--st300);color:var(--st500);}
.ftab[data-risk="high"]   {border-color:#C05050;color:#C05050;}
.ftab[data-risk="medium"] {border-color:#C89040;color:#C89040;}
.ftab[data-risk="low"]    {border-color:var(--s500);color:var(--s500);}
.ftab[data-risk="no_data"]{border-color:var(--st300);color:var(--st300);}
.ftab.active[data-risk="all"]    {background:var(--st500);color:white;border-color:var(--st500);}
.ftab.active[data-risk="high"]   {background:#C05050;color:white;}
.ftab.active[data-risk="medium"] {background:#C89040;color:white;}
.ftab.active[data-risk="low"]    {background:var(--s500);color:white;border-color:var(--s500);}
.ftab.active[data-risk="no_data"]{background:var(--st300);color:white;}
.search-box{flex:1;min-width:200px;max-width:280px;}
.search-box input{border:2px solid var(--s100);border-radius:var(--radius-md);padding:6px 14px;font-size:13px;width:100%;outline:none;transition:border .2s;font-family:'Outfit',sans-serif;background:var(--w50);color:var(--st700);}
.search-box input:focus{border-color:var(--s400);}

/* ── Resident card ── */
.res-card{background:white;border-radius:var(--radius-md);box-shadow:var(--shadow-soft);margin-bottom:14px;overflow:hidden;border-left:5px solid var(--st300);transition:transform .2s,box-shadow .2s;border:1px solid var(--s100);border-left-width:5px;}
.res-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-card);}
.res-card[data-risk="high"]  {border-left-color:#C05050;}
.res-card[data-risk="medium"]{border-left-color:#C89040;}
.res-card[data-risk="low"]   {border-left-color:var(--s500);}
.res-card-header{display:flex;align-items:center;gap:13px;padding:13px 18px;cursor:pointer;user-select:none;}
.res-avatar{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:white;flex-shrink:0;}
.res-avatar.high  {background:linear-gradient(135deg,#C05050,#7A1A1A);}
.res-avatar.medium{background:linear-gradient(135deg,#C89040,#7A5520);}
.res-avatar.low   {background:linear-gradient(135deg,var(--s400),var(--s700));}
.res-avatar.no_data{background:linear-gradient(135deg,var(--st300),var(--st500));}
.res-name{font-weight:700;color:var(--s800);font-size:14px;}
.res-meta{font-size:12px;color:var(--st300);}
.res-badge{padding:4px 12px;border-radius:var(--radius-md);font-size:12px;font-weight:700;color:white;font-family:'Outfit',sans-serif;text-transform:uppercase;letter-spacing:.04em;}
.res-badge.high  {background:#C05050;}.res-badge.medium{background:#C89040;}
.res-badge.low   {background:var(--s500);}.res-badge.no_data{background:var(--st300);}
.mini-bar-wrap{flex:1;max-width:155px;}
.mini-bar-track{height:7px;background:var(--s100);border-radius:6px;overflow:hidden;}
.mini-bar-fill{height:100%;border-radius:6px;transition:width 1s ease;}
.mini-bar-fill.high  {background:linear-gradient(90deg,#C05050,#7A1A1A);}
.mini-bar-fill.medium{background:linear-gradient(90deg,#C89040,#7A5520);}
.mini-bar-fill.low   {background:linear-gradient(90deg,var(--s400),var(--s700));}
.mini-bar-fill.no_data{background:var(--st300);}
.mini-bar-label{display:flex;justify-content:space-between;font-size:11px;color:var(--st300);margin-bottom:3px;}

/* ── Expanded detail ── */
.res-detail{display:none;padding:0 18px 16px;border-top:1px solid var(--s50);}
.res-detail.open{display:block;}
.vital-row{display:flex;align-items:center;gap:10px;padding:7px 10px;background:var(--s50);border-radius:var(--radius-sm);margin-bottom:5px;font-size:13px;}
.vital-row.flagged{background:var(--red-bg);}
.vital-row i{width:16px;text-align:center;font-size:12px;}
.ai-chip{display:inline-flex;align-items:center;gap:4px;background:var(--red-bg);color:var(--red-text);border-radius:var(--radius-sm);padding:4px 9px;font-size:12px;font-weight:700;margin:2px;}
.ai-chip.medium{background:var(--amber-bg);color:var(--amber-text);}

/* ── Trend chart ── */
.trend-wrap{padding:6px 18px 14px;}
.trend-label{font-size:11px;color:var(--st300);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;}
.no-trend{font-size:13px;color:var(--st300);padding:6px 0;}

/* ── History panel ── */
.hist-panel{padding:0 18px 16px;border-top:1px solid var(--s100);background:var(--s50);}
.hist-btn{font-size:12px;padding:5px 13px;border-radius:var(--radius-md);background:transparent;border:2px solid var(--s400);color:var(--s600);font-weight:700;cursor:pointer;transition:all .2s;font-family:'Outfit',sans-serif;}
.hist-btn:hover{background:var(--s500);color:white;border-color:var(--s500);}
.hist-table{width:100%;border-collapse:separate;border-spacing:0 3px;font-size:13px;}
.hist-table thead th{padding:7px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--s600);font-weight:700;border-bottom:2px solid var(--s100);}
.hist-table tbody tr{background:white;box-shadow:0 1px 4px rgba(36,56,22,.06);}
.hist-table tbody td{padding:8px 10px;vertical-align:middle;}
.hist-table tbody td:first-child{border-radius:var(--radius-sm) 0 0 var(--radius-sm);}
.hist-table tbody td:last-child{border-radius:0 var(--radius-sm) var(--radius-sm) 0;}
.rlvl{display:inline-block;padding:3px 9px;border-radius:var(--radius-md);font-size:11px;font-weight:700;text-transform:uppercase;}
.rlvl.high  {background:var(--red-bg);color:var(--red-text);}
.rlvl.medium{background:var(--amber-bg);color:var(--amber-text);}
.rlvl.low   {background:var(--green-bg);color:var(--green-text);}
.esc-badge{background:var(--red-bg);color:var(--red-text);border-radius:var(--radius-sm);padding:2px 7px;font-size:11px;font-weight:700;margin-left:4px;}
.fb-badge{background:var(--s50);color:var(--st500);border-radius:var(--radius-sm);padding:2px 7px;font-size:11px;margin-left:4px;}

/* ── Action notice ── */
.action-notice{padding:9px 14px;border-radius:var(--radius-sm);display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;margin:0 18px 12px;}
.action-notice.high  {background:var(--red-bg);color:var(--red-text);}
.action-notice.medium{background:var(--amber-bg);color:var(--amber-text);}

/* ── Card footer ── */
.res-footer{display:flex;align-items:center;justify-content:space-between;padding:9px 18px;background:var(--s50);border-top:1px solid var(--s100);font-size:12px;color:var(--st500);}
.res-footer a,.res-footer button{color:var(--s600);font-weight:700;text-decoration:none;font-size:12px;background:none;border:none;cursor:pointer;padding:0;font-family:'Outfit',sans-serif;}
.res-footer a:hover,.res-footer button:hover{text-decoration:underline;}

/* ── Alert cards ── */
.alert-card{background:white;border-radius:var(--radius-md);padding:16px 18px;margin-bottom:12px;box-shadow:var(--shadow-soft);border:1px solid var(--s100);border-left:5px solid var(--red-text);transition:all .2s;}
.alert-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-card);}
.alert-card.resolved{opacity:.65;border-left-color:var(--s200);}
.alert-type-badge{display:inline-block;padding:3px 10px;border-radius:var(--radius-md);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
.badge-health_warning{background:var(--green-bg);color:var(--green-text);}
.badge-critical      {background:var(--red-bg);color:var(--red-text);}
.badge-warning       {background:var(--amber-bg);color:var(--amber-text);}
.badge-ai_risk       {background:#EDE8F5;color:#4A2878;}
.resident-pill{background:var(--s50);color:var(--s700);padding:3px 10px;border-radius:var(--radius-md);font-size:11px;font-weight:600;border:1px solid var(--s100);}
.time-badge{background:var(--w100);color:var(--st500);padding:2px 8px;border-radius:var(--radius-sm);font-size:11px;display:inline-block;margin-top:4px;}
.btn-resolve{background:linear-gradient(135deg,var(--s500),var(--s800));border:none;border-radius:var(--radius-md);padding:6px 15px;font-size:12px;font-weight:700;color:white;transition:all .2s;cursor:pointer;font-family:'Outfit',sans-serif;}
.btn-resolve:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(94,138,64,.3);}
.btn-resolve-all{background:linear-gradient(135deg,var(--s500),var(--s800));border:none;border-radius:var(--radius-md);padding:10px 24px;font-weight:700;font-size:14px;color:white;transition:all .2s;cursor:pointer;font-family:'Outfit',sans-serif;}
.btn-resolve-all:hover{transform:translateY(-2px);box-shadow:0 5px 16px rgba(94,138,64,.35);}
.alert-filter-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:18px;}
.alert-filter-bar select,.alert-filter-bar input{border:2px solid var(--s100);border-radius:var(--radius-md);padding:6px 13px;font-size:13px;outline:none;transition:border .2s;font-family:'Outfit',sans-serif;background:var(--w50);color:var(--st700);}
.alert-filter-bar select:focus,.alert-filter-bar input:focus{border-color:var(--s400);}

/* ── Empty / no-results ── */
.no-results{text-align:center;padding:38px;color:var(--st300);display:none;}
.empty-state{text-align:center;padding:48px;color:var(--st300);}

/* ── Spinner ── */
.spin-sm{width:18px;height:18px;border:2px solid var(--s100);border-top-color:var(--s500);border-radius:50%;animation:sp .7s linear infinite;display:inline-block;}
@keyframes sp{to{transform:rotate(360deg);}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
.pulse{animation:pulse 2s infinite;}
@keyframes floating{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.floating{animation:floating 3s ease-in-out infinite;}
@media(max-width:768px){.sidebar{width:100%;height:auto;position:relative;}.content{margin-left:0;padding:15px;}}
</style>
</head>
<body>

<!-- ══ SIDEBAR ════════════════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sb-hdr">
        <div class="brand-mark">
            <div class="brand-icon"><i class="fas fa-leaf"></i></div>
            <h4>SmartCare<br>Guardian</h4>
        </div>
        <small>Admin Panel</small>
    </div>
    <div class="sb-nav">
        <a href="admin_dashboard.php"><i class="fa-solid fa-gauge-high"></i>Dashboard</a>
        <a href="manage_users.php"><i class="fa-solid fa-users"></i>Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i>Manage Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i>Manage Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i>Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i>Manage Services</a>
        <a href="messages.php"><i class="fa-solid fa-envelope"></i>Messages</a>
        <a href="#" class="active"><i class="fa-solid fa-bell"></i>Health Alerts</a>
        <a href="admin_reports.php"><i class="fa-solid fa-chart-line"></i>Reports & Analytics</a>
    </div>
    <div class="sb-ftr">
        <a href="/SmartCareGuardian/logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <!-- Topbar -->
    <div class="topbar d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4>Health Alerts &amp; AI Risk Monitor</h4>
            <p>All residents — AI risk assessment and threshold-based health alerts</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span style="font-size:13px;color:var(--st500);">
                <?php if($ai_online): ?>
                    <i class="fas fa-circle pulse me-1" style="color:var(--s500);font-size:10px;"></i>AI Online
                <?php else: ?>
                    <i class="fas fa-circle me-1" style="color:var(--red-text);font-size:10px;"></i>AI Offline
                <?php endif; ?>
            </span>
            <form action="/SmartCareGuardian/logout.php" method="POST">
                <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt me-2"></i>Logout</button>
            </form>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon ic-total"><i class="fas fa-users"></i></div><div class="stat-number"><?= count($residents_data) ?></div><div class="stat-label">Total Residents</div></div>
        <div class="stat-card"><div class="stat-icon ic-high"><i class="fas fa-circle-exclamation"></i></div><div class="stat-number"><?= $risk_counts['high'] ?></div><div class="stat-label">High AI Risk</div></div>
        <div class="stat-card"><div class="stat-icon ic-medium"><i class="fas fa-triangle-exclamation"></i></div><div class="stat-number"><?= $risk_counts['medium'] ?></div><div class="stat-label">Medium AI Risk</div></div>
        <div class="stat-card"><div class="stat-icon ic-low"><i class="fas fa-circle-check"></i></div><div class="stat-number"><?= $risk_counts['low'] ?></div><div class="stat-label">Low AI Risk</div></div>
        <div class="stat-card"><div class="stat-icon ic-pending"><i class="fas fa-bell"></i></div><div class="stat-number"><?= count($unresolved) ?></div><div class="stat-label">Active Alerts</div></div>
        <div class="stat-card"><div class="stat-icon ic-res"><i class="fas fa-check-double"></i></div><div class="stat-number"><?= count($db_alerts)-count($unresolved) ?></div><div class="stat-label">Resolved</div></div>
    </div>

    <!-- ═══ AI RISK SECTION ══════════════════════════════════════════ -->
    <div class="sc">
        <div class="sc-hdr">
            <h5><i class="fas fa-brain me-2"></i>AI Risk Assessment — All Residents</h5>
            <span style="font-size:12px;opacity:.8;">Click a resident card to expand · Risk history saved to database</span>
        </div>
        <div class="sc-body">
        <?php if(empty($residents_data)): ?>
            <div class="empty-state"><i class="fas fa-users-slash fa-3x mb-3 floating d-block" style="color:var(--s200);"></i><p>No active residents found.</p></div>
        <?php else: ?>
            <div class="filter-bar">
                <button class="ftab active" data-risk="all">All (<?= count($residents_data) ?>)</button>
                <button class="ftab" data-risk="high"><i class="fas fa-fire me-1"></i>High (<?= $risk_counts['high'] ?>)</button>
                <button class="ftab" data-risk="medium"><i class="fas fa-bolt me-1"></i>Medium (<?= $risk_counts['medium'] ?>)</button>
                <button class="ftab" data-risk="low"><i class="fas fa-leaf me-1"></i>Low (<?= $risk_counts['low'] ?>)</button>
                <button class="ftab" data-risk="no_data">No Data (<?= $risk_counts['no_data'] ?>)</button>
                <div class="search-box ms-auto">
                    <input type="text" id="residentSearch" placeholder="🔍  Search resident name...">
                </div>
            </div>

            <div id="residentsList">
            <?php foreach($residents_data as $r):
                $rid       = $r['user_id'];
                $pred      = $predictions[$rid] ?? null;
                $level     = $pred ? $pred['prediction']['risk_level'] : 'no_data';
                $pct       = $pred ? $pred['prediction']['risk_percentage'] : 0;
                $ai_alerts = $pred ? $pred['alerts']['alerts'] : [];
                $flagged   = []; foreach($ai_alerts as $a) $flagged[$a['vital_sign']] = $a['status'];
                $initials  = strtoupper(substr($r['full_name'],0,1));
                if(strpos($r['full_name'],' ')!==false) $initials .= strtoupper(substr(strrchr($r['full_name'],' '),1,1));
                $lvl_labels= ['high'=>'High Risk','medium'=>'Medium Risk','low'=>'Low Risk','no_data'=>'No Data'];
                $trend_data = json_encode($trends[$rid] ?? []);
                $has_trend  = count($trends[$rid] ?? []) >= 2;
            ?>
            <div class="res-card" data-risk="<?= $level ?>" data-name="<?= strtolower($r['full_name']) ?>">
                <div class="res-card-header" onclick="toggleDetail(<?= $rid ?>)">
                    <div class="res-avatar <?= $level ?>"><?= $initials ?></div>
                    <div style="flex:1;">
                        <div class="res-name"><?= htmlspecialchars($r['full_name']) ?></div>
                        <div class="res-meta">
                            <?php if($r['last_log']): ?><i class="fas fa-clock me-1"></i><?= date('d M Y, H:i',strtotime($r['last_log'])) ?>
                            <?php else: ?><i class="fas fa-minus-circle me-1"></i>No readings logged<?php endif; ?>
                        </div>
                    </div>
                    <?php if($r['last_log']): ?>
                    <div class="mini-bar-wrap">
                        <div class="mini-bar-label"><span>AI Risk</span><span><?= $pct ?>%</span></div>
                        <div class="mini-bar-track"><div class="mini-bar-fill <?= $level ?>" style="width:<?= $pct ?>%"></div></div>
                    </div>
                    <?php endif; ?>
                    <span class="res-badge <?= $level ?> ms-2"><?= $lvl_labels[$level] ?></span>
                    <i class="fas fa-chevron-down ms-3" id="chev-<?= $rid ?>" style="transition:transform .3s;color:var(--st300);font-size:12px;"></i>
                </div>

                <div class="res-detail" id="detail-<?= $rid ?>">
                    <?php if($r['last_log']): ?>
                    <div class="row g-3 mt-1">
                        <div class="col-md-4">
                            <small style="font-weight:700;color:var(--s600);text-transform:uppercase;letter-spacing:.05em;font-size:11px;">Latest Vitals</small>
                            <?php $vd=[
                                ['Blood Pressure Systolic',$r['blood_pressure_systolic'].'/'.$r['blood_pressure_diastolic'],'mmHg','fa-heart-pulse','BP'],
                                ['Blood Sugar',$r['blood_sugar'],'mg/dL','fa-droplet','Sugar'],
                                ['Pulse',$r['pulse'],'bpm','fa-wave-square','Pulse'],
                                ['Temperature',$r['temperature'],'°C','fa-thermometer-half','Temp'],
                                ['Oxygen Sat.',$r['oxygen_saturation'],'%','fa-lungs','O₂'],
                                ['Weight',$r['weight'],'kg','fa-weight-scale','Wt'],
                            ];
                            foreach($vd as [$key,$val,$unit,$ico,$lbl]):
                                $f=isset($flagged[$key]); ?>
                                <div class="vital-row <?= $f?'flagged':'' ?> mt-2">
                                    <i class="fas <?= $ico ?>" style="color:<?= $f?'var(--red-text)':'var(--s500)' ?>;"></i>
                                    <span style="flex:1;font-weight:600;font-size:13px;"><?= $lbl ?></span>
                                    <span style="font-weight:800;font-size:13px;color:<?= $f?'var(--red-text)':'var(--s800)' ?>;"><?= $val ?> <?= $unit ?></span>
                                    <?php if($f): ?><i class="fas fa-exclamation-circle" style="color:var(--red-text);font-size:11px;"></i><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="col-md-4">
                            <small style="font-weight:700;color:var(--s600);text-transform:uppercase;letter-spacing:.05em;font-size:11px;">AI Flagged Readings</small>
                            <div class="mt-2">
                            <?php if(!empty($ai_alerts)): foreach($ai_alerts as $al): $cls=$al['status']==='HIGH'?'':'medium'; ?>
                                <div class="ai-chip <?= $cls ?> mb-1 d-flex" style="font-size:12px;padding:5px 9px;">
                                    <i class="fas <?= $al['status']==='HIGH'?'fa-arrow-trend-up':'fa-arrow-trend-down' ?> me-1"></i>
                                    <div>
                                        <?= htmlspecialchars($al['vital_sign']) ?> — <?= $al['status'] ?><br>
                                        <span style="font-weight:400;opacity:.85;"><?= $al['value'].' '.$al['unit'] ?> (Normal: <?= $al['normal_range'] ?>)</span>
                                    </div>
                                </div>
                            <?php endforeach; else: ?>
                                <div style="background:var(--green-bg);border-radius:var(--radius-sm);padding:10px;color:var(--green-text);font-size:13px;">
                                    <i class="fas fa-circle-check me-1"></i>All vitals within normal range.
                                </div>
                            <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-4 d-flex flex-column gap-2 justify-content-start pt-3">
                            <button type="button" onclick="showProfile(<?= $rid ?>)"
                                style="background:linear-gradient(135deg,var(--s500),var(--s800));border:none;border-radius:var(--radius-sm);color:white;padding:9px 14px;font-weight:700;cursor:pointer;font-size:13px;transition:all .2s;font-family:'Outfit',sans-serif;"
                                onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
                                <i class="fas fa-user me-1"></i>View Full Profile
                            </button>
                        </div>
                    </div>

                    <div class="trend-wrap mt-3">
                        <div class="trend-label"><i class="fas fa-chart-line me-1"></i>14-Day Risk Trend</div>
                        <?php if($has_trend): ?>
                            <canvas id="trend-<?= $rid ?>" height="60"
                                    data-trend="<?= htmlspecialchars($trend_data) ?>"
                                    style="width:100%;max-width:600px;"></canvas>
                        <?php else: ?>
                            <div class="no-trend"><i class="fas fa-clock me-1"></i>Trend data will appear after 2+ days of readings.</div>
                        <?php endif; ?>
                    </div>

                    <?php else: ?>
                    <p style="font-size:13px;color:var(--st300);padding-top:6px;margin:0;"><i class="fas fa-clock me-1"></i>No readings logged yet.</p>
                    <?php endif; ?>
                </div>

                <?php if($level==='high'): ?>
                    <div class="action-notice high"><i class="fas fa-circle-exclamation"></i>Immediate attention required.</div>
                <?php elseif($level==='medium'): ?>
                    <div class="action-notice medium"><i class="fas fa-triangle-exclamation"></i>Monitor closely today.</div>
                <?php endif; ?>

                <div class="res-footer">
                    <span>
                        <?php if($level==='high'): ?><i class="fas fa-fire me-1" style="color:var(--red-text);"></i>High priority
                        <?php elseif($level==='medium'): ?><i class="fas fa-bolt me-1" style="color:var(--amber-text);"></i>Monitor today
                        <?php else: ?><i class="fas fa-circle-check me-1" style="color:var(--green-text);"></i>Stable<?php endif; ?>
                    </span>
                    <button class="hist-btn" onclick="toggleHistory(<?= $rid ?>)">
                        <i class="fas fa-clock-rotate-left me-1"></i>Risk History
                    </button>
                </div>

                <div class="hist-panel" id="hist-<?= $rid ?>" style="display:none;">
                    <div id="hist-content-<?= $rid ?>">
                        <div class="text-center py-3"><div class="spin-sm"></div></div>
                    </div>
                </div>

            </div>
            <?php endforeach; ?>
            </div>

            <div class="no-results" id="noResults"><i class="fas fa-search fa-2x mb-2 d-block"></i>No residents match this filter.</div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Resolve all -->
    <?php if(count($unresolved)>0): ?>
    <div class="sc">
        <div class="sc-body text-center py-3">
            <form method="POST">
                <button type="submit" name="resolve_all" class="btn-resolve-all">
                    <i class="fas fa-check-double me-2"></i>Resolve All <?= count($unresolved) ?> Active Alerts
                </button>
                <p style="color:var(--st300);font-size:13px;margin-top:8px;margin-bottom:0;">Marks all pending alerts as resolved.</p>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ THRESHOLD ALERTS ═════════════════════════════════════════ -->
    <div class="sc">
        <div class="sc-hdr">
            <h5><i class="fas fa-bell me-2"></i>All Health Alerts</h5>
            <span style="font-size:12px;opacity:.85;">
                <?php if(count($unresolved)>0): ?><span class="pulse"><i class="fas fa-circle me-1" style="font-size:9px;"></i><?= count($unresolved) ?> pending</span>
                <?php else: ?><i class="fas fa-check-circle me-1"></i>All resolved<?php endif; ?>
            </span>
        </div>
        <div class="sc-body">
            <div class="alert-filter-bar">
                <select id="filterResident"><option value="">All Residents</option>
                    <?php foreach($alert_residents as $rid=>$rn): ?><option value="<?= $rid ?>"><?= htmlspecialchars($rn) ?></option><?php endforeach; ?>
                </select>
                <select id="filterType"><option value="">All Types</option>
                    <option value="health_warning">Health Warning</option>
                    <option value="critical">Critical</option>
                    <option value="warning">Warning</option>
                    <option value="ai_risk">AI Risk</option>
                </select>
                <select id="filterStatus"><option value="">All Status</option>
                    <option value="unresolved">Active Only</option>
                    <option value="resolved">Resolved Only</option>
                </select>
                <input type="text" id="searchAlert" placeholder="🔍  Search alert message...">
            </div>
            <?php if(empty($db_alerts)): ?>
                <div class="empty-state floating"><i class="fas fa-check-circle fa-3x mb-3 d-block" style="color:var(--s200);"></i><p>No health alerts recorded yet.</p></div>
            <?php else: ?>
            <div id="alertsList">
            <?php foreach($db_alerts as $alert): $badge='badge-'.($alert['alert_type']??'health_warning'); ?>
            <div class="alert-card <?= $alert['resolved']?'resolved':'' ?>"
                 data-resident="<?= $alert['resident_id'] ?>"
                 data-type="<?= htmlspecialchars($alert['alert_type']) ?>"
                 data-status="<?= $alert['resolved']?'resolved':'unresolved' ?>"
                 data-msg="<?= strtolower(htmlspecialchars($alert['alert_message'])) ?>">
                <div class="row align-items-center">
                    <div class="col-lg-9">
                        <div class="d-flex align-items-start gap-3">
                            <div style="font-size:18px;color:<?= $alert['resolved']?'var(--s200)':'var(--red-text)' ?>;margin-top:2px;min-width:20px;">
                                <?php if(!$alert['resolved']): ?><i class="fas fa-exclamation-circle pulse"></i>
                                <?php else: ?><i class="fas fa-check-circle"></i><?php endif; ?>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                    <span class="alert-type-badge <?= $badge ?>"><?= strtoupper(str_replace('_',' ',$alert['alert_type'])) ?></span>
                                    <span class="resident-pill"><i class="fas fa-user me-1"></i><?= htmlspecialchars($alert['resident_name']) ?></span>
                                </div>
                                <p style="margin-bottom:4px;font-weight:700;font-size:14px;color:var(--s800);"><?= htmlspecialchars($alert['alert_message']) ?></p>
                                <span class="time-badge"><i class="far fa-clock me-1"></i><?= $alert['formatted_time'] ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 text-lg-end mt-2 mt-lg-0">
                        <?php if(!$alert['resolved']): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="alert_id" value="<?= $alert['alert_id'] ?>">
                                <button type="submit" name="resolve_alert" class="btn-resolve"><i class="fas fa-check me-1"></i>Resolve</button>
                            </form>
                        <?php else: ?>
                            <span style="background:var(--green-bg);color:var(--green-text);border-radius:var(--radius-md);padding:5px 14px;font-size:12px;font-weight:700;display:inline-block;">
                                <i class="fas fa-check me-1"></i>Resolved
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <div id="noAlerts" class="no-results"><i class="fas fa-filter fa-2x mb-2 d-block"></i>No alerts match your filters.</div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /content -->

<!-- Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:var(--radius-lg);border:1px solid var(--s100);box-shadow:0 12px 48px rgba(36,56,22,.18);">
            <div class="modal-header" style="background:linear-gradient(135deg,var(--s600),var(--s800));border-radius:var(--radius-lg) var(--radius-lg) 0 0;padding:18px 24px;border:none;position:relative;overflow:hidden;">
                <div style="position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.18) 0%,transparent 60%);pointer-events:none;"></div>
                <div class="d-flex align-items-center gap-3" style="position:relative;">
                    <div id="pm-avatar" style="width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:white;flex-shrink:0;border:2px solid rgba(255,255,255,.3);"></div>
                    <div><h5 class="modal-title mb-0" style="color:white;font-family:'Outfit',sans-serif;font-weight:700;font-size:16px;" id="pm-name"></h5><small style="color:rgba(255,255,255,.7);font-size:12px;" id="pm-email"></small></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:brightness(0) invert(1);opacity:.8;position:relative;"></button>
            </div>
            <div class="modal-body" style="padding:22px;">
                <div id="pm-risk-banner" style="border-radius:var(--radius-md);padding:12px 15px;margin-bottom:18px;display:flex;align-items:center;gap:13px;">
                    <div id="pm-risk-circle" style="width:54px;height:54px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;color:white;flex-shrink:0;">
                        <i id="pm-risk-icon" style="font-size:16px;"></i>
                        <span id="pm-risk-pct" style="font-size:14px;font-weight:800;line-height:1;margin-top:2px;"></span>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:700;font-size:14px;margin-bottom:4px;" id="pm-risk-label"></div>
                        <div style="height:6px;background:var(--s100);border-radius:6px;overflow:hidden;"><div id="pm-risk-bar" style="height:100%;border-radius:6px;transition:width 1s ease;width:0%;"></div></div>
                        <small style="color:var(--st300);font-size:12px;" id="pm-risk-note"></small>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6"><div style="background:var(--s50);border-radius:var(--radius-md);padding:15px;">
                        <p style="font-weight:700;color:var(--s600);font-size:11px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:11px;"><i class="fas fa-user me-1"></i>Personal Information</p>
                        <div id="pm-personal"></div>
                    </div></div>
                    <div class="col-md-6">
                        <div style="background:var(--w100);border-radius:var(--radius-md);padding:15px;margin-bottom:11px;">
                            <p style="font-weight:700;color:var(--s600);font-size:11px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:11px;"><i class="fas fa-notes-medical me-1"></i>Medical Information</p>
                            <div id="pm-medical"></div>
                        </div>
                        <div style="background:#EDE8F5;border-radius:var(--radius-md);padding:15px;">
                            <p style="font-weight:700;color:#4A2878;font-size:11px;text-transform:uppercase;letter-spacing:.06em;margin-bottom:11px;"><i class="fas fa-user-nurse me-1"></i>Assigned Caregiver</p>
                            <div id="pm-caregiver"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="background:var(--s50);border-radius:0 0 var(--radius-lg) var(--radius-lg);border-top:1px solid var(--s100);padding:13px 22px;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:var(--radius-sm);font-family:'Outfit',sans-serif;font-weight:700;font-size:13px;"><i class="fas fa-times me-1"></i>Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.mini-bar-fill').forEach(b => {
        const t = b.style.width; b.style.width = '0%';
        setTimeout(() => { b.style.width = t; }, 300);
    });
});

function toggleDetail(rid) {
    const d = document.getElementById('detail-' + rid);
    const c = document.getElementById('chev-' + rid);
    const open = d.classList.contains('open');
    document.querySelectorAll('.res-detail').forEach(x => x.classList.remove('open'));
    document.querySelectorAll('[id^="chev-"]').forEach(x => x.style.transform = '');
    if (!open) {
        d.classList.add('open');
        c.style.transform = 'rotate(180deg)';
        const canvas = document.getElementById('trend-' + rid);
        if (canvas && !canvas.dataset.drawn) {
            canvas.dataset.drawn = '1';
            drawTrend(canvas);
        }
    }
}

function drawTrend(canvas) {
    const trend = JSON.parse(canvas.dataset.trend || '[]');
    if (trend.length < 2) return;
    const colors = { low:'#5E8A40', medium:'#C89040', high:'#C05050' };
    new Chart(canvas, {
        type: 'line',
        data: {
            labels: trend.map(t => { const d = new Date(t.date); return (d.getMonth()+1)+'/'+(d.getDate()); }),
            datasets: [{
                data: trend.map(t => parseFloat(t.avg_pct)),
                borderColor: '#5E8A40', backgroundColor: 'rgba(94,138,64,0.07)',
                borderWidth: 2, tension: 0.38, fill: true,
                pointBackgroundColor: trend.map(t => colors[t.level] || '#B8B0A4'),
                pointRadius: 4, pointHoverRadius: 6,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: {
                backgroundColor: '#243816', titleColor: '#C4D9B4', bodyColor: '#E3EDDB',
                callbacks: {
                    label: ctx => ' Risk: ' + ctx.parsed.y + '%',
                    labelColor: ctx => ({
                        borderColor: (colors[trend[ctx.dataIndex]?.level] || '#B8B0A4'),
                        backgroundColor: (colors[trend[ctx.dataIndex]?.level] || '#B8B0A4')
                    })
                }
            }},
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 9 }, color: '#B8B0A4' } },
                y: { min: 0, max: 100, grid: { color: '#E3EDDB' },
                     ticks: { font: { size: 9 }, color: '#B8B0A4', callback: v => v + '%' } }
            }
        }
    });
}

function toggleHistory(rid) {
    const panel   = document.getElementById('hist-' + rid);
    const content = document.getElementById('hist-content-' + rid);
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        loadHistory(rid, content);
    } else {
        panel.style.display = 'none';
    }
}

function loadHistory(rid, container) {
    container.innerHTML = '<div class="text-center py-3"><div class="spin-sm"></div></div>';
    fetch('get_risk_history.php?resident_id=' + rid)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.history.length) {
                container.innerHTML = '<p style="color:var(--st300);font-size:13px;padding:10px 0 4px;">No risk history recorded yet for this resident.</p>';
                return;
            }
            const colors = { low:'#5E8A40', medium:'#C89040', high:'#C05050' };
            const rows = data.history.map(h => {
                const esc = h.is_escalation == 1 ? '<span class="esc-badge"><i class="fas fa-arrow-trend-up me-1"></i>ESCALATION</span>' : '';
                const fb  = h.is_fallback  == 1 ? '<span class="fb-badge">Rule-based</span>' : '';
                const ac  = h.alert_created == 1 ? '<span style="background:var(--red-bg);color:var(--red-text);border-radius:6px;padding:2px 7px;font-size:11px;font-weight:700;margin-left:4px;"><i class="fas fa-bell me-1"></i>Alert created</span>' : '';
                return `<tr>
                    <td style="white-space:nowrap;font-size:13px;">${h.predicted_at_fmt}</td>
                    <td><span class="rlvl ${h.risk_level}">${h.risk_level.toUpperCase()}</span>${esc}${fb}${ac}</td>
                    <td><strong>${h.risk_percentage}%</strong>
                        <div style="height:5px;background:var(--s100);border-radius:4px;overflow:hidden;width:70px;margin-top:3px;">
                            <div style="height:100%;width:${h.risk_percentage}%;background:${colors[h.risk_level]||'#B8B0A4'};border-radius:4px;"></div>
                        </div>
                    </td>
                    <td>${h.total_flagged > 0 ? '<span style="color:var(--red-text);font-weight:700;">'+h.total_flagged+' flagged</span>' : '<span style="color:var(--green-text);">None</span>'}</td>
                    <td style="color:var(--st300);font-size:12px;">${h.model_used || 'Unknown'}</td>
                </tr>`;
            }).join('');
            container.innerHTML = `
                <div style="overflow-x:auto;margin-top:8px;">
                    <table class="hist-table">
                        <thead><tr>
                            <th>Date &amp; Time</th><th>Risk Level</th><th>Score</th><th>Flagged Vitals</th><th>Model</th>
                        </tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                    <p style="color:var(--st300);margin-top:8px;margin-bottom:0;font-size:12px;">Showing last ${data.history.length} predictions.</p>
                </div>`;
        })
        .catch(() => { container.innerHTML = '<p style="color:var(--red-text);font-size:13px;padding:10px 0 4px;">Failed to load history.</p>'; });
}

document.querySelectorAll('.ftab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.ftab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        applyResidentFilters();
    });
});
document.getElementById('residentSearch')?.addEventListener('input', applyResidentFilters);
function applyResidentFilters() {
    const risk   = document.querySelector('.ftab.active')?.dataset.risk || 'all';
    const search = (document.getElementById('residentSearch')?.value || '').toLowerCase();
    let visible  = 0;
    document.querySelectorAll('.res-card').forEach(card => {
        const show = (risk==='all'||card.dataset.risk===risk) && card.dataset.name.includes(search);
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('noResults').style.display = visible===0 ? 'block' : 'none';
}

['filterResident','filterType','filterStatus','searchAlert'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', applyAlertFilters);
    document.getElementById(id)?.addEventListener('input',  applyAlertFilters);
});
function applyAlertFilters() {
    const resident = document.getElementById('filterResident').value;
    const type     = document.getElementById('filterType').value;
    const status   = document.getElementById('filterStatus').value;
    const search   = document.getElementById('searchAlert').value.toLowerCase();
    let visible    = 0;
    document.querySelectorAll('.alert-card').forEach(card => {
        const show = (!resident||card.dataset.resident===resident)
                  && (!type    ||card.dataset.type===type)
                  && (!status  ||card.dataset.status===status)
                  && (!search  ||card.dataset.msg.includes(search));
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('noAlerts').style.display = visible===0 ? 'block' : 'none';
}

const profileData = <?= json_encode($profile_js_data) ?>;
function showProfile(rid) {
    const d = profileData[rid]; if (!d) return;
    const initials = d.name.split(' ').map(w=>w[0]).join('').substring(0,2).toUpperCase();
    document.getElementById('pm-avatar').textContent = initials;
    document.getElementById('pm-name').textContent   = d.name;
    document.getElementById('pm-email').textContent  = d.email;
    const rC={'high':'#C05050','medium':'#C89040','low':'#5E8A40','no_data':'#B8B0A4'};
    const rB={'high':'#F5DADA','medium':'#FDF3DC','low':'#DDEFD8','no_data':'#F2F6EF'};
    const rI={'high':'fa-circle-exclamation','medium':'fa-triangle-exclamation','low':'fa-circle-check','no_data':'fa-question-circle'};
    const rN={'high':'Immediate attention may be required.','medium':'Monitor closely.','low':'All readings in healthy range.','no_data':'No health data yet.'};
    const lv = d.risk_level;
    document.getElementById('pm-risk-banner').style.background = rB[lv];
    document.getElementById('pm-risk-circle').style.background = rC[lv];
    document.getElementById('pm-risk-icon').className = 'fas ' + rI[lv];
    document.getElementById('pm-risk-pct').textContent = d.risk_pct + '%';
    document.getElementById('pm-risk-label').innerHTML = `<span style="color:${rC[lv]};font-size:14px;">${lv.charAt(0).toUpperCase()+lv.slice(1).replace('_',' ')} AI Risk</span>`;
    document.getElementById('pm-risk-note').textContent = rN[lv];
    const bar = document.getElementById('pm-risk-bar');
    bar.style.background = rC[lv]; bar.style.width = '0%';
    setTimeout(() => { bar.style.width = d.risk_pct + '%'; }, 200);
    const row = (ico,lbl,val) => val&&val!=='--' ? `<div style="display:flex;gap:9px;align-items:flex-start;margin-bottom:8px;"><i class="fas ${ico}" style="color:var(--s500);width:15px;margin-top:2px;font-size:12px;flex-shrink:0;"></i><div><div style="font-size:10px;font-weight:700;color:var(--st300);text-transform:uppercase;letter-spacing:.05em;">${lbl}</div><div style="font-size:13px;color:var(--s800);font-weight:600;">${val}</div></div></div>` : '';
    document.getElementById('pm-personal').innerHTML =
        row('fa-phone','Phone',d.phone)+row('fa-venus-mars','Gender',d.gender)+row('fa-birthday-cake','Date of Birth',d.dob)+row('fa-droplet','Blood Type',d.blood_type)+row('fa-phone-volume','Emergency Contact',d.emergency_contact) ||
        '<p style="color:var(--st300);font-size:13px;">No personal details.</p>';
    document.getElementById('pm-medical').innerHTML =
        row('fa-heart-pulse','Medical Conditions',d.medical_conditions)+row('fa-triangle-exclamation','Allergies',d.allergies)+row('fa-utensils','Dietary Restrictions',d.dietary)+row('fa-user-doctor','Primary Physician',d.physician) ||
        '<p style="color:var(--st300);font-size:13px;">No medical details.</p>';
    const cgBox = document.getElementById('pm-caregiver');
    if (d.caregiver_name) {
        const cgi = d.caregiver_name.split(' ').map(w=>w[0]).join('').substring(0,2).toUpperCase();
        cgBox.innerHTML = `<div style="display:flex;align-items:center;gap:11px;"><div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--s400),var(--s700));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:13px;flex-shrink:0;">${cgi}</div><div><div style="font-weight:700;color:var(--s800);font-size:14px;">${d.caregiver_name}</div>${d.caregiver_phone!=='--'?`<div style="font-size:12px;color:var(--st300);"><i class="fas fa-phone me-1"></i>${d.caregiver_phone}</div>`:''}</div></div>`;
    } else { cgBox.innerHTML = '<p style="color:var(--st300);font-size:13px;margin:0;"><i class="fas fa-user-slash me-1"></i>No caregiver assigned.</p>'; }
    new bootstrap.Modal(document.getElementById('profileModal')).show();
}

setInterval(() => location.reload(), 90000);
const highCount = <?= $risk_counts['high'] ?>;
if (highCount > 0) {
    let orig = document.title, alt = false;
    setInterval(() => { document.title = alt ? orig : `(${highCount} HIGH RISK) Admin Alerts`; alt=!alt; }, 1500);
}
</script>
</body>
</html>