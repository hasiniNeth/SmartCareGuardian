<?php
session_start();
include '../db_connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/SmartCareGuardian/includes/ai_service.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php"); exit();
}
$caregiver_id = $_SESSION['user_id'];

// ── Resolve single alert ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_alert'])) {
    $aid = intval($_POST['alert_id']);
    $s   = $conn->prepare("UPDATE alerts SET resolved=1 WHERE alert_id=?");
    $s->bind_param("i", $aid); $s->execute();
    header("Location: caregiver_ai_alerts.php"); exit();
}
// ── Mark all resolved ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $s = $conn->prepare("UPDATE alerts SET resolved=1 WHERE resident_id IN (SELECT resident_id FROM caregiver_assignments WHERE caregiver_id=?)");
    $s->bind_param("i", $caregiver_id); $s->execute();
    header("Location: caregiver_ai_alerts.php"); exit();
}

// ── Assigned residents ───────────────────────────────────────────────────────
$as = $conn->prepare("SELECT resident_id FROM caregiver_assignments WHERE caregiver_id=?");
$as->bind_param("i", $caregiver_id); $as->execute();
$assigned_ids = array_column($as->get_result()->fetch_all(MYSQLI_ASSOC), 'resident_id');

// ── Residents + latest health log ────────────────────────────────────────────
$residents_data = [];
if (!empty($assigned_ids)) {
    $ph = implode(',', array_fill(0, count($assigned_ids), '?'));
    $tp = str_repeat('i', count($assigned_ids));
    $rs = $conn->prepare("
        SELECT u.user_id, u.full_name,
               hl.blood_pressure_systolic, hl.blood_pressure_diastolic,
               hl.blood_sugar, hl.pulse, hl.weight, hl.temperature, hl.oxygen_saturation,
               hl.logged_at AS last_log
        FROM users u
        LEFT JOIN (
            SELECT resident_id,
                   blood_pressure_systolic, blood_pressure_diastolic,
                   blood_sugar, pulse, weight, temperature, oxygen_saturation, logged_at,
                   ROW_NUMBER() OVER (PARTITION BY resident_id ORDER BY logged_at DESC) AS rn
            FROM health_logs
        ) hl ON hl.resident_id = u.user_id AND hl.rn = 1
        WHERE u.user_id IN ($ph) AND u.role = 'resident'
        ORDER BY u.full_name
    ");
    $rs->bind_param($tp, ...$assigned_ids);
    $rs->execute();
    $residents_data = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── AI predictions ───────────────────────────────────────────────────────────
$ai          = new AIService($conn);
$ai_online   = $ai->checkStatus()['success'] ?? false;
$predictions = [];

function buildFallback(array $v): array {
    $score=0; $al=[];
    if ($v['blood_pressure_systolic']>140)   {$score+=2;$al[]=['vital_sign'=>'Blood Pressure Systolic','value'=>$v['blood_pressure_systolic'],'unit'=>'mmHg','status'=>'HIGH','normal_range'=>'90-140 mmHg'];}
    elseif($v['blood_pressure_systolic']<90) {$score+=2;$al[]=['vital_sign'=>'Blood Pressure Systolic','value'=>$v['blood_pressure_systolic'],'unit'=>'mmHg','status'=>'LOW','normal_range'=>'90-140 mmHg'];}
    if ($v['blood_sugar']>180)    {$score+=2;$al[]=['vital_sign'=>'Blood Sugar','value'=>$v['blood_sugar'],'unit'=>'mg/dL','status'=>'HIGH','normal_range'=>'70-180 mg/dL'];}
    elseif($v['blood_sugar']<70)  {$score+=2;$al[]=['vital_sign'=>'Blood Sugar','value'=>$v['blood_sugar'],'unit'=>'mg/dL','status'=>'LOW','normal_range'=>'70-180 mg/dL'];}
    if ($v['temperature']>37.8)   {$score+=1;$al[]=['vital_sign'=>'Temperature','value'=>$v['temperature'],'unit'=>'°C','status'=>'HIGH','normal_range'=>'36.0-37.8°C'];}
    elseif($v['temperature']<36.0){$score+=1;$al[]=['vital_sign'=>'Temperature','value'=>$v['temperature'],'unit'=>'°C','status'=>'LOW','normal_range'=>'36.0-37.8°C'];}
    if ($v['oxygen_saturation']<95){$score+=2;$al[]=['vital_sign'=>'Oxygen Saturation','value'=>$v['oxygen_saturation'],'unit'=>'%','status'=>'LOW','normal_range'=>'95-100%'];}
    $pct=$score*20; if($pct>95)$pct=95; $level=$pct>=60?'high':($pct>=30?'medium':'low');
    return ['prediction'=>['risk_level'=>$level,'risk_percentage'=>$pct,'risk_prediction'=>(int)($level!=='low'),'risk_probability'=>$pct/100],
            'alerts'=>['total_alerts'=>count($al),'alerts'=>$al,'requires_attention'=>count($al)>0]];
}

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

// ── DB alerts ────────────────────────────────────────────────────────────────
$db_alerts = [];
if (!empty($assigned_ids)) {
    $ph = implode(',', array_fill(0, count($assigned_ids), '?'));
    $tp = str_repeat('i', count($assigned_ids));
    $als = $conn->prepare("
        SELECT a.*, u.full_name AS resident_name,
               DATE_FORMAT(a.created_at,'%Y-%m-%d %h:%i %p') AS formatted_time
        FROM alerts a JOIN users u ON a.resident_id = u.user_id
        WHERE a.resident_id IN ($ph) ORDER BY a.created_at DESC
    ");
    $als->bind_param($tp, ...$assigned_ids);
    $als->execute();
    $db_alerts = $als->get_result()->fetch_all(MYSQLI_ASSOC);
}
$unresolved = array_filter($db_alerts, fn($a) => !$a['resolved']);

// ── Risk counts + trend data ─────────────────────────────────────────────────
$risk_counts = ['high'=>0,'medium'=>0,'low'=>0,'no_data'=>0];
$trends      = [];
foreach ($residents_data as $r) {
    $p = $predictions[$r['user_id']] ?? null;
    $risk_counts[$p ? $p['prediction']['risk_level'] : 'no_data']++;
    $trends[$r['user_id']] = $ai->getTrend((int)$r['user_id'], 14);
}

// ── Sidebar badges ───────────────────────────────────────────────────────────
$ms = $conn->prepare("SELECT COUNT(*) AS cnt FROM medications m JOIN caregiver_assignments ca ON ca.resident_id=m.resident_id WHERE ca.caregiver_id=? AND m.taken=0 AND m.medication_date=CURDATE()");
$ms->bind_param("i",$caregiver_id); $ms->execute();
$pending_meds = $ms->get_result()->fetch_assoc()['cnt'] ?? 0;
$us = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id=? AND is_read=0");
$us->bind_param("i",$caregiver_id); $us->execute();
$unread_messages = $us->get_result()->fetch_assoc()['cnt'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Health Alerts — SmartCare Guardian</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════
   SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
   Caregiver AI Alerts · Professional scale
═══════════════════════════════════════════════════════════ */
:root{
    --s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;
    --s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;
    --s600:#4A6E30;--s700:#365220;--s800:#243816;
    --w50:#FDFAF5;--w100:#F7F1E5;
    --st300:#B8B0A4;--st500:#7A7268;--st700:#4A4540;
    --green-bg:#DDEFD8;--green-text:#3A6830;
    --amber-bg:#FAECC8;--amber-text:#7A5010;
    --red-bg:#F5DADA;  --red-text:#6A2020;
    --blue-bg:#DBEEFF; --blue-text:#1A4870;
    --radius-sm:8px;--radius-md:12px;--radius-lg:20px;
    --shadow-soft:0 2px 12px rgba(36,56,22,.07),0 1px 3px rgba(36,56,22,.05);
    --shadow-card:0 4px 24px rgba(36,56,22,.09),0 1px 4px rgba(36,56,22,.06);
    --shadow-lift:0 8px 32px rgba(36,56,22,.13),0 2px 8px rgba(36,56,22,.07);
}
*{box-sizing:border-box;}
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
.sidebar-header{padding:22px 18px 16px;border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0;position:relative;}
.brand-mark{display:flex;align-items:center;gap:9px;margin-bottom:4px;}
.brand-icon{width:32px;height:32px;background:linear-gradient(135deg,var(--s300),var(--s500));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;color:white;box-shadow:0 3px 10px rgba(0,0,0,.25);flex-shrink:0;}
.sidebar-header h4{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:white;line-height:1.15;}
.sidebar-header small{font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.08em;text-transform:uppercase;font-weight:500;display:block;margin-left:41px;margin-top:1px;}
.sidebar-nav{flex:1;overflow-y:auto;padding:8px 0;position:relative;}
.sidebar-nav::-webkit-scrollbar{width:4px;}.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:3px;}
.sidebar a{display:flex;align-items:center;gap:9px;padding:10px 10px 10px 20px;color:rgba(255,255,255,.6);text-decoration:none;font-size:13.5px;font-weight:500;transition:all .2s;margin:1px 8px;border-radius:var(--radius-sm);position:relative;min-height:42px;}
.sidebar a:hover{background:rgba(255,255,255,.10);color:white;}
.sidebar a.active{background:rgba(157,192,126,.2);color:#C8E6A0;}
.sidebar a.active::before{content:'';position:absolute;left:-8px;top:20%;bottom:20%;width:3px;background:var(--s300);border-radius:0 3px 3px 0;}
.sidebar i{width:18px;text-align:center;font-size:13px;opacity:.85;flex-shrink:0;}
.sb-badge{margin-left:auto;background:#8B3A3A;color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;}
.msg-badge{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:#8B3A3A;border-radius:50%;min-width:18px;height:18px;font-size:.65rem;display:flex;align-items:center;justify-content:center;padding:0 3px;color:white;font-weight:700;}
.sidebar-footer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 8px;position:relative;}
.sidebar-footer a{margin:0;color:rgba(255,255,255,.5)!important;font-size:13.5px;}
.sidebar-footer a:hover{color:rgba(255,255,255,.8)!important;}

/* ── Layout ── */
.content{margin-left:240px;padding:24px;min-height:100vh;}

/* ── Topbar ── */
.topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
.topbar h4{font-size:22px;font-weight:500;color:var(--s800);margin-bottom:2px;}
.topbar p{font-size:13px;color:var(--st300);margin:0;}
.logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;}
.logout-btn:hover{opacity:.9;transform:translateY(-1px);}

/* ── Stats grid ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:22px;}
.stat-card{background:white;border-radius:var(--radius-lg);padding:18px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);transition:transform .2s;text-align:center;}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lift);}
.stat-icon{width:48px;height:48px;border-radius:var(--radius-md);margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;}
.ic-total {background:linear-gradient(135deg,var(--s300),var(--s600));color:white;}
.ic-high  {background:linear-gradient(135deg,#C87A7A,#8B3A3A);color:white;}
.ic-medium{background:linear-gradient(135deg,#D4A847,#A07830);color:white;}
.ic-low   {background:linear-gradient(135deg,var(--s400),var(--s700));color:white;}
.ic-pending{background:linear-gradient(135deg,#C87A7A,#8B3A3A);color:white;}
.ic-res   {background:linear-gradient(135deg,var(--s300),var(--s500));color:white;}
.stat-number{font-size:1.8rem;font-weight:800;color:var(--s800);line-height:1;}
.stat-label {font-size:12px;color:var(--st500);font-weight:600;margin-top:4px;}

/* ── Section card ── */
.sc{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;overflow:hidden;}
.sc-hdr{background:linear-gradient(135deg,var(--s600),var(--s800));padding:16px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;position:relative;overflow:hidden;}
.sc-hdr::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
.sc-hdr h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;position:relative;display:flex;align-items:center;gap:7px;}
.sc-hdr span{color:rgba(255,255,255,.75);font-size:12px;position:relative;}
.sc-body{padding:22px 24px;}

/* ── Filter tabs ── */
.filter-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;}
.ftab{padding:6px 16px;border-radius:25px;font-size:12px;font-weight:700;cursor:pointer;border:2px solid transparent;transition:all .2s;background:white;font-family:'Outfit',sans-serif;}
.ftab[data-risk="all"]    {border-color:var(--st300);color:var(--st500);}
.ftab[data-risk="high"]   {border-color:#C87A7A;color:#8B3A3A;}
.ftab[data-risk="medium"] {border-color:#D4A847;color:#A07830;}
.ftab[data-risk="low"]    {border-color:var(--s400);color:var(--s600);}
.ftab[data-risk="no_data"]{border-color:var(--st300);color:var(--st300);}
.ftab.active[data-risk="all"]    {background:var(--st500);color:white;border-color:var(--st500);}
.ftab.active[data-risk="high"]   {background:#8B3A3A;color:white;border-color:#8B3A3A;}
.ftab.active[data-risk="medium"] {background:#A07830;color:white;border-color:#A07830;}
.ftab.active[data-risk="low"]    {background:var(--s500);color:white;border-color:var(--s500);}
.ftab.active[data-risk="no_data"]{background:var(--st300);color:white;border-color:var(--st300);}

/* ── Resident AI card ── */
.rac{background:var(--w50);border-radius:var(--radius-lg);box-shadow:var(--shadow-soft);margin-bottom:18px;overflow:hidden;border-left:5px solid var(--st300);transition:transform .2s,box-shadow .2s;border:1px solid var(--s100);border-left-width:5px;}
.rac:hover{transform:translateY(-2px);box-shadow:var(--shadow-card);}
.rac[data-risk="high"]  {border-left-color:#8B3A3A;}
.rac[data-risk="medium"]{border-left-color:#A07830;}
.rac[data-risk="low"]   {border-left-color:var(--s500);}
.rac-hdr{display:flex;align-items:center;gap:12px;padding:14px 18px 10px;}
.rac-av{width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1rem;color:white;flex-shrink:0;}
.rac-av.high  {background:linear-gradient(135deg,#C87A7A,#8B3A3A);}
.rac-av.medium{background:linear-gradient(135deg,#D4A847,#A07830);}
.rac-av.low   {background:linear-gradient(135deg,var(--s400),var(--s700));}
.rac-av.no_data{background:linear-gradient(135deg,var(--st300),var(--st500));}
.rac-name{font-weight:700;font-size:14px;color:var(--s800);}
.rac-meta{font-size:12px;color:var(--st300);margin-top:2px;}
.rac-badge{margin-left:auto;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;color:white;font-family:'Outfit',sans-serif;}
.rac-badge.high  {background:#8B3A3A;}
.rac-badge.medium{background:#A07830;}
.rac-badge.low   {background:var(--s600);}
.rac-badge.no_data{background:var(--st500);}

/* ── Vital chips ── */
.rac-vitals{display:flex;flex-wrap:wrap;gap:6px;padding:0 18px 10px;}
.rv-chip{background:var(--s50);border-radius:var(--radius-sm);padding:4px 10px;font-size:12px;font-weight:600;color:var(--s800);border:1px solid var(--s100);}
.rv-chip.flagged{background:var(--red-bg);color:var(--red-text);border-color:rgba(107,34,34,.15);}

/* ── Risk progress bar ── */
.rac-bar-wrap{padding:0 18px 8px;}
.rac-bar-label{display:flex;justify-content:space-between;font-size:11px;color:var(--st300);margin-bottom:4px;font-weight:700;}
.rac-bar-track{height:7px;border-radius:6px;background:var(--s100);overflow:hidden;}
.rac-bar-fill{height:100%;border-radius:6px;transition:width 1s ease;}
.rac-bar-fill.high  {background:linear-gradient(90deg,#C87A7A,#8B3A3A);}
.rac-bar-fill.medium{background:linear-gradient(90deg,#D4A847,#A07830);}
.rac-bar-fill.low   {background:linear-gradient(90deg,var(--s400),var(--s600));}
.rac-bar-fill.no_data{background:var(--st300);}

/* ── AI alert chips ── */
.rac-ai-alerts{padding:4px 18px 10px;display:flex;flex-wrap:wrap;gap:6px;}
.ai-chip{display:inline-flex;align-items:center;gap:4px;background:var(--red-bg);color:var(--red-text);border-radius:var(--radius-sm);padding:4px 10px;font-size:12px;font-weight:700;}
.ai-chip.medium{background:var(--amber-bg);color:var(--amber-text);}

/* ── Action notice ── */
.action-notice{margin:0 18px 12px;border-radius:var(--radius-sm);padding:10px 13px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:7px;}
.action-notice.high  {background:var(--red-bg);  color:var(--red-text);}
.action-notice.medium{background:var(--amber-bg);color:var(--amber-text);}

/* ── Trend chart ── */
.trend-wrap{padding:4px 18px 12px;}
.trend-lbl{font-size:11px;color:var(--st300);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;}
.no-trend{font-size:12px;color:var(--st300);padding:4px 0;}

/* ── Risk history ── */
.hist-panel{padding:0 18px 14px;border-top:1px solid var(--s100);background:var(--s50);}
.hist-table{width:100%;border-collapse:separate;border-spacing:0 4px;font-size:12px;}
.hist-table thead th{padding:8px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--st500);font-weight:700;border-bottom:2px solid var(--s100);}
.hist-table tbody tr{background:white;box-shadow:0 1px 4px rgba(36,56,22,.05);}
.hist-table tbody td{padding:8px 10px;vertical-align:middle;}
.hist-table tbody td:first-child{border-radius:var(--radius-sm) 0 0 var(--radius-sm);}
.hist-table tbody td:last-child {border-radius:0 var(--radius-sm) var(--radius-sm) 0;}
.rlvl{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.rlvl.high  {background:var(--red-bg);  color:var(--red-text);}
.rlvl.medium{background:var(--amber-bg);color:var(--amber-text);}
.rlvl.low   {background:var(--green-bg);color:var(--green-text);}
.esc-badge{background:var(--red-bg);color:var(--red-text);border-radius:5px;padding:2px 7px;font-size:10px;font-weight:700;margin-left:4px;}
.fb-badge {background:var(--s50);color:var(--st500);border-radius:5px;padding:2px 7px;font-size:10px;margin-left:4px;}

/* ── Card footer ── */
.rac-footer{display:flex;align-items:center;justify-content:space-between;padding:10px 18px;background:var(--s50);border-top:1px solid var(--s100);font-size:12px;color:var(--st300);}
.rac-footer a{color:var(--s600);font-weight:700;text-decoration:none;font-size:12px;}
.rac-footer a:hover{text-decoration:underline;}
.hist-btn{font-size:12px;padding:5px 13px;border-radius:20px;background:transparent;border:2px solid var(--s400);color:var(--s600);font-weight:700;cursor:pointer;transition:all .2s;font-family:'Outfit',sans-serif;}
.hist-btn:hover{background:var(--s600);color:white;border-color:var(--s600);}

/* ── DB / Threshold alert cards ── */
.alert-card{background:white;border-radius:var(--radius-md);padding:18px;margin-bottom:14px;box-shadow:var(--shadow-soft);border-left:5px solid var(--red-text);transition:all .2s;border:1px solid var(--s100);border-left-width:5px;}
.alert-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-card);}
.alert-card.resolved{opacity:.75;border-left-color:var(--st300);}
.alert-type-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-health_warning{background:var(--green-bg);color:var(--green-text);}
.badge-critical      {background:var(--red-bg);  color:var(--red-text);}
.badge-warning       {background:var(--amber-bg);color:var(--amber-text);}
.badge-ai_risk       {background:#EDE9FE;         color:#5A3A7A;}
.resident-pill{background:var(--s50);color:var(--s700);padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;border:1px solid var(--s200);}
.time-badge{background:var(--s50);color:var(--st500);padding:3px 8px;border-radius:var(--radius-sm);font-size:11px;display:inline-block;margin-top:5px;border:1px solid var(--s100);}
.btn-resolve{background:linear-gradient(135deg,var(--s400),var(--s700));border:none;border-radius:22px;padding:7px 18px;font-size:13px;font-weight:700;color:white;transition:all .2s;cursor:pointer;font-family:'Outfit',sans-serif;}
.btn-resolve:hover{opacity:.9;transform:translateY(-1px);}
.btn-mark-all{background:linear-gradient(135deg,var(--s500),var(--s800));border:none;border-radius:22px;padding:10px 24px;font-weight:700;font-size:13px;color:white;transition:all .2s;cursor:pointer;font-family:'Outfit',sans-serif;}
.btn-mark-all:hover{opacity:.9;transform:translateY(-1px);}

/* ── Empty / spinner / animations ── */
.empty-state{text-align:center;padding:50px;color:var(--st300);}
#noResidents{display:none;text-align:center;padding:36px;color:var(--st300);}
.spin-sm{width:18px;height:18px;border:2px solid var(--s100);border-top-color:var(--s400);border-radius:50%;animation:sp .7s linear infinite;display:inline-block;}
@keyframes sp{to{transform:rotate(360deg);}}
@keyframes pulse-anim{0%,100%{opacity:1}50%{opacity:.45}}.pulse{animation:pulse-anim 2s infinite;}
@keyframes floating{0%,100%{transform:translateY(0)}50%{transform:translateY(-7px)}}.floating{animation:floating 3s ease-in-out infinite;}

@media(max-width:768px){
    .sidebar{width:100%;height:auto;position:relative;}
    .content{margin-left:0;padding:14px;}
}
</style>
</head>
<body>

<!-- ══ SIDEBAR ════════════════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sidebar-header">
        <div class="brand-mark">
            <div class="brand-icon"><i class="fas fa-leaf"></i></div>
            <h4>SmartCare<br>Guardian</h4>
        </div>
        <small>Caregiver Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="caregiver_dashboard.php"><i class="fa-solid fa-gauge-high"></i>Dashboard</a>
        <a href="caregiver_profile.php"><i class="fa-solid fa-user-pen"></i>My Profile</a>
        <a href="caregiver_residents.php"><i class="fa-solid fa-user-group"></i>My Residents</a>
        <a href="log_health.php"><i class="fa-solid fa-heart-pulse"></i>Log Health Data</a>
        <a href="manage_routines.php"><i class="fa-solid fa-calendar-check"></i>Manage Routines</a>
        <a href="manage_medications.php">
            <i class="fa-solid fa-pills"></i>Medications
            <?php if($pending_meds>0): ?><span class="sb-badge"><?= $pending_meds ?></span><?php endif; ?>
        </a>
        <a href="caregiver_appointments.php"><i class="fa-solid fa-calendar-days"></i>Appointments</a>
        <a href="caregiver_messages.php">
            <i class="fa-solid fa-comments"></i>Messages
            <?php if($unread_messages>0): ?><span class="msg-badge"><?= $unread_messages ?></span><?php endif; ?>
        </a>
        <a href="caregiver_ai_alerts.php" class="active"><i class="fa-solid fa-bell"></i>Health Alerts</a>
        <a href="ai_suggestions.php"><i class="fa-solid fa-wand-magic-sparkles"></i>Routine Suggestions</a>
        <a href="caregiver_reports.php"><i class="fa-solid fa-chart-line"></i>Health Reports</a>
    </div>
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <div class="topbar">
        <div>
            <h4><i class="fas fa-brain me-2" style="font-size:18px;color:var(--s500);"></i>Health Alerts &amp; AI Risk Monitor</h4>
            <p>AI risk assessment + alerts for your assigned residents</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span style="font-size:13px;font-weight:600;color:var(--st500);">
                <?php if($ai_online): ?>
                    <i class="fas fa-circle pulse me-1" style="color:var(--s400);font-size:9px;"></i>AI Online
                <?php else: ?>
                    <i class="fas fa-circle me-1" style="color:var(--red-text);font-size:9px;"></i>AI Offline
                <?php endif; ?>
            </span>
            <form action="/SmartCareGuardian/logout.php" method="POST">
                <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt"></i>Logout</button>
            </form>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon ic-total"><i class="fas fa-users"></i></div><div class="stat-number"><?= count($residents_data) ?></div><div class="stat-label">Assigned Residents</div></div>
        <div class="stat-card"><div class="stat-icon ic-high"><i class="fas fa-circle-exclamation"></i></div><div class="stat-number"><?= $risk_counts['high'] ?></div><div class="stat-label">High Risk</div></div>
        <div class="stat-card"><div class="stat-icon ic-medium"><i class="fas fa-triangle-exclamation"></i></div><div class="stat-number"><?= $risk_counts['medium'] ?></div><div class="stat-label">Medium Risk</div></div>
        <div class="stat-card"><div class="stat-icon ic-low"><i class="fas fa-circle-check"></i></div><div class="stat-number"><?= $risk_counts['low'] ?></div><div class="stat-label">Low Risk</div></div>
        <div class="stat-card"><div class="stat-icon ic-pending"><i class="fas fa-bell"></i></div><div class="stat-number"><?= count($unresolved) ?></div><div class="stat-label">Pending Alerts</div></div>
        <div class="stat-card"><div class="stat-icon ic-res"><i class="fas fa-check-double"></i></div><div class="stat-number"><?= count($db_alerts)-count($unresolved) ?></div><div class="stat-label">Resolved</div></div>
    </div>

    <!-- ═══ AI RISK PER RESIDENT ══════════════════════════════════ -->
    <div class="sc">
        <div class="sc-hdr">
            <h5><i class="fas fa-brain"></i>AI Risk Assessment — Per Resident</h5>
            <span>Risk saved to database · 14-day trends · escalation detection</span>
        </div>
        <div class="sc-body">
        <?php if(empty($residents_data)): ?>
            <div class="empty-state">
                <i class="fas fa-users-slash fa-3x mb-3 floating d-block" style="color:var(--s200);"></i>
                <p style="color:var(--st300);">No residents assigned to you yet.</p>
            </div>
        <?php else: ?>
            <div class="filter-tabs">
                <button class="ftab active" data-risk="all">All (<?= count($residents_data) ?>)</button>
                <button class="ftab" data-risk="high"><i class="fas fa-fire me-1"></i>High (<?= $risk_counts['high'] ?>)</button>
                <button class="ftab" data-risk="medium"><i class="fas fa-bolt me-1"></i>Medium (<?= $risk_counts['medium'] ?>)</button>
                <button class="ftab" data-risk="low"><i class="fas fa-leaf me-1"></i>Low (<?= $risk_counts['low'] ?>)</button>
                <button class="ftab" data-risk="no_data"><i class="fas fa-circle-question me-1"></i>No Data (<?= $risk_counts['no_data'] ?>)</button>
            </div>

            <div id="residentsContainer">
            <?php
            $lvl_labels=['high'=>'High Risk','medium'=>'Medium Risk','low'=>'Low Risk','no_data'=>'No Data'];
            foreach($residents_data as $r):
                $rid       = $r['user_id'];
                $pred      = $predictions[$rid] ?? null;
                $level     = $pred ? $pred['prediction']['risk_level'] : 'no_data';
                $pct       = $pred ? $pred['prediction']['risk_percentage'] : 0;
                $ai_alerts = $pred ? $pred['alerts']['alerts'] : [];
                $flagged   = []; foreach($ai_alerts as $a) $flagged[$a['vital_sign']]=$a['status'];
                $initials  = strtoupper(substr($r['full_name'],0,1));
                if(strpos($r['full_name'],' ')!==false) $initials .= strtoupper(substr(strrchr($r['full_name'],' '),1,1));
                $trend_data = json_encode($trends[$rid] ?? []);
                $has_trend  = count($trends[$rid] ?? []) >= 2;
            ?>
            <div class="rac" data-risk="<?= $level ?>">
                <div class="rac-hdr">
                    <div class="rac-av <?= $level ?>"><?= $initials ?></div>
                    <div>
                        <div class="rac-name"><?= htmlspecialchars($r['full_name']) ?></div>
                        <div class="rac-meta">
                            <?php if($r['last_log']): ?>
                                <i class="fas fa-clock me-1"></i>Last reading: <?= date('d M Y, H:i',strtotime($r['last_log'])) ?>
                            <?php else: ?>
                                <i class="fas fa-minus-circle me-1"></i>No readings logged yet
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="rac-badge <?= $level ?>"><?= $lvl_labels[$level] ?></span>
                </div>

                <?php if($r['last_log']): ?>
                <!-- Vital chips -->
                <div class="rac-vitals">
                    <?php $chips=[
                        ['Blood Pressure Systolic',$r['blood_pressure_systolic'].'/'.$r['blood_pressure_diastolic'],'mmHg','BP'],
                        ['Blood Sugar',$r['blood_sugar'],'mg/dL','Sugar'],
                        ['Pulse',$r['pulse'],'bpm','Pulse'],
                        ['Temperature',$r['temperature'],'°C','Temp'],
                        ['Oxygen Saturation',$r['oxygen_saturation'],'%','O₂'],
                        ['Weight',$r['weight'],'kg','Wt'],
                    ];
                    foreach($chips as [$key,$val,$unit,$lbl]):
                        $f=isset($flagged[$key]); ?>
                        <span class="rv-chip <?= $f?'flagged':'' ?>">
                            <?php if($f): ?><i class="fas fa-exclamation-triangle me-1"></i><?php endif; ?>
                            <?= $lbl ?>: <?= $val ?> <?= $unit ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <!-- Risk bar -->
                <div class="rac-bar-wrap">
                    <div class="rac-bar-label"><span>AI Risk Probability</span><span><?= $pct ?>%</span></div>
                    <div class="rac-bar-track"><div class="rac-bar-fill <?= $level ?>" style="width:<?= $pct ?>%"></div></div>
                </div>

                <!-- AI alert chips -->
                <?php if(!empty($ai_alerts)): ?>
                <div class="rac-ai-alerts">
                    <?php foreach($ai_alerts as $al): $cc=$al['status']==='HIGH'?'':'medium'; ?>
                        <span class="ai-chip <?= $cc ?>">
                            <i class="fas <?= $al['status']==='HIGH'?'fa-arrow-trend-up':'fa-arrow-trend-down' ?>"></i>
                            <?= htmlspecialchars($al['vital_sign']) ?> <?= $al['status'] ?> (<?= $al['value'].' '.$al['unit'] ?>)
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Trend chart -->
                <div class="trend-wrap">
                    <div class="trend-lbl"><i class="fas fa-chart-line me-1"></i>14-Day Risk Trend</div>
                    <?php if($has_trend): ?>
                        <canvas id="trend-<?= $rid ?>" height="55"
                                data-trend="<?= htmlspecialchars($trend_data) ?>"
                                style="width:100%;"></canvas>
                    <?php else: ?>
                        <div class="no-trend"><i class="fas fa-clock me-1"></i>Trend appears after 2+ days of data.</div>
                    <?php endif; ?>
                </div>

                <?php if($level==='high'): ?>
                    <div class="action-notice high"><i class="fas fa-circle-exclamation"></i>Immediate attention required — check on this resident now.</div>
                <?php elseif($level==='medium'): ?>
                    <div class="action-notice medium"><i class="fas fa-triangle-exclamation"></i>Monitor closely today — consider adjusting routine if readings persist.</div>
                <?php endif; ?>

                <?php else: ?>
                <div style="padding:4px 18px 12px;color:var(--st300);font-size:13px;"><i class="fas fa-clock me-1"></i>Log a health reading to enable AI assessment.</div>
                <?php endif; ?>

                <!-- Footer -->
                <div class="rac-footer">
                    <span>
                        <?php if($level==='high'): ?><i class="fas fa-fire me-1" style="color:var(--red-text);"></i>High priority
                        <?php elseif($level==='medium'): ?><i class="fas fa-bolt me-1" style="color:var(--amber-text);"></i>Monitor today
                        <?php else: ?><i class="fas fa-circle-check me-1" style="color:var(--green-text);"></i>Stable<?php endif; ?>
                    </span>
                    <div class="d-flex gap-3 align-items-center">
                        <a href="log_health.php?resident_id=<?= $rid ?>"><i class="fas fa-plus me-1"></i>Log Reading</a>
                        <button class="hist-btn" onclick="toggleHistory(<?= $rid ?>)">
                            <i class="fas fa-clock-rotate-left me-1"></i>Risk History
                        </button>
                    </div>
                </div>

                <!-- History panel -->
                <div id="hist-<?= $rid ?>" style="display:none;" class="hist-panel">
                    <div id="hist-content-<?= $rid ?>">
                        <div class="text-center py-3"><div class="spin-sm"></div></div>
                    </div>
                </div>

            </div><!-- /rac -->
            <?php endforeach; ?>
            </div>

            <div id="noResidents"><i class="fas fa-search-minus fa-2x mb-2 d-block"></i><p>No residents match this filter.</p></div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Mark all resolved -->
    <?php if(count($unresolved)>0): ?>
    <div class="sc">
        <div class="sc-body text-center py-3">
            <form method="POST">
                <button type="submit" name="mark_all_read" class="btn-mark-all">
                    <i class="fas fa-check-double me-2"></i>Mark All <?= count($unresolved) ?> Threshold Alerts as Resolved
                </button>
                <p style="color:var(--st300);font-size:12px;margin-top:8px;margin-bottom:0;">This resolves all pending alerts for your residents.</p>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ THRESHOLD ALERTS ══════════════════════════════════════ -->
    <div class="sc">
        <div class="sc-hdr">
            <h5><i class="fas fa-bell"></i>Threshold-Based Health Alerts</h5>
            <span>
                <?php if(count($unresolved)>0): ?>
                    <span class="pulse"><i class="fas fa-circle me-1" style="font-size:8px;"></i><?= count($unresolved) ?> pending</span>
                <?php else: ?>
                    <i class="fas fa-check-circle me-1"></i>All clear
                <?php endif; ?>
            </span>
        </div>
        <div class="sc-body">
        <?php if(empty($db_alerts)): ?>
            <div class="empty-state floating">
                <i class="fas fa-check-circle fa-3x mb-3 d-block" style="color:var(--s200);"></i>
                <h5 style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--st500);">No Alerts</h5>
                <p style="color:var(--st300);">All assigned residents are within normal thresholds.</p>
            </div>
        <?php else: ?>
            <?php foreach($db_alerts as $alert): $badge='badge-'.($alert['alert_type']??'health_warning'); ?>
            <div class="alert-card <?= $alert['resolved']?'resolved':'' ?>">
                <div class="row align-items-center">
                    <div class="col-lg-8">
                        <div class="d-flex align-items-start gap-3">
                            <div style="font-size:1.3rem;color:<?= $alert['resolved']?'var(--st300)':'var(--red-text)' ?>;margin-top:2px;flex-shrink:0;">
                                <?php if(!$alert['resolved']): ?><i class="fas fa-exclamation-circle pulse"></i>
                                <?php else: ?><i class="fas fa-check-circle"></i><?php endif; ?>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                                    <span class="alert-type-badge <?= $badge ?>"><?= strtoupper(str_replace('_',' ',$alert['alert_type'])) ?></span>
                                    <span class="resident-pill"><i class="fas fa-user me-1"></i><?= htmlspecialchars($alert['resident_name']) ?></span>
                                </div>
                                <p class="mb-1" style="font-weight:700;font-size:14px;color:var(--s800);"><?= htmlspecialchars($alert['alert_message']) ?></p>
                                <span class="time-badge"><i class="far fa-clock me-1"></i><?= $alert['formatted_time'] ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                        <?php if(!$alert['resolved']): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="alert_id" value="<?= $alert['alert_id'] ?>">
                                <button type="submit" name="resolve_alert" class="btn-resolve"><i class="fas fa-check me-1"></i>Mark Resolved</button>
                            </form>
                        <?php else: ?>
                            <span style="background:var(--green-bg);color:var(--green-text);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;">
                                <i class="fas fa-check me-1"></i>Resolved
                            </span>
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
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.rac-bar-fill').forEach(b => {
        const t=b.style.width; b.style.width='0%';
        setTimeout(()=>{b.style.width=t;},300);
    });
    document.querySelectorAll('canvas[id^="trend-"]').forEach(c => drawTrend(c));
});

function drawTrend(canvas) {
    if (canvas.dataset.drawn) return;
    canvas.dataset.drawn = '1';
    const trend = JSON.parse(canvas.dataset.trend || '[]');
    if (trend.length < 2) return;
    const colors = { low:'#5E8A40', medium:'#A07830', high:'#8B3A3A' };
    new Chart(canvas, {
        type:'line',
        data:{
            labels: trend.map(t=>{ const d=new Date(t.date); return (d.getMonth()+1)+'/'+(d.getDate()); }),
            datasets:[{
                data: trend.map(t=>parseFloat(t.avg_pct)),
                borderColor:'#7AA658', backgroundColor:'rgba(122,166,88,0.08)',
                borderWidth:2.5, tension:0.38, fill:true,
                pointBackgroundColor: trend.map(t=>colors[t.level]||'#B8B0A4'),
                pointRadius:5, pointHoverRadius:7,
            }]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{legend:{display:false},tooltip:{callbacks:{
                label:ctx=>' Risk: '+ctx.parsed.y+'%',
                labelColor:ctx=>({borderColor:colors[trend[ctx.dataIndex]?.level]||'#B8B0A4',backgroundColor:colors[trend[ctx.dataIndex]?.level]||'#B8B0A4'})
            }}},
            scales:{
                x:{grid:{display:false},ticks:{font:{size:9}}},
                y:{min:0,max:100,grid:{color:'#F2F6EF'},ticks:{font:{size:9},callback:v=>v+'%'}}
            }
        }
    });
}

// Filter tabs
document.querySelectorAll('.ftab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.ftab').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        const f=btn.dataset.risk; let visible=0;
        document.querySelectorAll('.rac').forEach(card=>{
            const show=f==='all'||card.dataset.risk===f;
            card.style.display=show?'':'none'; if(show) visible++;
        });
        document.getElementById('noResidents').style.display=visible===0?'block':'none';
    });
});

// History toggle
function toggleHistory(rid) {
    const panel   = document.getElementById('hist-'+rid);
    const content = document.getElementById('hist-content-'+rid);
    if (panel.style.display==='none') {
        panel.style.display='block';
        loadHistory(rid,content);
    } else { panel.style.display='none'; }
}
function loadHistory(rid, container) {
    container.innerHTML='<div class="text-center py-3"><div class="spin-sm"></div></div>';
    fetch('get_risk_history.php?resident_id='+rid)
        .then(r=>r.json())
        .then(data=>{
            if (!data.success||!data.history.length) {
                container.innerHTML='<p style="color:var(--st300);font-size:12px;padding:10px 0 4px;">No risk history recorded yet.</p>'; return;
            }
            const colors={low:'#5E8A40',medium:'#A07830',high:'#8B3A3A'};
            const rows=data.history.map(h=>{
                const esc=h.is_escalation==1?'<span class="esc-badge"><i class="fas fa-arrow-trend-up me-1"></i>ESCALATION</span>':'';
                const fb=h.is_fallback==1?'<span class="fb-badge">Rule-based</span>':'';
                const ac=h.alert_created==1?'<span style="background:var(--red-bg);color:var(--red-text);border-radius:5px;padding:2px 7px;font-size:10px;font-weight:700;margin-left:4px;"><i class="fas fa-bell me-1"></i>Alert</span>':'';
                return `<tr><td style="white-space:nowrap;">${h.predicted_at_fmt}</td>
                    <td><span class="rlvl ${h.risk_level}">${h.risk_level.toUpperCase()}</span>${esc}${fb}${ac}</td>
                    <td><strong>${h.risk_percentage}%</strong>
                        <div style="height:5px;background:var(--s100);border-radius:4px;overflow:hidden;width:70px;margin-top:3px;">
                            <div style="height:100%;width:${h.risk_percentage}%;background:${colors[h.risk_level]||'#B8B0A4'};border-radius:4px;"></div>
                        </div></td>
                    <td>${h.total_flagged>0?'<span style="color:var(--red-text);font-weight:700;">'+h.total_flagged+' flagged</span>':'<span style="color:var(--green-text);">None</span>'}</td>
                    <td style="color:var(--st300);">${h.model_used||'Unknown'}</td></tr>`;
            }).join('');
            container.innerHTML=`<div style="overflow-x:auto;margin-top:8px;">
                <table class="hist-table">
                    <thead><tr><th>Date &amp; Time</th><th>Risk Level</th><th>Score</th><th>Flagged Vitals</th><th>Model</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
                <p style="color:var(--st300);font-size:11px;margin-top:8px;margin-bottom:0;">Showing last ${data.history.length} predictions.</p>
            </div>`;
        })
        .catch(()=>{container.innerHTML='<p style="color:var(--red-text);font-size:12px;padding:10px 0 4px;">Failed to load history.</p>';});
}

setInterval(()=>location.reload(),60000);
const highCount=<?= $risk_counts['high'] ?>, pendingCount=<?= count($unresolved) ?>;
if (highCount>0||pendingCount>0) {
    let orig=document.title,alt=false;
    setInterval(()=>{document.title=alt?orig:`(${highCount} HIGH RISK) SmartCare Alerts`;alt=!alt;},1500);
}
</script>
</body>
</html>