<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: login.php"); exit();
}
include '../db_connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/SmartCareGuardian/includes/ai_service.php';

$user_id = $_SESSION['user_id'];

$rs = $conn->prepare("SELECT * FROM users WHERE user_id=? AND role='resident'");
$rs->bind_param("i",$user_id); $rs->execute();
$resident = $rs->get_result()->fetch_assoc();
if (!$resident) { header("Location: login.php"); exit(); }

$ls = $conn->prepare("SELECT * FROM health_logs WHERE resident_id=? ORDER BY logged_at DESC LIMIT 1");
$ls->bind_param("i",$user_id); $ls->execute();
$latest_log = $ls->get_result()->fetch_assoc();

$als = $conn->prepare("SELECT *, DATE_FORMAT(created_at,'%Y-%m-%d %h:%i %p') AS formatted_time FROM alerts WHERE resident_id=? ORDER BY created_at DESC");
$als->bind_param("i",$user_id); $als->execute();
$db_alerts  = $als->get_result()->fetch_all(MYSQLI_ASSOC);
$unresolved = array_filter($db_alerts, fn($a)=>!$a['resolved']);

$um = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id=? AND is_read=0");
$um->bind_param("i",$user_id); $um->execute();
$unread_messages = $um->get_result()->fetch_assoc()['cnt'] ?? 0;
$ua = $conn->prepare("SELECT COUNT(*) AS cnt FROM alerts WHERE resident_id=? AND resolved=0");
$ua->bind_param("i",$user_id); $ua->execute();
$unresolved_alerts = $ua->get_result()->fetch_assoc()['cnt'] ?? 0;

$ai        = new AIService($conn);
$ai_online = $ai->checkStatus()['success'] ?? false;
$ai_result = null;

if ($latest_log) {
    $vitals = [
        'blood_pressure_systolic'  => !empty($latest_log['blood_pressure_systolic'])  ? (float)$latest_log['blood_pressure_systolic']  : 120.0,
        'blood_pressure_diastolic' => !empty($latest_log['blood_pressure_diastolic']) ? (float)$latest_log['blood_pressure_diastolic'] : 80.0,
        'blood_sugar'              => !empty($latest_log['blood_sugar'])               ? (float)$latest_log['blood_sugar']               : 100.0,
        'pulse'                    => !empty($latest_log['pulse'])                     ? (float)$latest_log['pulse']                     : 72.0,
        'weight'                   => !empty($latest_log['weight'])                    ? (float)$latest_log['weight']                    : 65.0,
        'temperature'              => !empty($latest_log['temperature'])               ? (float)$latest_log['temperature']               : 36.6,
        'oxygen_saturation'        => !empty($latest_log['oxygen_saturation'])         ? (float)$latest_log['oxygen_saturation']         : 98.0,
        'logged_at'                => $latest_log['logged_at'],
    ];
    if ($ai_online) {
        $resp = $ai->getPrediction($user_id, $vitals);
        if ($resp['success'] && isset($resp['data']['data']['prediction'])) {
            $ai_result = $resp['data']['data'];
            $ai->savePrediction($user_id, $ai_result['prediction'], $ai_result['alerts'], $vitals, false, $ai_result['model_used'] ?? 'AI Model');
        }
    }
    if (!$ai_result) {
        $score=0; $al=[];
        if ($vitals['blood_pressure_systolic']>140)  {$score+=2;$al[]=['vital_sign'=>'Blood Pressure Systolic','value'=>$vitals['blood_pressure_systolic'],'unit'=>'mmHg','status'=>'HIGH','normal_range'=>'90-140 mmHg'];}
        elseif($vitals['blood_pressure_systolic']<90){$score+=2;$al[]=['vital_sign'=>'Blood Pressure Systolic','value'=>$vitals['blood_pressure_systolic'],'unit'=>'mmHg','status'=>'LOW','normal_range'=>'90-140 mmHg'];}
        if ($vitals['blood_sugar']>180)   {$score+=2;$al[]=['vital_sign'=>'Blood Sugar','value'=>$vitals['blood_sugar'],'unit'=>'mg/dL','status'=>'HIGH','normal_range'=>'70-180 mg/dL'];}
        elseif($vitals['blood_sugar']<70) {$score+=2;$al[]=['vital_sign'=>'Blood Sugar','value'=>$vitals['blood_sugar'],'unit'=>'mg/dL','status'=>'LOW','normal_range'=>'70-180 mg/dL'];}
        if ($vitals['temperature']>37.8)  {$score+=1;$al[]=['vital_sign'=>'Temperature','value'=>$vitals['temperature'],'unit'=>'°C','status'=>'HIGH','normal_range'=>'36.0-37.8°C'];}
        elseif($vitals['temperature']<36.0){$score+=1;$al[]=['vital_sign'=>'Temperature','value'=>$vitals['temperature'],'unit'=>'°C','status'=>'LOW','normal_range'=>'36.0-37.8°C'];}
        if ($vitals['oxygen_saturation']<95){$score+=2;$al[]=['vital_sign'=>'Oxygen Saturation','value'=>$vitals['oxygen_saturation'],'unit'=>'%','status'=>'LOW','normal_range'=>'95-100%'];}
        $pct=$min=min($score*20,95); $level=$pct>=60?'high':($pct>=30?'medium':'low');
        $ai_result=['prediction'=>['risk_level'=>$level,'risk_percentage'=>$pct,'risk_prediction'=>(int)($level!=='low'),'risk_probability'=>$pct/100],
                    'alerts'=>['total_alerts'=>count($al),'alerts'=>$al,'requires_attention'=>count($al)>0]];
        $ai->savePrediction($user_id, $ai_result['prediction'], $ai_result['alerts'], $vitals, true, 'Rule-Based Fallback');
    }
}

$trend_data = $ai->getTrend($user_id, 30);
$history    = $ai->getHistory($user_id, 20);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Health Alerts — SmartCare Guardian</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════
   SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
   Health Alerts · Very large fonts for elderly readability
═══════════════════════════════════════════════════════════ */
:root{
    --s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;
    --s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;
    --s600:#4A6E30;--s700:#365220;--s800:#243816;
    --w50:#FDFAF5;--w100:#F7F1E5;
    --st300:#B8B0A4;--st500:#7A7268;--st700:#4A4540;
    --green-bg:#DDEFD8;--green-text:#3A6830;
    --amber-bg:#FAECC8;--amber-text:#7A5010;
    --red-bg:#F5DADA;--red-text:#6A2020;
    --blue-bg:#DBEEFF;--blue-text:#1A4870;
    --purple-bg:#EDE9FE;--purple-text:#5A3A7A;
    --radius-sm:8px;--radius-md:14px;--radius-lg:22px;
    --shadow-soft:0 2px 12px rgba(36,56,22,.07),0 1px 3px rgba(36,56,22,.05);
    --shadow-card:0 4px 24px rgba(36,56,22,.09),0 1px 4px rgba(36,56,22,.06);
    --shadow-lift:0 8px 32px rgba(36,56,22,.13),0 2px 8px rgba(36,56,22,.07);
}
*,*::before,*::after{box-sizing:border-box;}
body{
    font-family:'Outfit',sans-serif;
    background:var(--w50);
    background-image:
        radial-gradient(ellipse 70% 50% at 90% 0%,rgba(157,192,126,.09) 0%,transparent 55%),
        radial-gradient(ellipse 50% 40% at 0% 100%,rgba(122,166,88,.06) 0%,transparent 50%);
    color:var(--st700);font-size:18px;line-height:1.75;
    min-height:100vh;margin:0;padding:0;
}
h1,h2,h3,h4,h5{font-family:'Cormorant Garamond',serif;color:var(--s800);margin:0;}

/* ── Sidebar ── */
.sidebar{width:240px;height:100vh;position:fixed;background:var(--s800);display:flex;flex-direction:column;z-index:1000;overflow:hidden;}
.sidebar::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:radial-gradient(ellipse 120% 60% at 50% -10%,rgba(157,192,126,.18) 0%,transparent 60%),radial-gradient(ellipse 80% 80% at 110% 110%,rgba(94,138,64,.15) 0%,transparent 55%);}
.sidebar-header{padding:26px 20px 18px;border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0;position:relative;}
.brand-mark{display:flex;align-items:center;gap:10px;margin-bottom:5px;}
.brand-icon{width:34px;height:34px;background:linear-gradient(135deg,var(--s300),var(--s500));border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;color:white;box-shadow:0 3px 10px rgba(0,0,0,.25);flex-shrink:0;}
.sidebar-header h4{font-family:'Cormorant Garamond',serif;font-size:17px;font-weight:600;color:white;line-height:1.1;}
.sidebar-header small{font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.08em;text-transform:uppercase;font-weight:500;display:block;margin-left:44px;margin-top:2px;}
.sidebar-nav{flex:1;overflow-y:auto;padding:10px 0;position:relative;}
.sidebar-nav::-webkit-scrollbar{width:4px;}.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:3px;}
.sidebar a{display:flex;align-items:center;gap:10px;padding:11px 12px 11px 22px;color:rgba(255,255,255,.6);text-decoration:none;font-size:15px;font-weight:500;transition:all .2s;margin:2px 10px;border-radius:var(--radius-sm);position:relative;min-height:46px;}
.sidebar a:hover{background:rgba(255,255,255,.10);color:white;}
.sidebar a.active{background:rgba(157,192,126,.2);color:#C8E6A0;}
.sidebar a.active::before{content:'';position:absolute;left:-10px;top:20%;bottom:20%;width:3px;background:var(--s300);border-radius:0 3px 3px 0;}
.sidebar i{width:20px;text-align:center;font-size:15px;opacity:.85;}
.sb-badge{margin-left:auto;background:#8B3A3A;color:white;font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;}
.sidebar-footer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:14px 10px;position:relative;}
.sidebar-footer a{margin:0;color:rgba(255,255,255,.5)!important;font-size:15px;}
.sidebar-footer a:hover{color:rgba(255,255,255,.8)!important;}

/* ── Layout ── */
.content{margin-left:240px;padding:28px;min-height:100vh;}

/* ── Topbar ── */
.topbar{background:white;border-radius:var(--radius-lg);padding:20px 28px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
.topbar h4{font-size:26px;font-weight:500;color:var(--s800);margin-bottom:3px;}
.topbar p{font-size:15px;color:var(--st300);margin:0;}
.logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-sm);color:white;padding:11px 22px;font-size:15px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .25s;display:inline-flex;align-items:center;gap:7px;}
.logout-btn:hover{opacity:.9;transform:translateY(-1px);}
.topbar-actions {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-top: 4px;
            flex-wrap: wrap;
          flex-wrap: wrap; gap: 8px; align-items: center; }

        .date-chip {
            background: white;
            border: 1px solid var(--s100);
            border-radius: 20px;
            padding: 7px 14px;
            font-size: 1rem;
            font-weight: 600;
            color: var(--s600);
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: var(--shadow-soft);
        }

        .topbar-btn {
            background: white;
            border: 1px solid var(--s100);
            border-radius: var(--radius-sm);
            padding: 7px 12px;
            font-size: 1rem;
            font-weight: 400;
            color: var(--st500);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: var(--shadow-soft);
            transition: all .2s;
            font-family: 'Outfit', sans-serif;
        }

        .topbar-btn:hover { background: var(--s50); border-color: var(--s200); color: var(--s600); }


/* ── Section card ── */
.sc{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:24px;overflow:hidden;animation:fadeUp .4s ease both;}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.sc:nth-child(2){animation-delay:.07s}.sc:nth-child(3){animation-delay:.13s}.sc:nth-child(4){animation-delay:.19s}
.sc-hdr{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:18px 26px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;position:relative;overflow:hidden;}
.sc-hdr::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
.sc-hdr h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-size:18px;font-weight:700;position:relative;}
.sc-hdr span{font-size:14px;opacity:.8;position:relative;}
.sc-body{padding:26px;}

/* ── Info banner ── */
.info-banner{background:var(--blue-bg);border:1px solid rgba(26,72,112,.2);border-radius:var(--radius-md);padding:18px 22px;margin-bottom:24px;display:flex;align-items:flex-start;gap:14px;}
.info-banner i{font-size:22px;color:var(--blue-text);margin-top:2px;flex-shrink:0;}
.info-banner p{margin:0;font-size:16px;color:var(--blue-text);line-height:1.65;}

/* ── Stats grid ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px;margin-bottom:24px;}
.stat-card{background:white;border-radius:var(--radius-lg);padding:22px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);transition:all .25s;text-align:center;}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lift);}
.stat-icon{width:56px;height:56px;border-radius:var(--radius-md);margin:0 auto 13px;display:flex;align-items:center;justify-content:center;font-size:22px;}
.ic-ai    {background:linear-gradient(135deg,#7C5CBF,#5A3A7A);color:white;}
.ic-hist  {background:linear-gradient(135deg,var(--s400),var(--s600));color:white;}
.ic-pend  {background:linear-gradient(135deg,#C87A7A,#8B3A3A);color:white;}
.ic-res   {background:linear-gradient(135deg,var(--s300),var(--s500));color:white;}
.stat-number{font-size:2.2rem;font-weight:800;color:var(--s800);line-height:1;}
.stat-label{color:var(--st500);font-weight:600;font-size:14px;margin-top:5px;}

/* ── Risk wrap ── */
.risk-wrap{border-radius:var(--radius-md);overflow:hidden;border:2px solid var(--s100);}
.risk-top{padding:16px 22px;display:flex;align-items:center;gap:10px;font-family:'Outfit',sans-serif;font-weight:700;font-size:18px;}
.risk-top.low   {background:var(--green-bg);color:var(--green-text);}
.risk-top.medium{background:var(--amber-bg);color:var(--amber-text);}
.risk-top.high  {background:var(--red-bg);  color:var(--red-text);}
.risk-circle{display:inline-flex;flex-direction:column;align-items:center;justify-content:center;width:130px;height:130px;border-radius:50%;color:white;box-shadow:var(--shadow-lift);transition:transform .3s;}
.risk-circle:hover{transform:scale(1.06);}
.risk-circle.low   {background:linear-gradient(135deg,var(--s400),var(--s600));}
.risk-circle.medium{background:linear-gradient(135deg,#D4A853,#7A5010);}
.risk-circle.high  {background:linear-gradient(135deg,#C87A7A,#8B3A3A);}
.rc-icon{font-size:1.8rem;}.rc-pct{font-size:1.35rem;font-weight:800;line-height:1;margin-top:4px;}.rc-lbl{font-size:.76rem;opacity:.9;}
.rbar-track{height:12px;border-radius:20px;background:var(--s100);overflow:hidden;margin-top:10px;}
.rbar-fill{height:100%;border-radius:20px;transition:width 1.2s cubic-bezier(.4,0,.2,1);}
.rbar-fill.low   {background:linear-gradient(90deg,var(--s400),var(--s600));}
.rbar-fill.medium{background:linear-gradient(90deg,#D4A853,#7A5010);}
.rbar-fill.high  {background:linear-gradient(90deg,#C87A7A,#8B3A3A);}

/* ── Vital chips ── */
.vital-chip{display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--s50);border-radius:var(--radius-sm);margin-bottom:8px;border-left:3px solid var(--s300);}
.vital-chip.flagged{background:var(--red-bg);border-left-color:#C87A7A;}
.vital-chip-label{font-weight:600;color:var(--s800);font-size:16px;flex:1;}
.vital-chip-value{font-weight:800;color:var(--s700);font-size:17px;}
.vital-chip.flagged .vital-chip-value{color:var(--red-text);}

/* ── AI alert items ── */
.ai-alert-item{display:flex;align-items:flex-start;gap:12px;background:var(--red-bg);border-radius:var(--radius-md);padding:13px 16px;margin-bottom:10px;}
.ai-alert-item .aii-icon{font-size:18px;color:var(--red-text);min-width:20px;margin-top:3px;}
.ai-alert-item.low-item{background:var(--blue-bg);}
.ai-alert-item.low-item .aii-icon{color:var(--blue-text);}
.ai-alert-title{font-size:16px;font-weight:700;color:var(--s800);}
.ai-alert-meta{font-size:14px;color:var(--st500);}
.all-clear{display:flex;align-items:center;gap:12px;background:var(--green-bg);border-radius:var(--radius-md);padding:16px;}
.all-clear .aii-icon{font-size:22px;color:var(--green-text);}

/* ── Action notice ── */
.action-notice{border-radius:var(--radius-md);padding:14px 18px;display:flex;align-items:center;gap:12px;font-weight:700;font-size:16px;margin-top:16px;}
.action-notice.high  {background:var(--red-bg);  color:var(--red-text);}
.action-notice.medium{background:var(--amber-bg);color:var(--amber-text);}
.action-notice.low   {background:var(--green-bg);color:var(--green-text);}

/* ── Tip box ── */
.tip-box{background:var(--blue-bg);border-left:4px solid var(--blue-text);border-radius:var(--radius-sm);padding:13px 16px;font-size:16px;color:var(--blue-text);margin-top:16px;line-height:1.6;}

/* ── Trend section ── */
.trend-section{background:var(--s50);border-radius:var(--radius-md);padding:20px 22px;margin-top:22px;}
.trend-title{font-size:13px;font-weight:700;color:var(--st500);text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px;}

/* ── History table ── */
.hist-wrap{overflow-x:auto;}
.hist-table{width:100%;border-collapse:separate;border-spacing:0 5px;font-size:16px;}
.hist-table thead th{padding:11px 14px;font-size:12px;text-transform:uppercase;letter-spacing:.07em;color:var(--st500);font-weight:700;border-bottom:2px solid var(--s100);}
.hist-table tbody tr{background:white;box-shadow:var(--shadow-soft);transition:all .18s;}
.hist-table tbody tr:hover{box-shadow:var(--shadow-card);transform:translateY(-1px);}
.hist-table tbody td{padding:13px 14px;vertical-align:middle;}
.hist-table tbody td:first-child{border-radius:var(--radius-sm) 0 0 var(--radius-sm);}
.hist-table tbody td:last-child{border-radius:0 var(--radius-sm) var(--radius-sm) 0;}
.rlvl{display:inline-block;padding:5px 14px;border-radius:20px;font-size:13px;font-weight:700;}
.rlvl.high  {background:var(--red-bg);  color:var(--red-text);}
.rlvl.medium{background:var(--amber-bg);color:var(--amber-text);}
.rlvl.low   {background:var(--green-bg);color:var(--green-text);}
.esc-badge{background:var(--red-bg);color:var(--red-text);border-radius:var(--radius-sm);padding:3px 8px;font-size:11px;font-weight:700;margin-left:5px;}
.fb-badge {background:var(--s100);color:var(--st500);border-radius:var(--radius-sm);padding:3px 8px;font-size:11px;margin-left:4px;}
.ac-badge {background:var(--red-bg);color:var(--red-text);border-radius:var(--radius-sm);padding:3px 8px;font-size:11px;font-weight:700;margin-left:4px;}

/* ── Alert cards ── */
.alert-card{background:white;border-radius:var(--radius-lg);padding:22px;margin-bottom:16px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.2);border-left:5px solid #C87A7A;transition:all .25s;}
.alert-card:hover{transform:translateX(5px);box-shadow:var(--shadow-lift);}
.alert-card.resolved{opacity:.75;border-left-color:var(--s300);}
.alert-icon-wrap{font-size:22px;min-width:28px;margin-top:2px;}
.alert-icon-wrap.active{color:#C87A7A;}.alert-icon-wrap.resolved{color:var(--s300);}
.alert-type-badge{display:inline-block;padding:5px 14px;border-radius:20px;font-size:13px;font-weight:700;margin-bottom:10px;}
.badge-health_warning{background:var(--green-bg);color:var(--green-text);}
.badge-critical      {background:var(--red-bg);  color:var(--red-text);}
.badge-warning       {background:var(--amber-bg);color:var(--amber-text);}
.badge-ai_risk       {background:var(--purple-bg);color:var(--purple-text);}
.alert-message{font-size:17px;font-weight:700;color:var(--s800);margin-bottom:6px;}
.alert-meaning{font-size:15px;color:var(--st500);margin-bottom:0;line-height:1.6;}
.time-badge{background:var(--s50);color:var(--s600);padding:4px 12px;border-radius:20px;font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:5px;margin-top:8px;}
.meaning-box{background:var(--s50);border-radius:var(--radius-md);padding:14px 18px;margin-bottom:22px;font-size:16px;color:var(--s700);border-left:4px solid var(--s300);}
.meaning-box strong{display:block;margin-bottom:4px;font-size:17px;}

/* ── Status pill in table ── */
.status-pill-reviewed{background:var(--green-bg);color:var(--green-text);padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:5px;}
.status-pill-pending {background:var(--red-bg);  color:var(--red-text);  padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:5px;}

/* ── Empty state ── */
.empty-state{text-align:center;padding:60px 20px;}
.empty-state i{font-size:4rem;color:var(--s200);display:block;margin-bottom:18px;animation:float 4s ease-in-out infinite;}
.empty-state h5{font-family:'Outfit',sans-serif;font-size:20px;font-weight:700;color:var(--s700);margin-bottom:8px;}
.empty-state p{font-size:16px;color:var(--st300);}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}

/* ── Animations ── */
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}.pulse{animation:pulse 2s infinite;}

/* ── Print / responsive ── */
@media print{.sidebar,.no-print{display:none!important;}.content{margin-left:0!important;padding:10px!important;}}
@media(max-width:768px){.sidebar{width:100%;height:auto;position:relative;}.content{margin-left:0;padding:16px;}}
</style>
</head>
<body>

<!-- ══ SIDEBAR ════════════════════════════════════════════════════ -->
<div class="sidebar no-print">
    <div class="sidebar-header">
        <div class="brand-mark">
            <div class="brand-icon"><i class="fas fa-leaf"></i></div>
            <h4>SmartCare<br>Guardian</h4>
        </div>
        <small>Resident Portal</small>
    </div>
    <div class="sidebar-nav">
        <a href="resident_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="elder_profile.php"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="elder_health.php"><i class="fa-solid fa-heart-pulse"></i> My Health Data</a>
        <a href="elder_schedule.php"><i class="fa-solid fa-calendar-check"></i> Daily Schedule</a>
        <a href="elder_medications.php"><i class="fa-solid fa-pills"></i> My Medications</a>
        <a href="elder_appointments.php"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="elder_messages.php">
            <i class="fa-solid fa-comments"></i> Messages
            <?php if($unread_messages>0): ?><span class="sb-badge"><?= $unread_messages ?></span><?php endif; ?>
        </a>
        <a href="resident_alerts.php" class="active">
            <i class="fa-solid fa-bell"></i> Health Alerts
            <?php if($unresolved_alerts>0): ?><span class="sb-badge"><?= $unresolved_alerts ?></span><?php endif; ?>
        </a>
    </div>
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <div class="topbar no-print">
        <div>
            <h4><i class="fas fa-bell me-2" style="font-size:22px;color:var(--s500);"></i>My Health Alerts</h4>
            <p>Your AI risk assessment, trend history, and health warnings</p>
        </div>
        <div class="topbar-actions">
            <button class="topbar-btn" onclick="increaseFontSize()"><i class="fas fa-search-plus"></i>Larger</button>
            <button class="topbar-btn" onclick="decreaseFontSize()"><i class="fas fa-search-minus"></i>Smaller</button>
            <button class="topbar-btn" onclick="resetFontSize()"><i class="fas fa-redo"></i>Reset</button>
            <button class="topbar-btn" onclick="toggleHighContrast()"><i class="fas fa-adjust"></i>Contrast</button>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span style="font-size:15px;font-weight:600;">
                <?php if($ai_online): ?><i class="fas fa-circle pulse me-1" style="color:var(--s400);"></i>AI Online
                <?php else: ?><i class="fas fa-circle me-1" style="color:#C87A7A;"></i>AI Offline<?php endif; ?>
            </span>
            <form action="/SmartCareGuardian/logout.php" method="POST">
                <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt"></i>Logout</button>
            </form>
        </div>
    </div>

    <!-- Info banner -->
    <div class="info-banner">
        <i class="fas fa-circle-info"></i>
        <p>This page shows your <strong>AI-predicted health risk</strong> based on your latest readings, your <strong>30-day risk trend</strong>, full prediction history, and any health warnings flagged by your care team. <strong>This is not a medical diagnosis.</strong> If you feel unwell, please contact your carer.</p>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon ic-ai"><i class="fas fa-brain"></i></div>
            <div class="stat-number" style="font-size:1.6rem;"><?= $ai_result ? ucfirst($ai_result['prediction']['risk_level']) : 'N/A' ?></div>
            <div class="stat-label">Current AI Risk</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon ic-hist"><i class="fas fa-clock-rotate-left"></i></div>
            <div class="stat-number"><?= count($history) ?></div>
            <div class="stat-label">Predictions Saved</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon ic-pend"><i class="fas fa-bell"></i></div>
            <div class="stat-number"><?= count($unresolved) ?></div>
            <div class="stat-label">Active Warnings</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon ic-res"><i class="fas fa-check-circle"></i></div>
            <div class="stat-number"><?= count($db_alerts)-count($unresolved) ?></div>
            <div class="stat-label">Resolved Warnings</div>
        </div>
    </div>

    <!-- ═══ AI HEALTH RISK ════════════════════════════════════ -->
    <div class="sc">
        <div class="sc-hdr">
            <h5><i class="fas fa-brain me-2"></i>AI Predictive Health Risk</h5>
            <span>
                <?php if($latest_log): ?>Based on reading: <?= date('d M Y, H:i',strtotime($latest_log['logged_at'])) ?>
                <?php else: ?>No readings yet<?php endif; ?>
            </span>
        </div>
        <div class="sc-body">

        <?php if(!$latest_log): ?>
            <div class="empty-state"><i class="fas fa-notes-medical"></i><h5>No Health Readings Yet</h5><p>Ask your carer to log a reading to see your AI risk assessment.</p></div>

        <?php elseif(!$ai_result): ?>
            <div class="d-flex align-items-center gap-3 p-4" style="background:var(--s50);border-radius:var(--radius-md);">
                <i class="fas fa-plug-circle-exclamation fa-2x" style="color:var(--st300);"></i>
                <div><strong style="font-size:17px;">AI service unavailable.</strong><br><span style="font-size:15px;color:var(--st500);">Your history and alerts are shown below.</span></div>
            </div>

        <?php else:
            $pred       = $ai_result['prediction'];
            $alert_info = $ai_result['alerts'];
            $level      = $pred['risk_level'];
            $pct        = $pred['risk_percentage'];
            $icons=['low'=>'fa-circle-check','medium'=>'fa-triangle-exclamation','high'=>'fa-circle-exclamation'];
            $tips=['low'=>'Your vitals are in a healthy range. Keep following your daily routine and stay hydrated!','medium'=>'One or more of your readings are slightly outside normal. Your carer has been informed and will review.','high'=>'Your readings show a higher risk level today. Please let your carer know or press your call button if you feel unwell.'];
            $flagged=[]; foreach($alert_info['alerts'] as $a) $flagged[$a['vital_sign']]=$a['status'];
        ?>
            <div class="risk-wrap">
                <div class="risk-top <?= $level ?>">
                    <i class="fas <?= $icons[$level] ?> fa-lg"></i>
                    Today's AI Health Risk Assessment
                    <span class="ms-auto" style="background:<?= $level==='low'?'var(--green-text)':($level==='medium'?'var(--amber-text)':'var(--red-text)') ?>;color:white;padding:6px 18px;border-radius:20px;font-size:14px;">
                        <?= ucfirst($level) ?> Risk
                    </span>
                </div>
                <div style="padding:24px;">
                    <div class="row g-4 align-items-start">

                        <!-- Circle -->
                        <div class="col-md-3 text-center">
                            <div class="risk-circle <?= $level ?> mx-auto">
                                <i class="fas <?= $icons[$level] ?> rc-icon"></i>
                                <div class="rc-pct"><?= $pct ?>%</div>
                                <div class="rc-lbl">Risk Score</div>
                            </div>
                            <div class="rbar-track mt-3">
                                <div class="rbar-fill <?= $level ?>" id="rbarFill" style="width:<?= $pct ?>%"></div>
                            </div>
                            <div style="font-size:13px;color:var(--st300);margin-top:6px;text-align:center;">
                                <?= $ai_result['model_used'] ?? ($ai_online ? 'AI Model' : 'Rule-Based') ?>
                            </div>
                        </div>

                        <!-- Vitals -->
                        <div class="col-md-5">
                            <div style="font-weight:700;font-size:17px;color:var(--s800);margin-bottom:14px;"><i class="fas fa-heartbeat me-1" style="color:#C87A7A;"></i>Your Latest Readings</div>
                            <?php $vd=[
                                ['Blood Pressure Systolic',$latest_log['blood_pressure_systolic'].'/'.$latest_log['blood_pressure_diastolic'],'mmHg','fa-heart-pulse','Blood Pressure'],
                                ['Blood Sugar',$latest_log['blood_sugar'],'mg/dL','fa-droplet','Blood Sugar'],
                                ['Pulse',$latest_log['pulse'],'bpm','fa-wave-square','Pulse Rate'],
                                ['Temperature',$latest_log['temperature'],'°C','fa-thermometer-half','Temperature'],
                                ['Oxygen Saturation',$latest_log['oxygen_saturation'],'%','fa-lungs','Oxygen Sat.'],
                                ['Weight',$latest_log['weight'],'kg','fa-weight-scale','Weight'],
                            ];
                            foreach($vd as [$key,$val,$unit,$ico,$lbl]):
                                $f=isset($flagged[$key]); ?>
                                <div class="vital-chip <?= $f?'flagged':'' ?>">
                                    <i class="fas <?= $ico ?>" style="color:<?= $f?'#C87A7A':'var(--s400)' ?>;width:18px;text-align:center;font-size:15px;"></i>
                                    <span class="vital-chip-label"><?= $lbl ?></span>
                                    <span class="vital-chip-value"><?= htmlspecialchars($val) ?> <?= $unit ?></span>
                                    <?php if($f): ?><i class="fas fa-circle-exclamation" style="color:#C87A7A;font-size:14px;"></i><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Flagged readings -->
                        <div class="col-md-4">
                            <div style="font-weight:700;font-size:17px;color:var(--s800);margin-bottom:14px;">
                                <i class="fas fa-bell me-1" style="color:var(--amber-text);"></i>Flagged Readings
                                <span style="background:<?= $alert_info['total_alerts']>0?'var(--red-bg)':'var(--green-bg)' ?>;color:<?= $alert_info['total_alerts']>0?'var(--red-text)':'var(--green-text)' ?>;padding:3px 10px;border-radius:20px;font-size:14px;font-weight:700;margin-left:6px;"><?= $alert_info['total_alerts'] ?></span>
                            </div>
                            <?php if($alert_info['total_alerts']>0): foreach($alert_info['alerts'] as $al):
                                $is_high=$al['status']==='HIGH'; ?>
                                <div class="ai-alert-item <?= $is_high?'':'low-item' ?>">
                                    <i class="fas <?= $is_high?'fa-arrow-trend-up':'fa-arrow-trend-down' ?> aii-icon"
                                       style="color:<?= $is_high?'var(--red-text)':'var(--blue-text)' ?>;"></i>
                                    <div>
                                        <div class="ai-alert-title"><?= htmlspecialchars($al['vital_sign']) ?></div>
                                        <div class="ai-alert-meta"><?= $al['value'].' '.$al['unit'] ?> — <strong><?= $al['status'] ?></strong></div>
                                        <div style="font-size:13px;color:var(--st300);">Normal: <?= $al['normal_range'] ?></div>
                                    </div>
                                </div>
                            <?php endforeach; else: ?>
                                <div class="all-clear">
                                    <i class="fas fa-circle-check aii-icon"></i>
                                    <div><strong style="color:var(--green-text);font-size:17px;">All Readings Normal</strong><br><span style="font-size:15px;color:var(--st500);">No flagged vitals today.</span></div>
                                </div>
                            <?php endif; ?>
                            <div class="tip-box"><i class="fas fa-lightbulb me-1"></i><?= htmlspecialchars($tips[$level]) ?></div>
                        </div>
                    </div>

                    <!-- Action notice -->
                    <?php if($level==='high'): ?>
                        <div class="action-notice high"><i class="fas fa-phone-volume fa-lg"></i><span>Please contact your carer immediately or press your call button if you feel unwell.</span></div>
                    <?php elseif($level==='medium'): ?>
                        <div class="action-notice medium"><i class="fas fa-person-walking fa-lg"></i><span>Your carer will review your readings. Follow your routine and rest if needed.</span></div>
                    <?php else: ?>
                        <div class="action-notice low"><i class="fas fa-star fa-lg"></i><span>Looking good! Keep up with your routines and enjoy your day.</span></div>
                    <?php endif; ?>

                    <!-- 30-day trend -->
                    <?php if(count($trend_data) >= 2): ?>
                    <div class="trend-section">
                        <div class="trend-title"><i class="fas fa-chart-line me-1"></i>Your 30-Day Risk Trend</div>
                        <div style="position:relative;height:80px;width:100%;">
                            <canvas id="trendChart" style="width:100%;height:80px;"></canvas>
                        </div>
                        <div class="d-flex gap-4 mt-2 justify-content-center" style="font-size:14px;font-weight:600;">
                            <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--s400);margin-right:5px;"></span>Low</span>
                            <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#D4A853;margin-right:5px;"></span>Medium</span>
                            <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#C87A7A;margin-right:5px;"></span>High</span>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="trend-section" style="text-align:center;color:var(--st300);font-size:15px;">
                        <i class="fas fa-chart-line me-1"></i>Your 30-day risk trend will appear after 2 or more days of readings are saved.
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- ═══ RISK PREDICTION HISTORY ══════════════════════════ -->
    <?php if(!empty($history)): ?>
    <div class="sc">
        <div class="sc-hdr">
            <h5><i class="fas fa-clock-rotate-left me-2"></i>My Risk Prediction History</h5>
            <span>Last <?= count($history) ?> predictions saved</span>
        </div>
        <div class="sc-body">
            <div class="hist-wrap">
                <table class="hist-table">
                    <thead><tr>
                        <th>Date &amp; Time</th>
                        <th>Risk Level</th>
                        <th>Score</th>
                        <th>Flagged Vitals</th>
                        <th>Notes</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach($history as $h):
                        $rc=['low'=>'var(--s400)','medium'=>'#D4A853','high'=>'#C87A7A'];
                        $c=$rc[$h['risk_level']]??'var(--st300)';
                    ?>
                    <tr>
                        <td style="white-space:nowrap;font-size:15px;"><?= $h['predicted_at_fmt'] ?></td>
                        <td>
                            <span class="rlvl <?= $h['risk_level'] ?>"><?= strtoupper($h['risk_level']) ?></span>
                            <?php if($h['is_escalation']): ?><span class="esc-badge"><i class="fas fa-arrow-trend-up me-1"></i>ESCALATION</span><?php endif; ?>
                            <?php if($h['is_fallback']): ?><span class="fb-badge">Rule-based</span><?php endif; ?>
                            <?php if($h['alert_created']): ?><span class="ac-badge"><i class="fas fa-bell me-1"></i>Alert sent</span><?php endif; ?>
                        </td>
                        <td>
                            <strong style="font-size:16px;"><?= $h['risk_percentage'] ?>%</strong>
                            <div style="height:6px;background:var(--s100);border-radius:4px;overflow:hidden;width:80px;margin-top:5px;">
                                <div style="height:100%;width:<?= $h['risk_percentage'] ?>%;background:<?= $c ?>;border-radius:4px;"></div>
                            </div>
                        </td>
                        <td>
                            <?php if($h['total_flagged']>0): ?>
                                <span style="color:var(--red-text);font-weight:700;font-size:15px;"><?= $h['total_flagged'] ?> flagged</span>
                                <?php if(!empty($h['flagged_vitals'])): ?>
                                    <div style="font-size:13px;color:var(--st500);margin-top:3px;">
                                        <?= implode(', ',array_map(fn($a)=>$a['vital_sign'].' ('.$a['status'].')',array_slice($h['flagged_vitals'],0,2))) ?>
                                        <?php if(count($h['flagged_vitals'])>2): ?>&hellip;<?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:var(--green-text);font-weight:600;font-size:15px;">None</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:14px;color:var(--st500);"><?= htmlspecialchars($h['model_used'] ?? 'Unknown') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="font-size:14px;color:var(--st300);margin-top:16px;margin-bottom:0;">
                <i class="fas fa-info-circle me-1"></i>
                Escalation means your risk level was higher than last time. "Alert sent" means your carer was automatically notified.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ THRESHOLD HEALTH WARNINGS ════════════════════════ -->
    <div class="sc">
        <div class="sc-hdr">
            <h5><i class="fas fa-triangle-exclamation me-2"></i>Health Warning History</h5>
            <span>
                <?php if(count($unresolved)>0): ?><span class="pulse"><i class="fas fa-circle me-1"></i><?= count($unresolved) ?> active</span>
                <?php else: ?><i class="fas fa-check-circle me-1"></i>All clear<?php endif; ?>
            </span>
        </div>
        <div class="sc-body">
        <?php if(empty($db_alerts)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h5>No Health Warnings</h5>
                <p>You have no recorded health warnings. Keep it up!</p>
            </div>
        <?php else: ?>
            <div class="meaning-box">
                <strong><i class="fas fa-circle-info me-1"></i>What are these warnings?</strong>
                These are alerts automatically generated when one of your vital sign readings went outside the normal range. Your carer can see these too. <em>Resolved</em> means your carer has reviewed it.
            </div>
            <?php foreach($db_alerts as $alert):
                $is_resolved=(bool)$alert['resolved'];
                $badge_class='badge-'.str_replace(' ','_',strtolower($alert['alert_type']??'health_warning'));
                $msg=$alert['alert_message'];
                $meaning='';
                if(stripos($msg,'blood pressure')!==false&&stripos($msg,'high')!==false)  $meaning='High BP can stress your heart. Your carer will review your activity and medication.';
                elseif(stripos($msg,'blood pressure')!==false&&stripos($msg,'low')!==false) $meaning='Low BP can cause dizziness. Sit or lie down and call your carer.';
                elseif(stripos($msg,'blood sugar')!==false&&stripos($msg,'high')!==false)   $meaning='High blood sugar may relate to diet or medication timing. Your carer will check.';
                elseif(stripos($msg,'blood sugar')!==false&&stripos($msg,'low')!==false)    $meaning='Low blood sugar can make you feel shaky. Eat something small and call your carer.';
                elseif(stripos($msg,'pulse')!==false||stripos($msg,'heart')!==false)        $meaning='An unusual heart rate was detected. Rest and inform your carer.';
                elseif(stripos($msg,'temperature')!==false)                                 $meaning='An unusual body temperature was recorded. Your carer will check.';
                elseif(stripos($msg,'oxygen')!==false)                                      $meaning='Low oxygen saturation needs attention. Your carer has been notified.';
                elseif(stripos($msg,'ai risk')!==false||stripos($msg,'escalated')!==false)  $meaning='The AI detected an elevated risk level based on your combined vitals. Your carer has been notified.';
            ?>
            <div class="alert-card <?= $is_resolved?'resolved':'' ?>">
                <div class="row align-items-center">
                    <div class="col-md-9">
                        <div class="d-flex align-items-start gap-3">
                            <div class="alert-icon-wrap <?= $is_resolved?'resolved':'active' ?>">
                                <?php if(!$is_resolved): ?><i class="fas fa-exclamation-circle pulse"></i>
                                <?php else: ?><i class="fas fa-check-circle"></i><?php endif; ?>
                            </div>
                            <div style="flex:1;">
                                <span class="alert-type-badge <?= $badge_class ?>"><?= strtoupper(str_replace('_',' ',$alert['alert_type']??'health warning')) ?></span>
                                <div class="alert-message"><?= htmlspecialchars($msg) ?></div>
                                <?php if($meaning): ?><p class="alert-meaning"><i class="fas fa-lightbulb me-1" style="color:var(--amber-text);"></i><?= $meaning ?></p><?php endif; ?>
                                <span class="time-badge"><i class="far fa-clock"></i><?= $alert['formatted_time'] ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 text-md-end mt-3 mt-md-0">
                        <?php if(!$is_resolved): ?>
                            <span class="status-pill-pending"><i class="fas fa-clock"></i>Awaiting Review</span>
                        <?php else: ?>
                            <span class="status-pill-reviewed"><i class="fas fa-check"></i>Reviewed by Carer</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>

</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    (function() {
    var BASE = 12, MIN = 10, MAX = 18;
    var sz = parseInt(localStorage.getItem('elderFontSize')) || BASE;
    if (isNaN(sz) || sz < MIN || sz > MAX) sz = BASE;
    document.documentElement.style.fontSize = sz + 'px';

    function save(v) {
        sz = v;
        document.documentElement.style.fontSize = sz + 'px';
        localStorage.setItem('elderFontSize', String(sz));
    }
    function toast(msg) {
        var old = document.getElementById('_acc_t');
        if (old) old.remove();
        var d = document.createElement('div');
        d.id = '_acc_t';
        d.style.cssText = 'position:fixed;bottom:28px;right:22px;z-index:99999;pointer-events:none;';
        d.innerHTML = '<div style="background:rgba(36,56,22,.96);color:#fff;padding:12px 20px;'
            + 'border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.28);'
            + 'font-weight:700;font-family:Outfit,sans-serif;font-size:16px;">' + msg + '</div>';
        document.body.appendChild(d);
        setTimeout(function() { if (d && d.parentNode) d.remove(); }, 2500);
    }
    window.increaseFontSize = function() {
        if (sz < MAX) { save(sz + 2); toast('Text enlarged (' + sz + 'px)'); }
        else toast('Maximum size reached');
    };
    window.decreaseFontSize = function() {
        if (sz > MIN) { save(sz - 2); toast('Text reduced (' + sz + 'px)'); }
        else toast('Minimum size reached');
    };
    window.resetFontSize = function() {
        save(BASE); toast('Text size reset');
    };
    window.toggleHighContrast = function() {
        document.body.classList.toggle('high-contrast');
        var on = document.body.classList.contains('high-contrast');
        localStorage.setItem('elderHighContrast', on ? 'true' : 'false');
        toast(on ? 'High contrast on' : 'High contrast off');
    };

    document.addEventListener('DOMContentLoaded', () => {
        const fill = document.getElementById('rbarFill');
        if (fill) { const t=fill.style.width; fill.style.width='0%'; setTimeout(()=>{fill.style.transition='width 1.2s cubic-bezier(.4,0,.2,1)';fill.style.width=t;},300); }

        const trendCanvas = document.getElementById('trendChart');
        if (trendCanvas) {
            const trend = <?= json_encode($trend_data) ?>;
            const colors = { low:'#7AA658', medium:'#D4A853', high:'#C87A7A' };
            new Chart(trendCanvas, {
                type:'line',
                data:{
                    labels: trend.map(t=>{ const d=new Date(t.date); return (d.getMonth()+1)+'/'+(d.getDate()); }),
                    datasets:[{
                        label:'Risk %',
                        data: trend.map(t=>parseFloat(t.avg_pct)),
                        borderColor:'#5E8A40', backgroundColor:'rgba(94,138,64,0.08)',
                        borderWidth:2.5, tension:0.38, fill:true,
                        pointBackgroundColor: trend.map(t=>colors[t.level]||'#B8B0A4'),
                        pointRadius:6, pointHoverRadius:8,
                    }]
                },
                options:{
                    responsive:true, maintainAspectRatio:false,
                    plugins:{
                        legend:{display:false},
                        tooltip:{callbacks:{
                            label:ctx=>' Risk: '+ctx.parsed.y+'%',
                            labelColor:ctx=>({borderColor:colors[trend[ctx.dataIndex]?.level]||'#B8B0A4',backgroundColor:colors[trend[ctx.dataIndex]?.level]||'#B8B0A4'})
                        }}
                    },
                    scales:{
                        x:{grid:{display:false},ticks:{font:{size:13}}},
                        y:{min:0,max:100,grid:{color:'rgba(196,217,180,.3)'},ticks:{font:{size:13},callback:v=>v+'%'}}
                    }
                }
            });
        }
    });

    const activeCount = <?= count($unresolved) ?>;
    if (activeCount > 0) {
        let orig=document.title, alt=false;
        setInterval(()=>{document.title=alt?orig:`(${activeCount}) Health Warning - SmartCare`;alt=!alt;},1500);
    }
    document.addEventListener('DOMContentLoaded', function() {
        document.documentElement.style.fontSize = sz + 'px';
        if (localStorage.getItem('elderHighContrast') === 'true')
            document.body.classList.add('high-contrast');
    });
})();
</script>
</body>
</html>