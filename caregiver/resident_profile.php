<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: ../login.php");
    exit();
}

include '../db_connection.php';

$caregiver_user_id = $_SESSION['user_id'];

$resident_user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$resident_user_id) { header("Location: caregiver_dashboard.php"); exit(); }

$auth_stmt = $conn->prepare("SELECT ca.id FROM caregiver_assignments ca WHERE ca.caregiver_id = ? AND ca.resident_id = ? LIMIT 1");
$auth_stmt->bind_param("ii", $caregiver_user_id, $resident_user_id);
$auth_stmt->execute();
$auth_result = $auth_stmt->get_result();
if ($auth_result->num_rows === 0) { header("Location: caregiver_dashboard.php?error=unauthorized"); exit(); }
$auth_stmt->close();

$user_stmt = $conn->prepare("SELECT full_name, email, status FROM users WHERE user_id = ? AND role = 'resident'");
$user_stmt->bind_param("i", $resident_user_id);
$user_stmt->execute();
$user_data = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();
if (!$user_data) { header("Location: caregiver_dashboard.php"); exit(); }

$profile_stmt = $conn->prepare("SELECT * FROM residents WHERE user_id = ?");
$profile_stmt->bind_param("i", $resident_user_id);
$profile_stmt->execute();
$profile_data = $profile_stmt->get_result()->fetch_assoc();
$profile_stmt->close();

$age = null;
if (!empty($profile_data['dob'])) {
    $age = (new DateTime())->diff(new DateTime($profile_data['dob']))->y;
}

$logs_stmt = $conn->prepare("SELECT * FROM health_logs WHERE resident_id = ? ORDER BY logged_at DESC LIMIT 10");
$logs_stmt->bind_param("i", $resident_user_id);
$logs_stmt->execute();
$health_logs = $logs_stmt->get_result();
$logs_stmt->close();

$alerts_stmt = $conn->prepare("SELECT * FROM alerts WHERE resident_id = ? AND resolved = 0 ORDER BY created_at DESC LIMIT 5");
$alerts_stmt->bind_param("i", $resident_user_id);
$alerts_stmt->execute();
$alerts = $alerts_stmt->get_result();
$alerts_stmt->close();

$today = date('Y-m-d');
$appts_stmt = $conn->prepare("SELECT * FROM appointments WHERE resident_id = ? AND appointment_date >= ? AND status = 'scheduled' ORDER BY appointment_date ASC, appointment_time ASC LIMIT 5");
$appts_stmt->bind_param("is", $resident_user_id, $today);
$appts_stmt->execute();
$appointments = $appts_stmt->get_result();
$appts_stmt->close();

$cg_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$cg_stmt->bind_param("i", $caregiver_user_id);
$cg_stmt->execute();
$caregiver = $cg_stmt->get_result()->fetch_assoc();
$cg_stmt->close();

$unread_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND is_read = 0");
$unread_stmt->bind_param("i", $caregiver_user_id);
$unread_stmt->execute();
$unread_messages = $unread_stmt->get_result()->fetch_assoc()['cnt'];
$unread_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($user_data['full_name']) ?> — Resident Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════
           SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM (updated)
           Resident Profile · Professional scale
        ═══════════════════════════════════════════════════════════ */
        :root {
            --s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;
            --s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;
            --s600:#4A6E30;--s700:#365220;--s800:#243816;
            --w50:#FDFAF5;--w100:#F7F1E5;
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
        .back-btn{background:linear-gradient(135deg,var(--s400),var(--s600));border:none;border-radius:var(--radius-md);color:white;padding:9px 18px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;text-decoration:none;}
        .back-btn:hover{opacity:.9;transform:translateY(-1px);color:white;}

        /* ── Profile hero ── */
        .profile-hero{background:linear-gradient(135deg,var(--s600),var(--s800));border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;gap:24px;margin-bottom:22px;box-shadow:var(--shadow-card);flex-wrap:wrap;position:relative;overflow:hidden;}
        .profile-hero::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 80% 80% at 110% 20%,rgba(157,192,126,.2) 0%,transparent 55%);pointer-events:none;}
        .hero-avatar{width:84px;height:84px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:2rem;flex-shrink:0;border:3px solid rgba(255,255,255,.3);position:relative;}
        .hero-info{flex:1;min-width:200px;position:relative;}
        .hero-name{font-family:'Outfit',sans-serif;font-size:19px;font-weight:800;color:white;margin:0 0 4px;}
        .hero-email{color:rgba(255,255,255,.7);font-size:13px;margin:0 0 10px;display:flex;align-items:center;gap:6px;}
        .hero-badges{display:flex;flex-wrap:wrap;gap:7px;}
        .hero-badge{background:rgba(255,255,255,.15);color:white;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;}
        .hero-badge.active-badge{background:rgba(157,192,126,.3);}

        /* ── Quick actions ── */
        .action-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:22px;}
        .act-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:var(--radius-md);font-weight:700;font-size:13px;text-decoration:none;transition:all .2s;border:none;cursor:pointer;font-family:'Outfit',sans-serif;}
        .act-green {background:linear-gradient(135deg,var(--s400),var(--s600));color:white;}
        .act-purple{background:linear-gradient(135deg,#9B8FD4,#6B5FA6);color:white;}
        .act-amber {background:linear-gradient(135deg,#D4A853,#7A5010);color:white;}
        .act-btn:hover{transform:translateY(-2px);box-shadow:var(--shadow-card);}

        /* ── Info card ── */
        .info-card{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);overflow:hidden;margin-bottom:18px;}
        .card-head{background:linear-gradient(135deg,var(--s600),var(--s800));padding:14px 20px;display:flex;align-items:center;gap:9px;position:relative;overflow:hidden;}
        .card-head::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
        .card-head h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;position:relative;}
        .card-head i{color:rgba(255,255,255,.8);position:relative;}
        .card-body-p{padding:20px 22px;}

        /* ── Info grid ── */
        .info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;}
        .info-label{font-size:11px;font-weight:700;color:var(--st500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
        .info-value{font-size:14px;color:var(--s800);font-weight:600;}
        .info-value.muted{color:var(--st300);font-weight:400;font-style:italic;}

        /* ── Conditions box ── */
        .conditions-box{background:var(--s50);border-radius:var(--radius-md);padding:14px 16px;border-left:3px solid var(--s400);font-size:13px;color:var(--st700);line-height:1.6;}

        /* ── Emergency strip ── */
        .emergency-strip{background:var(--red-bg);border:2px solid #EEC0C0;border-radius:var(--radius-md);padding:16px 20px;display:flex;align-items:center;gap:14px;}
        .emergency-icon{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#C87A7A,#8B3A3A);display:flex;align-items:center;justify-content:center;color:white;font-size:1.1rem;flex-shrink:0;}
        .emergency-label{font-size:11px;font-weight:700;color:var(--red-text);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
        .emergency-value{font-size:14px;font-weight:700;color:var(--red-text);}

        /* ── Tables ── */
        .table{margin:0;font-size:13px;}
        .table thead{background:var(--s50);}
        .table thead th{border:none;padding:11px 13px;font-weight:700;color:var(--s700);font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
        .table tbody tr{border-bottom:1px solid var(--s50);transition:background .15s;}
        .table tbody tr:hover{background:var(--s50);}
        .table tbody td{padding:11px 13px;vertical-align:middle;border:none;color:var(--st700);font-size:13px;}

        /* ── Vitals chips ── */
        .vitals-chip{display:inline-flex;align-items:center;gap:4px;background:var(--s50);border-radius:6px;padding:3px 8px;font-size:12px;font-weight:600;color:var(--s700);margin:1px;}

        /* ── Badges ── */
        .badge{padding:4px 10px;border-radius:20px;font-weight:700;font-size:11px;}
        .bg-success{background:var(--green-bg)!important;color:var(--green-text)!important;}
        .bg-danger {background:var(--red-bg)!important;  color:var(--red-text)!important;}
        .bg-warning{background:var(--amber-bg)!important;color:var(--amber-text)!important;}
        .bg-info   {background:var(--blue-bg)!important; color:var(--blue-text)!important;}

        /* ── Appointment rows ── */
        .appt-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--s50);}
        .appt-row:last-child{border-bottom:none;}
        .appt-date{background:linear-gradient(135deg,var(--s500),var(--s700));color:white;border-radius:var(--radius-md);padding:8px 12px;text-align:center;flex-shrink:0;min-width:52px;}
        .appt-day  {font-size:1.2rem;font-weight:800;line-height:1;}
        .appt-month{font-size:10px;text-transform:uppercase;opacity:.85;margin-top:1px;}
        .appt-title{font-weight:700;color:var(--s800);font-size:13px;}
        .appt-meta {font-size:11px;color:var(--st300);margin-top:2px;}

        /* ── Empty state ── */
        .empty-st{text-align:center;padding:32px 16px;color:var(--st300);}
        .empty-st i{font-size:2.2rem;display:block;margin-bottom:8px;opacity:.25;}
        .empty-st p{margin:0;font-size:13px;}

        @media(max-width:768px){
            .sidebar{width:100%;height:auto;position:relative;}
            .content{margin-left:0;padding:14px;}
            .profile-hero{padding:20px 16px;gap:14px;}
            .hero-avatar{width:68px;height:68px;font-size:1.6rem;}
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
        <a href="caregiver_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="caregiver_profile.php"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="caregiver_residents.php" class="active"><i class="fa-solid fa-user-group"></i> My Residents</a>
        <a href="log_health.php"><i class="fa-solid fa-heart-pulse"></i> Log Health Data</a>
        <a href="manage_routines.php"><i class="fa-solid fa-calendar-check"></i> Manage Routines</a>
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

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <!-- Topbar -->
    <div class="topbar">
        <div>
            <h4><i class="fas fa-user me-2" style="font-size:18px;color:var(--s500);"></i>Resident Profile</h4>
            <p>Viewing details for <?= htmlspecialchars($user_data['full_name']) ?></p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="caregiver_dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i>Back to Dashboard</a>
            <form action="/SmartCareGuardian/logout.php" method="POST">
                <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt"></i>Logout</button>
            </form>
        </div>
    </div>

    <!-- Profile hero -->
    <div class="profile-hero">
        <div class="hero-avatar"><?= strtoupper(substr($user_data['full_name'],0,1)) ?></div>
        <div class="hero-info">
            <h2 class="hero-name"><?= htmlspecialchars($user_data['full_name']) ?></h2>
            <p class="hero-email"><i class="fas fa-envelope"></i><?= htmlspecialchars($user_data['email']) ?></p>
            <div class="hero-badges">
                <span class="hero-badge <?= $user_data['status'] === 'active' ? 'active-badge' : '' ?>">
                    <i class="fas fa-circle me-1" style="font-size:.5rem;"></i><?= ucfirst($user_data['status']) ?>
                </span>
                <?php if ($age): ?><span class="hero-badge"><i class="fas fa-birthday-cake me-1"></i><?= $age ?> years old</span><?php endif; ?>
                <?php if (!empty($profile_data['blood_type'])): ?><span class="hero-badge"><i class="fas fa-tint me-1"></i><?= htmlspecialchars($profile_data['blood_type']) ?></span><?php endif; ?>
                <?php if (!empty($profile_data['gender'])): ?><span class="hero-badge"><i class="fas fa-venus-mars me-1"></i><?= htmlspecialchars($profile_data['gender']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="action-row">
        <a href="log_health.php?resident_id=<?= $resident_user_id ?>" class="act-btn act-green"><i class="fas fa-heart-pulse"></i>Log Health Data</a>
        <a href="caregiver_messages.php?compose=<?= $resident_user_id ?>" class="act-btn act-purple"><i class="fas fa-comments"></i>Send Message</a>
        <a href="manage_routines.php?resident_id=<?= $resident_user_id ?>" class="act-btn act-amber"><i class="fas fa-calendar-check"></i>Manage Routines</a>
        <a href="caregiver_appointments.php?resident_id=<?= $resident_user_id ?>" class="act-btn act-green"><i class="fas fa-calendar-plus"></i>Add Appointment</a>
    </div>

    <div class="row g-4">

        <!-- Left column -->
        <div class="col-lg-6">

            <!-- Personal Information -->
            <div class="info-card">
                <div class="card-head"><i class="fas fa-user fa-sm"></i><h5>Personal Information</h5></div>
                <div class="card-body-p">
                    <div class="info-grid">
                        <div><div class="info-label">Phone</div><div class="info-value <?= empty($profile_data['phone']) ? 'muted' : '' ?>"><?= !empty($profile_data['phone']) ? htmlspecialchars($profile_data['phone']) : 'Not provided' ?></div></div>
                        <div><div class="info-label">Date of Birth</div><div class="info-value <?= empty($profile_data['dob']) ? 'muted' : '' ?>"><?= !empty($profile_data['dob']) ? date('F j, Y', strtotime($profile_data['dob'])) . ($age ? " ($age yrs)" : '') : 'Not provided' ?></div></div>
                        <div><div class="info-label">Gender</div><div class="info-value <?= empty($profile_data['gender']) ? 'muted' : '' ?>"><?= !empty($profile_data['gender']) ? htmlspecialchars($profile_data['gender']) : 'Not specified' ?></div></div>
                        <div><div class="info-label">Address</div><div class="info-value <?= empty($profile_data['address']) ? 'muted' : '' ?>"><?= !empty($profile_data['address']) ? htmlspecialchars($profile_data['address']) : 'Not provided' ?></div></div>
                    </div>
                </div>
            </div>

            <!-- Medical Information -->
            <div class="info-card">
                <div class="card-head"><i class="fas fa-heartbeat fa-sm"></i><h5>Medical Information</h5></div>
                <div class="card-body-p">
                    <div class="info-grid mb-3">
                        <div><div class="info-label">Blood Type</div><div class="info-value <?= empty($profile_data['blood_type']) ? 'muted' : '' ?>"><?= !empty($profile_data['blood_type']) ? htmlspecialchars($profile_data['blood_type']) : 'Not recorded' ?></div></div>
                        <div><div class="info-label">Primary Physician</div><div class="info-value <?= empty($profile_data['primary_physician']) ? 'muted' : '' ?>"><?= !empty($profile_data['primary_physician']) ? htmlspecialchars($profile_data['primary_physician']) : 'Not assigned' ?></div></div>
                        <div><div class="info-label">Allergies</div><div class="info-value <?= empty($profile_data['allergies']) ? 'muted' : '' ?>"><?= !empty($profile_data['allergies']) ? htmlspecialchars($profile_data['allergies']) : 'No known allergies' ?></div></div>
                        <div><div class="info-label">Dietary Restrictions</div><div class="info-value <?= empty($profile_data['dietary_restrictions']) ? 'muted' : '' ?>"><?= !empty($profile_data['dietary_restrictions']) ? htmlspecialchars($profile_data['dietary_restrictions']) : 'No restrictions' ?></div></div>
                    </div>
                    <div class="info-label mb-2">Medical Conditions</div>
                    <?php if (!empty($profile_data['medical_conditions'])): ?>
                        <div class="conditions-box"><?= nl2br(htmlspecialchars($profile_data['medical_conditions'])) ?></div>
                    <?php else: ?>
                        <div class="conditions-box muted" style="color:var(--st300);font-style:italic;">No medical conditions recorded.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Emergency Contact -->
            <div class="info-card">
                <div class="card-head"><i class="fas fa-phone-alt fa-sm"></i><h5>Emergency Contact</h5></div>
                <div class="card-body-p">
                    <?php if (!empty($profile_data['emergency_contact'])): ?>
                        <div class="emergency-strip">
                            <div class="emergency-icon"><i class="fas fa-phone"></i></div>
                            <div>
                                <div class="emergency-label">Emergency Contact</div>
                                <div class="emergency-value"><?= htmlspecialchars($profile_data['emergency_contact']) ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-st" style="padding:20px 0;"><i class="fas fa-phone-slash"></i><p>No emergency contact set by resident.</p></div>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /left col -->

        <!-- Right column -->
        <div class="col-lg-6">

            <!-- Active Alerts -->
            <div class="info-card">
                <div class="card-head"><i class="fas fa-bell fa-sm"></i><h5>Active Health Alerts</h5></div>
                <?php if ($alerts->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead><tr><th>Type</th><th>Message</th><th>When</th></tr></thead>
                            <tbody>
                                <?php while ($a = $alerts->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="badge bg-danger"><?= htmlspecialchars($a['alert_type']) ?></span></td>
                                    <td><?= htmlspecialchars($a['alert_message']) ?></td>
                                    <td><small style="color:var(--st300);"><?= date('M j, g:i A', strtotime($a['created_at'])) ?></small></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="card-body-p">
                        <div class="empty-st">
                            <i class="fas fa-check-circle" style="color:var(--s400);opacity:.6;"></i>
                            <p style="color:var(--s600);font-weight:700;">No active alerts. All looks good!</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upcoming Appointments -->
            <div class="info-card">
                <div class="card-head"><i class="fas fa-calendar-days fa-sm"></i><h5>Upcoming Appointments</h5></div>
                <div class="card-body-p">
                    <?php if ($appointments->num_rows > 0): ?>
                        <?php while ($ap = $appointments->fetch_assoc()): ?>
                        <div class="appt-row">
                            <div class="appt-date">
                                <div class="appt-day"><?= date('d', strtotime($ap['appointment_date'])) ?></div>
                                <div class="appt-month"><?= date('M', strtotime($ap['appointment_date'])) ?></div>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div class="appt-title"><?= htmlspecialchars($ap['title']) ?></div>
                                <div class="appt-meta">
                                    <i class="fas fa-clock me-1"></i><?= date('g:i A', strtotime($ap['appointment_time'])) ?>
                                    <?php if ($ap['location']): ?>&nbsp;·&nbsp;<i class="fas fa-location-dot me-1"></i><?= htmlspecialchars($ap['location']) ?><?php endif; ?>
                                </div>
                            </div>
                            <span class="badge bg-info"><?= ucfirst($ap['status']) ?></span>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-st"><i class="fas fa-calendar-xmark"></i><p>No upcoming appointments.</p></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Health Logs -->
            <div class="info-card">
                <div class="card-head"><i class="fas fa-heart-pulse fa-sm"></i><h5>Recent Health Logs</h5></div>
                <?php if ($health_logs->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead><tr><th>Date</th><th>BP</th><th>Sugar</th><th>Pulse</th><th>Weight</th><th>O₂</th></tr></thead>
                            <tbody>
                                <?php while ($log = $health_logs->fetch_assoc()): ?>
                                <tr>
                                    <td><small style="color:var(--st300);"><?= date('M j, g:i A', strtotime($log['logged_at'])) ?></small></td>
                                    <td><?php if ($log['blood_pressure_systolic']): ?><span class="vitals-chip"><?= $log['blood_pressure_systolic'] ?>/<?= $log['blood_pressure_diastolic'] ?></span><?php else: ?>—<?php endif; ?></td>
                                    <td><?php if ($log['blood_sugar']): ?><span class="vitals-chip"><?= $log['blood_sugar'] ?></span><?php else: ?>—<?php endif; ?></td>
                                    <td><?php if ($log['pulse']): ?><span class="vitals-chip"><?= $log['pulse'] ?></span><?php else: ?>—<?php endif; ?></td>
                                    <td><?php if ($log['weight']): ?><span class="vitals-chip"><?= $log['weight'] ?>kg</span><?php else: ?>—<?php endif; ?></td>
                                    <td><?php if ($log['oxygen_saturation']): ?><span class="vitals-chip"><?= $log['oxygen_saturation'] ?>%</span><?php else: ?>—<?php endif; ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="card-body-p">
                        <div class="empty-st"><i class="fas fa-stethoscope"></i><p>No health data logged yet.</p></div>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /right col -->
    </div>
</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>