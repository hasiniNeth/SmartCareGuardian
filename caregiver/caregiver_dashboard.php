<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: ../login.php");
    exit();
}

date_default_timezone_set('Asia/Colombo');

include '../db_connection.php';

$caregiver_id = $_SESSION['user_id'];

$caregiver_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$caregiver_stmt->bind_param("i", $caregiver_id);
$caregiver_stmt->execute();
$caregiver = $caregiver_stmt->get_result()->fetch_assoc();
$caregiver_stmt->close();

$profile_stmt = $conn->prepare("SELECT phone, address, gender, experience_years, skills FROM caregivers WHERE user_id = ?");
$profile_stmt->bind_param("i", $caregiver_id);
$profile_stmt->execute();
$caregiver_profile = $profile_stmt->get_result()->fetch_assoc();
$profile_stmt->close();

$res_count_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM caregiver_assignments WHERE caregiver_id = ?");
$res_count_stmt->bind_param("i", $caregiver_id);
$res_count_stmt->execute();
$residents_count = $res_count_stmt->get_result()->fetch_assoc()['cnt'];
$res_count_stmt->close();

$today = date('Y-m-d');
$appt_count_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE caregiver_id = ? AND appointment_date = ? AND status = 'scheduled'");
$appt_count_stmt->bind_param("is", $caregiver_id, $today);
$appt_count_stmt->execute();
$appointments_count = $appt_count_stmt->get_result()->fetch_assoc()['cnt'];
$appt_count_stmt->close();

$alerts_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM alerts WHERE resolved = 0");
$alerts_stmt->execute();
$alerts_count = $alerts_stmt->get_result()->fetch_assoc()['cnt'];
$alerts_stmt->close();

$unread_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND is_read = 0");
$unread_stmt->bind_param("i", $caregiver_id);
$unread_stmt->execute();
$unread_messages = $unread_stmt->get_result()->fetch_assoc()['cnt'];
$unread_stmt->close();

$assigned_stmt = $conn->prepare("SELECT u.user_id, u.full_name, u.email, u.status, r.dob, r.medical_conditions, r.blood_type FROM users u JOIN caregiver_assignments ca ON u.user_id = ca.resident_id LEFT JOIN residents r ON u.user_id = r.user_id WHERE ca.caregiver_id = ? AND u.role = 'resident' ORDER BY u.full_name ASC");
$assigned_stmt->bind_param("i", $caregiver_id);
$assigned_stmt->execute();
$assigned_residents = $assigned_stmt->get_result();
$assigned_stmt->close();

$alerts_detail_stmt = $conn->prepare("SELECT a.*, u.full_name AS resident_name FROM alerts a JOIN users u ON a.resident_id = u.user_id WHERE a.resolved = 0 AND a.resident_id IN (SELECT resident_id FROM caregiver_assignments WHERE caregiver_id = ?) ORDER BY a.created_at DESC LIMIT 5");
$alerts_detail_stmt->bind_param("i", $caregiver_id);
$alerts_detail_stmt->execute();
$recent_alerts = $alerts_detail_stmt->get_result();
$alerts_detail_stmt->close();

$day_name = date('l');
$today_routines_stmt = $conn->prepare("SELECT ro.*, u.full_name AS resident_name FROM routines ro JOIN users u ON ro.resident_id = u.user_id WHERE ro.caregiver_id = ? AND ro.status = 'pending' AND (ro.days_of_week IS NULL OR FIND_IN_SET(?, ro.days_of_week)) ORDER BY ro.schedule_time ASC LIMIT 10");
$today_routines_stmt->bind_param("is", $caregiver_id, $day_name);
$today_routines_stmt->execute();
$today_routines = $today_routines_stmt->get_result();
$today_routines_stmt->close();

$upcoming_stmt = $conn->prepare("SELECT ap.*, u.full_name AS resident_name FROM appointments ap JOIN users u ON ap.resident_id = u.user_id WHERE ap.caregiver_id = ? AND ap.appointment_date >= ? AND ap.status = 'scheduled' ORDER BY ap.appointment_date ASC, ap.appointment_time ASC LIMIT 5");
$upcoming_stmt->bind_param("is", $caregiver_id, $today);
$upcoming_stmt->execute();
$upcoming_appointments = $upcoming_stmt->get_result();
$upcoming_stmt->close();

$health_logs_stmt = $conn->prepare("SELECT hl.*, u.full_name AS resident_name FROM health_logs hl JOIN users u ON hl.resident_id = u.user_id WHERE hl.caregiver_id = ? ORDER BY hl.logged_at DESC LIMIT 5");
$health_logs_stmt->bind_param("i", $caregiver_id);
$health_logs_stmt->execute();
$recent_health_logs = $health_logs_stmt->get_result();
$health_logs_stmt->close();

$med_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM medications m JOIN caregiver_assignments ca ON ca.resident_id = m.resident_id WHERE ca.caregiver_id = ? AND m.taken = 0 AND m.medication_date = CURDATE()");
$med_stmt->bind_param("i", $caregiver_id);
$med_stmt->execute();
$pending_meds = $med_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$med_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caregiver Dashboard – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════
           SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
           Caregiver Dashboard · Professional scale
        ═══════════════════════════════════════════════════════════ */
        :root {
            --s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;
            --s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;
            --s600:#4A6E30;--s700:#365220;--s800:#243816;
            --w50:#FDFAF5;--w100:#F7F1E5;--w200:#EDE5D0;
            --st300:#B8B0A4;--st500:#7A7268;--st700:#4A4540;
            --green-bg:#DDEFD8;--green-text:#3A6830;
            --amber-bg:#FAECC8;--amber-text:#7A5010;
            --red-bg:#F5DADA;  --red-text:#6A2020;
            --blue-bg:#DBEEFF; --blue-text:#1A4870;
            --purple-bg:#EDE9FE;--purple-text:#5A3A7A;
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
        .sb-badge,.msg-badge{margin-left:auto;background:#8B3A3A;color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;}
        .msg-badge{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:#8B3A3A;border-radius:50%;min-width:18px;height:18px;font-size:.65rem;display:flex;align-items:center;justify-content:center;padding:0 3px;}
        .sidebar-footer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 8px;position:relative;}
        .sidebar-footer a{margin:0;color:rgba(255,255,255,.5)!important;font-size:13.5px;}
        .sidebar-footer a:hover{color:rgba(255,255,255,.8)!important;}

        /* ── Layout ── */
        .content{margin-left:240px;padding:24px;min-height:100vh;}

        /* ── Topbar ── */
        .topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
        .topbar h4{font-size:22px;font-weight:500;color:var(--s800);margin-bottom:2px;}
        .topbar p{font-size:13px;color:var(--st300);margin:0;}
        .topbar strong{color:var(--s700);}
        .logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;}
        .logout-btn:hover{opacity:.9;transform:translateY(-1px);}

        /* ── Stats grid ── */
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:22px;}
        .stat-card{background:white;border-radius:var(--radius-lg);padding:20px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);transition:all .25s;text-decoration:none;display:block;color:inherit;}
        .stat-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lift);color:inherit;}
        .stat-icon{width:50px;height:50px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;font-size:1.25rem;margin-bottom:12px;}
        .icon-residents   {background:linear-gradient(135deg,var(--s300),var(--s500));color:white;}
        .icon-appointments{background:linear-gradient(135deg,var(--s200),var(--s400));color:white;}
        .icon-alerts      {background:linear-gradient(135deg,#C87A7A,#8B3A3A);color:white;}
        .icon-messages    {background:linear-gradient(135deg,#9B8FD4,#6B5FA6);color:white;}
        .icon-experience  {background:linear-gradient(135deg,#D4A853,#7A5010);color:white;}
        .stat-number{font-size:1.9rem;font-weight:800;color:var(--s800);line-height:1;margin-bottom:4px;}
        .stat-label {color:var(--st500);font-weight:600;font-size:13px;}
        .stat-sub   {font-size:11px;color:var(--st300);margin-top:2px;}

        /* ── Quick actions ── */
        .quick-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:22px;}
        .action-btn{background:white;border:2px solid var(--s100);border-radius:var(--radius-md);padding:18px 14px;text-align:center;text-decoration:none;color:var(--s700);transition:all .2s;font-weight:600;font-size:13px;display:block;position:relative;}
        .action-btn:hover{background:linear-gradient(135deg,var(--s500),var(--s700));color:white;border-color:transparent;transform:translateY(-3px);box-shadow:var(--shadow-lift);}
        .action-btn i{font-size:1.5rem;margin-bottom:8px;display:block;}

        /* ── Section card ── */
        .section-card{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;overflow:hidden;}
        .section-header{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;}
        .section-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
        .section-header h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-size:14px;font-weight:700;position:relative;}
        .section-header .btn-sm-white{background:rgba(255,255,255,.15);color:white;border:none;border-radius:8px;padding:5px 14px;font-size:12px;font-weight:600;font-family:'Outfit',sans-serif;cursor:pointer;text-decoration:none;transition:background .2s;position:relative;}
        .section-header .btn-sm-white:hover{background:rgba(255,255,255,.25);color:white;}
        .section-header span{font-size:12px;opacity:.8;position:relative;}
        .section-body{padding:20px 22px;}

        /* ── Tables ── */
        .table{margin:0;font-size:13px;}
        .table thead{background:var(--s50);}
        .table thead th{border:none;padding:11px 13px;font-weight:700;color:var(--s700);font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
        .table tbody tr{border-bottom:1px solid var(--s50);transition:background .15s;}
        .table tbody tr:hover{background:var(--s50);}
        .table tbody td{padding:11px 13px;vertical-align:middle;border:none;color:var(--st700);font-size:13px;}

        /* ── Badges ── */
        .badge{padding:4px 10px;border-radius:20px;font-weight:700;font-size:11px;}
        .bg-success{background:var(--green-bg)!important;color:var(--green-text)!important;}
        .bg-warning{background:var(--amber-bg)!important;color:var(--amber-text)!important;}
        .bg-danger {background:var(--red-bg)!important;  color:var(--red-text)!important;}
        .bg-info   {background:var(--blue-bg)!important; color:var(--blue-text)!important;}

        /* ── Resident cards ── */
        .resident-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;}
        .resident-card{background:var(--s50);border-radius:var(--radius-md);padding:18px;box-shadow:var(--shadow-soft);transition:all .2s;border-left:4px solid var(--s400);}
        .resident-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-card);background:white;}
        .resident-avatar{width:48px;height:48px;background:linear-gradient(135deg,var(--s300),var(--s500));border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:1.2rem;margin-bottom:12px;}
        .resident-name{font-size:14px;font-weight:700;color:var(--s800);margin-bottom:2px;}
        .resident-meta{font-size:12px;color:var(--st300);margin-top:2px;}

        /* ── Profile strip ── */
        .profile-info{background:var(--s50);border-radius:var(--radius-md);padding:16px 18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;}
        .profile-item{display:flex;align-items:center;gap:9px;}
        .profile-item i{color:var(--s400);font-size:14px;width:18px;}
        .profile-label{font-weight:700;color:var(--s800);font-size:12px;}
        .profile-value{color:var(--st500);font-size:12px;}

        /* ── Routine items ── */
        .routine-item{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--s50);}
        .routine-item:last-child{border-bottom:none;}
        .routine-time{background:var(--s50);border-radius:var(--radius-sm);padding:7px 10px;font-weight:700;font-size:12px;color:var(--s700);white-space:nowrap;flex-shrink:0;}
        .routine-icon{width:34px;height:34px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;color:white;}
        .ri-meal    {background:linear-gradient(135deg,#D4A853,#7A5010);}
        .ri-exercise{background:linear-gradient(135deg,var(--s300),var(--s500));}
        .ri-med     {background:linear-gradient(135deg,#C87A7A,#8B3A3A);}
        .ri-general {background:linear-gradient(135deg,var(--s200),var(--s400));}
        .routine-name{font-weight:700;font-size:13px;color:var(--s800);}
        .routine-sub {font-size:11px;color:var(--st300);}

        /* ── Appointment items ── */
        .appt-item{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--s50);}
        .appt-item:last-child{border-bottom:none;}
        .appt-date-box{background:linear-gradient(135deg,var(--s500),var(--s700));border-radius:var(--radius-md);padding:9px 12px;text-align:center;color:white;flex-shrink:0;min-width:54px;}
        .appt-day  {font-size:1.2rem;font-weight:800;line-height:1;}
        .appt-month{font-size:10px;text-transform:uppercase;opacity:.85;margin-top:1px;}
        .appt-title{font-weight:700;color:var(--s800);font-size:13px;}
        .appt-meta {font-size:11px;color:var(--st300);margin-top:2px;}

        /* ── Vitals chips ── */
        .vitals-chip{display:inline-flex;align-items:center;gap:4px;background:var(--s50);border-radius:6px;padding:3px 8px;font-size:12px;font-weight:600;color:var(--s700);margin:2px;}

        /* ── Button inline ── */
        .btn-sage{background:linear-gradient(135deg,var(--s400),var(--s600));color:white;border:none;border-radius:var(--radius-md);padding:9px 18px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;text-decoration:none;}
        .btn-sage:hover{opacity:.9;transform:translateY(-1px);color:white;}
        .btn-outline-sage{background:transparent;color:var(--s600);border:1.5px solid var(--s300);border-radius:var(--radius-sm);padding:5px 12px;font-size:12px;font-weight:600;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;text-decoration:none;}
        .btn-outline-sage:hover{background:var(--s50);color:var(--s700);}

        /* ── Empty state ── */
        .empty-state{text-align:center;padding:36px 20px;color:var(--st300);}
        .empty-state i{font-size:2.5rem;display:block;margin-bottom:10px;opacity:.3;}
        .empty-state p{margin:0;font-size:13px;}

        @media(max-width:768px){
            .sidebar{width:100%;height:auto;position:relative;}
            .content{margin-left:0;padding:14px;}
            .stats-grid{grid-template-columns:1fr 1fr;}
            .quick-actions{grid-template-columns:repeat(2,1fr);}
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
        <a href="caregiver_dashboard.php" class="active"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="caregiver_profile.php"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="caregiver_residents.php"><i class="fa-solid fa-user-group"></i> My Residents</a>
        <a href="log_health.php"><i class="fa-solid fa-heart-pulse"></i> Log Health Data</a>
        <a href="manage_routines.php"><i class="fa-solid fa-calendar-check"></i> Manage Routines</a>
        <a href="manage_medications.php">
            <i class="fa-solid fa-pills"></i> Medications
            <?php if ($pending_meds > 0): ?><span class="sb-badge"><?= $pending_meds ?></span><?php endif; ?>
        </a>
        <a href="caregiver_appointments.php"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="caregiver_messages.php">
            <i class="fa-solid fa-comments"></i> Messages
            <?php if ($unread_messages > 0): ?><span class="msg-badge"><?= $unread_messages ?></span><?php endif; ?>
        </a>
        <a href="caregiver_ai_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="ai_suggestions.php"><i class="fa-solid fa-wand-magic-sparkles"></i> Routine Suggestions</a>
        <a href="caregiver_reports.php"><i class="fa-solid fa-chart-line"></i> Health Reports</a>
    </div>
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<!-- ══ MAIN CONTENT ════════════════════════════════════════════════ -->
<div class="content">

    <!-- Topbar -->
    <div class="topbar">
        <div>
            <h4><i class="fas fa-gauge-high me-2" style="font-size:18px;color:var(--s500);"></i>Caregiver Dashboard</h4>
            <p>Welcome back, <strong><?= htmlspecialchars($caregiver['full_name']) ?></strong> &nbsp;·&nbsp; <i class="fas fa-calendar-day me-1"></i><?= date('l, F j, Y') ?></p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt"></i>Logout</button>
        </form>
    </div>

    <!-- Profile strip -->
    <?php if ($caregiver_profile): ?>
    <div class="section-card mb-4">
        <div class="section-header">
            <h5><i class="fas fa-user me-2"></i>My Profile</h5>
            <a href="caregiver_profile.php" class="btn-sm-white"><i class="fas fa-edit me-1"></i>Edit</a>
        </div>
        <div class="section-body py-3">
            <div class="profile-info">
                <div class="profile-item"><i class="fas fa-phone"></i><div><div class="profile-label">Phone</div><div class="profile-value"><?= htmlspecialchars($caregiver_profile['phone'] ?? 'Not set') ?></div></div></div>
                <div class="profile-item"><i class="fas fa-award"></i><div><div class="profile-label">Experience</div><div class="profile-value"><?= htmlspecialchars($caregiver_profile['experience_years'] ?? '0') ?> years</div></div></div>
                <div class="profile-item"><i class="fas fa-star"></i><div><div class="profile-label">Skills</div><div class="profile-value"><?= htmlspecialchars($caregiver_profile['skills'] ?? 'Not specified') ?></div></div></div>
                <div class="profile-item"><i class="fas fa-venus-mars"></i><div><div class="profile-label">Gender</div><div class="profile-value"><?= htmlspecialchars($caregiver_profile['gender'] ?? 'Not set') ?></div></div></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <a href="caregiver_residents.php" class="stat-card">
            <div class="stat-icon icon-residents"><i class="fas fa-user-group"></i></div>
            <div class="stat-number"><?= $residents_count ?></div>
            <div class="stat-label">Assigned Residents</div>
        </a>
        <a href="caregiver_appointments.php" class="stat-card">
            <div class="stat-icon icon-appointments"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-number"><?= $appointments_count ?></div>
            <div class="stat-label">Today's Appointments</div>
            <div class="stat-sub"><?= date('M j, Y') ?></div>
        </a>
        <a href="caregiver_ai_alerts.php" class="stat-card">
            <div class="stat-icon icon-alerts"><i class="fas fa-bell"></i></div>
            <div class="stat-number"><?= $alerts_count ?></div>
            <div class="stat-label">Pending Alerts</div>
        </a>
        <a href="caregiver_messages.php" class="stat-card">
            <div class="stat-icon icon-messages"><i class="fas fa-comments"></i></div>
            <div class="stat-number"><?= $unread_messages ?></div>
            <div class="stat-label">Unread Messages</div>
            <?php if ($unread_messages > 0): ?>
                <div class="stat-sub" style="color:var(--red-text);font-weight:700;"><i class="fas fa-circle me-1" style="font-size:.5rem;"></i>New messages</div>
            <?php endif; ?>
        </a>
        <div class="stat-card">
            <div class="stat-icon icon-experience"><i class="fas fa-award"></i></div>
            <div class="stat-number"><?= htmlspecialchars($caregiver_profile['experience_years'] ?? '0') ?></div>
            <div class="stat-label">Years Experience</div>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="quick-actions">
        <a href="log_health.php"           class="action-btn"><i class="fas fa-heart-pulse"></i>Log Health Data</a>
        <a href="manage_routines.php"      class="action-btn"><i class="fas fa-calendar-check"></i>Manage Routines</a>
        <a href="caregiver_residents.php"  class="action-btn"><i class="fas fa-user-group"></i>View Residents</a>
        <a href="caregiver_appointments.php" class="action-btn"><i class="fas fa-calendar-days"></i>Appointments</a>
        <a href="caregiver_messages.php"   class="action-btn" style="position:relative;">
            <i class="fas fa-comments"></i>Messages
            <?php if ($unread_messages > 0): ?>
                <span style="position:absolute;top:10px;right:10px;background:#8B3A3A;color:white;border-radius:50%;width:18px;height:18px;font-size:.65rem;display:flex;align-items:center;justify-content:center;font-weight:700;"><?= $unread_messages ?></span>
            <?php endif; ?>
        </a>
        <a href="ai_suggestions.php"       class="action-btn"><i class="fas fa-wand-magic-sparkles"></i>AI Suggestions</a>
    </div>

    <!-- Row 1: Residents + Alerts -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="section-card h-100 mb-0">
                <div class="section-header">
                    <h5><i class="fas fa-user-group me-2"></i>My Assigned Residents</h5>
                    <a href="caregiver_residents.php" class="btn-sm-white">View All</a>
                </div>
                <div class="section-body">
                    <?php if ($assigned_residents->num_rows > 0): ?>
                        <div class="resident-grid">
                            <?php while ($res = $assigned_residents->fetch_assoc()): ?>
                            <div class="resident-card">
                                <div class="resident-avatar"><?= strtoupper(substr($res['full_name'],0,1)) ?></div>
                                <div class="resident-name"><?= htmlspecialchars($res['full_name']) ?></div>
                                <div class="resident-meta"><?= htmlspecialchars($res['email']) ?></div>
                                <?php if ($res['blood_type']): ?>
                                    <div class="resident-meta mt-1"><i class="fas fa-tint me-1" style="color:var(--red-text);"></i><?= htmlspecialchars($res['blood_type']) ?></div>
                                <?php endif; ?>
                                <?php if ($res['medical_conditions']): ?>
                                    <div class="resident-meta mt-1" title="<?= htmlspecialchars($res['medical_conditions']) ?>">
                                        <i class="fas fa-notes-medical me-1"></i><?= mb_strimwidth(htmlspecialchars($res['medical_conditions']),0,40,'…') ?>
                                    </div>
                                <?php endif; ?>
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <span class="badge bg-<?= $res['status'] === 'active' ? 'success' : 'warning' ?>"><?= ucfirst($res['status']) ?></span>
                                    <a href="resident_profile.php?id=<?= $res['user_id'] ?>" class="btn-outline-sage"><i class="fas fa-eye me-1"></i>View Profile</a>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-users"></i><p>No residents assigned yet.</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="section-card h-100 mb-0">
                <div class="section-header">
                    <h5><i class="fas fa-bell me-2"></i>Recent Health Alerts</h5>
                    <a href="caregiver_ai_alerts.php" class="btn-sm-white">View All</a>
                </div>
                <div class="section-body">
                    <?php if ($recent_alerts->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th>Resident</th><th>Type</th><th>Message</th><th>When</th></tr></thead>
                                <tbody>
                                    <?php while ($alert = $recent_alerts->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($alert['resident_name']) ?></strong></td>
                                        <td><span class="badge bg-danger"><?= htmlspecialchars($alert['alert_type']) ?></span></td>
                                        <td style="font-size:12px;"><?= htmlspecialchars($alert['alert_message']) ?></td>
                                        <td><small style="color:var(--st300);"><?= date('M j, g:i A', strtotime($alert['created_at'])) ?></small></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle" style="color:var(--s400);opacity:.6;"></i>
                            <p style="color:var(--s600);font-weight:700;">No pending alerts. Great job!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 2: Routines + Appointments -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="section-card h-100 mb-0">
                <div class="section-header">
                    <h5><i class="fas fa-calendar-day me-2"></i>Today's Schedule</h5>
                    <span><?= date('l, M j') ?></span>
                </div>
                <div class="section-body">
                    <?php if ($today_routines->num_rows > 0): ?>
                        <?php while ($r = $today_routines->fetch_assoc()):
                            $type = strtolower($r['routine_type'] ?? 'general');
                            $icon_class = match(true) {
                                str_contains($type,'meal')||str_contains($type,'food')||str_contains($type,'diet') => 'ri-meal fas fa-utensils',
                                str_contains($type,'exercise')||str_contains($type,'physio')                       => 'ri-exercise fas fa-dumbbell',
                                str_contains($type,'med')||str_contains($type,'drug')                              => 'ri-med fas fa-pills',
                                default                                                                             => 'ri-general fas fa-clipboard-list',
                            };
                            [$icon_bg, $fa_icon] = explode(' ', $icon_class, 2);
                        ?>
                        <div class="routine-item">
                            <div class="routine-time"><?= date('g:i A', strtotime($r['schedule_time'])) ?></div>
                            <div class="routine-icon <?= $icon_bg ?>"><i class="<?= $fa_icon ?>"></i></div>
                            <div style="flex:1;min-width:0;">
                                <div class="routine-name"><?= htmlspecialchars($r['routine_type']) ?></div>
                                <div class="routine-sub"><i class="fas fa-user me-1"></i><?= htmlspecialchars($r['resident_name']) ?><?php if ($r['description']): ?> · <?= mb_strimwidth(htmlspecialchars($r['description']),0,35,'…') ?><?php endif; ?></div>
                            </div>
                            <span class="badge bg-warning ms-auto"><?= ucfirst($r['status']) ?></span>
                        </div>
                        <?php endwhile; ?>
                        <a href="manage_routines.php" class="btn-sage w-100 mt-3 justify-content-center"><i class="fas fa-list"></i>Manage All Routines</a>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-calendar-plus"></i><p>No routines scheduled for today.</p></div>
                        <a href="manage_routines.php" class="btn-sage w-100 mt-2 justify-content-center"><i class="fas fa-plus"></i>Create Routine</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="section-card h-100 mb-0">
                <div class="section-header">
                    <h5><i class="fas fa-calendar-days me-2"></i>Upcoming Appointments</h5>
                    <a href="caregiver_appointments.php" class="btn-sm-white">View All</a>
                </div>
                <div class="section-body">
                    <?php if ($upcoming_appointments->num_rows > 0): ?>
                        <?php while ($appt = $upcoming_appointments->fetch_assoc()): ?>
                        <div class="appt-item">
                            <div class="appt-date-box">
                                <div class="appt-day"><?= date('d', strtotime($appt['appointment_date'])) ?></div>
                                <div class="appt-month"><?= date('M', strtotime($appt['appointment_date'])) ?></div>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div class="appt-title"><?= htmlspecialchars($appt['title']) ?></div>
                                <div class="appt-meta">
                                    <i class="fas fa-user me-1"></i><?= htmlspecialchars($appt['resident_name']) ?>
                                    &nbsp;·&nbsp;<i class="fas fa-clock me-1"></i><?= date('g:i A', strtotime($appt['appointment_time'])) ?>
                                    <?php if ($appt['location']): ?>&nbsp;·&nbsp;<i class="fas fa-location-dot me-1"></i><?= htmlspecialchars($appt['location']) ?><?php endif; ?>
                                </div>
                            </div>
                            <span class="badge bg-info"><?= ucfirst($appt['status']) ?></span>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-calendar-xmark"></i><p>No upcoming appointments.</p></div>
                        <a href="caregiver_appointments.php" class="btn-sage w-100 mt-2 justify-content-center"><i class="fas fa-plus"></i>Schedule Appointment</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 3: Health logs -->
    <div class="section-card">
        <div class="section-header">
            <h5><i class="fas fa-heart-pulse me-2"></i>Recent Health Logs</h5>
            <a href="log_health.php" class="btn-sm-white"><i class="fas fa-plus me-1"></i>Log New</a>
        </div>
        <div class="section-body p-0">
            <?php if ($recent_health_logs->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Resident</th><th>Blood Pressure</th><th>Blood Sugar</th><th>Pulse</th><th>Weight</th><th>Temp</th><th>O₂ Sat</th><th>Logged At</th></tr></thead>
                    <tbody>
                        <?php while ($log = $recent_health_logs->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($log['resident_name']) ?></strong></td>
                            <td><?php if ($log['blood_pressure_systolic'] && $log['blood_pressure_diastolic']): ?><span class="vitals-chip"><i class="fas fa-heart-pulse"></i><?= $log['blood_pressure_systolic'] ?>/<?= $log['blood_pressure_diastolic'] ?></span><?php else: ?><span style="color:var(--st300);">—</span><?php endif; ?></td>
                            <td><?php if ($log['blood_sugar']): ?><span class="vitals-chip"><i class="fas fa-droplet"></i><?= $log['blood_sugar'] ?></span><?php else: ?><span style="color:var(--st300);">—</span><?php endif; ?></td>
                            <td><?php if ($log['pulse']): ?><span class="vitals-chip"><i class="fas fa-wave-square"></i><?= $log['pulse'] ?></span><?php else: ?><span style="color:var(--st300);">—</span><?php endif; ?></td>
                            <td><?php if ($log['weight']): ?><span class="vitals-chip"><?= $log['weight'] ?> kg</span><?php else: ?><span style="color:var(--st300);">—</span><?php endif; ?></td>
                            <td><?php if ($log['temperature']): ?><span class="vitals-chip"><?= $log['temperature'] ?>°C</span><?php else: ?><span style="color:var(--st300);">—</span><?php endif; ?></td>
                            <td><?php if ($log['oxygen_saturation']): ?><span class="vitals-chip"><?= $log['oxygen_saturation'] ?>%</span><?php else: ?><span style="color:var(--st300);">—</span><?php endif; ?></td>
                            <td><small style="color:var(--st300);"><?= date('M j, g:i A', strtotime($log['logged_at'])) ?></small></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-stethoscope"></i>
                    <p>No health logs recorded yet.</p>
                    <a href="log_health.php" class="btn-sage mt-3"><i class="fas fa-plus"></i>Log Health Data</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>