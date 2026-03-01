<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

include 'db_connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/SmartCareGuardian/includes/ai_service.php';

// Handle resolve alert
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_alert'])) {
    $stmt = $conn->prepare("UPDATE alerts SET resolved = 1 WHERE alert_id = ?");
    $stmt->bind_param("i", $_POST['alert_id']);
    $stmt->execute();
    header("Location: admin_alerts.php");
    exit();
}

// Handle resolve all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_all'])) {
    $conn->query("UPDATE alerts SET resolved = 1 WHERE resolved = 0");
    header("Location: admin_alerts.php");
    exit();
}

// ── All residents + latest health log + personal details + caregiver ───────
// FIX: The original query joined caregivers via c.caregiver_id = ca.caregiver_id
// but the caregivers table uses user_id as its key, not caregiver_id.
// The fix: join users (cu) directly from ca.caregiver_id, then caregivers (c) via c.user_id
// Also use GROUP BY in the subquery instead of ORDER BY (not valid in derived tables for this purpose)
$res_stmt = $conn->query("
    SELECT u.user_id, u.full_name, u.email, u.status,
           hl.blood_pressure_systolic, hl.blood_pressure_diastolic,
           hl.blood_sugar, hl.pulse, hl.weight,
           hl.temperature, hl.oxygen_saturation,
           hl.logged_at AS last_log,
           r.phone, r.gender, r.dob, r.blood_type,
           r.emergency_contact, r.medical_conditions,
           r.allergies, r.dietary_restrictions, r.primary_physician,
           cu.full_name AS caregiver_name,
           c.phone AS caregiver_phone
    FROM users u
    LEFT JOIN (
        SELECT resident_id,
               blood_pressure_systolic, blood_pressure_diastolic,
               blood_sugar, pulse, weight, temperature, oxygen_saturation,
               logged_at,
               ROW_NUMBER() OVER (PARTITION BY resident_id ORDER BY logged_at DESC) AS rn
        FROM health_logs
    ) hl ON hl.resident_id = u.user_id AND hl.rn = 1
    LEFT JOIN residents r ON r.user_id = u.user_id
    LEFT JOIN (
        SELECT resident_id, caregiver_id
        FROM caregiver_assignments
        GROUP BY resident_id
    ) ca ON ca.resident_id = u.user_id
    LEFT JOIN users cu ON cu.user_id = ca.caregiver_id
    LEFT JOIN caregivers c ON c.user_id = ca.caregiver_id
    WHERE u.role = 'resident' AND u.status = 'active'
    GROUP BY u.user_id
    ORDER BY u.full_name
");
$residents_data = $res_stmt->fetch_all(MYSQLI_ASSOC);

// ── All DB alerts ──────────────────────────────────────────────────────────
$alerts_stmt = $conn->query("
    SELECT a.*, u.full_name AS resident_name,
           DATE_FORMAT(a.created_at,'%Y-%m-%d %h:%i %p') AS formatted_time
    FROM alerts a
    JOIN users u ON a.resident_id = u.user_id
    ORDER BY a.created_at DESC
");
$db_alerts   = $alerts_stmt->fetch_all(MYSQLI_ASSOC);
$unresolved  = array_filter($db_alerts, fn($a) => !$a['resolved']);

// Build alert_residents for filter dropdown
$alert_residents = [];
foreach ($db_alerts as $al) {
    $alert_residents[$al['resident_id']] = $al['resident_name'];
}

// ── AI batch predictions ───────────────────────────────────────────────────
$ai        = new AIService();
$ai_online = $ai->checkStatus()['success'] ?? false;
$predictions = [];

if ($ai_online && !empty($residents_data)) {
    foreach ($residents_data as $r) {
        $rid = $r['user_id'];
        if (!$r['last_log']) continue;
        $vitals = [
            'resident_id'              => (int)$rid,
            'blood_pressure_systolic'  => !empty($r['blood_pressure_systolic'])  ? (float)$r['blood_pressure_systolic']  : 120.0,
            'blood_pressure_diastolic' => !empty($r['blood_pressure_diastolic']) ? (float)$r['blood_pressure_diastolic'] : 80.0,
            'blood_sugar'              => !empty($r['blood_sugar'])               ? (float)$r['blood_sugar']               : 100.0,
            'pulse'                    => !empty($r['pulse'])                     ? (float)$r['pulse']                     : 72.0,
            'weight'                   => !empty($r['weight'])                    ? (float)$r['weight']                    : 65.0,
            'temperature'              => !empty($r['temperature'])               ? (float)$r['temperature']               : 36.6,
            'oxygen_saturation'        => !empty($r['oxygen_saturation'])         ? (float)$r['oxygen_saturation']         : 98.0,
            'logged_at'                => $r['last_log'],
        ];
        $resp = $ai->getPrediction($rid, $vitals);
        if ($resp['success'] && isset($resp['data']['data']['prediction'])) {
            $predictions[$rid] = $resp['data']['data'];
        } else {
            // Rule-based fallback
            $score = 0; $al = [];
            if ($vitals['blood_pressure_systolic'] > 140)   { $score+=2; $al[]=['vital_sign'=>'BP Systolic','value'=>$vitals['blood_pressure_systolic'],'unit'=>'mmHg','status'=>'HIGH','normal_range'=>'90-140 mmHg']; }
            elseif ($vitals['blood_pressure_systolic'] < 90){ $score+=2; $al[]=['vital_sign'=>'BP Systolic','value'=>$vitals['blood_pressure_systolic'],'unit'=>'mmHg','status'=>'LOW','normal_range'=>'90-140 mmHg']; }
            if ($vitals['blood_sugar'] > 180)    { $score+=2; $al[]=['vital_sign'=>'Blood Sugar','value'=>$vitals['blood_sugar'],'unit'=>'mg/dL','status'=>'HIGH','normal_range'=>'70-180 mg/dL']; }
            elseif ($vitals['blood_sugar'] < 70) { $score+=2; $al[]=['vital_sign'=>'Blood Sugar','value'=>$vitals['blood_sugar'],'unit'=>'mg/dL','status'=>'LOW','normal_range'=>'70-180 mg/dL']; }
            if ($vitals['temperature'] > 37.8)    { $score+=1; $al[]=['vital_sign'=>'Temperature','value'=>$vitals['temperature'],'unit'=>'°C','status'=>'HIGH','normal_range'=>'36-37.8°C']; }
            elseif ($vitals['temperature'] < 36.0){ $score+=1; $al[]=['vital_sign'=>'Temperature','value'=>$vitals['temperature'],'unit'=>'°C','status'=>'LOW','normal_range'=>'36-37.8°C']; }
            if ($vitals['oxygen_saturation'] < 95){ $score+=2; $al[]=['vital_sign'=>'Oxygen Sat.','value'=>$vitals['oxygen_saturation'],'unit'=>'%','status'=>'LOW','normal_range'=>'95-100%']; }
            $pct   = min($score*20, 95);
            $level = $pct >= 60 ? 'high' : ($pct >= 30 ? 'medium' : 'low');
            $predictions[$rid] = [
                'prediction' => ['risk_level'=>$level,'risk_percentage'=>$pct],
                'alerts'     => ['total_alerts'=>count($al),'alerts'=>$al],
            ];
        }
    }
} else {
    // AI offline — run rule-based for all residents
    foreach ($residents_data as $r) {
        $rid = $r['user_id'];
        if (!$r['last_log']) continue;
        $score = 0; $al = [];
        $sys = (float)($r['blood_pressure_systolic'] ?? 120);
        $sug = (float)($r['blood_sugar'] ?? 100);
        $tmp = (float)($r['temperature'] ?? 36.6);
        $o2  = (float)($r['oxygen_saturation'] ?? 98);
        if ($sys > 140)   { $score+=2; $al[]=['vital_sign'=>'BP Systolic','value'=>$sys,'unit'=>'mmHg','status'=>'HIGH','normal_range'=>'90-140 mmHg']; }
        elseif ($sys < 90){ $score+=2; $al[]=['vital_sign'=>'BP Systolic','value'=>$sys,'unit'=>'mmHg','status'=>'LOW','normal_range'=>'90-140 mmHg']; }
        if ($sug > 180)   { $score+=2; $al[]=['vital_sign'=>'Blood Sugar','value'=>$sug,'unit'=>'mg/dL','status'=>'HIGH','normal_range'=>'70-180 mg/dL']; }
        elseif ($sug < 70){ $score+=2; $al[]=['vital_sign'=>'Blood Sugar','value'=>$sug,'unit'=>'mg/dL','status'=>'LOW','normal_range'=>'70-180 mg/dL']; }
        if ($tmp > 37.8)  { $score+=1; $al[]=['vital_sign'=>'Temperature','value'=>$tmp,'unit'=>'°C','status'=>'HIGH','normal_range'=>'36-37.8°C']; }
        elseif ($tmp < 36){ $score+=1; $al[]=['vital_sign'=>'Temperature','value'=>$tmp,'unit'=>'°C','status'=>'LOW','normal_range'=>'36-37.8°C']; }
        if ($o2 < 95)     { $score+=2; $al[]=['vital_sign'=>'Oxygen Sat.','value'=>$o2,'unit'=>'%','status'=>'LOW','normal_range'=>'95-100%']; }
        $pct   = min($score*20, 95);
        $level = $pct >= 60 ? 'high' : ($pct >= 30 ? 'medium' : 'low');
        $predictions[$rid] = [
            'prediction' => ['risk_level'=>$level,'risk_percentage'=>$pct],
            'alerts'     => ['total_alerts'=>count($al),'alerts'=>$al],
        ];
    }
}

// Risk counts
$risk_counts = ['high'=>0,'medium'=>0,'low'=>0,'no_data'=>0];
foreach ($residents_data as $r) {
    $p = $predictions[$r['user_id']] ?? null;
    $risk_counts[$p ? $p['prediction']['risk_level'] : 'no_data']++;
}

// Build JS profile data
$profile_js_data = [];
foreach ($residents_data as $r) {
    $rid_key = $r['user_id'];
    $pred    = $predictions[$rid_key] ?? null;
    $profile_js_data[$rid_key] = [
        'name'                => $r['full_name'],
        'email'               => $r['email'] ?? '--',
        'phone'               => $r['phone'] ?? '--',
        'gender'              => $r['gender'] ? ucfirst($r['gender']) : '--',
        'dob'                 => (!empty($r['dob']) && $r['dob'] !== '0000-00-00') ? date('d M Y', strtotime($r['dob'])) : '--',
        'blood_type'          => $r['blood_type'] ?? '--',
        'emergency_contact'   => $r['emergency_contact'] ?? '--',
        'medical_conditions'  => $r['medical_conditions'] ?? '--',
        'allergies'           => $r['allergies'] ?? '--',
        'dietary'             => $r['dietary_restrictions'] ?? '--',
        'physician'           => $r['primary_physician'] ?? '--',
        'caregiver_name'      => $r['caregiver_name'] ?? null,
        'caregiver_phone'     => $r['caregiver_phone'] ?? '--',
        'last_log'            => $r['last_log'] ? date('d M Y, H:i', strtotime($r['last_log'])) : null,
        'bp'                  => ($r['blood_pressure_systolic'] && $r['blood_pressure_diastolic'])
                                 ? $r['blood_pressure_systolic'].'/'.$r['blood_pressure_diastolic'] : '--',
        'sugar'               => $r['blood_sugar']         ?? '--',
        'pulse'               => $r['pulse']               ?? '--',
        'temp'                => $r['temperature']         ?? '--',
        'o2'                  => $r['oxygen_saturation']   ?? '--',
        'weight'              => $r['weight']              ?? '--',
        'risk_level'          => $pred ? $pred['prediction']['risk_level']     : 'no_data',
        'risk_pct'            => $pred ? $pred['prediction']['risk_percentage'] : 0,
        'alerts'              => $pred ? $pred['alerts']['alerts']              : [],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Alerts - SmartCare Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Quicksand:wght@400;500;600&family=Jost:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --sage-green:#87A96B; --mint-cream:#F0FFF0; --seafoam:#9FE2BF;
            --forest-mist:#B8E0D2; --dusty-teal:#6D9B8E; --deep-emerald:#4A766E;
            --light-sage:#E8F5E8; --warning-red:#FF6B6B; --warning-orange:#FFA726;
        }
        body { font-family:'Quicksand',sans-serif; background:linear-gradient(135deg,var(--mint-cream) 0%,var(--forest-mist) 100%); margin:0; min-height:100vh; }
        h1,h2,h3,h4,h5 { font-family:'Playfair Display',serif; color:var(--deep-emerald); }
        .brand-font { font-family:'Jost',sans-serif; font-weight:600; }
        .sidebar { width:280px; height:100vh; position:fixed; background:linear-gradient(180deg,var(--sage-green),var(--dusty-teal)); color:white; box-shadow:4px 0 20px rgba(0,0,0,.1); z-index:1000; display:flex; flex-direction:column; }
        .sidebar-header { text-align:center; padding:30px 20px 20px; border-bottom:1px solid rgba(255,255,255,.2); flex-shrink:0; }
        .sidebar-nav { flex:1; overflow-y:auto; padding:20px 0; }
        .sidebar-footer { flex-shrink:0; border-top:1px solid rgba(255,255,255,.2); padding:20px; }
        .sidebar a { color:white; display:flex; align-items:center; padding:15px 25px; text-decoration:none; transition:all .3s; margin:5px 15px; border-radius:12px; font-weight:500; }
        .sidebar a:hover { background:rgba(255,255,255,.15); transform:translateX(5px); }
        .sidebar a.active { background:rgba(255,255,255,.25); box-shadow:0 4px 15px rgba(0,0,0,.1); }
        .sidebar i { width:25px; margin-right:12px; font-size:1.1rem; }
        .sidebar-nav::-webkit-scrollbar { width:6px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background:rgba(255,255,255,.3); border-radius:3px; }
        .content { margin-left:280px; padding:30px; min-height:100vh; }
        .topbar { background:rgba(255,255,255,.95); backdrop-filter:blur(10px); border-radius:20px; padding:20px 30px; box-shadow:0 8px 32px rgba(0,0,0,.1); margin-bottom:30px; }
        .logout-btn { background:linear-gradient(135deg,#ff6b6b,#ee5a52); border:none; border-radius:50px; color:white; padding:10px 25px; font-weight:600; transition:all .3s; }
        .logout-btn:hover { transform:translateY(-2px); box-shadow:0 5px 15px rgba(255,107,107,.4); }
        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:18px; margin-bottom:28px; }
        .stat-card { background:rgba(255,255,255,.95); border-radius:20px; padding:20px; box-shadow:0 8px 32px rgba(0,0,0,.07); transition:all .3s; text-align:center; }
        .stat-card:hover { transform:translateY(-4px); }
        .stat-icon { width:54px; height:54px; border-radius:14px; margin:0 auto 12px; display:flex; align-items:center; justify-content:center; font-size:1.4rem; }
        .ic-total   { background:linear-gradient(135deg,var(--dusty-teal),var(--deep-emerald)); color:white; }
        .ic-high    { background:linear-gradient(135deg,#ef4444,#b91c1c); color:white; }
        .ic-medium  { background:linear-gradient(135deg,#f59e0b,#d97706); color:white; }
        .ic-low     { background:linear-gradient(135deg,#22c55e,#15803d); color:white; }
        .ic-pending { background:linear-gradient(135deg,#ff6b6b,#ee5a52); color:white; }
        .ic-resolved{ background:linear-gradient(135deg,var(--seafoam),var(--sage-green)); color:white; }
        .stat-number { font-size:1.9rem; font-weight:800; color:var(--deep-emerald); line-height:1; }
        .stat-label  { color:var(--dusty-teal); font-weight:600; font-size:.8rem; margin-top:4px; }
        .section-card { background:rgba(255,255,255,.95); border-radius:20px; box-shadow:0 8px 32px rgba(0,0,0,.07); margin-bottom:28px; overflow:hidden; }
        .section-header { background:linear-gradient(135deg,var(--sage-green),var(--dusty-teal)); color:white; padding:18px 26px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
        .section-header h5 { color:white; margin:0; }
        .section-body { padding:24px; }
        .filter-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:22px; }
        .ftab { padding:7px 18px; border-radius:25px; font-size:.82rem; font-weight:700; cursor:pointer; border:2px solid transparent; transition:all .2s; background:#fff; font-family:'Jost',sans-serif; }
        .ftab[data-risk="all"]    { border-color:#94a3b8; color:#475569; }
        .ftab[data-risk="high"]   { border-color:#ef4444; color:#ef4444; }
        .ftab[data-risk="medium"] { border-color:#f59e0b; color:#d97706; }
        .ftab[data-risk="low"]    { border-color:#22c55e; color:#16a34a; }
        .ftab[data-risk="no_data"]{ border-color:#94a3b8; color:#94a3b8; }
        .ftab.active[data-risk="all"]    { background:#475569; color:#fff; border-color:#475569; }
        .ftab.active[data-risk="high"]   { background:#ef4444; color:#fff; }
        .ftab.active[data-risk="medium"] { background:#f59e0b; color:#fff; }
        .ftab.active[data-risk="low"]    { background:#22c55e; color:#fff; }
        .ftab.active[data-risk="no_data"]{ background:#94a3b8; color:#fff; }
        .search-box { flex:1; min-width:200px; max-width:280px; }
        .search-box input { border:2px solid var(--forest-mist); border-radius:25px; padding:7px 16px; font-size:.85rem; width:100%; outline:none; transition:border .2s; }
        .search-box input:focus { border-color:var(--sage-green); }
        .res-card { background:#fff; border-radius:16px; box-shadow:0 3px 14px rgba(0,0,0,.07); margin-bottom:16px; overflow:hidden; border-left:6px solid #94a3b8; transition:transform .2s,box-shadow .2s; }
        .res-card:hover { transform:translateY(-3px); box-shadow:0 8px 26px rgba(0,0,0,.12); }
        .res-card[data-risk="high"]   { border-left-color:#ef4444; }
        .res-card[data-risk="medium"] { border-left-color:#f59e0b; }
        .res-card[data-risk="low"]    { border-left-color:#22c55e; }
        .res-card-header { display:flex; align-items:center; gap:14px; padding:14px 20px; cursor:pointer; user-select:none; }
        .res-avatar { width:46px; height:46px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1rem; color:#fff; flex-shrink:0; }
        .res-avatar.high   { background:linear-gradient(135deg,#ef4444,#b91c1c); }
        .res-avatar.medium { background:linear-gradient(135deg,#f59e0b,#d97706); }
        .res-avatar.low    { background:linear-gradient(135deg,#22c55e,#15803d); }
        .res-avatar.no_data{ background:linear-gradient(135deg,#94a3b8,#64748b); }
        .res-name { font-weight:700; color:var(--deep-emerald); font-size:.97rem; }
        .res-meta { font-size:.78rem; color:#64748b; }
        .res-badge { padding:5px 13px; border-radius:20px; font-size:.78rem; font-weight:700; color:#fff; font-family:'Jost',sans-serif; }
        .res-badge.high   { background:#ef4444; }
        .res-badge.medium { background:#f59e0b; }
        .res-badge.low    { background:#22c55e; }
        .res-badge.no_data{ background:#94a3b8; }
        .mini-bar-wrap { flex:1; max-width:160px; }
        .mini-bar-track { height:8px; background:#e2e8f0; border-radius:6px; overflow:hidden; }
        .mini-bar-fill { height:100%; border-radius:6px; transition:width 1s ease; }
        .mini-bar-fill.high   { background:linear-gradient(90deg,#ef4444,#b91c1c); }
        .mini-bar-fill.medium { background:linear-gradient(90deg,#f59e0b,#d97706); }
        .mini-bar-fill.low    { background:linear-gradient(90deg,#22c55e,#15803d); }
        .mini-bar-fill.no_data{ background:#94a3b8; }
        .mini-bar-label { font-size:.72rem; color:#64748b; display:flex; justify-content:space-between; margin-bottom:3px; }
        .res-detail { display:none; padding:0 20px 16px; border-top:1px solid #f1f5f9; }
        .res-detail.open { display:block; }
        .vital-row { display:flex; align-items:center; gap:10px; padding:7px 10px; background:var(--light-sage); border-radius:8px; margin-bottom:6px; font-size:.85rem; }
        .vital-row.flagged { background:#fef2f2; }
        .vital-row i { width:18px; text-align:center; }
        .ai-chip { display:inline-flex; align-items:center; gap:4px; background:#fef2f2; color:#dc2626; border-radius:7px; padding:3px 9px; font-size:.76rem; font-weight:700; margin:2px; }
        .ai-chip.medium { background:#fffbeb; color:#d97706; }
        .btn-details { font-size:.8rem; padding:5px 14px; border-radius:20px; background:linear-gradient(135deg,var(--sage-green),var(--dusty-teal)); color:#fff; border:none; font-weight:600; text-decoration:none; transition:all .2s; }
        .btn-details:hover { transform:translateY(-1px); box-shadow:0 3px 10px rgba(135,169,107,.3); color:#fff; }
        .alert-card { background:#fff; border-radius:14px; padding:18px 20px; margin-bottom:14px; box-shadow:0 3px 14px rgba(0,0,0,.06); border-left:5px solid var(--warning-red); transition:all .3s; }
        .alert-card:hover { transform:translateY(-2px); box-shadow:0 7px 22px rgba(0,0,0,.1); }
        .alert-card.resolved { opacity:.7; border-left-color:var(--forest-mist); }
        .alert-type-badge { display:inline-block; padding:3px 11px; border-radius:20px; font-size:.72rem; font-weight:700; }
        .badge-health_warning { background:linear-gradient(135deg,var(--sage-green),var(--dusty-teal)); color:#fff; }
        .badge-critical       { background:linear-gradient(135deg,#ff6b6b,#ee5a52); color:#fff; }
        .badge-warning        { background:linear-gradient(135deg,#ffa726,#f57c00); color:#fff; }
        .resident-pill { background:var(--forest-mist); color:var(--deep-emerald); padding:3px 11px; border-radius:20px; font-size:.72rem; font-weight:600; }
        .time-badge { background:var(--mint-cream); color:var(--dusty-teal); padding:2px 9px; border-radius:10px; font-size:.72rem; display:inline-block; margin-top:5px; }
        .btn-resolve { background:linear-gradient(135deg,var(--sage-green),var(--dusty-teal)); border:none; border-radius:20px; padding:6px 16px; font-size:.8rem; font-weight:600; color:#fff; transition:all .3s; }
        .btn-resolve:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(135,169,107,.3); }
        .btn-resolve-all { background:linear-gradient(135deg,var(--dusty-teal),var(--deep-emerald)); border:none; border-radius:20px; padding:9px 22px; font-weight:600; color:#fff; transition:all .3s; }
        .btn-resolve-all:hover { transform:translateY(-2px); }
        .alert-filter-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:20px; }
        .alert-filter-bar select, .alert-filter-bar input { border:2px solid var(--forest-mist); border-radius:20px; padding:6px 14px; font-size:.83rem; outline:none; transition:border .2s; font-family:'Quicksand',sans-serif; }
        .alert-filter-bar select:focus, .alert-filter-bar input:focus { border-color:var(--sage-green); }
        .no-results { text-align:center; padding:40px; color:#94a3b8; display:none; }
        .empty-state { text-align:center; padding:50px; color:#94a3b8; }
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
        .pulse { animation:pulse 2s infinite; }
        @keyframes floating{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
        .floating { animation:floating 3s ease-in-out infinite; }
        @media(max-width:768px){.sidebar{width:100%;height:auto;position:relative;}.content{margin-left:0;padding:15px;}}
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <h4 class="brand-font mb-2"><i class="fa-solid fa-shield-heart me-2"></i>SmartCare Guardian</h4>
        <small class="opacity-75">Admin Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="admin_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="manage_users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i> Manage Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i> Manage Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i> Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i> Manage Services</a>
        <a href="messages.php"><i class="fa-solid fa-envelope"></i> Messages</a>
        <a href="#" class="active"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="admin_reports.php"><i class="fa-solid fa-chart-line"></i> Reports & Analytics</a>
    </div>
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<div class="content">

    <div class="topbar d-flex justify-content-between align-items-center">
        <div>
            <h4 class="brand-font mb-1">Health Alerts & AI Risk Monitor</h4>
            <p class="text-muted mb-0">All residents — AI risk assessment and threshold-based health alerts</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span style="font-size:.83rem;">
                <?php if ($ai_online): ?>
                    <i class="fas fa-circle pulse me-1" style="color:#22c55e;"></i>AI Online
                <?php else: ?>
                    <i class="fas fa-circle me-1" style="color:#ef4444;"></i>AI Offline
                <?php endif; ?>
            </span>
            <form action="/SmartCareGuardian/logout.php" method="POST">
                <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt me-2"></i>Logout</button>
            </form>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon ic-total"><i class="fas fa-users"></i></div><div class="stat-number"><?php echo count($residents_data); ?></div><div class="stat-label">Total Residents</div></div>
        <div class="stat-card"><div class="stat-icon ic-high"><i class="fas fa-circle-exclamation"></i></div><div class="stat-number"><?php echo $risk_counts['high']; ?></div><div class="stat-label">High AI Risk</div></div>
        <div class="stat-card"><div class="stat-icon ic-medium"><i class="fas fa-triangle-exclamation"></i></div><div class="stat-number"><?php echo $risk_counts['medium']; ?></div><div class="stat-label">Medium AI Risk</div></div>
        <div class="stat-card"><div class="stat-icon ic-low"><i class="fas fa-circle-check"></i></div><div class="stat-number"><?php echo $risk_counts['low']; ?></div><div class="stat-label">Low AI Risk</div></div>
        <div class="stat-card"><div class="stat-icon ic-pending"><i class="fas fa-bell"></i></div><div class="stat-number"><?php echo count($unresolved); ?></div><div class="stat-label">Active Alerts</div></div>
        <div class="stat-card"><div class="stat-icon ic-resolved"><i class="fas fa-check-double"></i></div><div class="stat-number"><?php echo count($db_alerts)-count($unresolved); ?></div><div class="stat-label">Resolved</div></div>
    </div>

    <div class="section-card">
        <div class="section-header">
            <h5 class="brand-font"><i class="fas fa-brain me-2"></i>AI Risk Assessment — All Residents</h5>
            <span style="font-size:.82rem;opacity:.85;">Click a resident to expand details</span>
        </div>
        <div class="section-body">
            <?php if (empty($residents_data)): ?>
                <div class="empty-state"><i class="fas fa-users-slash fa-3x mb-3 floating d-block" style="color:var(--forest-mist);"></i><p>No active residents found.</p></div>
            <?php else: ?>
            <div class="filter-bar">
                <button class="ftab active" data-risk="all">All (<?php echo count($residents_data); ?>)</button>
                <button class="ftab" data-risk="high"><i class="fas fa-fire me-1"></i>High (<?php echo $risk_counts['high']; ?>)</button>
                <button class="ftab" data-risk="medium"><i class="fas fa-bolt me-1"></i>Medium (<?php echo $risk_counts['medium']; ?>)</button>
                <button class="ftab" data-risk="low"><i class="fas fa-leaf me-1"></i>Low (<?php echo $risk_counts['low']; ?>)</button>
                <button class="ftab" data-risk="no_data">No Data (<?php echo $risk_counts['no_data']; ?>)</button>
                <div class="search-box ms-auto">
                    <input type="text" id="residentSearch" placeholder="🔍  Search resident name...">
                </div>
            </div>
            <div id="residentsList">
            <?php foreach ($residents_data as $r):
                $rid        = $r['user_id'];
                $pred       = $predictions[$rid] ?? null;
                $level      = $pred ? $pred['prediction']['risk_level'] : 'no_data';
                $pct        = $pred ? $pred['prediction']['risk_percentage'] : 0;
                $ai_alerts  = $pred ? $pred['alerts']['alerts'] : [];
                $flagged    = [];
                foreach ($ai_alerts as $a) $flagged[$a['vital_sign']] = $a['status'];
                $initials   = strtoupper(substr($r['full_name'],0,1));
                if (strpos($r['full_name'],' ')!==false) $initials .= strtoupper(substr(strrchr($r['full_name'],' '),1,1));
                $level_labels = ['high'=>'High Risk','medium'=>'Medium Risk','low'=>'Low Risk','no_data'=>'No Data'];
            ?>
                <div class="res-card" data-risk="<?php echo $level; ?>" data-name="<?php echo strtolower($r['full_name']); ?>">
                    <div class="res-card-header" onclick="toggleDetail(<?php echo $rid; ?>)">
                        <div class="res-avatar <?php echo $level; ?>"><?php echo $initials; ?></div>
                        <div style="flex:1;">
                            <div class="res-name"><?php echo htmlspecialchars($r['full_name']); ?></div>
                            <div class="res-meta">
                                <?php if ($r['last_log']): ?>
                                    <i class="fas fa-clock me-1"></i><?php echo date('d M Y, H:i', strtotime($r['last_log'])); ?>
                                <?php else: ?>
                                    <i class="fas fa-minus-circle me-1"></i>No readings logged
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($r['last_log']): ?>
                        <div class="mini-bar-wrap">
                            <div class="mini-bar-label"><span>AI Risk</span><span><?php echo $pct; ?>%</span></div>
                            <div class="mini-bar-track"><div class="mini-bar-fill <?php echo $level; ?>" style="width:<?php echo $pct; ?>%"></div></div>
                        </div>
                        <?php endif; ?>
                        <span class="res-badge <?php echo $level; ?> ms-2"><?php echo $level_labels[$level]; ?></span>
                        <i class="fas fa-chevron-down ms-3 text-secondary" id="chevron-<?php echo $rid; ?>" style="transition:transform .3s;"></i>
                    </div>
                    <div class="res-detail" id="detail-<?php echo $rid; ?>">
                        <?php if ($r['last_log']): ?>
                        <div class="row g-3 mt-1">
                            <div class="col-md-5">
                                <small class="fw-bold" style="color:var(--deep-emerald);text-transform:uppercase;letter-spacing:.04em;font-size:.72rem;">Latest Vitals</small>
                                <?php
                                $vd = [
                                    ['Blood Pressure Systolic', $r['blood_pressure_systolic'].'/'.$r['blood_pressure_diastolic'], 'mmHg',  'fa-heart-pulse',      'BP'],
                                    ['Blood Sugar',             $r['blood_sugar'],               'mg/dL', 'fa-droplet',          'Sugar'],
                                    ['Pulse',                   $r['pulse'],                     'bpm',   'fa-wave-square',      'Pulse'],
                                    ['Temperature',             $r['temperature'],               '°C',    'fa-thermometer-half', 'Temp'],
                                    ['Oxygen Sat.',             $r['oxygen_saturation'],         '%',     'fa-lungs',            'O₂'],
                                    ['Weight',                  $r['weight'],                    'kg',    'fa-weight-scale',     'Wt'],
                                ];
                                foreach ($vd as [$key,$val,$unit,$ico,$lbl]):
                                    $f = isset($flagged[$key]); ?>
                                    <div class="vital-row <?php echo $f?'flagged':''; ?> mt-2">
                                        <i class="fas <?php echo $ico; ?>" style="color:<?php echo $f?'#dc2626':'var(--dusty-teal)'; ?>"></i>
                                        <span style="flex:1;font-weight:600;"><?php echo $lbl; ?></span>
                                        <span style="font-weight:800;color:<?php echo $f?'#dc2626':'var(--deep-emerald)'; ?>;"><?php echo $val; ?> <?php echo $unit; ?></span>
                                        <?php if ($f): ?><i class="fas fa-exclamation-circle text-danger" style="font-size:.75rem;"></i><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="col-md-4">
                                <small class="fw-bold" style="color:var(--deep-emerald);text-transform:uppercase;letter-spacing:.04em;font-size:.72rem;">AI Flagged Readings</small>
                                <div class="mt-2">
                                <?php if (!empty($ai_alerts)):
                                    foreach ($ai_alerts as $al):
                                        $cls = $al['status']==='HIGH'?'':'medium'; ?>
                                        <div class="ai-chip <?php echo $cls; ?> mb-2 d-flex" style="font-size:.8rem;padding:5px 10px;">
                                            <i class="fas <?php echo $al['status']==='HIGH'?'fa-arrow-trend-up':'fa-arrow-trend-down'; ?> me-1"></i>
                                            <div>
                                                <?php echo htmlspecialchars($al['vital_sign']); ?> — <?php echo $al['status']; ?><br>
                                                <span style="font-weight:400;opacity:.85;"><?php echo $al['value'].' '.$al['unit']; ?> (Normal: <?php echo $al['normal_range']; ?>)</span>
                                            </div>
                                        </div>
                                <?php endforeach; else: ?>
                                    <div style="background:#edfdf4;border-radius:8px;padding:10px;color:#059669;font-size:.85rem;">
                                        <i class="fas fa-circle-check me-1"></i>All vitals within normal range.
                                    </div>
                                <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-3 d-flex flex-column gap-2 justify-content-center">
                                <button type="button" class="btn-details text-center"
                                        style="background:linear-gradient(135deg,#667eea,#764ba2);"
                                        onclick="showProfile(<?php echo $rid; ?>)">
                                    <i class="fas fa-user me-1"></i>View Profile
                                </button>
                            </div>
                        </div>
                        <?php else: ?>
                            <p class="text-muted mb-0" style="font-size:.87rem;padding-top:4px;">
                                <i class="fas fa-clock me-1"></i>No readings logged yet.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <div class="no-results" id="noResults">
                <i class="fas fa-search fa-2x mb-2 d-block"></i>No residents match this filter.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (count($unresolved) > 0): ?>
    <div class="section-card">
        <div class="section-body text-center py-3">
            <form method="POST">
                <button type="submit" name="resolve_all" class="btn btn-resolve-all">
                    <i class="fas fa-check-double me-2"></i>Resolve All <?php echo count($unresolved); ?> Active Alerts
                </button>
                <p class="text-muted small mt-2 mb-0">Marks all pending alerts across all residents as resolved.</p>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="section-card">
        <div class="section-header">
            <h5 class="brand-font"><i class="fas fa-bell me-2"></i>All Threshold-Based Health Alerts</h5>
            <span style="font-size:.82rem;opacity:.85;">
                <?php if (count($unresolved) > 0): ?>
                    <span class="pulse"><i class="fas fa-circle me-1"></i><?php echo count($unresolved); ?> pending</span>
                <?php else: ?>
                    <i class="fas fa-check-circle me-1"></i>All resolved
                <?php endif; ?>
            </span>
        </div>
        <div class="section-body">
            <div class="alert-filter-bar">
                <select id="filterResident">
                    <option value="">All Residents</option>
                    <?php foreach ($alert_residents as $rid => $rname): ?>
                        <option value="<?php echo $rid; ?>"><?php echo htmlspecialchars($rname); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterType">
                    <option value="">All Types</option>
                    <option value="health_warning">Health Warning</option>
                    <option value="critical">Critical</option>
                    <option value="warning">Warning</option>
                </select>
                <select id="filterStatus">
                    <option value="">All Status</option>
                    <option value="unresolved">Active Only</option>
                    <option value="resolved">Resolved Only</option>
                </select>
                <input type="text" id="searchAlert" placeholder="🔍  Search alert message...">
            </div>
            <?php if (empty($db_alerts)): ?>
                <div class="empty-state floating">
                    <i class="fas fa-check-circle fa-3x mb-3 d-block" style="color:var(--forest-mist);"></i>
                    <p class="mb-0">No health alerts recorded yet.</p>
                </div>
            <?php else: ?>
                <div id="alertsList">
                <?php foreach ($db_alerts as $alert): ?>
                <div class="alert-card <?php echo $alert['resolved']?'resolved':''; ?>"
                     data-resident="<?php echo $alert['resident_id']; ?>"
                     data-type="<?php echo htmlspecialchars($alert['alert_type']); ?>"
                     data-status="<?php echo $alert['resolved']?'resolved':'unresolved'; ?>"
                     data-msg="<?php echo strtolower(htmlspecialchars($alert['alert_message'])); ?>">
                    <div class="row align-items-center">
                        <div class="col-lg-9">
                            <div class="d-flex align-items-start gap-3">
                                <div style="font-size:1.3rem;color:<?php echo $alert['resolved']?'var(--forest-mist)':'var(--warning-red)'; ?>;margin-top:2px;min-width:22px;">
                                    <?php if (!$alert['resolved']): ?>
                                        <i class="fas fa-exclamation-circle pulse"></i>
                                    <?php else: ?>
                                        <i class="fas fa-check-circle"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                        <span class="alert-type-badge badge-<?php echo htmlspecialchars($alert['alert_type']); ?>">
                                            <?php echo strtoupper(str_replace('_',' ',$alert['alert_type'])); ?>
                                        </span>
                                        <span class="resident-pill">
                                            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($alert['resident_name']); ?>
                                        </span>
                                    </div>
                                    <p class="mb-1 fw-bold" style="font-size:.93rem;color:var(--deep-emerald);"><?php echo htmlspecialchars($alert['alert_message']); ?></p>
                                    <span class="time-badge"><i class="far fa-clock me-1"></i><?php echo $alert['formatted_time']; ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 text-lg-end mt-2 mt-lg-0">
                            <?php if (!$alert['resolved']): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="alert_id" value="<?php echo $alert['alert_id']; ?>">
                                    <button type="submit" name="resolve_alert" class="btn btn-resolve">
                                        <i class="fas fa-check me-1"></i>Resolve
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="badge px-3 py-2" style="background:linear-gradient(135deg,var(--seafoam),var(--sage-green));color:#fff;font-size:.78rem;">
                                    <i class="fas fa-check me-1"></i>Resolved
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                <div class="no-results" id="noAlerts" style="display:none;">
                    <i class="fas fa-filter fa-2x mb-2 d-block"></i>No alerts match your filters.
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Resident Profile Modal -->
<div class="modal fade" id="residentProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 10px 40px rgba(0,0,0,.2);">
            <div class="modal-header" style="background:linear-gradient(135deg,#667eea,#764ba2);border-radius:16px 16px 0 0;padding:20px 28px;border:none;">
                <div class="d-flex align-items-center gap-3">
                    <div id="pm-avatar" style="width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.1rem;color:#fff;background:rgba(255,255,255,.25);flex-shrink:0;"></div>
                    <div>
                        <h5 class="modal-title brand-font mb-0" style="color:white;" id="pm-name"></h5>
                        <small style="color:rgba(255,255,255,.8);" id="pm-email"></small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:brightness(0) invert(1);opacity:.9;"></button>
            </div>
            <div class="modal-body" style="padding:24px;">
                <div id="pm-risk-banner" style="border-radius:12px;padding:13px 16px;margin-bottom:20px;display:flex;align-items:center;gap:14px;">
                    <div id="pm-risk-circle" style="width:58px;height:58px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff;font-family:'Jost',sans-serif;flex-shrink:0;">
                        <i id="pm-risk-icon" style="font-size:1.1rem;"></i>
                        <span id="pm-risk-pct" style="font-size:.95rem;font-weight:800;line-height:1;margin-top:2px;"></span>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:700;font-size:.95rem;margin-bottom:4px;" id="pm-risk-label"></div>
                        <div style="height:7px;background:#e2e8f0;border-radius:6px;overflow:hidden;">
                            <div id="pm-risk-bar" style="height:100%;border-radius:6px;transition:width 1s ease;width:0%;"></div>
                        </div>
                        <small style="color:#64748b;font-size:.76rem;" id="pm-risk-note"></small>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div style="background:var(--light-sage);border-radius:12px;padding:16px;">
                            <p style="font-weight:700;color:var(--deep-emerald);font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">
                                <i class="fas fa-user me-1"></i> Personal Information
                            </p>
                            <div id="pm-personal"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div style="background:#fef9ff;border-radius:12px;padding:16px;margin-bottom:12px;">
                            <p style="font-weight:700;color:var(--deep-emerald);font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">
                                <i class="fas fa-notes-medical me-1"></i> Medical Information
                            </p>
                            <div id="pm-medical"></div>
                        </div>
                        <div style="background:#f0f4ff;border-radius:12px;padding:16px;">
                            <p style="font-weight:700;color:#4338ca;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">
                                <i class="fas fa-user-nurse me-1"></i> Assigned Caregiver
                            </p>
                            <div id="pm-caregiver"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="background:var(--light-sage);border-radius:0 0 16px 16px;padding:14px 24px;border-top:1px solid var(--forest-mist);">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.mini-bar-fill').forEach(bar => {
        const t = bar.style.width; bar.style.width = '0%';
        setTimeout(() => { bar.style.width = t; }, 300);
    });
});

function toggleDetail(rid) {
    const detail  = document.getElementById('detail-' + rid);
    const chevron = document.getElementById('chevron-' + rid);
    const isOpen  = detail.classList.contains('open');
    document.querySelectorAll('.res-detail').forEach(d => d.classList.remove('open'));
    document.querySelectorAll('[id^="chevron-"]').forEach(c => c.style.transform = '');
    if (!isOpen) { detail.classList.add('open'); chevron.style.transform = 'rotate(180deg)'; }
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
        const show = (risk === 'all' || card.dataset.risk === risk) && card.dataset.name.includes(search);
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('noResults').style.display = visible === 0 ? 'block' : 'none';
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
        const show = (!resident || card.dataset.resident === resident)
                  && (!type     || card.dataset.type === type)
                  && (!status   || card.dataset.status === status)
                  && (!search   || card.dataset.msg.includes(search));
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const noAlerts = document.getElementById('noAlerts');
    if (noAlerts) noAlerts.style.display = visible === 0 ? 'block' : 'none';
}

setInterval(() => location.reload(), 90000);

const profileData = <?php echo json_encode($profile_js_data); ?>;

function showProfile(rid) {
    const d = profileData[rid];
    if (!d) return;

    const initials = d.name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
    document.getElementById('pm-avatar').textContent = initials;
    document.getElementById('pm-name').textContent   = d.name;
    document.getElementById('pm-email').textContent  = d.email;

    const riskColors = { high:'#ef4444', medium:'#f59e0b', low:'#22c55e', no_data:'#94a3b8' };
    const riskBgs    = { high:'#fef2f2', medium:'#fffbeb', low:'#edfdf4', no_data:'#f8fafc' };
    const riskIcons  = { high:'fa-circle-exclamation', medium:'fa-triangle-exclamation', low:'fa-circle-check', no_data:'fa-question-circle' };
    const riskNotes  = { high:'Immediate attention may be required.', medium:'Monitor closely — slightly outside normal.', low:'All readings within healthy range.', no_data:'No health data available yet.' };
    const lvl = d.risk_level;
    document.getElementById('pm-risk-banner').style.background = riskBgs[lvl];
    document.getElementById('pm-risk-circle').style.background = riskColors[lvl];
    document.getElementById('pm-risk-icon').className          = 'fas ' + riskIcons[lvl];
    document.getElementById('pm-risk-pct').textContent         = d.risk_pct + '%';
    document.getElementById('pm-risk-label').innerHTML         = `<span style="color:${riskColors[lvl]};font-size:1rem;">${lvl.charAt(0).toUpperCase()+lvl.slice(1).replace('_',' ')} AI Risk</span>`;
    document.getElementById('pm-risk-note').textContent        = riskNotes[lvl];
    const bar = document.getElementById('pm-risk-bar');
    bar.style.background = riskColors[lvl];
    bar.style.width = '0%';
    setTimeout(() => { bar.style.width = d.risk_pct + '%'; }, 200);

    const row = (ico, lbl, val) => val && val !== '--' ? `
        <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:9px;">
            <i class="fas ${ico}" style="color:var(--dusty-teal);width:16px;margin-top:2px;font-size:.85rem;flex-shrink:0;"></i>
            <div>
                <div style="font-size:.7rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;">${lbl}</div>
                <div style="font-size:.88rem;color:#1e293b;font-weight:600;">${val}</div>
            </div>
        </div>` : '';

    document.getElementById('pm-personal').innerHTML =
        row('fa-phone',        'Phone',             d.phone) +
        row('fa-venus-mars',   'Gender',            d.gender) +
        row('fa-birthday-cake','Date of Birth',     d.dob) +
        row('fa-droplet',      'Blood Type',        d.blood_type) +
        row('fa-phone-volume', 'Emergency Contact', d.emergency_contact) ||
        '<p style="color:#94a3b8;font-size:.85rem;">No personal details on record.</p>';

    document.getElementById('pm-medical').innerHTML =
        row('fa-heart-pulse',          'Medical Conditions',   d.medical_conditions) +
        row('fa-triangle-exclamation', 'Allergies',            d.allergies) +
        row('fa-utensils',             'Dietary Restrictions', d.dietary) +
        row('fa-user-doctor',          'Primary Physician',    d.physician) ||
        '<p style="color:#94a3b8;font-size:.85rem;">No medical details on record.</p>';

    const cgBox = document.getElementById('pm-caregiver');
    if (d.caregiver_name) {
        const cgInitials = d.caregiver_name.split(' ').map(w=>w[0]).join('').substring(0,2).toUpperCase();
        cgBox.innerHTML = `
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:.9rem;flex-shrink:0;">${cgInitials}</div>
                <div>
                    <div style="font-weight:700;color:#1e293b;font-size:.93rem;">${d.caregiver_name}</div>
                    ${d.caregiver_phone !== '--' ? `<div style="font-size:.8rem;color:#64748b;"><i class="fas fa-phone me-1"></i>${d.caregiver_phone}</div>` : ''}
                </div>
            </div>`;
    } else {
        cgBox.innerHTML = `<p style="color:#94a3b8;font-size:.85rem;margin:0;"><i class="fas fa-user-slash me-1"></i>No caregiver assigned yet.</p>`;
    }

    new bootstrap.Modal(document.getElementById('residentProfileModal')).show();
}

const highCount = <?php echo $risk_counts['high']; ?>;
if (highCount > 0) {
    let orig = document.title, alt = false;
    setInterval(() => { document.title = alt ? orig : `(${highCount} HIGH RISK) Admin Alerts`; alt = !alt; }, 1500);
}
</script>
</body>
</html>