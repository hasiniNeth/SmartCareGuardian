<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: ../login.php");
    exit();
}

include '../db_connection.php';
require_once '../ai-service/rourine_suggestions/RoutineSuggestionAI.php';

$caregiver_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$stmt->bind_param("i", $caregiver_id);
$stmt->execute();
$caregiver = $stmt->get_result()->fetch_assoc();
$stmt->close();

$residents_stmt = $conn->prepare("
    SELECT u.user_id, u.full_name, u.status,
           r.resident_id, r.gender, r.dob,
           TIMESTAMPDIFF(YEAR, r.dob, CURDATE()) AS age,
           r.medical_conditions, r.dietary_restrictions
    FROM   users u
    JOIN   caregiver_assignments ca ON u.user_id = ca.resident_id
    LEFT JOIN residents r ON r.user_id = u.user_id
    WHERE  ca.caregiver_id = ?
      AND  u.role = 'resident'
    ORDER  BY u.full_name ASC
");
$residents_stmt->bind_param("i", $caregiver_id);
$residents_stmt->execute();
$residents = $residents_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$residents_stmt->close();

$selected_resident_user_id = isset($_GET['resident_id']) ? (int)$_GET['resident_id'] : null;
if (!$selected_resident_user_id && !empty($residents)) {
    $selected_resident_user_id = $residents[0]['user_id'];
}
$selected_resident = null;
foreach ($residents as $r) {
    if ($r['user_id'] == $selected_resident_user_id) { $selected_resident = $r; break; }
}

$aiResult    = [];
$suggestions = [];
$aiError     = false;
$lastLogDate = null;
$logCount    = 0;

if ($selected_resident && !empty($selected_resident['resident_id'])) {
    $rid = (int)$selected_resident['resident_id'];
    $log_stmt = $conn->prepare("SELECT COUNT(*) AS cnt, MAX(logged_at) AS last_logged FROM health_logs WHERE resident_id = ?");
    $log_stmt->bind_param("i", $rid);
    $log_stmt->execute();
    $logInfo     = $log_stmt->get_result()->fetch_assoc();
    $logCount    = (int)$logInfo['cnt'];
    $lastLogDate = $logInfo['last_logged'];
    $log_stmt->close();
    $ai       = new RoutineSuggestionAI($conn);
    $aiResult = $ai->getSuggestionsForResident($rid, $caregiver_id);
    $suggestions = $aiResult['suggestions'] ?? [];
    if (isset($aiResult['error'])) $aiError = $aiResult['error'];
}

$routineIcons = [
    'meal'     => 'fa-utensils',
    'exercise' => 'fa-person-running',
    'checkup'  => 'fa-stethoscope',
    'hygiene'  => 'fa-hands-bubbles',
    'therapy'  => 'fa-brain',
];
$routineColors = [
    'meal'     => '#59a14f',
    'exercise' => '#4e79a7',
    'checkup'  => '#e15759',
    'hygiene'  => '#f28e2b',
    'therapy'  => '#9b59b6',
];

$med_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM medications m JOIN caregiver_assignments ca ON ca.resident_id = m.resident_id WHERE ca.caregiver_id = ? AND m.taken = 0 AND m.medication_date = CURDATE()");
$med_stmt->bind_param("i", $caregiver_id);
$med_stmt->execute();
$pending_meds = $med_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$med_stmt->close();

$unread_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND is_read = 0");
$unread_stmt->bind_param("i", $caregiver_id);
$unread_stmt->execute();
$unread_messages = $unread_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$unread_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Routine Suggestions — SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* ═══════════════════════════════════════════════════════════
       SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
       AI Suggestions · Professional scale (15px base)
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
        color:var(--st700);min-height:100vh;margin:0;padding:0;
    }
    h1,h2,h3,h4,h5,h6{font-family:'Cormorant Garamond',serif;color:var(--s800);margin:0;}

    /* ── Sidebar ── */
    .sidebar{width:240px;height:100vh;position:fixed;top:0;left:0;background:var(--s800);display:flex;flex-direction:column;z-index:1000;overflow:hidden;}
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
    .sb-badge{margin-left:auto;background:#8B3A3A;color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;}
    .msg-badge{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:#8B3A3A;border-radius:50%;min-width:18px;height:18px;font-size:.65rem;display:flex;align-items:center;justify-content:center;padding:0 3px;color:white;font-weight:700;}
    .sb-ftr{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 8px;position:relative;}
    .sb-ftr a{margin:0;color:rgba(255,255,255,.5)!important;font-size:13.5px;}
    .sb-ftr a:hover{color:rgba(255,255,255,.8)!important;}

    /* ── Layout ── */
    .content{margin-left:240px;padding:24px;min-height:100vh;}

    /* ── Topbar ── */
    .topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
    .topbar h4{font-size:22px;font-weight:500;color:var(--s800);margin-bottom:2px;}
    .topbar p{font-size:13px;color:var(--st300);margin:0;}
    .logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;}
    .logout-btn:hover{opacity:.9;transform:translateY(-1px);}

    /* ── Section card ── */
    .sc{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:20px;overflow:hidden;}
    .sc-hdr{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:16px 22px;position:relative;overflow:hidden;}
    .sc-hdr::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
    .sc-body{padding:20px 22px;}

    /* ── Resident pills ── */
    .res-sel{background:white;border-radius:var(--radius-lg);padding:18px 22px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:20px;}
    .res-sel-label{color:var(--st300);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px;display:flex;align-items:center;gap:6px;}
    .rpill{display:inline-flex;align-items:center;gap:8px;padding:7px 15px;border-radius:50px;cursor:pointer;border:2px solid var(--s100);background:var(--w50);color:var(--s800);font-weight:600;text-decoration:none;transition:all .2s;margin:3px;font-size:13px;}
    .rpill:hover{border-color:var(--s400);background:var(--s50);transform:translateY(-1px);}
    .rpill.active{background:linear-gradient(135deg,var(--s500),var(--s800));border-color:transparent;color:white;box-shadow:0 4px 14px rgba(94,138,64,.35);}
    .pill-av{width:26px;height:26px;border-radius:50%;background:var(--s100);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;color:var(--s600);}
    .rpill.active .pill-av{background:rgba(255,255,255,.2);color:white;}

    /* ── Vitals strip ── */
    .h-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-top:14px;}
    .h-chip{background:var(--s50);border-radius:var(--radius-sm);padding:12px 10px;text-align:center;border-top:3px solid var(--s400);border:1px solid var(--s100);border-top-width:3px;}
    .cv{font-size:15px;font-weight:700;color:var(--s800);}
    .cl{font-size:11px;color:var(--st300);font-weight:700;margin-top:3px;text-transform:uppercase;letter-spacing:.05em;}

    /* ── AI status bar ── */
    .ai-bar{display:flex;align-items:center;gap:10px;background:white;border-radius:var(--radius-md);padding:10px 16px;margin-bottom:18px;box-shadow:var(--shadow-soft);border:1px solid rgba(196,217,180,.3);font-size:13px;color:var(--st500);font-weight:600;flex-wrap:wrap;}
    .ai-dot{width:9px;height:9px;border-radius:50%;background:var(--s400);flex-shrink:0;animation:pd 2s infinite;}
    .ai-dot.off{background:var(--red-text);animation:none;}
    @keyframes pd{0%,100%{box-shadow:0 0 0 0 rgba(94,138,64,.4);}50%{box-shadow:0 0 0 6px rgba(94,138,64,0);}}

    /* ── Tab bar ── */
    .tab-bar{display:flex;gap:6px;margin-bottom:20px;background:white;padding:6px;border-radius:var(--radius-md);box-shadow:var(--shadow-soft);border:1px solid rgba(196,217,180,.3);width:fit-content;}
    .tab-btn{padding:8px 20px;border-radius:var(--radius-sm);border:none;background:transparent;font-family:'Outfit',sans-serif;font-weight:700;font-size:13px;color:var(--st500);cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:7px;}
    .tab-btn.active{background:linear-gradient(135deg,var(--s500),var(--s800));color:white;box-shadow:0 3px 12px rgba(94,138,64,.35);}
    .tab-count{border-radius:20px;padding:2px 7px;font-size:11px;}
    .tab-btn.active .tab-count{background:rgba(255,255,255,.25);}
    .tab-btn:not(.active) .tab-count{background:var(--s50);color:var(--s700);}
    .tab-pane{display:none;}
    .tab-pane.active{display:block;}

    /* ── Suggestion cards grid ── */
    .sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:17px;}
    .scard{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-soft);border:1px solid rgba(196,217,180,.2);overflow:hidden;transition:all .25s;display:flex;flex-direction:column;}
    .scard:hover{transform:translateY(-3px);box-shadow:var(--shadow-lift);}
    .scard.dismissed{opacity:.3;pointer-events:none;transition:opacity .35s;}

    /* card top */
    .ct{padding:18px 18px 12px;border-left:5px solid var(--c);flex:1;}
    .ric{width:42px;height:42px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:white;margin-bottom:12px;background:var(--c);}
    .ab{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:7px;}
    .ab-add   {background:var(--green-bg);color:var(--green-text);}
    .ab-change{background:var(--amber-bg);color:var(--amber-text);}
    .st{font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;color:var(--s800);margin-bottom:5px;}
    .sd{font-size:13px;color:var(--st500);line-height:1.55;}

    /* overlap badges */
    .ob{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;margin-top:8px;margin-right:4px;}
    .ob-cache   {background:var(--blue-bg);color:var(--blue-text);}
    .ob-conflict{background:var(--amber-bg);color:var(--amber-text);}

    /* card bottom */
    .cb{padding:12px 18px 16px;background:var(--s50);border-top:1px solid var(--s100);}
    .cw{margin-bottom:10px;}
    .cl2{display:flex;justify-content:space-between;font-size:11px;color:var(--st300);margin-bottom:4px;font-weight:700;}
    .ctr{height:6px;background:var(--s100);border-radius:10px;overflow:hidden;}
    .cf{height:100%;border-radius:10px;background:var(--c);width:0%;transition:width 1s ease;}
    .abtns{display:flex;gap:7px;margin-bottom:7px;}
    .btn-acc{flex:1;padding:8px;border-radius:var(--radius-sm);font-weight:700;font-size:13px;border:none;background:var(--c);color:white;cursor:pointer;transition:all .2s;font-family:'Outfit',sans-serif;}
    .btn-acc:hover{opacity:.85;transform:translateY(-1px);}
    .btn-dis{padding:8px 12px;border-radius:var(--radius-sm);font-weight:700;font-size:13px;border:2px solid var(--s100);background:white;color:var(--st300);cursor:pointer;transition:all .2s;font-family:'Outfit',sans-serif;}
    .btn-dis:hover{border-color:var(--red-text);color:var(--red-text);}
    .btn-goto{display:block;width:100%;padding:8px;border-radius:var(--radius-sm);text-align:center;font-weight:700;font-size:13px;text-decoration:none;border:2px solid var(--c);color:var(--c);background:white;transition:all .2s;font-family:'Outfit',sans-serif;}
    .btn-goto:hover{background:var(--c);color:white;}

    /* ── All good panel ── */
    .ag-panel{text-align:center;padding:48px 28px;background:var(--s50);border-radius:var(--radius-lg);border:2px dashed var(--s300);}
    .ag-icon{width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,var(--s400),var(--s700));color:white;font-size:1.6rem;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;}

    /* ── History table ── */
    .hw{overflow-x:auto;}
    .htbl{width:100%;border-collapse:separate;border-spacing:0 4px;font-size:13px;}
    .htbl thead th{padding:9px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--st300);font-weight:700;border-bottom:2px solid var(--s100);}
    .htbl tbody tr{background:white;box-shadow:var(--shadow-soft);transition:all .18s;}
    .htbl tbody tr:hover{box-shadow:var(--shadow-card);transform:translateY(-1px);}
    .htbl tbody td{padding:10px 12px;vertical-align:middle;}
    .htbl tbody td:first-child{border-radius:var(--radius-sm) 0 0 var(--radius-sm);}
    .htbl tbody td:last-child {border-radius:0 var(--radius-sm) var(--radius-sm) 0;}
    .rtdot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px;}
    .sp{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
    .sp-p{background:var(--amber-bg);color:var(--amber-text);}
    .sp-a{background:var(--green-bg);color:var(--green-text);}
    .sp-d{background:var(--red-bg);  color:var(--red-text);}
    .he{text-align:center;padding:44px 20px;color:var(--st300);}
    .he i{font-size:2.2rem;opacity:.25;display:block;margin-bottom:10px;}

    /* ── Spinner ── */
    .hl{text-align:center;padding:36px;color:var(--st300);}
    .spin{width:32px;height:32px;border:3px solid var(--s100);border-top-color:var(--s400);border-radius:50%;animation:sp .7s linear infinite;margin:0 auto 10px;}
    @keyframes sp{to{transform:rotate(360deg);}}

    /* ── Toast ── */
    .tc{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;}
    .tm{background:var(--s800);color:white;padding:11px 18px;border-radius:var(--radius-md);font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;box-shadow:var(--shadow-lift);animation:si .28s ease;display:flex;align-items:center;gap:9px;}
    .tm.success{border-left:4px solid var(--s400);}
    .tm.error  {border-left:4px solid var(--red-text);}
    @keyframes si{from{transform:translateX(110%);opacity:0;}to{transform:translateX(0);opacity:1;}}

    /* ── Empty state ── */
    .es{text-align:center;padding:50px 20px;}
    .es i{font-size:3rem;color:var(--s200);margin-bottom:14px;display:block;}

    /* ── Alert ── */
    .alert-warning{background:var(--amber-bg);color:var(--amber-text);border:1px solid rgba(122,80,16,.15);border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;}
    .alert-danger {background:var(--red-bg);  color:var(--red-text);  border:1px solid rgba(107,34,34,.15);border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;}
    .alert-link{color:inherit;font-weight:700;}

    @media(max-width:768px){
        .sidebar{width:100%;height:auto;position:relative;}
        .content{margin-left:0;padding:14px;}
        .sg{grid-template-columns:1fr;}
        .tab-bar{width:100%;}
    }
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
        <small>Caregiver Panel</small>
    </div>
    <div class="sb-nav">
        <a href="caregiver_dashboard.php"><i class="fa-solid fa-gauge-high"></i>Dashboard</a>
        <a href="caregiver_profile.php"><i class="fa-solid fa-user-pen"></i>My Profile</a>
        <a href="caregiver_residents.php"><i class="fa-solid fa-user-group"></i>My Residents</a>
        <a href="log_health.php"><i class="fa-solid fa-heart-pulse"></i>Log Health Data</a>
        <a href="manage_routines.php"><i class="fa-solid fa-calendar-check"></i>Manage Routines</a>
        <a href="manage_medications.php">
            <i class="fa-solid fa-pills"></i>Medications
            <?php if ($pending_meds > 0): ?><span class="sb-badge"><?= $pending_meds ?></span><?php endif; ?>
        </a>
        <a href="caregiver_appointments.php"><i class="fa-solid fa-calendar-days"></i>Appointments</a>
        <a href="caregiver_messages.php">
            <i class="fa-solid fa-comments"></i>Messages
            <?php if ($unread_messages > 0): ?><span class="msg-badge"><?= $unread_messages ?></span><?php endif; ?>
        </a>
        <a href="caregiver_ai_alerts.php"><i class="fa-solid fa-bell"></i>Health Alerts</a>
        <a href="ai_suggestions.php" class="active"><i class="fa-solid fa-wand-magic-sparkles"></i>Routine Suggestions</a>
        <a href="caregiver_reports.php"><i class="fa-solid fa-chart-line"></i>Health Reports</a>
    </div>
    <div class="sb-ftr">
        <a href="/SmartCareGuardian/logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <div class="topbar">
        <div>
            <h4><i class="fa-solid fa-robot me-2" style="font-size:18px;color:var(--s500);"></i>AI Routine Suggestions</h4>
            <p>Personalised care routine recommendations &nbsp;·&nbsp; Welcome, <?= htmlspecialchars($caregiver['full_name']) ?></p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt me-2"></i>Logout</button>
        </form>
    </div>

    <?php if (empty($residents)): ?>
    <div class="sc">
        <div class="sc-body">
            <div class="es">
                <i class="fa-solid fa-user-group d-block"></i>
                <h5 style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--st500);">No Residents Assigned Yet</h5>
                <p style="color:var(--st300);">AI suggestions will appear here once residents are assigned to you.</p>
                <a href="caregiver_residents.php" style="background:linear-gradient(135deg,var(--s500),var(--s800));color:white;border-radius:var(--radius-md);padding:10px 24px;text-decoration:none;font-weight:700;font-size:13px;display:inline-flex;align-items:center;gap:7px;">
                    <i class="fas fa-user-plus"></i>View Residents
                </a>
            </div>
        </div>
    </div>

    <?php else: ?>

    <!-- Resident selector -->
    <div class="res-sel">
        <div class="res-sel-label"><i class="fas fa-users"></i>Select a Resident</div>
        <?php foreach ($residents as $r): ?>
            <a href="ai_suggestions.php?resident_id=<?= $r['user_id'] ?>"
               class="rpill <?= $r['user_id'] == $selected_resident_user_id ? 'active' : '' ?>">
                <span class="pill-av"><?= strtoupper(substr($r['full_name'], 0, 1)) ?></span>
                <?= htmlspecialchars($r['full_name']) ?>
                <?php if (!empty($r['age'])): ?><small style="opacity:.65;"><?= $r['age'] ?>y</small><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($selected_resident):
        $vs = $conn->prepare("SELECT blood_pressure_systolic, blood_pressure_diastolic, blood_sugar, pulse, weight, temperature, oxygen_saturation, logged_at FROM health_logs WHERE resident_id = ? ORDER BY logged_at DESC LIMIT 1");
        $vs->bind_param("i", $selected_resident['resident_id']);
        $vs->execute();
        $latest = $vs->get_result()->fetch_assoc();
        $vs->close();
    ?>

    <!-- Health summary -->
    <div class="sc">
        <div class="sc-hdr d-flex justify-content-between align-items-center flex-wrap gap-2" style="position:relative;">
            <div class="d-flex align-items-center gap-3" style="position:relative;">
                <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.2rem;font-weight:700;color:white;">
                    <?= strtoupper(substr($selected_resident['full_name'], 0, 1)) ?>
                </div>
                <div>
                    <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:15px;color:white;">
                        <?= htmlspecialchars($selected_resident['full_name']) ?>
                    </div>
                    <small style="opacity:.8;color:rgba(255,255,255,.8);font-size:12px;">
                        <?= $selected_resident['gender'] ?? '' ?>
                        <?php if ($selected_resident['age']): ?> · <?= $selected_resident['age'] ?> yrs<?php endif; ?>
                        <?php if ($selected_resident['medical_conditions']): ?> · <?= htmlspecialchars($selected_resident['medical_conditions']) ?><?php endif; ?>
                    </small>
                </div>
            </div>
            <small style="opacity:.75;color:rgba(255,255,255,.75);font-size:12px;position:relative;">
                <?= $logCount ?> health log<?= $logCount != 1 ? 's' : '' ?>
                <?php if ($lastLogDate): ?> · Last: <?= date('d M Y', strtotime($lastLogDate)) ?><?php endif; ?>
            </small>
        </div>
        <div class="sc-body">
            <?php if ($latest): ?>
                <p style="color:var(--st300);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:0;">
                    <i class="fas fa-chart-line me-2"></i>Latest Readings
                    <span style="color:var(--st300);text-transform:none;letter-spacing:0;font-weight:400;font-size:12px;margin-left:8px;">
                        — <?= date('d M Y, g:i A', strtotime($latest['logged_at'])) ?>
                    </span>
                </p>
                <div class="h-strip">
                    <div class="h-chip" style="border-top-color:#8B3A3A;">
                        <div class="cv"><?= $latest['blood_pressure_systolic'] ?>/<?= $latest['blood_pressure_diastolic'] ?></div>
                        <div class="cl">Blood Pressure</div>
                    </div>
                    <div class="h-chip" style="border-top-color:#A07830;">
                        <div class="cv"><?= $latest['blood_sugar'] ?></div>
                        <div class="cl">Blood Sugar</div>
                    </div>
                    <div class="h-chip" style="border-top-color:#8B3A3A;">
                        <div class="cv"><?= $latest['pulse'] ?></div>
                        <div class="cl">Pulse (bpm)</div>
                    </div>
                    <div class="h-chip" style="border-top-color:var(--blue-text);">
                        <div class="cv"><?= $latest['weight'] ?></div>
                        <div class="cl">Weight (kg)</div>
                    </div>
                    <div class="h-chip" style="border-top-color:var(--s600);">
                        <div class="cv"><?= $latest['temperature'] ?>°C</div>
                        <div class="cl">Temperature</div>
                    </div>
                    <div class="h-chip" style="border-top-color:var(--s400);">
                        <div class="cv"><?= $latest['oxygen_saturation'] ?>%</div>
                        <div class="cl">SpO₂</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    No health data logged yet. AI suggestions use safe default values.
                    <a href="log_health.php?resident_id=<?= $selected_resident['resident_id'] ?>" class="alert-link ms-2">Log health data →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- AI status bar -->
    <div class="ai-bar">
        <?php if ($aiError): ?>
            <div class="ai-dot off"></div>
            <span>AI service offline — make sure <code>python app.py</code> is running on port 5001.</span>
        <?php else: ?>
            <div class="ai-dot"></div>
            <span>AI service online &nbsp;·&nbsp; Random Forest · 15 models · 98.2% avg accuracy &nbsp;·&nbsp; Generated: <strong><?= date('d M Y, g:i A') ?></strong></span>
            <?php if (!empty($aiResult['total'])): ?>
                <span class="ms-auto" style="background:var(--s600);color:white;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:700;">
                    <?= $aiResult['total'] ?> suggestion<?= $aiResult['total'] != 1 ? 's' : '' ?>
                </span>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Tab bar -->
    <div class="tab-bar">
        <button class="tab-btn active" onclick="switchTab('suggestions', this)">
            <i class="fas fa-wand-magic-sparkles"></i>Suggestions
            <span class="tab-count" id="sug-count"><?= count($suggestions) ?></span>
        </button>
        <button class="tab-btn" onclick="switchTab('history', this)">
            <i class="fas fa-clock-rotate-left"></i>History
            <span class="tab-count" id="hist-count">—</span>
        </button>
    </div>

    <!-- TAB: SUGGESTIONS -->
    <div class="tab-pane active" id="tab-suggestions">
        <?php if ($aiError): ?>
            <div class="sc"><div class="sc-body">
                <div class="alert-danger">
                    <i class="fas fa-robot me-2"></i>
                    Could not reach the AI service at <code>http://127.0.0.1:5001</code>.
                    Run <code>python app.py</code> inside your <code>ai-service/rourine_suggestions/</code> folder and refresh.
                </div>
            </div></div>
        <?php elseif (empty($suggestions)): ?>
            <div class="ag-panel">
                <div class="ag-icon"><i class="fas fa-check"></i></div>
                <h5 style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--s800);margin-bottom:8px;">All Routines Are Appropriate</h5>
                <p style="color:var(--st500);margin:0;font-size:14px;">
                    The AI reviewed <strong><?= htmlspecialchars($selected_resident['full_name']) ?></strong>'s
                    health data and current routines. No changes are recommended right now.
                    Check again after new health data is logged.
                </p>
            </div>
        <?php else: ?>
            <div class="sg" id="sg">
                <?php foreach ($suggestions as $s):
                    $icon      = $routineIcons[$s['routine_type']]  ?? 'fa-list';
                    $color     = $routineColors[$s['routine_type']] ?? '#5E8A40';
                    $confPct   = round(($s['confidence'] ?? 0) * 100);
                    $rid_db    = $selected_resident['resident_id'];
                    $btnHref   = ($s['action'] === 'add')
                        ? "manage_routines.php?resident_id={$rid_db}&type={$s['routine_type']}&action=add"
                        : "manage_routines.php?resident_id={$rid_db}&type={$s['routine_type']}&action=edit";
                    $btnText   = ($s['action'] === 'add') ? 'Go to Add Routine' : 'Go to Edit Routine';
                    $sid       = (int)($s['id'] ?? 0);
                    $fromCache = !empty($s['_from_cache']);
                    $hasConflict = !empty($s['time_conflict']);
                ?>
                <div class="scard" style="--c:<?= $color ?>;" id="card-<?= $sid ?>">
                    <div class="ct" style="border-left-color:<?= $color ?>;">
                        <span class="ab ab-<?= $s['action'] ?>"><?= $s['action'] === 'add' ? '＋ Add New' : '✎ Update' ?></span>
                        <div class="ric"><i class="fas <?= $icon ?>"></i></div>
                        <div class="st"><?= htmlspecialchars($s['title']) ?></div>
                        <div class="sd"><?= htmlspecialchars($s['description']) ?></div>
                        <div class="mt-2">
                            <?php if ($fromCache): ?>
                                <span class="ob ob-cache"><i class="fas fa-rotate-right fa-xs"></i>Already suggested today</span>
                            <?php endif; ?>
                            <?php if ($hasConflict): ?>
                                <span class="ob ob-conflict"><i class="fas fa-triangle-exclamation fa-xs"></i>Schedule conflict detected</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cb">
                        <div class="cw">
                            <div class="cl2"><span>AI Confidence</span><span><?= $confPct ?>%</span></div>
                            <div class="ctr"><div class="cf" data-width="<?= $confPct ?>"></div></div>
                        </div>
                        <div class="abtns">
                            <?php if ($sid): ?>
                                <button class="btn-acc" style="background:<?= $color ?>;" onclick="doAction('accept', <?= $sid ?>, 'card-<?= $sid ?>')">
                                    <i class="fas fa-check me-1"></i>Accept
                                </button>
                                <button class="btn-dis" onclick="doAction('dismiss', <?= $sid ?>, 'card-<?= $sid ?>')">
                                    <i class="fas fa-xmark"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                        <a href="<?= $btnHref ?>" class="btn-goto" style="color:<?= $color ?>;border-color:<?= $color ?>;">
                            <i class="fas fa-arrow-right me-1"></i><?= $btnText ?>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="text-center mt-4" style="font-size:13px;color:var(--st300);">
                <i class="fas fa-info-circle me-1"></i>Suggestions refresh automatically when new health data is logged.
                <a href="ai_suggestions.php?resident_id=<?= $selected_resident_user_id ?>" class="ms-2" style="color:var(--s600);font-weight:700;">
                    <i class="fas fa-rotate-right me-1"></i>Refresh now
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB: HISTORY -->
    <div class="tab-pane" id="tab-history">
        <div class="sc">
            <div class="sc-hdr d-flex justify-content-between align-items-center flex-wrap gap-2" style="position:relative;">
                <div style="position:relative;">
                    <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;color:white;display:flex;align-items:center;gap:7px;">
                        <i class="fas fa-clock-rotate-left"></i>Suggestion History
                    </div>
                    <small style="opacity:.8;color:rgba(255,255,255,.75);font-size:12px;">
                        All AI suggestions generated for <?= htmlspecialchars($selected_resident['full_name']) ?>
                    </small>
                </div>
                <button onclick="loadHistory()" style="background:rgba(255,255,255,.15);color:white;border:none;border-radius:var(--radius-sm);padding:7px 14px;font-weight:700;font-size:13px;cursor:pointer;font-family:'Outfit',sans-serif;position:relative;">
                    <i class="fas fa-rotate-right me-1"></i>Refresh
                </button>
            </div>
            <div class="sc-body p-0" id="history-container">
                <div class="hl"><div class="spin"></div><p style="font-size:13px;margin:0;">Loading history…</p></div>
            </div>
        </div>
    </div>

    <?php endif; // selected_resident ?>
    <?php endif; // residents check ?>

</div><!-- /.content -->

<div class="tc" id="tc"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const RID = <?= $selected_resident ? (int)$selected_resident['resident_id'] : 0 ?>;

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.cf').forEach(bar => {
        const w = bar.dataset.width;
        setTimeout(() => { bar.style.width = w + '%'; }, 350);
    });
});

function switchTab(name, btn) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
    if (name === 'history') loadHistory();
}

function doAction(action, sid, cardId) {
    fetch('suggestion_action.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action, suggestion_id: sid })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const card = document.getElementById(cardId);
            if (card) {
                card.classList.add('dismissed');
                setTimeout(() => {
                    card.style.transition = 'all .4s ease';
                    card.style.opacity    = '0';
                    card.style.transform  = 'scale(0.88)';
                    setTimeout(() => card.remove(), 420);
                }, 280);
            }
            toast(action === 'accept' ? '✓ Suggestion accepted' : '✕ Suggestion dismissed', action === 'accept' ? 'success' : 'error');
            const grid = document.getElementById('sg');
            if (grid) {
                const rem = grid.querySelectorAll('.scard:not(.dismissed)').length - 1;
                document.getElementById('sug-count').textContent = Math.max(0, rem);
            }
        } else { toast('Action failed — please try again', 'error'); }
    })
    .catch(() => toast('Network error', 'error'));
}

function loadHistory() {
    if (!RID) return;
    const container = document.getElementById('history-container');
    container.innerHTML = '<div class="hl"><div class="spin"></div><p style="font-size:13px;margin:0;">Loading history…</p></div>';
    fetch('suggestion_action.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'history', resident_id: RID })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { container.innerHTML = '<div class="he"><i class="fas fa-exclamation-circle"></i><p>Could not load history.</p></div>'; return; }
        document.getElementById('hist-count').textContent = data.history.length;
        if (!data.history.length) {
            container.innerHTML = `<div class="he"><i class="fas fa-clock-rotate-left"></i><p>No history yet.<br><small>Accept or dismiss suggestions to see them here.</small></p></div>`;
            return;
        }
        const colors = { meal:'#59a14f', exercise:'#4e79a7', checkup:'#e15759', hygiene:'#f28e2b', therapy:'#9b59b6' };
        const icons  = { meal:'fa-utensils', exercise:'fa-person-running', checkup:'fa-stethoscope', hygiene:'fa-hands-bubbles', therapy:'fa-brain' };
        const statusClass = { pending:'sp-p', accepted:'sp-a', dismissed:'sp-d' };
        const rows = data.history.map(h => {
            const col  = colors[h.routine_type] || '#5E8A40';
            const ico  = icons[h.routine_type]  || 'fa-list';
            const scls = statusClass[h.status]  || 'sp-p';
            const cfBadge = h.time_conflict == 1 ? `<span class="ob ob-conflict" style="font-size:.64rem;margin-top:4px;"><i class="fas fa-triangle-exclamation fa-xs"></i> Conflict</span>` : '';
            const actBadge = h.suggestion_action === 'add' ? `<span class="ab ab-add" style="font-size:.66rem;">＋ Add</span>` : `<span class="ab ab-change" style="font-size:.66rem;">✎ Update</span>`;
            return `<tr>
                <td><span class="rtdot" style="background:${col};"></span><i class="fas ${ico} me-1" style="color:${col};font-size:.78rem;"></i><strong>${cap(h.routine_type)}</strong></td>
                <td>${actBadge}</td>
                <td style="max-width:260px;">
                    <div style="font-weight:700;font-size:13px;color:var(--s800);">${esc(h.title)}</div>
                    <div style="font-size:12px;color:var(--st300);margin-top:2px;">${esc(h.description)}</div>
                    ${cfBadge}
                </td>
                <td>
                    <strong style="font-size:13px;">${h.confidence_pct}%</strong>
                    <div class="ctr" style="width:75px;margin-top:4px;"><div class="cf" style="width:${h.confidence_pct}%;background:${col};"></div></div>
                </td>
                <td><span class="sp ${scls}">${cap(h.status)}</span></td>
                <td style="font-size:12px;color:var(--st300);white-space:nowrap;">${h.generated_at_fmt}</td>
                <td style="font-size:12px;color:var(--st300);white-space:nowrap;">${h.acted_at_fmt || '—'}</td>
            </tr>`;
        }).join('');
        container.innerHTML = `<div class="hw p-3"><table class="htbl"><thead><tr><th>Type</th><th>Action</th><th>Suggestion</th><th>Confidence</th><th>Status</th><th>Generated</th><th>Acted At</th></tr></thead><tbody>${rows}</tbody></table></div>`;
    })
    .catch(() => { container.innerHTML = '<div class="he"><i class="fas fa-wifi"></i><p>Network error loading history.</p></div>'; });
}

function toast(msg, type = 'success') {
    const container = document.getElementById('tc');
    const el = document.createElement('div');
    el.className = `tm ${type}`;
    el.innerHTML = `<i class="fas ${type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark'}"></i> ${msg}`;
    container.appendChild(el);
    setTimeout(() => { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 320); }, 3200);
}

function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
</body>
</html>