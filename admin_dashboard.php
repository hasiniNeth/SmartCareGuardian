<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}
date_default_timezone_set('Asia/Colombo');

include 'db_connection.php';

$residentCount  = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='resident' AND status='active'")->fetch_assoc()['c'];
$caregiverCount = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='caregiver' AND status='active'")->fetch_assoc()['c'];
$unresolvedAlerts = $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE resolved=0")->fetch_assoc()['c'];
$totalAlerts      = $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'];

$unassigned = $conn->query("
    SELECT COUNT(*) AS c FROM users u
    WHERE u.role='resident' AND u.status='active'
      AND NOT EXISTS (
          SELECT 1 FROM caregiver_assignments ca WHERE ca.resident_id = u.user_id
      )
")->fetch_assoc()['c'];

$todayReadings = $conn->query("SELECT COUNT(*) AS c FROM health_logs WHERE DATE(logged_at) = CURDATE()")->fetch_assoc()['c'];

$medTotal = $conn->query("SELECT COUNT(*) AS c FROM medications WHERE medication_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$medTaken = $conn->query("SELECT COUNT(*) AS c FROM medications WHERE taken=1 AND medication_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$medRate  = $medTotal > 0 ? round(($medTaken / $medTotal) * 100) : 0;

$routineTotal     = $conn->query("SELECT COUNT(*) AS c FROM routine_logs WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$routineCompleted = $conn->query("SELECT COUNT(*) AS c FROM routine_logs WHERE status='completed' AND log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$routineRate      = $routineTotal > 0 ? round(($routineCompleted / $routineTotal) * 100) : 0;

$riskResidents = $conn->query("
    SELECT u.user_id,
           hl.blood_pressure_systolic, hl.blood_sugar,
           hl.temperature, hl.oxygen_saturation
    FROM users u
    LEFT JOIN (
        SELECT resident_id, blood_pressure_systolic, blood_sugar,
               temperature, oxygen_saturation,
               ROW_NUMBER() OVER (PARTITION BY resident_id ORDER BY logged_at DESC) AS rn
        FROM health_logs
    ) hl ON hl.resident_id = u.user_id AND hl.rn = 1
    WHERE u.role='resident' AND u.status='active'
")->fetch_all(MYSQLI_ASSOC);

$riskCounts = ['high'=>0,'medium'=>0,'low'=>0,'no_data'=>0];
foreach ($riskResidents as $r) {
    if (!$r['blood_pressure_systolic']) { $riskCounts['no_data']++; continue; }
    $s = 0;
    if ($r['blood_pressure_systolic'] > 140 || $r['blood_pressure_systolic'] < 90) $s += 2;
    if ($r['blood_sugar'] > 180            || $r['blood_sugar'] < 70)             $s += 2;
    if ($r['temperature'] > 37.8           || $r['temperature'] < 36)             $s += 1;
    if ($r['oxygen_saturation'] < 95)                                              $s += 2;
    $pct = min($s * 20, 95);
    $riskCounts[$pct >= 60 ? 'high' : ($pct >= 30 ? 'medium' : 'low')]++;
}

$recentAlerts = $conn->query("
    SELECT a.alert_id, a.alert_type, a.alert_message, a.created_at,
           u.full_name AS resident_name
    FROM alerts a
    JOIN users u ON a.resident_id = u.user_id
    WHERE a.resolved = 0
    ORDER BY a.created_at DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

$recentReadings = $conn->query("
    SELECT hl.logged_at, hl.blood_pressure_systolic, hl.blood_pressure_diastolic,
           hl.blood_sugar, hl.pulse, hl.oxygen_saturation,
           ur.full_name AS resident_name,
           uc.full_name AS caregiver_name
    FROM health_logs hl
    JOIN users ur ON hl.resident_id = ur.user_id
    LEFT JOIN users uc ON hl.caregiver_id = uc.user_id
    ORDER BY hl.logged_at DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

$recentUsers = $conn->query("
    SELECT user_id, full_name, role, status
    FROM users
    ORDER BY user_id DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

$workload = $conn->query("
    SELECT u.full_name, COUNT(ca.resident_id) AS assigned
    FROM users u
    LEFT JOIN caregiver_assignments ca ON ca.caregiver_id = u.user_id
    WHERE u.role='caregiver' AND u.status='active'
    GROUP BY u.user_id
    ORDER BY assigned DESC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);
$wl_names  = array_column($workload, 'full_name');
$wl_counts = array_column($workload, 'assigned');

$alertsTrend = $conn->query("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM alerts
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at) ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);
$trendDays = array_column($alertsTrend, 'day');
$trendCnts = array_column($alertsTrend, 'cnt');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
    /* ═══════════════════════════════════════════════════════════
       SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
       Admin Dashboard · Professional scale (15px base)
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
    *,*::before,*::after{box-sizing:border-box;}
    body{
        font-family:'Outfit',sans-serif;font-size:15px;line-height:1.6;
        background:var(--w50);
        background-image:
            radial-gradient(ellipse 70% 50% at 90% 0%,rgba(157,192,126,.09) 0%,transparent 55%),
            radial-gradient(ellipse 50% 40% at 0% 100%,rgba(122,166,88,.06) 0%,transparent 50%);
        color:var(--st700);min-height:100vh;margin:0;padding:0;overflow-x:hidden;
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
    .sidebar-footer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 8px;position:relative;}
    .sidebar-footer a{margin:0;color:rgba(255,255,255,.5)!important;font-size:13.5px;}
    .sidebar-footer a:hover{color:rgba(255,255,255,.8)!important;}

    /* ── Layout ── */
    .content{margin-left:240px;padding:24px;min-height:100vh;}

    /* ── Topbar ── */
    .topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;}
    .welcome-text{font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:500;color:var(--s800);margin:0;}
    .welcome-sub{color:var(--st300);font-size:13px;margin:0;}
    .date-chip{background:var(--s50);color:var(--s600);padding:6px 13px;border-radius:20px;font-size:12px;font-weight:700;border:1px solid var(--s100);}
    .logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;}
    .logout-btn:hover{opacity:.9;transform:translateY(-1px);}

    /* ── Alert banner ── */
    .alert-banner{background:var(--red-bg);border:2px solid rgba(107,34,34,.15);border-radius:var(--radius-md);padding:13px 18px;margin-bottom:18px;display:flex;align-items:center;gap:13px;}
    .alert-banner i{font-size:1.3rem;color:var(--red-text);flex-shrink:0;}
    .alert-banner .ab-text{flex:1;}
    .alert-banner .ab-title{font-weight:700;color:var(--red-text);font-size:13px;}
    .alert-banner .ab-sub{font-size:12px;color:var(--red-text);opacity:.8;}
    .alert-banner a{background:var(--red-text);color:white;padding:7px 15px;border-radius:var(--radius-sm);font-size:12px;font-weight:700;text-decoration:none;transition:all .2s;white-space:nowrap;}
    .alert-banner a:hover{opacity:.85;transform:translateY(-1px);}
    @keyframes pulse-red{0%,100%{opacity:1}50%{opacity:.45}}
    .pulse-anim{animation:pulse-red 2s infinite;}

    /* ── KPI grid ── */
    .kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
    .kpi-card{background:white;border-radius:var(--radius-lg);padding:18px 16px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);transition:all .25s;position:relative;overflow:hidden;}
    .kpi-card::before{content:'';position:absolute;top:0;right:0;width:70px;height:70px;border-radius:0 var(--radius-lg) 0 70px;opacity:.05;}
    .kpi-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lift);}
    .kpi-icon{width:46px;height:46px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin-bottom:12px;}
    .kpi-num  {font-size:2rem;font-weight:800;line-height:1;color:var(--s800);}
    .kpi-label{font-weight:700;color:var(--st500);font-size:12px;margin-top:4px;}
    .kpi-sub  {font-size:11px;color:var(--st300);margin-top:2px;}
    .kpi-trend{font-size:11px;font-weight:700;margin-top:6px;display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;}
    .trend-up     {background:var(--green-bg);color:var(--green-text);}
    .trend-warn   {background:var(--amber-bg);color:var(--amber-text);}
    .trend-bad    {background:var(--red-bg);  color:var(--red-text);}
    .trend-neutral{background:var(--s50);     color:var(--st500);}

    /* Icon colour schemes */
    .ic-teal  {background:linear-gradient(135deg,var(--s400),var(--s700));color:white;}
    .ic-green {background:linear-gradient(135deg,var(--s300),var(--s500));color:white;}
    .ic-red   {background:linear-gradient(135deg,#C87A7A,#8B3A3A);color:white;}
    .ic-amber {background:linear-gradient(135deg,#C8A44C,#8B6820);color:white;}
    .ic-purple{background:linear-gradient(135deg,#9A7ABE,#5A3A7A);color:white;}
    .ic-sky   {background:linear-gradient(135deg,#6AAED4,#1A5878);color:white;}
    .ic-rose  {background:linear-gradient(135deg,#D4849A,#8B3A52);color:white;}
    .ic-lime  {background:linear-gradient(135deg,var(--s200),var(--s500));color:white;}

    /* ── Section card ── */
    .section-card{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:20px;overflow:hidden;}
    .section-header{background:linear-gradient(135deg,var(--s600),var(--s800));padding:14px 20px;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;}
    .section-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
    .section-header h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-weight:700;font-size:13.5px;position:relative;display:flex;align-items:center;gap:7px;}
    .section-header a{color:rgba(255,255,255,.75);font-size:12px;font-weight:600;text-decoration:none;position:relative;transition:color .2s;}
    .section-header a:hover{color:white;}
    .section-body{padding:18px 20px;}

    /* ── Quick actions ── */
    .qa-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:11px;margin-bottom:20px;}
    .qa-card{background:white;border-radius:var(--radius-md);padding:16px 14px;text-align:center;text-decoration:none;box-shadow:var(--shadow-soft);border:2px solid transparent;transition:all .25s;}
    .qa-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-card);border-color:var(--s100);}
    .qa-icon{width:44px;height:44px;border-radius:var(--radius-sm);margin:0 auto 9px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;}
    .qa-label{font-weight:700;color:var(--s800);font-size:12px;font-family:'Outfit',sans-serif;}

    /* ── Alert feed ── */
    .alert-item{display:flex;align-items:flex-start;gap:11px;padding:11px 0;border-bottom:1px solid var(--s50);}
    .alert-item:last-child{border-bottom:none;padding-bottom:0;}
    .alert-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;margin-top:5px;}
    .dot-critical{background:var(--red-text);  box-shadow:0 0 0 3px rgba(107,34,34,.15);}
    .dot-warning {background:var(--amber-text);box-shadow:0 0 0 3px rgba(122,80,16,.15);}
    .dot-health  {background:var(--blue-text); box-shadow:0 0 0 3px rgba(26,72,112,.15);}
    .alert-msg {font-size:13px;font-weight:600;color:var(--s800);line-height:1.4;}
    .alert-meta{font-size:12px;color:var(--st300);margin-top:2px;}
    .alert-resident{background:var(--s50);color:var(--s700);padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;border:1px solid var(--s100);}
    .no-alerts{text-align:center;padding:28px;color:var(--st300);}
    .no-alerts i{font-size:1.9rem;margin-bottom:8px;display:block;color:var(--s200);}

    /* ── Reading feed ── */
    .reading-item{display:flex;align-items:center;gap:11px;padding:10px 0;border-bottom:1px solid var(--s50);}
    .reading-item:last-child{border-bottom:none;padding-bottom:0;}
    .reading-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--s300),var(--s600));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:12px;flex-shrink:0;}
    .reading-name{font-weight:700;font-size:13px;color:var(--s800);}
    .reading-meta{font-size:12px;color:var(--st300);}
    .vital-pill{background:var(--s50);color:var(--s700);padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;margin:2px;display:inline-block;border:1px solid var(--s100);}
    .vital-pill.abn{background:var(--red-bg);color:var(--red-text);border-color:rgba(107,34,34,.12);}

    /* ── Activity feed ── */
    .activity-item{display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px solid var(--s50);}
    .activity-item:last-child{border-bottom:none;padding-bottom:0;}
    .act-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex-shrink:0;color:white;}
    .role-admin    {background:linear-gradient(135deg,#8A6AAE,#5A3A7A);}
    .role-caregiver{background:linear-gradient(135deg,var(--s300),var(--s600));}
    .role-resident {background:linear-gradient(135deg,#6AAED4,#1A5878);}
    .act-name{font-weight:700;font-size:13px;color:var(--s800);}
    .act-meta{font-size:12px;color:var(--st300);}
    .role-badge{padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase;}
    .rb-admin    {background:var(--s50);   color:var(--s700);}
    .rb-caregiver{background:var(--green-bg);color:var(--green-text);}
    .rb-resident {background:var(--blue-bg);color:var(--blue-text);}

    /* ── AI Risk summary ── */
    .risk-row{display:flex;gap:9px;margin-bottom:12px;}
    .risk-box{flex:1;border-radius:var(--radius-sm);padding:13px 10px;text-align:center;transition:all .2s;border:2px solid transparent;}
    .risk-box:hover{transform:translateY(-2px);}
    .risk-box-high  {background:var(--red-bg);  border-color:rgba(107,34,34,.15);}
    .risk-box-medium{background:var(--amber-bg);border-color:rgba(122,80,16,.15);}
    .risk-box-low   {background:var(--green-bg);border-color:rgba(58,104,48,.15);}
    .risk-box-nodata{background:var(--s50);     border-color:var(--s100);}
    .risk-num{font-size:1.6rem;font-weight:800;line-height:1;}
    .risk-lbl{font-size:11px;font-weight:700;margin-top:3px;text-transform:uppercase;letter-spacing:.04em;}
    .rn-high  {color:var(--red-text);}
    .rn-medium{color:var(--amber-text);}
    .rn-low   {color:var(--green-text);}
    .rn-nodata{color:var(--st300);}

    /* ── Workload chart ── */
    .chart-wrap{position:relative;height:210px;}

    /* ── Unassigned chip ── */
    .unassigned-chip{background:var(--amber-bg);border:2px solid rgba(122,80,16,.15);border-radius:var(--radius-md);padding:13px 18px;margin-bottom:18px;display:flex;align-items:center;gap:13px;}
    .unassigned-chip i{font-size:1.2rem;color:var(--amber-text);flex-shrink:0;}
    .uc-text{flex:1;}
    .uc-title{font-weight:700;color:var(--amber-text);font-size:13px;}
    .uc-sub  {font-size:12px;color:var(--amber-text);opacity:.8;}
    .uc-btn{background:var(--amber-text);color:white;padding:6px 14px;border-radius:var(--radius-sm);font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap;transition:all .2s;}
    .uc-btn:hover{opacity:.85;}

    @media(max-width:1200px){.kpi-grid{grid-template-columns:repeat(4,1fr);}.qa-grid{grid-template-columns:repeat(4,1fr);}}
    @media(max-width:900px) {.kpi-grid{grid-template-columns:repeat(2,1fr);}.qa-grid{grid-template-columns:repeat(4,1fr);}}
    @media(max-width:768px) {.sidebar{width:100%;height:auto;position:relative;}.content{margin-left:0;padding:14px;}.kpi-grid{grid-template-columns:repeat(2,1fr);}.qa-grid{grid-template-columns:repeat(2,1fr);}}
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
        <small>Administrator Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="#" class="active"><i class="fa-solid fa-gauge-high"></i>Dashboard</a>
        <a href="manage_users.php"><i class="fa-solid fa-users"></i>Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i>Manage Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i>Manage Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i>Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i>Manage Services</a>
        <a href="messages.php"><i class="fa-solid fa-envelope"></i>Messages</a>
        <a href="admin_alerts.php"><i class="fa-solid fa-bell"></i>Health Alerts</a>
        <a href="admin_reports.php"><i class="fa-solid fa-chart-line"></i>Reports & Analytics</a>
    </div>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <!-- Topbar -->
    <div class="topbar">
        <div>
            <p class="welcome-text">Welcome back, <?= htmlspecialchars($_SESSION['full_name']); ?> 👋</p>
            <p class="welcome-sub">Here's what's happening at your facility today</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span class="date-chip"><i class="far fa-calendar me-1"></i><?= date('D, d M Y'); ?></span>
            <form action="logout.php" method="POST" class="mb-0">
                <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt me-2"></i>Logout</button>
            </form>
        </div>
    </div>

    <!-- Urgent alert banner -->
    <?php if ($unresolvedAlerts > 0 || $riskCounts['high'] > 0): ?>
    <div class="alert-banner">
        <i class="fas fa-circle-exclamation pulse-anim"></i>
        <div class="ab-text">
            <div class="ab-title">
                <?php
                $msgs = [];
                if ($unresolvedAlerts > 0) $msgs[] = "{$unresolvedAlerts} unresolved health alert" . ($unresolvedAlerts > 1 ? 's' : '');
                if ($riskCounts['high']  > 0) $msgs[] = "{$riskCounts['high']} resident" . ($riskCounts['high'] > 1 ? 's' : '') . " flagged as high risk";
                echo implode(' &nbsp;·&nbsp; ', $msgs);
                ?>
            </div>
            <div class="ab-sub">Immediate attention may be required</div>
        </div>
        <a href="admin_alerts.php"><i class="fas fa-arrow-right me-1"></i>View Alerts</a>
    </div>
    <?php endif; ?>

    <!-- Unassigned residents warning -->
    <?php if ($unassigned > 0): ?>
    <div class="unassigned-chip">
        <i class="fas fa-user-slash"></i>
        <div class="uc-text">
            <div class="uc-title"><?= $unassigned; ?> resident<?= $unassigned > 1 ? 's have' : ' has'; ?> no caregiver assigned</div>
            <div class="uc-sub">These residents are not currently being monitored by any caregiver</div>
        </div>
        <a href="assign_caregiver.php" class="uc-btn"><i class="fas fa-link me-1"></i>Assign Now</a>
    </div>
    <?php endif; ?>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon ic-teal"><i class="fas fa-users"></i></div>
            <div class="kpi-num"><?= $residentCount; ?></div>
            <div class="kpi-label">Active Residents</div>
            <?php if ($unassigned > 0): ?>
                <div class="kpi-trend trend-warn"><i class="fas fa-exclamation"></i><?= $unassigned; ?> unassigned</div>
            <?php else: ?>
                <div class="kpi-trend trend-up"><i class="fas fa-check"></i>All assigned</div>
            <?php endif; ?>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-green"><i class="fas fa-user-nurse"></i></div>
            <div class="kpi-num"><?= $caregiverCount; ?></div>
            <div class="kpi-label">Active Caregivers</div>
            <div class="kpi-sub">On duty staff</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-red"><i class="fas fa-bell"></i></div>
            <div class="kpi-num"><?= $unresolvedAlerts; ?></div>
            <div class="kpi-label">Unresolved Alerts</div>
            <?php if ($unresolvedAlerts > 0): ?>
                <div class="kpi-trend trend-bad"><i class="fas fa-circle pulse-anim"></i>Needs attention</div>
            <?php else: ?>
                <div class="kpi-trend trend-up"><i class="fas fa-check"></i>All clear</div>
            <?php endif; ?>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-sky"><i class="fas fa-notes-medical"></i></div>
            <div class="kpi-num"><?= $todayReadings; ?></div>
            <div class="kpi-label">Readings Today</div>
            <div class="kpi-sub"><?= date('d M Y'); ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-purple"><i class="fas fa-pills"></i></div>
            <div class="kpi-num"><?= $medRate; ?>%</div>
            <div class="kpi-label">Medication Adherence</div>
            <div class="kpi-sub">Last 7 days · <?= $medTaken; ?>/<?= $medTotal; ?></div>
            <?php if ($medRate >= 80): ?>
                <div class="kpi-trend trend-up"><i class="fas fa-arrow-up"></i>Good</div>
            <?php elseif ($medRate >= 60): ?>
                <div class="kpi-trend trend-warn"><i class="fas fa-minus"></i>Fair</div>
            <?php else: ?>
                <div class="kpi-trend trend-bad"><i class="fas fa-arrow-down"></i>Low</div>
            <?php endif; ?>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-lime"><i class="fas fa-calendar-check"></i></div>
            <div class="kpi-num"><?= $routineRate; ?>%</div>
            <div class="kpi-label">Routine Completion</div>
            <div class="kpi-sub">Last 7 days · <?= $routineCompleted; ?>/<?= $routineTotal; ?></div>
            <?php if ($routineRate >= 80): ?>
                <div class="kpi-trend trend-up"><i class="fas fa-arrow-up"></i>Good</div>
            <?php elseif ($routineRate >= 60): ?>
                <div class="kpi-trend trend-warn"><i class="fas fa-minus"></i>Fair</div>
            <?php else: ?>
                <div class="kpi-trend trend-bad"><i class="fas fa-arrow-down"></i>Low</div>
            <?php endif; ?>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-rose"><i class="fas fa-brain"></i></div>
            <div class="kpi-num"><?= $riskCounts['high']; ?></div>
            <div class="kpi-label">High AI Risk</div>
            <?php if ($riskCounts['high'] > 0): ?>
                <div class="kpi-trend trend-bad"><i class="fas fa-circle pulse-anim"></i>Review now</div>
            <?php else: ?>
                <div class="kpi-trend trend-up"><i class="fas fa-check"></i>None flagged</div>
            <?php endif; ?>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-amber"><i class="fas fa-triangle-exclamation"></i></div>
            <div class="kpi-num"><?= $totalAlerts; ?></div>
            <div class="kpi-label">Alerts Today</div>
            <div class="kpi-sub">Generated <?= date('d M'); ?></div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="qa-grid">
        <a href="add_resident.php"    class="qa-card"><div class="qa-icon ic-teal">  <i class="fas fa-user-plus"></i></div><div class="qa-label">Add Resident</div></a>
        <a href="add_caregiver.php"   class="qa-card"><div class="qa-icon ic-green"> <i class="fas fa-user-nurse"></i></div><div class="qa-label">Add Caregiver</div></a>
        <a href="assign_caregiver.php"class="qa-card"><div class="qa-icon ic-sky">   <i class="fas fa-link"></i></div><div class="qa-label">Assign Caregiver</div></a>
        <a href="admin_alerts.php"    class="qa-card"><div class="qa-icon ic-red">   <i class="fas fa-bell"></i></div><div class="qa-label">View Alerts</div></a>
        <a href="admin_reports.php"   class="qa-card"><div class="qa-icon ic-purple"><i class="fas fa-chart-line"></i></div><div class="qa-label">Reports</div></a>
        <a href="messages.php"        class="qa-card"><div class="qa-icon ic-amber"> <i class="fas fa-envelope"></i></div><div class="qa-label">Messages</div></a>
        <a href="manage_users.php"    class="qa-card"><div class="qa-icon ic-rose">  <i class="fas fa-users-gear"></i></div><div class="qa-label">Manage Users</div></a>
        <a href="manage_services.php" class="qa-card"><div class="qa-icon ic-lime">  <i class="fas fa-spa"></i></div><div class="qa-label">Services</div></a>
    </div>

    <!-- Alerts + Recent Readings -->
    <div class="row g-4 mb-0">
        <div class="col-lg-5">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5><i class="fas fa-bell"></i>Active Alerts</h5>
                    <a href="admin_alerts.php">View all <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
                <div class="section-body">
                    <?php if (empty($recentAlerts)): ?>
                        <div class="no-alerts">
                            <i class="fas fa-check-circle" style="color:var(--s300);"></i>
                            <p style="font-size:13px;margin:0;">No unresolved alerts. All clear!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentAlerts as $al):
                            $dotClass = match($al['alert_type']) {
                                'critical' => 'dot-critical',
                                'warning'  => 'dot-warning',
                                default    => 'dot-health',
                            };
                        ?>
                        <div class="alert-item">
                            <div class="alert-dot <?= $dotClass; ?>"></div>
                            <div style="flex:1;min-width:0;">
                                <div class="alert-msg"><?= htmlspecialchars($al['alert_message']); ?></div>
                                <div class="alert-meta">
                                    <span class="alert-resident"><?= htmlspecialchars($al['resident_name']); ?></span>
                                    &nbsp;<?= date('d M, H:i', strtotime($al['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5><i class="fas fa-notes-medical"></i>Recent Health Readings</h5>
                    <a href="admin_reports.php">View reports <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
                <div class="section-body">
                    <?php if (empty($recentReadings)): ?>
                        <div class="no-alerts">
                            <i class="fas fa-clipboard-list"></i>
                            <p style="font-size:13px;margin:0;">No readings logged yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentReadings as $rd):
                            $initials = strtoupper(substr($rd['resident_name'], 0, 1));
                            if (strpos($rd['resident_name'], ' ') !== false) {
                                $initials .= strtoupper(substr(strrchr($rd['resident_name'], ' '), 1, 1));
                            }
                            $sys_abn = $rd['blood_pressure_systolic'] > 140 || $rd['blood_pressure_systolic'] < 90;
                            $sug_abn = $rd['blood_sugar'] > 180 || $rd['blood_sugar'] < 70;
                            $o2_abn  = $rd['oxygen_saturation'] < 95;
                        ?>
                        <div class="reading-item">
                            <div class="reading-avatar"><?= $initials; ?></div>
                            <div style="flex:1;min-width:0;">
                                <div class="reading-name"><?= htmlspecialchars($rd['resident_name']); ?></div>
                                <div class="reading-meta">
                                    <?= date('d M, H:i', strtotime($rd['logged_at'])); ?>
                                    <?php if ($rd['caregiver_name']): ?> &nbsp;·&nbsp; by <?= htmlspecialchars($rd['caregiver_name']); ?><?php endif; ?>
                                </div>
                                <div style="margin-top:4px;">
                                    <span class="vital-pill <?= $sys_abn ? 'abn' : ''; ?>">BP <?= $rd['blood_pressure_systolic']; ?>/<?= $rd['blood_pressure_diastolic']; ?></span>
                                    <span class="vital-pill <?= $sug_abn ? 'abn' : ''; ?>">Sugar <?= $rd['blood_sugar']; ?></span>
                                    <span class="vital-pill"><?= $rd['pulse']; ?> bpm</span>
                                    <span class="vital-pill <?= $o2_abn ? 'abn' : ''; ?>">O₂ <?= $rd['oxygen_saturation']; ?>%</span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- AI Risk + Workload + Registrations -->
    <div class="row g-4 mt-0">
        <div class="col-lg-4">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5><i class="fas fa-brain"></i>AI Risk Summary</h5>
                    <a href="admin_alerts.php">Details <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
                <div class="section-body">
                    <div class="risk-row">
                        <div class="risk-box risk-box-high">
                            <div class="risk-num rn-high"><?= $riskCounts['high']; ?></div>
                            <div class="risk-lbl rn-high"><i class="fas fa-fire me-1"></i>High</div>
                        </div>
                        <div class="risk-box risk-box-medium">
                            <div class="risk-num rn-medium"><?= $riskCounts['medium']; ?></div>
                            <div class="risk-lbl rn-medium"><i class="fas fa-bolt me-1"></i>Medium</div>
                        </div>
                    </div>
                    <div class="risk-row">
                        <div class="risk-box risk-box-low">
                            <div class="risk-num rn-low"><?= $riskCounts['low']; ?></div>
                            <div class="risk-lbl rn-low"><i class="fas fa-leaf me-1"></i>Low</div>
                        </div>
                        <div class="risk-box risk-box-nodata">
                            <div class="risk-num rn-nodata"><?= $riskCounts['no_data']; ?></div>
                            <div class="risk-lbl rn-nodata"><i class="fas fa-minus me-1"></i>No Data</div>
                        </div>
                    </div>
                    <div style="margin-top:12px;">
                        <div style="font-size:11px;font-weight:700;color:var(--st300);text-transform:uppercase;letter-spacing:.05em;margin-bottom:7px;">
                            <i class="fas fa-chart-line me-1"></i>Alerts — Last 7 Days
                        </div>
                        <div style="position:relative;height:70px;"><canvas id="sparkChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5><i class="fas fa-chart-bar"></i>Caregiver Workload</h5>
                    <a href="assign_caregiver.php">Manage <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
                <div class="section-body">
                    <?php if (empty($workload)): ?>
                        <div class="no-alerts"><i class="fas fa-user-nurse"></i><p style="font-size:13px;margin:0;">No caregivers found.</p></div>
                    <?php else: ?>
                        <div class="chart-wrap"><canvas id="workloadChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5><i class="fas fa-user-plus"></i>Recent Registrations</h5>
                    <a href="manage_users.php">View all <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
                <div class="section-body">
                    <?php if (empty($recentUsers)): ?>
                        <div class="no-alerts"><i class="fas fa-users"></i><p style="font-size:13px;margin:0;">No users registered yet.</p></div>
                    <?php else: ?>
                        <?php foreach ($recentUsers as $u):
                            $initials = strtoupper(substr($u['full_name'], 0, 1));
                            if (strpos($u['full_name'], ' ') !== false) {
                                $initials .= strtoupper(substr(strrchr($u['full_name'], ' '), 1, 1));
                            }
                        ?>
                        <div class="activity-item">
                            <div class="act-avatar role-<?= $u['role']; ?>"><?= $initials; ?></div>
                            <div style="flex:1;min-width:0;">
                                <div class="act-name"><?= htmlspecialchars($u['full_name']); ?></div>
                                <div class="act-meta"><span class="role-badge rb-<?= $u['role']; ?>"><?= $u['role']; ?></span></div>
                            </div>
                            <?php if ($u['status'] === 'active'): ?>
                                <span style="width:8px;height:8px;border-radius:50%;background:var(--green-text);flex-shrink:0;"></span>
                            <?php else: ?>
                                <span style="width:8px;height:8px;border-radius:50%;background:var(--st300);flex-shrink:0;"></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
<?php if (!empty($workload)): ?>
new Chart(document.getElementById('workloadChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($n) => strlen($n) > 12 ? substr($n, 0, 12) . '…' : $n, $wl_names)); ?>,
        datasets: [{
            data: <?= json_encode($wl_counts); ?>,
            backgroundColor: <?= json_encode($wl_counts); ?>.map(c =>
                c === 0 ? 'rgba(184,176,164,0.4)' : 'rgba(94,138,64,0.75)'
            ),
            borderColor: 'rgba(54,82,32,0.8)',
            borderWidth: 1, borderRadius: 6,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'white',
                titleColor: '#243816', bodyColor: '#7A7268',
                borderColor: '#E3EDDB', borderWidth: 1,
                padding: 10, cornerRadius: 10,
                callbacks: { label: ctx => `${ctx.parsed.y} resident${ctx.parsed.y !== 1 ? 's' : ''}` }
            }
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#7A7268' } },
            y: { grid: { color: 'rgba(36,56,22,.04)' }, ticks: { stepSize: 1, color: '#B8B0A4' }, min: 0 }
        }
    }
});
<?php endif; ?>

new Chart(document.getElementById('sparkChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($trendDays); ?>,
        datasets: [{
            data: <?= json_encode($trendCnts); ?>,
            borderColor: '#8B3A3A', backgroundColor: 'rgba(139,58,58,0.1)',
            tension: .4, fill: true, pointRadius: 3,
            pointBackgroundColor: '#8B3A3A',
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: {
            backgroundColor: 'white', titleColor: '#243816', bodyColor: '#7A7268',
            borderColor: '#F5DADA', borderWidth: 1, padding: 8, cornerRadius: 8,
        }},
        scales: { x: { display: false }, y: { display: false, min: 0 } }
    }
});

document.querySelectorAll('.kpi-num').forEach(el => {
    const target = parseInt(el.textContent) || 0;
    if (isNaN(target) || el.textContent.includes('%')) return;
    let current = 0;
    const step  = Math.max(1, Math.floor(target / 30));
    const timer = setInterval(() => {
        current = Math.min(current + step, target);
        el.textContent = current;
        if (current >= target) clearInterval(timer);
    }, 30);
});
</script>
</body>
</html>