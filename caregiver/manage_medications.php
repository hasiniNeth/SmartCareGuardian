<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: ../login.php"); exit();
}
date_default_timezone_set('Asia/Colombo');

include '../db_connection.php';
$caregiver_id = (int)$_SESSION['user_id'];
$today        = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_taken'])) {
    $mid   = (int)$_POST['medication_id'];
    $taken = (int)$_POST['taken'];
    $own = $conn->prepare("SELECT m.medication_id FROM medications m JOIN caregiver_assignments ca ON m.resident_id = ca.resident_id WHERE m.medication_id=? AND ca.caregiver_id=? LIMIT 1");
    $own->bind_param("ii", $mid, $caregiver_id);
    $own->execute();
    if ($own->get_result()->num_rows) {
        $u = $conn->prepare("UPDATE medications SET taken=? WHERE medication_id=?");
        $u->bind_param("ii", $taken, $mid);
        $u->execute(); $u->close();
    }
    $own->close();
    header("Location: manage_medications.php?msg=".urlencode($taken ? 'Marked as taken ✓' : 'Marked as not taken'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_med'])) {
    $res_id   = (int)$_POST['resident_id'];
    $name     = trim($_POST['medication_name']);
    $dosage   = trim($_POST['dosage']);
    $freq     = trim($_POST['frequency']);
    $med_date = trim($_POST['medication_date']);
    $med_time = trim($_POST['medication_time']);
    $errors = [];
    if (!$res_id)   $errors[] = 'Select a resident.';
    if (!$name)     $errors[] = 'Medication name required.';
    if (!$med_date) $errors[] = 'Date required.';
    if (!$med_time) $errors[] = 'Time required.';
    if (empty($errors)) {
        $ins = $conn->prepare("INSERT INTO medications (resident_id, medication_name, dosage, frequency, medication_date, medication_time, taken) VALUES (?, ?, ?, ?, ?, ?, 0)");
        $ins->bind_param("isssss", $res_id, $name, $dosage, $freq, $med_date, $med_time);
        $ins->execute() ? header("Location: manage_medications.php?msg=".urlencode("Medication added ✓")) : header("Location: manage_medications.php?msg=".urlencode("DB error: ".$ins->error));
        $ins->close(); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_med'])) {
    $mid = (int)$_POST['medication_id'];
    $own = $conn->prepare("SELECT m.medication_id FROM medications m JOIN caregiver_assignments ca ON m.resident_id = ca.resident_id WHERE m.medication_id=? AND ca.caregiver_id=? LIMIT 1");
    $own->bind_param("ii", $mid, $caregiver_id);
    $own->execute();
    if ($own->get_result()->num_rows) {
        $d = $conn->prepare("DELETE FROM medications WHERE medication_id=?");
        $d->bind_param("i", $mid); $d->execute(); $d->close();
    }
    $own->close();
    header("Location: manage_medications.php?msg=".urlencode("Medication deleted"));
    exit;
}

$resStmt = $conn->prepare("SELECT u.user_id, u.full_name FROM users u JOIN caregiver_assignments ca ON u.user_id = ca.resident_id WHERE ca.caregiver_id=? ORDER BY u.full_name");
$resStmt->bind_param("i", $caregiver_id);
$resStmt->execute();
$residents = $resStmt->get_result(); $resStmt->close();

$f_res    = $_GET['f_resident'] ?? '';
$f_date   = $_GET['f_date']     ?? '';
$f_status = $_GET['f_status']   ?? '';
$limit    = 15;
$page     = max(1,(int)($_GET['p']??1));
$offset   = ($page-1)*$limit;

$where = ["ca.caregiver_id=$caregiver_id"];
if ($f_res)   $where[] = "m.resident_id=".(int)$f_res;
if ($f_date)  $where[] = "m.medication_date='".$conn->real_escape_string($f_date)."'";
if ($f_status==='taken')   $where[] = "m.taken=1";
if ($f_status==='pending') $where[] = "m.taken=0";
$wSql = implode(' AND ',$where);

$total  = $conn->query("SELECT COUNT(*) AS c FROM medications m JOIN caregiver_assignments ca ON m.resident_id=ca.resident_id WHERE $wSql")->fetch_assoc()['c'];
$tPages = max(1,ceil($total/$limit));
$meds   = $conn->query("SELECT m.*, u.full_name AS resident_name FROM medications m JOIN users u ON m.resident_id=u.user_id JOIN caregiver_assignments ca ON m.resident_id=ca.resident_id WHERE $wSql ORDER BY m.medication_date ASC, m.medication_time ASC LIMIT $limit OFFSET $offset");

$todayStats = $conn->query("SELECT COUNT(*) AS tot, SUM(taken) AS done, SUM(taken=0) AS pend FROM medications m JOIN caregiver_assignments ca ON m.resident_id=ca.resident_id WHERE ca.caregiver_id=$caregiver_id AND m.medication_date='$today'")->fetch_assoc();
$todayMeds  = $conn->query("SELECT m.*, u.full_name AS resident_name FROM medications m JOIN users u ON m.resident_id=u.user_id JOIN caregiver_assignments ca ON m.resident_id=ca.resident_id WHERE ca.caregiver_id=$caregiver_id AND m.medication_date='$today' ORDER BY m.medication_time ASC");

$unread_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM messages WHERE receiver_id=? AND is_read=0");
$unread_stmt->bind_param("i",$caregiver_id);
$unread_stmt->execute();
$unread_messages = $unread_stmt->get_result()->fetch_assoc()['c'];
$unread_stmt->close();

$form_errors = $errors ?? [];

$med_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM medications m JOIN caregiver_assignments ca ON ca.resident_id = m.resident_id WHERE ca.caregiver_id = ? AND m.taken = 0 AND m.medication_date = CURDATE()");
$med_stmt->bind_param("i", $caregiver_id);
$med_stmt->execute();
$pending_meds = $med_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$med_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Medications – SmartCare Guardian</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════
   SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
   Manage Medications · Professional scale
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
.btn-routines{background:linear-gradient(135deg,var(--s400),var(--s600));border:none;border-radius:var(--radius-md);color:white;padding:9px 18px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-routines:hover{opacity:.9;transform:translateY(-1px);color:white;}

/* ── Flash messages ── */
.alert-ok {background:var(--green-bg);color:var(--green-text);border:none;border-radius:var(--radius-md);padding:13px 18px;margin-bottom:18px;font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px;}
.alert-err{background:var(--red-bg);  color:var(--red-text);  border:none;border-radius:var(--radius-md);padding:13px 18px;margin-bottom:18px;font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px;}

/* ── Stats ── */
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:20px;}
.stat-card{background:white;border-radius:var(--radius-lg);padding:18px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);text-align:center;transition:transform .2s;}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lift);}
.stat-icon{width:46px;height:46px;border-radius:var(--radius-md);margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;}
.ic-t{background:linear-gradient(135deg,var(--s300),var(--s500));color:white;}
.ic-p{background:linear-gradient(135deg,#C87A7A,#8B3A3A);color:white;}
.ic-d{background:linear-gradient(135deg,var(--s400),var(--s700));color:white;}
.stat-num{font-size:1.8rem;font-weight:800;color:var(--s800);line-height:1.1;}
.stat-lbl{font-size:12px;font-weight:600;color:var(--st500);margin-top:4px;}

/* ── Main card ── */
.main-card{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:20px;overflow:hidden;}
.card-head{background:linear-gradient(135deg,var(--s600),var(--s800));padding:15px 22px;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;}
.card-head::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
/* Red variant for today's medications header */
.card-head.red-head{background:linear-gradient(135deg,#8B3A3A,#6A2020);}
.card-head h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;position:relative;display:flex;align-items:center;gap:7px;}
.card-head small{color:rgba(255,255,255,.7);font-size:12px;position:relative;}
.card-bp{padding:20px 24px;}

/* ── Medication items (today's list) ── */
.med-item{background:var(--w50);border-radius:var(--radius-md);padding:14px 16px;border-left:4px solid var(--red-text);box-shadow:var(--shadow-soft);transition:all .2s;margin-bottom:10px;}
.med-item:hover{transform:translateY(-2px);box-shadow:var(--shadow-card);}
.med-item.taken{border-left-color:var(--s500);background:var(--green-bg);opacity:.9;}
.res-av{width:38px;height:38px;background:linear-gradient(135deg,#C87A7A,#8B3A3A);border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:.95rem;flex-shrink:0;}
.res-av.taken{background:linear-gradient(135deg,var(--s400),var(--s700));}
.med-name{font-weight:800;color:var(--s800);font-size:14px;}
.med-detail{font-size:12px;color:var(--st500);}
.time-lbl{color:var(--red-text);font-weight:700;font-size:13px;}
.time-lbl.taken{color:var(--green-text);}

/* ── Chips ── */
.chip{padding:3px 9px;border-radius:20px;font-weight:700;font-size:11px;}
.chip-taken  {background:var(--green-bg);color:var(--green-text);}
.chip-pending{background:var(--red-bg);  color:var(--red-text);}

/* ── Action buttons ── */
.btn-take  {background:linear-gradient(135deg,var(--s400),var(--s600));border:none;border-radius:var(--radius-sm);color:white;padding:6px 13px;font-weight:700;font-size:12px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;display:inline-flex;align-items:center;gap:5px;}
.btn-untake{background:linear-gradient(135deg,var(--st500),var(--st700));border:none;border-radius:var(--radius-sm);color:white;padding:6px 11px;font-weight:700;font-size:12px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;display:inline-flex;align-items:center;gap:5px;}
.btn-del   {background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-sm);color:white;padding:6px 11px;font-weight:700;font-size:12px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;display:inline-flex;align-items:center;gap:5px;}
.btn-take:hover,.btn-untake:hover,.btn-del:hover{transform:translateY(-1px);box-shadow:var(--shadow-soft);}
.btn-save{background:linear-gradient(135deg,var(--s400),var(--s700));border:none;border-radius:var(--radius-md);color:white;padding:11px 26px;font-weight:700;font-size:14px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;display:inline-flex;align-items:center;gap:7px;}
.btn-save:hover{opacity:.9;transform:translateY(-1px);box-shadow:var(--shadow-card);}

/* ── Form ── */
.form-label{color:var(--s800);font-weight:700;margin-bottom:6px;font-size:13px;}
.form-control,.form-select{border:2px solid var(--s100);border-radius:var(--radius-md);padding:10px 13px;font-family:'Outfit',sans-serif;font-size:14px;color:var(--st700);background:var(--w50);transition:all .2s;}
.form-control:focus,.form-select:focus{border-color:var(--s400);box-shadow:0 0 0 3px rgba(122,166,88,.15);outline:none;}
.form-control::placeholder{color:var(--st300);}
.sec-div{font-family:'Outfit',sans-serif;font-weight:700;color:var(--s800);margin-bottom:14px;padding-bottom:8px;border-bottom:2px solid var(--s100);display:flex;align-items:center;gap:7px;font-size:13px;}
.info-box{background:var(--red-bg);border-radius:var(--radius-md);padding:14px 16px;border-left:4px solid var(--red-text);}
.info-box strong{color:var(--red-text);font-size:13px;}
.info-box p{color:var(--st500);font-size:12px;margin:4px 0 0;}

/* ── Filter box ── */
.filter-box{background:var(--w50);border-radius:var(--radius-md);padding:16px 18px;margin-bottom:18px;border:1px solid var(--s100);}

/* ── Table ── */
.table{margin:0;font-size:13px;}
.table thead{background:var(--s50);}
.table thead th{border:none;padding:11px 13px;font-weight:700;color:var(--s700);font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
.table tbody tr{border-bottom:1px solid var(--s50);transition:background .15s;}
.table tbody tr:hover{background:var(--s50);}
.table tbody td{padding:11px 13px;vertical-align:middle;border:none;color:var(--st700);}

/* ── Empty state ── */
.empty-st{text-align:center;padding:36px 16px;color:var(--st300);}
.empty-st i{font-size:2.2rem;display:block;margin-bottom:8px;opacity:.25;}
.empty-st p{margin:0;font-size:13px;}

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
        <a href="caregiver_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="caregiver_profile.php"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="caregiver_residents.php"><i class="fa-solid fa-user-group"></i> My Residents</a>
        <a href="log_health.php"><i class="fa-solid fa-heart-pulse"></i> Log Health Data</a>
        <a href="manage_routines.php"><i class="fa-solid fa-calendar-check"></i> Manage Routines</a>
        <a href="manage_medications.php" class="active">
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

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <div class="topbar">
        <div>
            <h4><i class="fas fa-pills me-2" style="font-size:18px;color:var(--red-text);"></i>Medications</h4>
            <p><i class="fas fa-calendar-day me-1"></i><?= date('l, F j, Y') ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="manage_routines.php" class="btn-routines"><i class="fas fa-calendar-check"></i>Routines</a>
            <form action="/SmartCareGuardian/logout.php" method="POST">
                <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt"></i>Logout</button>
            </form>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert-ok"><i class="fas fa-check-circle"></i><?= htmlspecialchars($_GET['msg']) ?></div>
    <?php endif; ?>
    <?php if (!empty($form_errors)): ?>
        <div class="alert-err"><i class="fas fa-exclamation-circle"></i><?= implode(' ', array_map('htmlspecialchars', $form_errors)) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon ic-t"><i class="fas fa-pills"></i></div>
            <div class="stat-num"><?= (int)$todayStats['tot'] ?></div>
            <div class="stat-lbl">Today's Meds</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon ic-p"><i class="fas fa-clock"></i></div>
            <div class="stat-num"><?= (int)$todayStats['pend'] ?></div>
            <div class="stat-lbl">Pending Today</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon ic-d"><i class="fas fa-check-double"></i></div>
            <div class="stat-num"><?= (int)$todayStats['done'] ?></div>
            <div class="stat-lbl">Administered Today</div>
        </div>
    </div>

    <!-- Today's Medications -->
    <div class="main-card">
        <div class="card-head red-head">
            <h5><i class="fas fa-sun"></i>Today's Medications — <?= date('M j, Y') ?></h5>
            <small><?= (int)$todayStats['done'] ?> / <?= (int)$todayStats['tot'] ?> given</small>
        </div>
        <div class="card-bp">
            <?php if ($todayMeds->num_rows === 0): ?>
                <div class="empty-st"><i class="fas fa-pills"></i><p>No medications scheduled for today.</p></div>
            <?php else: ?>
                <?php while ($med = $todayMeds->fetch_assoc()): ?>
                <div class="med-item <?= $med['taken'] ? 'taken' : '' ?>">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <div class="res-av <?= $med['taken']?'taken':'' ?>"><?= strtoupper(substr($med['resident_name'],0,1)) ?></div>
                        <div style="flex:1;min-width:180px;">
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <span class="med-name"><?= htmlspecialchars($med['medication_name']) ?></span>
                                <?php if ($med['dosage']): ?><small style="color:var(--st300);"><?= htmlspecialchars($med['dosage']) ?></small><?php endif; ?>
                                <span class="chip <?= $med['taken']?'chip-taken':'chip-pending' ?>"><?= $med['taken'] ? 'Taken' : 'Pending' ?></span>
                            </div>
                            <div class="med-detail">
                                <i class="fas fa-user me-1"></i><?= htmlspecialchars($med['resident_name']) ?>
                                &nbsp;·&nbsp;
                                <span class="time-lbl <?= $med['taken']?'taken':'' ?>"><i class="far fa-clock me-1"></i><?= date('g:i A',strtotime($med['medication_time'])) ?></span>
                                <?php if ($med['frequency']): ?>&nbsp;·&nbsp;<i class="fas fa-repeat me-1"></i><?= htmlspecialchars($med['frequency']) ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="medication_id" value="<?= $med['medication_id'] ?>">
                                <?php if (!$med['taken']): ?>
                                    <input type="hidden" name="taken" value="1">
                                    <button type="submit" name="toggle_taken" class="btn-take"><i class="fas fa-check"></i>Mark Taken</button>
                                <?php else: ?>
                                    <input type="hidden" name="taken" value="0">
                                    <button type="submit" name="toggle_taken" class="btn-untake"><i class="fas fa-undo"></i>Undo</button>
                                <?php endif; ?>
                            </form>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="medication_id" value="<?= $med['medication_id'] ?>">
                                <button type="submit" name="delete_med" class="btn-del" onclick="return confirm('Delete this medication entry?')"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add New Medication -->
    <div class="main-card">
        <div class="card-head">
            <h5><i class="fas fa-plus-circle"></i>Add Medication</h5>
        </div>
        <div class="card-bp">
            <form method="POST" id="medForm">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="sec-div"><i class="fas fa-user" style="color:var(--s400);"></i>Resident & Medication</div>
                        <div class="mb-3">
                            <label class="form-label">Resident *</label>
                            <select name="resident_id" class="form-select" required>
                                <option value="">— Select Resident —</option>
                                <?php $residents->data_seek(0); while($r=$residents->fetch_assoc()): ?>
                                    <option value="<?= $r['user_id'] ?>" <?= isset($_POST['resident_id']) && $_POST['resident_id']==$r['user_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r['full_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Medication Name *</label>
                            <input type="text" name="medication_name" class="form-control" required placeholder="e.g. Metformin 500mg" value="<?= htmlspecialchars($_POST['medication_name'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Dosage</label>
                            <input type="text" name="dosage" class="form-control" placeholder="e.g. 1 tablet, 5ml" value="<?= htmlspecialchars($_POST['dosage'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Frequency</label>
                            <input type="text" name="frequency" class="form-control" placeholder="e.g. Twice daily, After meals" value="<?= htmlspecialchars($_POST['frequency'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="sec-div"><i class="fas fa-calendar-alt" style="color:var(--s400);"></i>Schedule</div>
                        <div class="mb-3">
                            <label class="form-label">Medication Date *</label>
                            <input type="date" name="medication_date" class="form-control" required value="<?= htmlspecialchars($_POST['medication_date'] ?? $today) ?>" min="<?= date('Y-m-d', strtotime('-1 year')) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Medication Time *</label>
                            <input type="time" name="medication_time" class="form-control" required value="<?= htmlspecialchars($_POST['medication_time'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <div class="info-box">
                                <strong><i class="fas fa-info-circle me-1"></i>How this works</strong>
                                <p>Each entry represents one scheduled dose. For recurring medications (e.g. daily), add a separate entry for each occurrence, or use the date field to plan ahead.</p>
                            </div>
                        </div>
                        <button type="submit" name="add_med" class="btn-save"><i class="fas fa-plus"></i>Add Medication</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- All Medications Table -->
    <div class="main-card">
        <div class="card-head">
            <h5><i class="fas fa-list-alt"></i>All Medication Records</h5>
            <small><?= $total ?> records</small>
        </div>
        <div class="card-bp">
            <div class="filter-box">
                <form class="row g-3 align-items-end">
                    <input type="hidden" name="p" value="1">
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" style="font-size:12px;">Resident</label>
                        <select name="f_resident" class="form-select form-select-sm">
                            <option value="">All Residents</option>
                            <?php $residents->data_seek(0); while($r=$residents->fetch_assoc()): ?>
                                <option value="<?= $r['user_id'] ?>" <?= $f_res==$r['user_id']?'selected':'' ?>><?= htmlspecialchars($r['full_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label" style="font-size:12px;">Date</label>
                        <input type="date" name="f_date" class="form-control form-control-sm" value="<?= htmlspecialchars($f_date) ?>">
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label class="form-label" style="font-size:12px;">Status</label>
                        <select name="f_status" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="pending" <?= $f_status==='pending'?'selected':'' ?>>Pending</option>
                            <option value="taken"   <?= $f_status==='taken'?'selected':'' ?>>Taken</option>
                        </select>
                    </div>
                    <div class="col-lg-2">
                        <button type="submit" class="btn-save w-100" style="padding:9px;font-size:13px;justify-content:center;"><i class="fas fa-filter"></i>Filter</button>
                    </div>
                    <div class="col-lg-2">
                        <a href="manage_medications.php" class="btn-untake w-100 justify-content-center" style="padding:9px;text-decoration:none;display:flex;align-items:center;gap:5px;"><i class="fas fa-times"></i>Clear</a>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Date</th><th>Time</th><th>Resident</th><th>Medication</th><th>Dosage</th><th>Frequency</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if ($meds->num_rows === 0): ?>
                        <tr><td colspan="8" class="py-3"><div class="empty-st"><i class="fas fa-pills"></i><p>No medication records found.</p></div></td></tr>
                    <?php else: ?>
                        <?php while($med=$meds->fetch_assoc()): ?>
                        <tr>
                            <td><?= date('M j, Y',strtotime($med['medication_date'])) ?></td>
                            <td><strong style="color:var(--s800);"><?= date('g:i A',strtotime($med['medication_time'])) ?></strong></td>
                            <td><?= htmlspecialchars($med['resident_name']) ?></td>
                            <td><strong style="color:var(--s800);"><?= htmlspecialchars($med['medication_name']) ?></strong></td>
                            <td><?= $med['dosage'] ? htmlspecialchars($med['dosage']) : '—' ?></td>
                            <td><?= $med['frequency'] ? htmlspecialchars($med['frequency']) : '—' ?></td>
                            <td><span class="chip <?= $med['taken']?'chip-taken':'chip-pending' ?>"><?= $med['taken']?'Taken':'Pending' ?></span></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="medication_id" value="<?= $med['medication_id'] ?>">
                                        <input type="hidden" name="taken" value="<?= $med['taken'] ? 0 : 1 ?>">
                                        <button type="submit" name="toggle_taken" class="<?= $med['taken']?'btn-untake':'btn-take' ?>">
                                            <?= $med['taken'] ? '<i class="fas fa-undo"></i>' : '<i class="fas fa-check"></i>' ?>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="medication_id" value="<?= $med['medication_id'] ?>">
                                        <button type="submit" name="delete_med" class="btn-del" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($tPages > 1): ?>
                <nav class="mt-4"><ul class="pagination justify-content-center">
                    <?php for($i=1;$i<=$tPages;$i++): ?>
                        <li class="page-item <?= $i==$page?'active':'' ?>">
                            <a class="page-link" style="<?= $i==$page?'background:var(--s600);border-color:var(--s600);':'' ?>"
                               href="?<?= http_build_query(array_merge($_GET,['p'=>$i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul></nav>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('select[name^="f_"]').forEach(s => s.addEventListener('change', () => s.form.submit()));
</script>
</body>
</html>