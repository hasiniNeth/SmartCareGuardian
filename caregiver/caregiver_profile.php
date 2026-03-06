<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: ../login.php");
    exit();
}

include '../db_connection.php';
date_default_timezone_set('Asia/Colombo');

$caregiver_id = $_SESSION['user_id'];
$message      = '';
$message_type = '';

$user_stmt = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $caregiver_id);
$user_stmt->execute();
$user_data = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$profile_stmt = $conn->prepare("SELECT * FROM caregivers WHERE user_id = ?");
$profile_stmt->bind_param("i", $caregiver_id);
$profile_stmt->execute();
$profile_data = $profile_stmt->get_result()->fetch_assoc();
$profile_stmt->close();

$has_profile = !empty($profile_data['caregiver_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone            = trim($_POST['phone']);
    $address          = trim($_POST['address']);
    $gender           = $_POST['gender'];
    $dob              = $_POST['dob'];
    $experience_years = intval($_POST['experience_years']);
    $skills           = trim($_POST['skills']);

    if (empty($phone) || empty($address) || empty($gender) || empty($dob) || $experience_years === '') {
        $message      = "Please fill in all required fields.";
        $message_type = "error";
    } else {
        if ($has_profile) {
            $stmt = $conn->prepare("UPDATE caregivers SET phone = ?, address = ?, gender = ?, dob = ?, experience_years = ?, skills = ? WHERE user_id = ?");
            $stmt->bind_param("ssssis i", $phone, $address, $gender, $dob, $experience_years, $skills, $caregiver_id);
            $stmt->close();
            $stmt = $conn->prepare("UPDATE caregivers SET phone = ?, address = ?, gender = ?, dob = ?, experience_years = ?, skills = ? WHERE user_id = ?");
            $stmt->bind_param("ssssisi", $phone, $address, $gender, $dob, $experience_years, $skills, $caregiver_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO caregivers (user_id, phone, address, gender, dob, experience_years, skills, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("issssis", $caregiver_id, $phone, $address, $gender, $dob, $experience_years, $skills);
        }
        if ($stmt->execute()) {
            $message      = $has_profile ? "Profile updated successfully!" : "Profile created successfully!";
            $message_type = "success";
            $r = $conn->prepare("SELECT * FROM caregivers WHERE user_id = ?");
            $r->bind_param("i", $caregiver_id);
            $r->execute();
            $profile_data = $r->get_result()->fetch_assoc();
            $r->close();
            $has_profile  = true;
        } else {
            $message      = "Error saving profile. Please try again.";
            $message_type = "error";
        }
        $stmt->close();
    }
}

$age = null;
if (!empty($profile_data['dob'])) {
    $age = (new DateTime())->diff(new DateTime($profile_data['dob']))->y;
}

$completion_fields = ['phone','address','gender','dob','experience_years','skills'];
$filled = 0;
foreach ($completion_fields as $f) { if (!empty($profile_data[$f])) $filled++; }
$completion = $has_profile ? round(($filled / count($completion_fields)) * 100) : 0;

$unread_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND is_read = 0");
$unread_stmt->bind_param("i", $caregiver_id);
$unread_stmt->execute();
$unread_messages = $unread_stmt->get_result()->fetch_assoc()['cnt'];
$unread_stmt->close();

$show_edit = isset($_GET['edit']) || $message_type === 'error' || !$has_profile;

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
    <title>My Profile – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════
           SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
           Caregiver Profile · Professional scale
        ═══════════════════════════════════════════════════════════ */
        :root {
            --s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;
            --s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;
            --s600:#4A6E30;--s700:#365220;--s800:#243816;
            --w50:#FDFAF5;--w100:#F7F1E5;
            --st300:#B8B0A4;--st500:#7A7268;--st700:#4A4540;
            --green-bg:#DDEFD8;--green-text:#3A6830;
            --red-bg:#F5DADA;  --red-text:#6A2020;
            --amber-bg:#FAECC8;--amber-text:#7A5010;
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
        .logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;}
        .logout-btn:hover{opacity:.9;transform:translateY(-1px);}

        /* ── Flash alerts ── */
        .flash-msg{border-radius:var(--radius-md);border:none;padding:13px 18px;margin-bottom:18px;font-weight:600;font-size:14px;display:flex;align-items:center;gap:10px;}
        .flash-success{background:var(--green-bg);color:var(--green-text);}
        .flash-error  {background:var(--red-bg);  color:var(--red-text);}

        /* ── Completion card ── */
        .completion-card{background:white;border-radius:var(--radius-lg);padding:18px 22px;margin-bottom:20px;border:1px solid rgba(196,217,180,.3);box-shadow:var(--shadow-soft);border-left:4px solid var(--s400);}
        .completion-card h5{font-family:'Outfit',sans-serif;font-size:14px;font-weight:700;color:var(--s800);}
        .completion-pct{font-weight:800;color:var(--s700);font-size:16px;}
        .completion-track{height:8px;background:var(--s100);border-radius:20px;overflow:hidden;margin:10px 0 6px;}
        .completion-bar{height:100%;background:linear-gradient(90deg,var(--s400),var(--s600));border-radius:20px;transition:width .6s ease;}

        /* ── Profile container ── */
        .profile-container{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);overflow:hidden;}
        .profile-hero{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:32px 28px;text-align:center;position:relative;overflow:hidden;}
        .profile-hero::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 80% 20%,rgba(157,192,126,.2) 0%,transparent 60%);pointer-events:none;}
        .profile-avatar{width:88px;height:88px;background:rgba(255,255,255,.2);border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-size:2.2rem;font-weight:800;margin:0 auto 14px;border:3px solid rgba(255,255,255,.35);position:relative;}
        .profile-hero h4{font-family:'Outfit',sans-serif;font-size:18px;font-weight:700;color:white;margin-bottom:3px;position:relative;}
        .profile-hero p{font-size:13px;color:rgba(255,255,255,.75);margin:0;position:relative;}
        .profile-hero .meta-row{font-size:12px;color:rgba(255,255,255,.6);margin-top:6px;position:relative;}
        .profile-body{padding:28px 32px;}

        /* ── Info sections (view mode) ── */
        .info-section{background:var(--s50);border-radius:var(--radius-md);padding:18px 20px;margin-bottom:18px;border-left:3px solid var(--s300);}
        .info-section-title{font-family:'Outfit',sans-serif;font-weight:700;color:var(--s800);font-size:13px;margin-bottom:14px;display:flex;align-items:center;gap:8px;text-transform:uppercase;letter-spacing:.05em;}
        .info-section-title i{color:var(--s400);}
        .info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;}
        .info-label{font-size:11px;font-weight:700;color:var(--st500);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;}
        .info-value{font-size:14px;color:var(--s800);font-weight:600;}
        .info-value.muted{color:var(--st300);font-style:italic;font-weight:400;}

        /* Skills chips */
        .skills-wrap{display:flex;flex-wrap:wrap;gap:7px;margin-top:5px;}
        .skill-chip{background:linear-gradient(135deg,var(--s300),var(--s500));color:white;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;}

        /* ── Edit button ── */
        .edit-btn{background:linear-gradient(135deg,var(--s400),var(--s600));border:none;border-radius:var(--radius-md);color:white;padding:10px 22px;font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:7px;}
        .edit-btn:hover{opacity:.9;transform:translateY(-1px);box-shadow:var(--shadow-card);}

        /* ── Section divider in form ── */
        .section-divider{font-family:'Outfit',sans-serif;font-weight:700;color:var(--s800);font-size:13px;margin-bottom:16px;padding-bottom:9px;border-bottom:2px solid var(--s100);display:flex;align-items:center;gap:8px;text-transform:uppercase;letter-spacing:.05em;}
        .section-divider i{color:var(--s400);}

        /* ── Form controls ── */
        .form-label{color:var(--s800);font-weight:700;margin-bottom:5px;font-size:13px;}
        .required::after{content:" *";color:var(--red-text);}
        .form-control,.form-select{border:2px solid var(--s100);border-radius:var(--radius-md);padding:10px 13px;font-size:14px;font-family:'Outfit',sans-serif;color:var(--st700);background:var(--w50);transition:border-color .2s;}
        .form-control:focus,.form-select:focus{border-color:var(--s400);box-shadow:0 0 0 3px rgba(122,166,88,.15);outline:none;}
        .form-control::placeholder{color:var(--st300);}
        .input-wrap{position:relative;}
        .input-wrap .fi{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--s400);z-index:3;pointer-events:none;font-size:13px;}
        .input-wrap .form-control,.input-wrap .form-select{padding-left:38px;}
        .input-wrap textarea.form-control{padding-top:10px;}
        .input-wrap .fi-ta{top:14px;transform:none;}
        .form-text{font-size:12px;color:var(--st300);margin-top:4px;}

        /* ── Buttons ── */
        .btn-save{background:linear-gradient(135deg,var(--s400),var(--s600));border:none;border-radius:var(--radius-md);color:white;padding:12px 24px;font-weight:700;font-size:14px;font-family:'Outfit',sans-serif;width:100%;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;}
        .btn-save::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--s600),var(--s400));opacity:0;transition:opacity .3s;}
        .btn-save:hover::before{opacity:1;}
        .btn-save span{position:relative;z-index:2;}
        .btn-save i{position:relative;z-index:2;}
        .btn-save:hover{transform:translateY(-1px);box-shadow:var(--shadow-card);}
        .btn-cancel{background:transparent;border:2px solid var(--s300);color:var(--s600);border-radius:var(--radius-md);padding:12px 24px;font-weight:700;font-size:14px;font-family:'Outfit',sans-serif;width:100%;cursor:pointer;transition:all .2s;text-decoration:none;display:block;text-align:center;}
        .btn-cancel:hover{background:var(--s100);color:var(--s700);transform:translateY(-1px);}

        @media(max-width:768px){
            .sidebar{width:100%;height:auto;position:relative;}
            .content{margin-left:0;padding:14px;}
            .profile-body{padding:20px 16px;}
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
        <a href="caregiver_profile.php" class="active"><i class="fa-solid fa-user-pen"></i> My Profile</a>
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
            <h4><i class="fas fa-user-pen me-2" style="font-size:18px;color:var(--s500);"></i>My Profile</h4>
            <p>View and manage your caregiver profile</p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt"></i>Logout</button>
        </form>
    </div>

    <!-- Flash message -->
    <?php if ($message): ?>
        <div class="flash-msg flash-<?= $message_type === 'success' ? 'success' : 'error' ?>">
            <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Profile completion -->
    <div class="completion-card">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Profile Completion</h5>
            <span class="completion-pct"><?= $completion ?>%</span>
        </div>
        <div class="completion-track">
            <div class="completion-bar" style="width:<?= $completion ?>%"></div>
        </div>
        <p class="mb-0" style="font-size:13px;color:var(--st500);">
            <?php if ($completion === 100): ?>
                <i class="fas fa-check-circle me-1" style="color:var(--green-text);"></i>Your profile is complete!
            <?php elseif ($completion >= 50): ?>
                <i class="fas fa-circle-half-stroke me-1" style="color:var(--amber-text);"></i>Almost there — fill in the remaining fields.
            <?php else: ?>
                <i class="fas fa-exclamation-circle me-1" style="color:var(--red-text);"></i>Please complete your profile to use all features.
            <?php endif; ?>
        </p>
    </div>

    <!-- Profile card -->
    <div class="profile-container">

        <!-- Hero -->
        <div class="profile-hero">
            <div class="profile-avatar"><?= strtoupper(substr($user_data['full_name'],0,1)) ?></div>
            <h4><?= htmlspecialchars($user_data['full_name']) ?></h4>
            <p><?= htmlspecialchars($user_data['email']) ?></p>
            <?php if ($age): ?>
                <div class="meta-row">
                    <i class="fas fa-birthday-cake me-1"></i><?= $age ?> years old
                    <?php if (!empty($profile_data['experience_years'])): ?>
                        &nbsp;·&nbsp;<i class="fas fa-briefcase me-1"></i><?= $profile_data['experience_years'] ?> yrs experience
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="profile-body">

            <!-- ── VIEW MODE ── -->
            <div id="viewMode" style="display:<?= $show_edit ? 'none' : 'block' ?>">
                <?php if ($has_profile): ?>

                <div class="info-section">
                    <div class="info-section-title"><i class="fas fa-user"></i>Basic Information</div>
                    <div class="info-grid">
                        <div>
                            <div class="info-label">Phone</div>
                            <div class="info-value <?= empty($profile_data['phone']) ? 'muted' : '' ?>"><?= !empty($profile_data['phone']) ? htmlspecialchars($profile_data['phone']) : 'Not provided' ?></div>
                        </div>
                        <div>
                            <div class="info-label">Gender</div>
                            <div class="info-value <?= empty($profile_data['gender']) ? 'muted' : '' ?>"><?= !empty($profile_data['gender']) ? htmlspecialchars($profile_data['gender']) : 'Not specified' ?></div>
                        </div>
                        <div>
                            <div class="info-label">Date of Birth</div>
                            <div class="info-value <?= empty($profile_data['dob']) ? 'muted' : '' ?>">
                                <?php if (!empty($profile_data['dob'])): ?><?= date('F j, Y', strtotime($profile_data['dob'])) ?><?= $age ? " ($age yrs)" : '' ?><?php else: ?>Not provided<?php endif; ?>
                            </div>
                        </div>
                        <div style="grid-column:1/-1;">
                            <div class="info-label">Address</div>
                            <div class="info-value <?= empty($profile_data['address']) ? 'muted' : '' ?>"><?= !empty($profile_data['address']) ? nl2br(htmlspecialchars($profile_data['address'])) : 'Not provided' ?></div>
                        </div>
                    </div>
                </div>

                <div class="info-section">
                    <div class="info-section-title"><i class="fas fa-briefcase"></i>Professional Information</div>
                    <div class="info-grid">
                        <div>
                            <div class="info-label">Years of Experience</div>
                            <div class="info-value <?= empty($profile_data['experience_years']) ? 'muted' : '' ?>"><?= !empty($profile_data['experience_years']) ? $profile_data['experience_years'] . ' years' : 'Not specified' ?></div>
                        </div>
                        <div style="grid-column:1/-1;">
                            <div class="info-label">Skills &amp; Specializations</div>
                            <?php if (!empty($profile_data['skills'])): ?>
                                <div class="skills-wrap mt-1">
                                    <?php foreach (explode(',', $profile_data['skills']) as $sk): $sk=trim($sk); if($sk): ?>
                                        <span class="skill-chip"><?= htmlspecialchars($sk) ?></span>
                                    <?php endif; endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="info-value muted">No skills listed</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <button class="edit-btn w-100" onclick="showEdit()"><i class="fas fa-pen"></i>Edit Profile</button>
                    </div>
                </div>

                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-user-circle fa-4x mb-3 d-block" style="color:var(--s200);"></i>
                    <h5 style="font-family:'Outfit',sans-serif;font-size:16px;font-weight:700;color:var(--s700);">No profile created yet</h5>
                    <p style="color:var(--st300);font-size:13px;margin-bottom:20px;">Fill in your details below to get started.</p>
                    <button class="edit-btn" onclick="showEdit()"><i class="fas fa-plus"></i>Create Profile</button>
                </div>
                <?php endif; ?>
            </div><!-- /viewMode -->


            <!-- ── EDIT MODE ── -->
            <div id="editMode" style="display:<?= $show_edit ? 'block' : 'none' ?>">
                <form method="POST" id="profileForm">
                    <div class="row g-4">

                        <!-- Left: Basic -->
                        <div class="col-md-6">
                            <div class="section-divider"><i class="fas fa-user"></i>Basic Information</div>

                            <div class="mb-3">
                                <label class="form-label required">Phone Number</label>
                                <div class="input-wrap">
                                    <i class="fi fas fa-phone"></i>
                                    <input type="tel" class="form-control" name="phone"
                                           value="<?= htmlspecialchars($profile_data['phone'] ?? '') ?>"
                                           placeholder="+94 XX XXX XXXX" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label required">Address</label>
                                <div class="input-wrap">
                                    <i class="fi fi-ta fas fa-home"></i>
                                    <textarea class="form-control" name="address" rows="3"
                                              placeholder="Enter your full address" required><?= htmlspecialchars($profile_data['address'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label required">Gender</label>
                                <div class="input-wrap">
                                    <i class="fi fas fa-venus-mars"></i>
                                    <select class="form-select" name="gender" required>
                                        <option value="">Select Gender</option>
                                        <option value="Male"   <?= ($profile_data['gender'] ?? '') === 'Male'   ? 'selected' : '' ?>>Male</option>
                                        <option value="Female" <?= ($profile_data['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Professional -->
                        <div class="col-md-6">
                            <div class="section-divider"><i class="fas fa-briefcase"></i>Professional Information</div>

                            <div class="mb-3">
                                <label class="form-label required">Date of Birth</label>
                                <div class="input-wrap">
                                    <i class="fi fas fa-calendar"></i>
                                    <input type="date" class="form-control" name="dob" id="dob"
                                           value="<?= htmlspecialchars($profile_data['dob'] ?? '') ?>"
                                           max="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="form-text" id="ageHint"></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label required">Years of Experience</label>
                                <div class="input-wrap">
                                    <i class="fi fas fa-clock"></i>
                                    <input type="number" class="form-control" name="experience_years" id="experience_years"
                                           value="<?= htmlspecialchars($profile_data['experience_years'] ?? '') ?>"
                                           min="0" max="60" placeholder="e.g. 5" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Skills &amp; Specializations</label>
                                <div class="input-wrap">
                                    <i class="fi fi-ta fas fa-star"></i>
                                    <textarea class="form-control" name="skills" rows="3"
                                              placeholder="CPR Certified, Dementia Care, Physiotherapy…"><?= htmlspecialchars($profile_data['skills'] ?? '') ?></textarea>
                                </div>
                                <div class="form-text">Separate multiple skills with commas</div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="row g-3 mt-2">
                        <div class="col-md-5">
                            <button type="submit" class="btn-save">
                                <i class="fas fa-save me-2"></i>
                                <span><?= $has_profile ? 'Update Profile' : 'Create Profile' ?></span>
                            </button>
                        </div>
                        <div class="col-md-4">
                            <?php if ($has_profile): ?>
                                <a href="caregiver_profile.php" class="btn-cancel"><i class="fas fa-times me-2"></i>Cancel</a>
                            <?php else: ?>
                                <a href="caregiver_dashboard.php" class="btn-cancel"><i class="fas fa-arrow-left me-2"></i>Dashboard</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div><!-- /editMode -->

        </div><!-- /profile-body -->
    </div><!-- /profile-container -->
</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showEdit() {
    document.getElementById('viewMode').style.display = 'none';
    document.getElementById('editMode').style.display = 'block';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

const dobInput = document.getElementById('dob');
if (dobInput) {
    function updateAgeHint() {
        if (!dobInput.value) return;
        const dob = new Date(dobInput.value), today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const m = today.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
        document.getElementById('ageHint').textContent = age >= 0 ? `Age: ${age} years old` : '';
    }
    dobInput.addEventListener('change', updateAgeHint);
    updateAgeHint();
}

document.getElementById('profileForm')?.addEventListener('submit', function(e) {
    const phone = document.querySelector('[name="phone"]').value;
    if (!/^[0-9+\-\s()]{7,15}$/.test(phone)) { e.preventDefault(); alert('Please enter a valid phone number.'); return; }
    const exp = parseInt(document.querySelector('[name="experience_years"]').value);
    if (isNaN(exp) || exp < 0 || exp > 60) { e.preventDefault(); alert('Experience must be between 0 and 60 years.'); return; }
    const dob = new Date(document.getElementById('dob').value);
    if (dob > new Date()) { e.preventDefault(); alert('Date of birth cannot be in the future.'); return; }
    const age = new Date().getFullYear() - dob.getFullYear();
    if (age < 18) { e.preventDefault(); alert('Caregiver must be at least 18 years old.'); return; }
    const btn = this.querySelector('.btn-save');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><span>Saving…</span>';
    btn.disabled = true;
});

document.querySelectorAll('textarea').forEach(ta => {
    ta.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
    });
});
</script>
</body>
</html>