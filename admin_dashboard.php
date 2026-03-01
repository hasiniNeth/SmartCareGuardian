<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

include 'db_connection.php';

// ── Core counts ────────────────────────────────────────────────────────────
$residentCount  = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='resident' AND status='active'")->fetch_assoc()['c'];
$caregiverCount = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='caregiver' AND status='active'")->fetch_assoc()['c'];
$unresolvedAlerts = $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE resolved=0")->fetch_assoc()['c'];
$totalAlerts      = $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'];

// ── Unassigned residents (no caregiver assigned) ───────────────────────────
$unassigned = $conn->query("
    SELECT COUNT(*) AS c FROM users u
    WHERE u.role='resident' AND u.status='active'
      AND NOT EXISTS (
          SELECT 1 FROM caregiver_assignments ca WHERE ca.resident_id = u.user_id
      )
")->fetch_assoc()['c'];

// ── Today's readings ───────────────────────────────────────────────────────
$todayReadings = $conn->query("SELECT COUNT(*) AS c FROM health_logs WHERE DATE(logged_at) = CURDATE()")->fetch_assoc()['c'];

// ── Medication adherence (last 7 days) ────────────────────────────────────
$medTotal = $conn->query("SELECT COUNT(*) AS c FROM medications WHERE medication_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$medTaken = $conn->query("SELECT COUNT(*) AS c FROM medications WHERE taken=1 AND medication_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$medRate  = $medTotal > 0 ? round(($medTaken / $medTotal) * 100) : 0;

// ── Routine completion (last 7 days) ──────────────────────────────────────
$routineTotal     = $conn->query("SELECT COUNT(*) AS c FROM routine_logs WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$routineCompleted = $conn->query("SELECT COUNT(*) AS c FROM routine_logs WHERE status='completed' AND log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$routineRate      = $routineTotal > 0 ? round(($routineCompleted / $routineTotal) * 100) : 0;

// ── AI risk counts (rule-based fallback, no AI call on dashboard for speed) ─
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

// ── Recent unresolved alerts (last 5) ─────────────────────────────────────
$recentAlerts = $conn->query("
    SELECT a.alert_id, a.alert_type, a.alert_message, a.created_at,
           u.full_name AS resident_name
    FROM alerts a
    JOIN users u ON a.resident_id = u.user_id
    WHERE a.resolved = 0
    ORDER BY a.created_at DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ── Recent health readings (last 5) ───────────────────────────────────────
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

// ── Recent user registrations (last 5) ───────────────────────────────────
$recentUsers = $conn->query("
    SELECT user_id, full_name, role, status
    FROM users
    ORDER BY user_id DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ── Caregiver workload (for mini chart) ──────────────────────────────────
$workload = $conn->query("
    SELECT u.full_name,
           COUNT(ca.resident_id) AS assigned
    FROM users u
    LEFT JOIN caregiver_assignments ca ON ca.caregiver_id = u.user_id
    WHERE u.role='caregiver' AND u.status='active'
    GROUP BY u.user_id
    ORDER BY assigned DESC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);
$wl_names  = array_column($workload, 'full_name');
$wl_counts = array_column($workload, 'assigned');

// ── 7-day alerts trend (for sparkline) ───────────────────────────────────
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
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Quicksand:wght@300;400;500;600;700&family=Jost:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --sage-green: #87A96B;
            --mint-cream: #F0FFF0;
            --seafoam: #9FE2BF;
            --forest-mist: #B8E0D2;
            --dusty-teal: #6D9B8E;
            --deep-emerald: #4A766E;
            --light-sage: #E8F5E8;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Quicksand', sans-serif;
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
            margin: 0; padding: 0; min-height: 100vh; overflow-x: hidden;
        }

        h1, h2, h3, h4, h5 { font-family: 'Playfair Display', serif; color: var(--deep-emerald); }
        .brand-font { font-family: 'Jost', sans-serif; font-weight: 600; }

        /* ── Sidebar ─────────────────────────────────────────── */
        .sidebar {
            width: 280px; height: 100vh; position: fixed;
            background: linear-gradient(180deg, var(--sage-green) 0%, var(--dusty-teal) 100%);
            color: white; box-shadow: 4px 0 20px rgba(0,0,0,.1);
            z-index: 1000; display: flex; flex-direction: column;
        }
        .sidebar-header { text-align: center; padding: 30px 20px 20px; border-bottom: 1px solid rgba(255,255,255,.2); flex-shrink: 0; }
        .sidebar-nav    { flex: 1; overflow-y: auto; padding: 20px 0; }
        .sidebar-footer { flex-shrink: 0; border-top: 1px solid rgba(255,255,255,.2); padding: 20px; }
        .sidebar a {
            color: white; display: flex; align-items: center;
            padding: 15px 25px; text-decoration: none; transition: all .3s;
            margin: 5px 15px; border-radius: 12px; font-weight: 500;
        }
        .sidebar a:hover  { background: rgba(255,255,255,.15); transform: translateX(5px); }
        .sidebar a.active { background: rgba(255,255,255,.25); box-shadow: 0 4px 15px rgba(0,0,0,.1); }
        .sidebar i { width: 25px; margin-right: 12px; font-size: 1.1rem; }
        .sidebar-nav::-webkit-scrollbar { width: 6px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.3); border-radius: 3px; }

        /* ── Layout ──────────────────────────────────────────── */
        .content { margin-left: 280px; padding: 28px; min-height: 100vh; }

        /* ── Topbar ──────────────────────────────────────────── */
        .topbar {
            background: rgba(255,255,255,.97); backdrop-filter: blur(10px);
            border-radius: 20px; padding: 22px 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,.08);
            margin-bottom: 26px; display: flex; align-items: center; justify-content: space-between;
        }
        .welcome-text { font-size: 1.55rem; font-weight: 700; color: var(--deep-emerald); font-family: 'Playfair Display', serif; margin: 0; }
        .welcome-sub  { color: #94a3b8; font-size: .85rem; margin: 0; }
        .date-chip { background: var(--light-sage); color: var(--dusty-teal); padding: 6px 14px; border-radius: 20px; font-size: .8rem; font-weight: 700; font-family: 'Jost', sans-serif; }
        .logout-btn {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            border: none; border-radius: 50px; color: white;
            padding: 10px 22px; font-weight: 600; transition: all .3s; font-size: .87rem;
        }
        .logout-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(255,107,107,.4); }

        /* ── Alert banner (shows only if high-risk or unresolved alerts) ── */
        .alert-banner {
            background: linear-gradient(135deg, #fef2f2, #fff5f5);
            border: 2px solid #fecaca; border-radius: 16px;
            padding: 14px 22px; margin-bottom: 22px;
            display: flex; align-items: center; gap: 14px;
        }
        .alert-banner i { font-size: 1.4rem; color: #ef4444; }
        .alert-banner .ab-text { flex: 1; }
        .alert-banner .ab-title { font-weight: 700; color: #991b1b; font-size: .93rem; }
        .alert-banner .ab-sub   { font-size: .8rem; color: #b91c1c; }
        .alert-banner a { background: #ef4444; color: white; padding: 7px 16px; border-radius: 20px; font-size: .8rem; font-weight: 700; text-decoration: none; transition: all .2s; white-space: nowrap; }
        .alert-banner a:hover { background: #dc2626; transform: translateY(-1px); }
        @keyframes pulse-red { 0%,100%{opacity:1} 50%{opacity:.5} }
        .pulse-anim { animation: pulse-red 2s infinite; }

        /* ── KPI grid ────────────────────────────────────────── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .kpi-card {
            background: rgba(255,255,255,.97); border-radius: 20px;
            padding: 22px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.06);
            transition: all .3s; position: relative; overflow: hidden;
        }
        .kpi-card::before {
            content: ''; position: absolute; top: 0; right: 0;
            width: 80px; height: 80px; border-radius: 0 20px 0 80px;
            opacity: .06;
        }
        .kpi-card:hover { transform: translateY(-4px); box-shadow: 0 14px 36px rgba(0,0,0,.1); }
        .kpi-icon {
            width: 50px; height: 50px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem; margin-bottom: 14px;
        }
        .kpi-num   { font-size: 2.1rem; font-weight: 800; line-height: 1; color: var(--deep-emerald); }
        .kpi-label { font-weight: 600; color: #64748b; font-size: .8rem; margin-top: 5px; }
        .kpi-sub   { font-size: .72rem; color: #94a3b8; margin-top: 3px; }
        .kpi-trend { font-size: .75rem; font-weight: 700; margin-top: 6px; display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 20px; }
        .trend-up   { background: #dcfce7; color: #16a34a; }
        .trend-warn { background: #fef9c3; color: #ca8a04; }
        .trend-bad  { background: #fef2f2; color: #dc2626; }
        .trend-neutral { background: #f1f5f9; color: #64748b; }

        /* Icon colour schemes */
        .ic-teal   { background: linear-gradient(135deg, var(--dusty-teal), var(--deep-emerald)); color: white; }
        .ic-green  { background: linear-gradient(135deg, var(--seafoam), var(--sage-green));  color: white; }
        .ic-red    { background: linear-gradient(135deg, #ff6b6b, #ee5a52); color: white; }
        .ic-amber  { background: linear-gradient(135deg, #fbbf24, #d97706); color: white; }
        .ic-purple { background: linear-gradient(135deg, #a78bfa, #7c3aed); color: white; }
        .ic-sky    { background: linear-gradient(135deg, #38bdf8, #0284c7); color: white; }
        .ic-rose   { background: linear-gradient(135deg, #fb7185, #e11d48); color: white; }
        .ic-lime   { background: linear-gradient(135deg, #86efac, #16a34a); color: white; }

        /* ── Section card ────────────────────────────────────── */
        .section-card {
            background: rgba(255,255,255,.97); border-radius: 20px;
            box-shadow: 0 6px 24px rgba(0,0,0,.06); margin-bottom: 22px; overflow: hidden;
        }
        .section-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            padding: 15px 22px; display: flex; align-items: center; justify-content: space-between;
        }
        .section-header h5 { color: white; margin: 0; font-size: .95rem; font-family: 'Jost', sans-serif; font-weight: 700; }
        .section-header a  { color: rgba(255,255,255,.85); font-size: .78rem; font-weight: 600; text-decoration: none; }
        .section-header a:hover { color: white; }
        .section-body { padding: 20px; }

        /* ── Quick actions ───────────────────────────────────── */
        .qa-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
        .qa-card {
            background: rgba(255,255,255,.97); border-radius: 16px;
            padding: 18px 16px; text-align: center; text-decoration: none;
            box-shadow: 0 4px 16px rgba(0,0,0,.06); transition: all .3s; border: 2px solid transparent;
        }
        .qa-card:hover { transform: translateY(-4px); box-shadow: 0 10px 28px rgba(0,0,0,.1); border-color: var(--forest-mist); }
        .qa-icon { width: 46px; height: 46px; border-radius: 12px; margin: 0 auto 10px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; }
        .qa-label { font-weight: 700; color: var(--deep-emerald); font-size: .83rem; font-family: 'Jost', sans-serif; }

        /* ── Alert feed ──────────────────────────────────────── */
        .alert-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 12px 0; border-bottom: 1px solid #f1f5f9;
        }
        .alert-item:last-child { border-bottom: none; padding-bottom: 0; }
        .alert-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; margin-top: 5px; }
        .dot-critical { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.2); }
        .dot-warning  { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.2); }
        .dot-health   { background: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.2); }
        .alert-msg  { font-size: .85rem; font-weight: 600; color: var(--deep-emerald); line-height: 1.4; }
        .alert-meta { font-size: .75rem; color: #94a3b8; margin-top: 2px; }
        .alert-resident { background: var(--light-sage); color: var(--deep-emerald); padding: 2px 8px; border-radius: 10px; font-size: .72rem; font-weight: 700; }
        .no-alerts { text-align: center; padding: 30px; color: #94a3b8; }
        .no-alerts i { font-size: 2rem; margin-bottom: 8px; display: block; color: var(--forest-mist); }

        /* ── Reading feed ────────────────────────────────────── */
        .reading-item {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 0; border-bottom: 1px solid #f1f5f9;
        }
        .reading-item:last-child { border-bottom: none; padding-bottom: 0; }
        .reading-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal));
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 800; font-size: .8rem; flex-shrink: 0;
        }
        .reading-name { font-weight: 700; font-size: .85rem; color: var(--deep-emerald); }
        .reading-meta { font-size: .75rem; color: #94a3b8; }
        .vital-pill { background: var(--light-sage); color: var(--deep-emerald); padding: 3px 9px; border-radius: 10px; font-size: .72rem; font-weight: 700; margin: 2px; display: inline-block; }
        .vital-pill.abn { background: #fef2f2; color: #dc2626; }

        /* ── Activity feed (user registrations) ─────────────── */
        .activity-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 0; border-bottom: 1px solid #f1f5f9;
        }
        .activity-item:last-child { border-bottom: none; padding-bottom: 0; }
        .act-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: .8rem; flex-shrink: 0; color: white;
        }
        .role-admin    { background: linear-gradient(135deg, #667eea, #764ba2); }
        .role-caregiver{ background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal)); }
        .role-resident { background: linear-gradient(135deg, var(--seafoam), #0891b2); }
        .act-name { font-weight: 700; font-size: .85rem; color: var(--deep-emerald); }
        .act-meta { font-size: .75rem; color: #94a3b8; }
        .role-badge { padding: 2px 9px; border-radius: 10px; font-size: .7rem; font-weight: 700; text-transform: uppercase; }
        .rb-admin    { background: #ede9fe; color: #7c3aed; }
        .rb-caregiver{ background: var(--light-sage); color: var(--deep-emerald); }
        .rb-resident { background: #dbeafe; color: #1d4ed8; }

        /* ── AI Risk summary ─────────────────────────────────── */
        .risk-row { display: flex; gap: 10px; margin-bottom: 16px; }
        .risk-box {
            flex: 1; border-radius: 14px; padding: 14px 12px; text-align: center;
            transition: all .2s;
        }
        .risk-box:hover { transform: translateY(-2px); }
        .risk-box-high   { background: #fef2f2; border: 2px solid #fecaca; }
        .risk-box-medium { background: #fffbeb; border: 2px solid #fde68a; }
        .risk-box-low    { background: #edfdf4; border: 2px solid #bbf7d0; }
        .risk-box-nodata { background: #f8fafc; border: 2px solid #e2e8f0; }
        .risk-num  { font-size: 1.7rem; font-weight: 800; line-height: 1; }
        .risk-lbl  { font-size: .72rem; font-weight: 700; margin-top: 4px; text-transform: uppercase; letter-spacing: .04em; }
        .rn-high   { color: #ef4444; }
        .rn-medium { color: #d97706; }
        .rn-low    { color: #16a34a; }
        .rn-nodata { color: #64748b; }

        /* ── Workload chart ──────────────────────────────────── */
        .chart-wrap { position: relative; height: 210px; }

        /* ── Unassigned warning chip ─────────────────────────── */
        .unassigned-chip {
            background: linear-gradient(135deg, #fff7ed, #fef3c7);
            border: 2px solid #fed7aa; border-radius: 14px;
            padding: 14px 18px; margin-bottom: 22px;
            display: flex; align-items: center; gap: 14px;
        }
        .unassigned-chip i { font-size: 1.3rem; color: #ea580c; flex-shrink: 0; }
        .uc-text { flex: 1; }
        .uc-title { font-weight: 700; color: #9a3412; font-size: .9rem; }
        .uc-sub   { font-size: .78rem; color: #c2410c; }
        .uc-btn { background: #ea580c; color: white; padding: 6px 16px; border-radius: 20px; font-size: .78rem; font-weight: 700; text-decoration: none; white-space: nowrap; transition: all .2s; }
        .uc-btn:hover { background: #c2410c; }

        /* ── Responsive ──────────────────────────────────────── */
        @media (max-width: 1200px) {
            .kpi-grid { grid-template-columns: repeat(4, 1fr); }
            .qa-grid  { grid-template-columns: repeat(4, 1fr); }
        }
        @media (max-width: 900px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .qa-grid  { grid-template-columns: repeat(4, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar  { width: 100%; height: auto; position: relative; }
            .content  { margin-left: 0; padding: 14px; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .qa-grid  { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<!-- ══════ SIDEBAR ══════ -->
<div class="sidebar">
    <div class="sidebar-header">
        <h4 class="brand-font mb-2"><i class="fa-solid fa-shield-heart me-2"></i>SmartCare Guardian</h4>
        <small class="opacity-75">Administrator Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="#" class="active"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="manage_users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i> Manage Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i> Manage Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i> Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i> Manage Services</a>
        <a href="messages.php"><i class="fa-solid fa-envelope"></i> Messages</a>
        <a href="admin_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="admin_reports.php"><i class="fa-solid fa-chart-line"></i> Reports & Analytics</a>
    </div>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<!-- ══════ CONTENT ══════ -->
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

    <!-- ── KPI Cards ─────────────────────────────────────────────── -->
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

    <!-- ── Quick Actions ──────────────────────────────────────────── -->
    <div class="qa-grid">
        <a href="add_resident.php" class="qa-card">
            <div class="qa-icon ic-teal"><i class="fas fa-user-plus"></i></div>
            <div class="qa-label">Add Resident</div>
        </a>
        <a href="add_caregiver.php" class="qa-card">
            <div class="qa-icon ic-green"><i class="fas fa-user-nurse"></i></div>
            <div class="qa-label">Add Caregiver</div>
        </a>
        <a href="assign_caregiver.php" class="qa-card">
            <div class="qa-icon ic-sky"><i class="fas fa-link"></i></div>
            <div class="qa-label">Assign Caregiver</div>
        </a>
        <a href="admin_alerts.php" class="qa-card">
            <div class="qa-icon ic-red"><i class="fas fa-bell"></i></div>
            <div class="qa-label">View Alerts</div>
        </a>
        <a href="admin_reports.php" class="qa-card">
            <div class="qa-icon ic-purple"><i class="fas fa-chart-line"></i></div>
            <div class="qa-label">Reports</div>
        </a>
        <a href="messages.php" class="qa-card">
            <div class="qa-icon ic-amber"><i class="fas fa-envelope"></i></div>
            <div class="qa-label">Messages</div>
        </a>
        <a href="manage_users.php" class="qa-card">
            <div class="qa-icon ic-rose"><i class="fas fa-users-gear"></i></div>
            <div class="qa-label">Manage Users</div>
        </a>
        <a href="manage_services.php" class="qa-card">
            <div class="qa-icon ic-lime"><i class="fas fa-spa"></i></div>
            <div class="qa-label">Services</div>
        </a>
    </div>

    <!-- ── Row: Alerts + Recent Readings ─────────────────────────── -->
    <div class="row g-4 mb-0">

        <!-- Unresolved Alerts Feed -->
        <div class="col-lg-5">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5><i class="fas fa-bell me-2"></i>Active Alerts</h5>
                    <a href="admin_alerts.php">View all <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
                <div class="section-body">
                    <?php if (empty($recentAlerts)): ?>
                        <div class="no-alerts">
                            <i class="fas fa-check-circle" style="color:var(--forest-mist);"></i>
                            <p class="mb-0" style="font-size:.87rem;">No unresolved alerts. All clear!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentAlerts as $al):
                            $dotClass = match($al['alert_type']) {
                                'critical'       => 'dot-critical',
                                'warning'        => 'dot-warning',
                                default          => 'dot-health',
                            };
                        ?>
                        <div class="alert-item">
                            <div class="alert-dot <?= $dotClass; ?>"></div>
                            <div style="flex:1; min-width:0;">
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

        <!-- Recent Readings Feed -->
        <div class="col-lg-7">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5><i class="fas fa-notes-medical me-2"></i>Recent Health Readings</h5>
                    <a href="admin_reports.php">View reports <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
                <div class="section-body">
                    <?php if (empty($recentReadings)): ?>
                        <div class="no-alerts">
                            <i class="fas fa-clipboard-list"></i>
                            <p class="mb-0" style="font-size:.87rem;">No readings logged yet.</p>
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
                            <div style="flex:1; min-width:0;">
                                <div class="reading-name"><?= htmlspecialchars($rd['resident_name']); ?></div>
                                <div class="reading-meta">
                                    <?= date('d M, H:i', strtotime($rd['logged_at'])); ?>
                                    <?php if ($rd['caregiver_name']): ?>
                                        &nbsp;·&nbsp; by <?= htmlspecialchars($rd['caregiver_name']); ?>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top:4px;">
                                    <span class="vital-pill <?= $sys_abn ? 'abn' : ''; ?>">
                                        BP <?= $rd['blood_pressure_systolic']; ?>/<?= $rd['blood_pressure_diastolic']; ?>
                                    </span>
                                    <span class="vital-pill <?= $sug_abn ? 'abn' : ''; ?>">
                                        Sugar <?= $rd['blood_sugar']; ?>
                                    </span>
                                    <span class="vital-pill"><?= $rd['pulse']; ?> bpm</span>
                                    <span class="vital-pill <?= $o2_abn ? 'abn' : ''; ?>">
                                        O₂ <?= $rd['oxygen_saturation']; ?>%
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- ── Row: AI Risk + Workload Chart + Recent Registrations ─── -->
    <div class="row g-4 mt-0">

        <!-- AI Risk Summary -->
        <div class="col-lg-4">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5><i class="fas fa-brain me-2"></i>AI Risk Summary</h5>
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
                    <!-- 7-day alert sparkline -->
                    <div style="margin-top:14px;">
                        <div style="font-size:.75rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">
                            <i class="fas fa-chart-line me-1"></i>Alerts — Last 7 Days
                        </div>
                        <div style="position:relative; height:70px;"><canvas id="sparkChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Caregiver Workload Chart -->
        <div class="col-lg-4">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5><i class="fas fa-chart-bar me-2"></i>Caregiver Workload</h5>
                    <a href="assign_caregiver.php">Manage <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
                <div class="section-body">
                    <?php if (empty($workload)): ?>
                        <div class="no-alerts">
                            <i class="fas fa-user-nurse"></i>
                            <p class="mb-0" style="font-size:.87rem;">No caregivers found.</p>
                        </div>
                    <?php else: ?>
                        <div class="chart-wrap"><canvas id="workloadChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Registrations -->
        <div class="col-lg-4">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5><i class="fas fa-user-plus me-2"></i>Recent Registrations</h5>
                    <a href="manage_users.php">View all <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
                <div class="section-body">
                    <?php if (empty($recentUsers)): ?>
                        <div class="no-alerts">
                            <i class="fas fa-users"></i>
                            <p class="mb-0" style="font-size:.87rem;">No users registered yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentUsers as $u):
                            $initials = strtoupper(substr($u['full_name'], 0, 1));
                            if (strpos($u['full_name'], ' ') !== false) {
                                $initials .= strtoupper(substr(strrchr($u['full_name'], ' '), 1, 1));
                            }
                        ?>
                        <div class="activity-item">
                            <div class="act-avatar role-<?= $u['role']; ?>"><?= $initials; ?></div>
                            <div style="flex:1; min-width:0;">
                                <div class="act-name"><?= htmlspecialchars($u['full_name']); ?></div>
                                <div class="act-meta">
                                    <span class="role-badge rb-<?= $u['role']; ?>"><?= $u['role']; ?></span>
                                </div>
                            </div>
                            <?php if ($u['status'] === 'active'): ?>
                                <span style="width:8px;height:8px;border-radius:50%;background:#22c55e;flex-shrink:0;"></span>
                            <?php else: ?>
                                <span style="width:8px;height:8px;border-radius:50%;background:#94a3b8;flex-shrink:0;"></span>
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
// ── Workload bar chart ────────────────────────────────────────────────────
<?php if (!empty($workload)): ?>
new Chart(document.getElementById('workloadChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($n) => strlen($n) > 12 ? substr($n, 0, 12) . '…' : $n, $wl_names)); ?>,
        datasets: [{
            data: <?= json_encode($wl_counts); ?>,
            backgroundColor: <?= json_encode($wl_counts); ?>.map(c =>
                c === 0 ? 'rgba(200,200,200,0.5)' : 'rgba(135,169,107,0.8)'
            ),
            borderColor: 'rgba(74,118,110,0.9)',
            borderWidth: 1, borderRadius: 6,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(255,255,255,.97)',
                titleColor: '#4A766E', bodyColor: '#475569',
                borderColor: '#B8E0D2', borderWidth: 1,
                padding: 10, cornerRadius: 10,
                callbacks: { label: ctx => `${ctx.parsed.y} resident${ctx.parsed.y !== 1 ? 's' : ''}` }
            }
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#64748b' } },
            y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1, color: '#94a3b8' }, min: 0 }
        }
    }
});
<?php endif; ?>

// ── Alerts sparkline ──────────────────────────────────────────────────────
new Chart(document.getElementById('sparkChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($trendDays); ?>,
        datasets: [{
            data: <?= json_encode($trendCnts); ?>,
            borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,0.1)',
            tension: .4, fill: true, pointRadius: 3,
            pointBackgroundColor: '#ef4444',
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: {
            backgroundColor: 'rgba(255,255,255,.97)',
            titleColor: '#4A766E', bodyColor: '#475569',
            borderColor: '#fecaca', borderWidth: 1, padding: 8, cornerRadius: 8,
        }},
        scales: {
            x: { display: false },
            y: { display: false, min: 0 }
        }
    }
});

// ── Animate KPI numbers counting up ──────────────────────────────────────
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