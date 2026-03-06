<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: ../login.php"); exit();
}

date_default_timezone_set('Asia/Colombo');

include '../db_connection.php';
$caregiver_id  = (int)$_SESSION['user_id'];
$today_date    = date('Y-m-d');
$today_weekday = date('l');

/* ── Auto-insert today's routine_logs for due routines ─────────── */
$auto = $conn->prepare("
    INSERT IGNORE INTO routine_logs (routine_id, log_date, status, created_at)
    SELECT r.id, ?, 'pending', NOW()
    FROM routines r
    WHERE r.caregiver_id = ?
      AND r.status = 'pending'
      AND (r.days_of_week IS NULL OR FIND_IN_SET(?, r.days_of_week))
      AND NOT EXISTS (
          SELECT 1 FROM routine_logs rl WHERE rl.routine_id = r.id AND rl.log_date = ?
      )
");
$auto->bind_param("siss", $today_date, $caregiver_id, $today_weekday, $today_date);
$auto->execute(); $auto->close();

/* ── Assigned residents ─────────────────────────────────────────── */
$resStmt = $conn->prepare("
    SELECT u.user_id, u.full_name FROM users u
    JOIN caregiver_assignments ca ON u.user_id = ca.resident_id
    WHERE ca.caregiver_id = ? ORDER BY u.full_name
");
$resStmt->bind_param("i", $caregiver_id);
$resStmt->execute();
$residents = $resStmt->get_result(); $resStmt->close();

/* ── Today's tasks with log status ─────────────────────────────── */
$todayStmt = $conn->prepare("
    SELECT r.*, u.full_name AS resident_name,
           COALESCE(rl.status,'pending') AS today_status,
           rl.log_id, rl.completed_at
    FROM routines r
    JOIN users u ON r.resident_id = u.user_id
    LEFT JOIN routine_logs rl ON rl.routine_id = r.id AND rl.log_date = ?
    WHERE r.caregiver_id = ? AND r.status = 'pending'
      AND (r.days_of_week IS NULL OR FIND_IN_SET(?, r.days_of_week))
    ORDER BY r.schedule_time ASC
");
$todayStmt->bind_param("sis", $today_date, $caregiver_id, $today_weekday);
$todayStmt->execute();
$todayTasks = $todayStmt->get_result(); $todayStmt->close();

/* ── Today's medication count (for the quick-link badge) ────────── */
$medTodayStmt = $conn->prepare("
    SELECT COUNT(*) AS cnt FROM medications m
    JOIN caregiver_assignments ca ON m.resident_id = ca.resident_id
    WHERE ca.caregiver_id = ? AND m.medication_date = ? AND m.taken = 0
");
$medTodayStmt->bind_param("is", $caregiver_id, $today_date);
$medTodayStmt->execute();
$pending_meds = $medTodayStmt->get_result()->fetch_assoc()['cnt'];
$medTodayStmt->close();

/* ── Editing mode ───────────────────────────────────────────────── */
$editing = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $e   = $conn->prepare("SELECT * FROM routines WHERE id=? AND caregiver_id=?");
    $e->bind_param("ii", $eid, $caregiver_id);
    $e->execute();
    $editing = $e->get_result()->fetch_assoc(); $e->close();
}

/* ── AI suggestion pre-fill ─────────────────────────────────────── */
$ai_prefill = null;
if (!$editing && isset($_GET['type'], $_GET['action'], $_GET['resident_id'])) {
    $ai_type   = preg_replace('/[^a-z_]/', '', strtolower($_GET['type']));
    $ai_action = $_GET['action'] === 'edit' ? 'edit' : 'add';
    $ai_res_id = (int)$_GET['resident_id'];
    $type_map  = ['meal'=>'meal','exercise'=>'exercise','hygiene'=>'personal_care','therapy'=>'other','checkup'=>'other'];
    $desc_map  = [
        'meal'     => ['add'=>'Structured daily meal — low-sodium, diabetic-friendly (AI)','edit'=>'Update meal plan — low-sodium, diabetic diet (AI)'],
        'exercise' => ['add'=>'Daily light exercise — gentle walks, chair yoga (AI)','edit'=>'Update exercise — adjust to light intensity (AI)'],
        'checkup'  => ['add'=>'Weekly vitals checkup (AI recommended)','edit'=>'Increase checkup frequency (AI)'],
        'hygiene'  => ['add'=>'Daily hygiene routine with caregiver supervision (AI)','edit'=>'Update hygiene — caregiver assistance (AI)'],
        'therapy'  => ['add'=>'Physiotherapy — joint mobility & muscle strength (AI)','edit'=>'Update therapy routine (AI)'],
    ];
    $time_map = ['meal'=>'08:00','exercise'=>'09:00','checkup'=>'10:00','hygiene'=>'07:00','therapy'=>'14:00'];
    if ($ai_action === 'edit') {
        $mapped = $type_map[$ai_type] ?? $ai_type;
        $eq = $conn->prepare("SELECT * FROM routines WHERE resident_id=? AND LOWER(routine_type)=? AND caregiver_id=? ORDER BY id DESC LIMIT 1");
        $eq->bind_param("isi", $ai_res_id, $mapped, $caregiver_id);
        $eq->execute();
        $ex = $eq->get_result()->fetch_assoc(); $eq->close();
        if ($ex) $editing = $ex;
    }
    $ai_prefill = [
        'resident_id'  => $ai_res_id,
        'routine_type' => $type_map[$ai_type] ?? 'other',
        'description'  => $desc_map[$ai_type][$ai_action] ?? 'AI recommended routine',
        'schedule_time'=> $time_map[$ai_type] ?? '08:00',
        'action'       => $ai_action, 'ai_type' => $ai_type,
    ];
}

/* ── Filter / paginate all routines ─────────────────────────────── */
$limit   = 10; $page = max(1,(int)($_GET['p']??1)); $offset = ($page-1)*$limit;
$f_res   = $_GET['f_resident']??''; $f_type = $_GET['f_type']??''; $f_day = $_GET['f_day']??'';
$where   = ["r.caregiver_id=$caregiver_id"];
if ($f_res)  $where[] = "r.resident_id=".(int)$f_res;
if ($f_type) $where[] = "r.routine_type='".$conn->real_escape_string($f_type)."'";
if ($f_day)  $where[] = "FIND_IN_SET('".$conn->real_escape_string($f_day)."',r.days_of_week)";
$wSql    = implode(' AND ',$where);
$total   = $conn->query("SELECT COUNT(*) AS c FROM routines r WHERE $wSql")->fetch_assoc()['c'];
$tPages  = max(1,ceil($total/$limit));
$listRes = $conn->query("
    SELECT r.*, u.full_name AS resident_name,
           COALESCE(rl.status,'pending') AS today_status
    FROM routines r
    JOIN users u ON r.resident_id=u.user_id
    LEFT JOIN routine_logs rl ON rl.routine_id=r.id AND rl.log_date='$today_date'
    WHERE $wSql ORDER BY r.schedule_time ASC, r.id DESC LIMIT $limit OFFSET $offset
");

/* ── Stats ──────────────────────────────────────────────────────── */
$sts = $conn->query("
    SELECT
      COUNT(DISTINCT r.id) AS tot,
      SUM(CASE WHEN COALESCE(rl.status,'pending')='completed' THEN 1 ELSE 0 END) AS done,
      SUM(CASE WHEN COALESCE(rl.status,'pending')='pending'   THEN 1 ELSE 0 END) AS pend
    FROM routines r
    LEFT JOIN routine_logs rl ON rl.routine_id=r.id AND rl.log_date='$today_date'
    WHERE r.caregiver_id=$caregiver_id AND r.status='pending'
      AND (r.days_of_week IS NULL OR FIND_IN_SET('$today_weekday',r.days_of_week))
")->fetch_assoc();

$unread_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM messages WHERE receiver_id=? AND is_read=0");
$unread_stmt->bind_param("i",$caregiver_id);
$unread_stmt->execute();
$unread_messages = $unread_stmt->get_result()->fetch_assoc()['c'];
$unread_stmt->close();

$days  = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
$types = ['meal'=>'Meal','exercise'=>'Exercise','personal_care'=>'Personal Care','other'=>'Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Routines – SmartCare Guardian</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════
   SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
   Manage Routines · Professional scale
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

/* ── Flash alert ── */
.alert-ok{background:var(--green-bg);color:var(--green-text);border:none;border-radius:var(--radius-md);padding:13px 18px;margin-bottom:18px;font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px;}

/* ── Stat cards ── */
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:20px;}
.stat-card{background:white;border-radius:var(--radius-lg);padding:18px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);text-align:center;transition:transform .2s;cursor:default;}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lift);}
.stat-icon{width:46px;height:46px;border-radius:var(--radius-md);margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;}
.ic-t{background:linear-gradient(135deg,var(--s300),var(--s500));color:white;}
.ic-p{background:linear-gradient(135deg,#D4A853,#7A5010);color:white;}
.ic-d{background:linear-gradient(135deg,var(--s400),var(--s700));color:white;}
.ic-m{background:linear-gradient(135deg,#C87A7A,#8B3A3A);color:white;}
.stat-num{font-size:1.8rem;font-weight:800;color:var(--s800);line-height:1.1;}
.stat-lbl{font-size:12px;font-weight:600;color:var(--st500);margin-top:4px;}

/* ── Main card ── */
.main-card{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:20px;overflow:hidden;}
.card-head{background:linear-gradient(135deg,var(--s600),var(--s800));padding:15px 22px;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;}
.card-head::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
.card-head h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;position:relative;display:flex;align-items:center;gap:7px;}
.card-head small{color:rgba(255,255,255,.7);font-size:12px;position:relative;}
.card-bp{padding:20px 24px;}

/* ── Medication CTA banner ── */
.med-cta{background:var(--red-bg);border:2px solid #EEC0C0;border-radius:var(--radius-md);padding:16px 20px;display:flex;align-items:center;gap:14px;margin-bottom:20px;flex-wrap:wrap;}
.med-cta-icon{width:46px;height:46px;border-radius:var(--radius-md);background:linear-gradient(135deg,#C87A7A,#8B3A3A);display:flex;align-items:center;justify-content:center;color:white;font-size:1.1rem;flex-shrink:0;}
.med-cta-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 18px;font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;text-decoration:none;transition:all .2s;white-space:nowrap;position:relative;display:inline-flex;align-items:center;gap:6px;}
.med-cta-btn:hover{opacity:.9;transform:translateY(-1px);color:white;}
.med-badge{position:absolute;top:-7px;right:-7px;background:#8B3A3A;color:white;border-radius:50%;width:18px;height:18px;font-size:.6rem;display:flex;align-items:center;justify-content:center;font-weight:700;}

/* ── AI banner ── */
.ai-banner{background:var(--purple-bg);border:2px solid #C4B5F8;border-radius:var(--radius-md);padding:14px 16px;margin-bottom:18px;display:flex;align-items:flex-start;gap:12px;}
.ai-bi{width:38px;height:38px;border-radius:var(--radius-sm);flex-shrink:0;background:linear-gradient(135deg,#9B8FD4,#6B5FA6);display:flex;align-items:center;justify-content:center;color:white;font-size:1rem;}

/* ── Today's task items ── */
.task-item{background:var(--w50);border-radius:var(--radius-md);padding:14px 16px;border-left:4px solid var(--s300);box-shadow:var(--shadow-soft);transition:all .2s;margin-bottom:10px;}
.task-item:hover{transform:translateY(-2px);box-shadow:var(--shadow-card);}
.task-item.completed{border-left-color:var(--s500);background:var(--green-bg);}
.task-item.skipped{border-left-color:var(--st300);opacity:.8;}
.res-av{width:38px;height:38px;background:linear-gradient(135deg,var(--s300),var(--s600));border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:.95rem;flex-shrink:0;}
.type-chip{background:var(--s50);color:var(--s700);padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;border:1px solid var(--s200);}
.time-lbl{color:var(--s600);font-weight:700;font-size:13px;}

/* ── Form styles ── */
.form-label{color:var(--s800);font-weight:700;margin-bottom:6px;font-size:13px;display:flex;align-items:center;gap:5px;}
.form-control,.form-select{border:2px solid var(--s100);border-radius:var(--radius-md);padding:10px 13px;font-family:'Outfit',sans-serif;font-size:14px;color:var(--st700);background:var(--w50);transition:all .2s;}
.form-control:focus,.form-select:focus{border-color:var(--s400);box-shadow:0 0 0 3px rgba(122,166,88,.15);outline:none;}
.form-control::placeholder{color:var(--st300);}
.sec-div{font-family:'Outfit',sans-serif;font-weight:700;color:var(--s800);margin-bottom:14px;padding-bottom:8px;border-bottom:2px solid var(--s100);display:flex;align-items:center;gap:7px;font-size:13px;}

/* ── Day checkboxes ── */
.days-g{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-top:6px;}
.day-l{background:var(--s50);border:2px solid var(--s100);border-radius:var(--radius-sm);padding:8px 4px;text-align:center;cursor:pointer;transition:all .2s;}
.day-l input{display:none;}
.day-l span{font-size:12px;font-weight:700;color:var(--s700);}
.day-l.checked{background:linear-gradient(135deg,var(--s400),var(--s700));border-color:var(--s500);}
.day-l.checked span{color:white;}

/* ── Reminder box ── */
.reminder-box{background:var(--s50);border-radius:var(--radius-md);padding:13px 15px;border:1px solid var(--s100);}
.reminder-box .form-check-label{font-weight:700;color:var(--s800);font-size:13px;}

/* ── Filter box ── */
.filter-box{background:var(--w50);border-radius:var(--radius-md);padding:16px 18px;margin-bottom:18px;border:1px solid var(--s100);}

/* ── Tables ── */
.table{margin:0;font-size:13px;}
.table thead{background:var(--s50);}
.table thead th{border:none;padding:11px 13px;font-weight:700;color:var(--s700);font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
.table tbody tr{border-bottom:1px solid var(--s50);transition:background .15s;}
.table tbody tr:hover{background:var(--s50);}
.table tbody td{padding:11px 13px;vertical-align:middle;border:none;color:var(--st700);}

/* ── Status chips ── */
.chip{padding:4px 10px;border-radius:20px;font-weight:700;font-size:11px;}
.chip-pending  {background:var(--amber-bg);color:var(--amber-text);}
.chip-completed{background:var(--green-bg);color:var(--green-text);}
.chip-skipped  {background:var(--s50);color:var(--st500);}

/* ── Action buttons ── */
.btn-ok{background:linear-gradient(135deg,var(--s400),var(--s600));border:none;border-radius:var(--radius-sm);color:white;padding:6px 13px;font-weight:700;font-size:12px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;}
.btn-sk{background:linear-gradient(135deg,var(--st500),var(--st700));border:none;border-radius:var(--radius-sm);color:white;padding:6px 11px;font-weight:700;font-size:12px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;}
.btn-ed{background:linear-gradient(135deg,#5B8FB9,#1A4870);border:none;border-radius:var(--radius-sm);color:white;padding:6px 11px;font-weight:700;font-size:12px;font-family:'Outfit',sans-serif;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:5px;}
.btn-dl{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-sm);color:white;padding:6px 11px;font-weight:700;font-size:12px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;display:inline-flex;align-items:center;gap:5px;}
.btn-ok:hover,.btn-sk:hover,.btn-ed:hover,.btn-dl:hover{transform:translateY(-1px);box-shadow:var(--shadow-soft);}
.btn-save{background:linear-gradient(135deg,var(--s400),var(--s700));border:none;border-radius:var(--radius-md);color:white;padding:11px 26px;font-weight:700;font-size:14px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;display:inline-flex;align-items:center;gap:7px;}
.btn-save:hover{opacity:.9;transform:translateY(-1px);box-shadow:var(--shadow-card);}
.btn-cancel{background:transparent;border:2px solid var(--s200);border-radius:var(--radius-md);color:var(--st500);padding:11px 22px;font-weight:700;font-size:14px;font-family:'Outfit',sans-serif;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:7px;}
.btn-cancel:hover{background:var(--s50);border-color:var(--s300);color:var(--s700);}
.btn-ai{background:linear-gradient(135deg,#9B8FD4,#6B5FA6);border:none;border-radius:var(--radius-md);color:white;padding:9px 18px;font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-ai:hover{opacity:.9;transform:translateY(-1px);color:white;}

/* ── Empty state ── */
.empty-st{text-align:center;padding:40px 20px;color:var(--st300);}
.empty-st i{font-size:2.4rem;display:block;margin-bottom:10px;opacity:.25;}
.empty-st p{margin:0;font-size:13px;}

@media(max-width:768px){
    .sidebar{width:100%;height:auto;position:relative;}
    .content{margin-left:0;padding:14px;}
    .days-g{grid-template-columns:repeat(4,1fr);}
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
        <a href="manage_routines.php" class="active"><i class="fa-solid fa-calendar-check"></i> Manage Routines</a>
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

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <div class="topbar">
        <div>
            <h4><i class="fas fa-calendar-check me-2" style="font-size:18px;color:var(--s500);"></i>Manage Routines</h4>
            <p><i class="fas fa-calendar-day me-1"></i><?= date('l, F j, Y') ?></p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="ai_suggestions.php" class="btn-ai"><i class="fas fa-robot"></i>AI Suggestions</a>
            <form action="/SmartCareGuardian/logout.php" method="POST">
                <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt"></i>Logout</button>
            </form>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert-ok"><i class="fas fa-check-circle"></i><?= htmlspecialchars($_GET['msg']) ?></div>
    <?php endif; ?>

    <!-- Medication CTA Banner -->
    <div class="med-cta">
        <div class="med-cta-icon"><i class="fas fa-pills"></i></div>
        <div style="flex:1;">
            <strong style="color:var(--red-text);font-size:14px;display:block;margin-bottom:3px;">Medications have a dedicated page</strong>
            <p class="mb-0" style="font-size:12px;color:var(--st500);">This page manages routines: meals, exercise, personal care, and other care tasks. Medication scheduling, dosage tracking, and administration records are managed separately.</p>
        </div>
        <a href="manage_medications.php" class="med-cta-btn" style="position:relative;">
            <i class="fas fa-pills"></i>Manage Medications
            <?php if ($pending_meds > 0): ?><span class="med-badge"><?= $pending_meds ?></span><?php endif; ?>
        </a>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon ic-t"><i class="fas fa-list-check"></i></div>
            <div class="stat-num"><?= (int)$sts['tot'] ?></div>
            <div class="stat-lbl">Today's Routines</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon ic-p"><i class="fas fa-clock"></i></div>
            <div class="stat-num"><?= (int)$sts['pend'] ?></div>
            <div class="stat-lbl">Pending</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon ic-d"><i class="fas fa-check-double"></i></div>
            <div class="stat-num"><?= (int)$sts['done'] ?></div>
            <div class="stat-lbl">Completed Today</div>
        </div>
        <div class="stat-card" onclick="location.href='manage_medications.php'" style="cursor:pointer;">
            <div class="stat-icon ic-m"><i class="fas fa-pills"></i></div>
            <div class="stat-num" style="font-size:1.5rem;"><?= $pending_meds > 0 ? $pending_meds : '✓' ?></div>
            <div class="stat-lbl">Pending Medications</div>
        </div>
    </div>

    <!-- Add / Edit Form -->
    <div class="main-card" id="formCard">
        <div class="card-head">
            <h5><i class="fas fa-<?= ($editing||$ai_prefill)?'edit':'plus-circle' ?>"></i>
                <?php
                    if ($ai_prefill && $ai_prefill['action']==='add') echo 'Add Routine — AI Suggestion';
                    elseif ($ai_prefill && $ai_prefill['action']==='edit') echo 'Edit Routine — AI Suggestion';
                    elseif ($editing) echo 'Edit Routine';
                    else echo 'Add New Routine';
                ?>
            </h5>
            <small>Meals · Exercise · Personal Care · Other tasks only</small>
        </div>
        <div class="card-bp">
            <?php if ($ai_prefill): ?>
            <div class="ai-banner">
                <div class="ai-bi"><i class="fas fa-robot"></i></div>
                <div style="flex:1;">
                    <strong style="color:var(--purple-text);">🤖 AI: <?= $ai_prefill['action']==='add'?'Add':'Update' ?> <?= htmlspecialchars(ucfirst($ai_prefill['ai_type'])) ?> routine</strong>
                    <p class="mb-0" style="font-size:12px;color:var(--st500);margin-top:3px;">Form pre-filled from AI analysis. Review & adjust before saving.</p>
                </div>
                <a href="ai_suggestions.php" class="btn-cancel ms-2" style="white-space:nowrap;padding:7px 14px;font-size:12px;"><i class="fas fa-arrow-left"></i>Back</a>
            </div>
            <?php endif; ?>

            <form method="POST" action="routines_action.php" id="routineForm">
                <input type="hidden" name="action" value="<?= $editing?'edit':'add' ?>">
                <?php if ($editing): ?>
                    <input type="hidden" name="routine_id" value="<?= (int)$editing['id'] ?>">
                <?php endif; ?>

                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="sec-div"><i class="fas fa-user" style="color:var(--s400);"></i>Basic Details</div>

                        <div class="mb-3">
                            <label class="form-label">Resident *</label>
                            <select name="resident_id" class="form-select" required>
                                <option value="">— Select Resident —</option>
                                <?php $residents->data_seek(0); while($r=$residents->fetch_assoc()): ?>
                                    <option value="<?= $r['user_id'] ?>"
                                        <?php
                                        if ($editing && $editing['resident_id']==$r['user_id']) echo 'selected';
                                        elseif (!$editing && $ai_prefill && $ai_prefill['resident_id']==$r['user_id']) echo 'selected';
                                        ?>>
                                        <?= htmlspecialchars($r['full_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Type *</label>
                            <select name="routine_type" class="form-select" required>
                                <option value="">— Select Type —</option>
                                <?php foreach ($types as $k=>$v): ?>
                                    <option value="<?= $k ?>"
                                        <?php
                                        if ($editing && $editing['routine_type']==$k) echo 'selected';
                                        elseif (!$editing && $ai_prefill && $ai_prefill['routine_type']==$k) echo 'selected';
                                        ?>>
                                        <?= $v ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color:var(--red-text);display:block;margin-top:4px;font-size:12px;"><i class="fas fa-pills me-1"></i>For medications, use <a href="manage_medications.php" style="color:var(--red-text);">Manage Medications</a></small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Schedule Time *</label>
                            <input type="time" name="schedule_time" class="form-control" required
                                   value="<?= $editing ? $editing['schedule_time'] : ($ai_prefill ? $ai_prefill['schedule_time'] : '') ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description *</label>
                            <textarea name="description" class="form-control" rows="3" required
                                      placeholder="Describe the care task..."><?= $editing ? htmlspecialchars($editing['description']) : ($ai_prefill ? htmlspecialchars($ai_prefill['description']) : '') ?></textarea>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="sec-div"><i class="fas fa-repeat" style="color:var(--s400);"></i>Schedule & Reminders</div>

                        <div class="mb-3">
                            <label class="form-label">Repeat Days <small style="color:var(--st300);font-weight:400;">(none = one-time)</small></label>
                            <div class="days-g">
                                <?php
                                $sel_d = [];
                                if ($editing && $editing['days_of_week']) $sel_d = array_map('trim', explode(',', $editing['days_of_week']));
                                elseif ($ai_prefill) $sel_d = $days;
                                foreach($days as $d):
                                    $chk = in_array($d, $sel_d);
                                ?>
                                <label class="day-l <?= $chk?'checked':'' ?>">
                                    <input type="checkbox" name="days_of_week[]" value="<?= $d ?>" <?= $chk?'checked':'' ?>>
                                    <span><?= substr($d,0,3) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Repeat Every (hours) <small style="color:var(--st300);font-weight:400;">— 0 = no intra-day repeat</small></label>
                            <input type="number" name="repeat_hours" class="form-control" min="0" max="168"
                                   value="<?= $editing ? (int)$editing['repeat_hours'] : 0 ?>">
                        </div>

                        <div class="mb-3">
                            <div class="reminder-box">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="send_reminder" id="remCb" value="1"
                                           <?= $editing && $editing['send_reminder'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="remCb">
                                        <i class="fas fa-bell me-2" style="color:var(--s400);"></i>Send email reminder (10 min before)
                                    </label>
                                </div>
                                <p class="mb-0 mt-2" style="font-size:12px;color:var(--st300);padding-left:26px;">
                                    Automated email sent to caregiver and resident before the routine time.
                                    Requires cron job: <code>send_reminders.php</code> every 5 min.
                                </p>
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap mt-3">
                            <button type="submit" class="btn-save">
                                <i class="fas fa-<?= $editing?'save':'plus' ?>"></i>
                                <?= $editing ? 'Update Routine' : 'Save Routine' ?>
                            </button>
                            <?php if ($editing || $ai_prefill): ?>
                                <a href="<?= $ai_prefill ? 'ai_suggestions.php' : 'manage_routines.php' ?>" class="btn-cancel">
                                    <i class="fas fa-times"></i>Cancel
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Today's Schedule -->
    <div class="main-card">
        <div class="card-head">
            <h5><i class="fas fa-sun"></i>Today's Schedule — <?= date('l, M j') ?></h5>
            <small><?= (int)$sts['done'] ?> / <?= (int)$sts['tot'] ?> completed</small>
        </div>
        <div class="card-bp">
            <?php if ($todayTasks->num_rows === 0): ?>
                <div class="empty-st">
                    <i class="fas fa-calendar-plus"></i>
                    <p>No routines scheduled for today. Add one above.</p>
                </div>
            <?php else: ?>
                <?php while ($r = $todayTasks->fetch_assoc()):
                    $ts = $r['today_status'];
                ?>
                <div class="task-item <?= $ts ?>">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <div class="res-av"><?= strtoupper(substr($r['resident_name'],0,1)) ?></div>
                        <div style="flex:1;min-width:180px;">
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <strong style="font-size:14px;color:var(--s800);"><?= htmlspecialchars($r['resident_name']) ?></strong>
                                <span class="type-chip"><?= ucfirst(str_replace('_',' ',$r['routine_type'])) ?></span>
                                <span class="time-lbl"><i class="far fa-clock me-1"></i><?= date('g:i A', strtotime($r['schedule_time'])) ?></span>
                            </div>
                            <div style="font-size:13px;color:var(--st500);"><?= htmlspecialchars($r['description']) ?></div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if ($ts === 'pending'): ?>
                                <a href="complete_routine.php?id=<?= $r['id'] ?>&date=<?= $today_date ?>" class="btn-ok"><i class="fas fa-check"></i> Done</a>
                                <a href="skip_routine.php?id=<?= $r['id'] ?>&date=<?= $today_date ?>" class="btn-sk" onclick="return confirm('Mark as skipped for today?')"><i class="fas fa-forward"></i> Skip</a>
                            <?php elseif ($ts === 'completed'): ?>
                                <span class="chip chip-completed"><i class="fas fa-check me-1"></i>Done</span>
                                <?php if ($r['completed_at']): ?>
                                    <small style="font-size:11px;color:var(--st300);align-self:center;"><?= date('g:i A',strtotime($r['completed_at'])) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="chip chip-skipped"><i class="fas fa-forward me-1"></i>Skipped</span>
                            <?php endif; ?>
                            <a href="manage_routines.php?edit=<?= $r['id'] ?>" class="btn-ed"><i class="fas fa-edit"></i></a>
                            <form action="routines_action.php" method="POST" class="d-inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="routine_id" value="<?= $r['id'] ?>">
                                <button class="btn-dl" onclick="return confirm('Delete routine?')"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- All Routines -->
    <div class="main-card">
        <div class="card-head">
            <h5><i class="fas fa-list-alt"></i>All Routines</h5>
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
                    <div class="col-lg-2 col-md-6">
                        <label class="form-label" style="font-size:12px;">Type</label>
                        <select name="f_type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <?php foreach($types as $k=>$v): ?>
                                <option value="<?= $k ?>" <?= $f_type==$k?'selected':'' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label class="form-label" style="font-size:12px;">Day</label>
                        <select name="f_day" class="form-select form-select-sm">
                            <option value="">Any Day</option>
                            <?php foreach($days as $d): ?>
                                <option value="<?= $d ?>" <?= $f_day==$d?'selected':'' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2">
                        <button type="submit" class="btn-save w-100" style="padding:9px;font-size:13px;justify-content:center;"><i class="fas fa-filter"></i>Filter</button>
                    </div>
                    <div class="col-lg-2">
                        <a href="manage_routines.php" class="btn-cancel w-100 justify-content-center" style="padding:9px;font-size:13px;"><i class="fas fa-times"></i>Clear</a>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr><th>Time</th><th>Type</th><th>Resident</th><th>Description</th><th>Days</th><th>Repeat</th><th>🔔</th><th>Today</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($listRes->num_rows === 0): ?>
                        <tr><td colspan="9" class="py-3">
                            <div class="empty-st" style="padding:24px 0;"><i class="fas fa-search"></i><p>No routines match.</p></div>
                        </td></tr>
                    <?php else: ?>
                        <?php while($r=$listRes->fetch_assoc()): ?>
                        <tr>
                            <td><strong style="color:var(--s800);"><?= date('g:i A',strtotime($r['schedule_time'])) ?></strong></td>
                            <td><span class="type-chip"><?= ucfirst(str_replace('_',' ',$r['routine_type'])) ?></span></td>
                            <td><?= htmlspecialchars($r['resident_name']) ?></td>
                            <td style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($r['description']) ?>"><?= htmlspecialchars($r['description']) ?></td>
                            <td><small style="color:var(--st300);"><?= $r['days_of_week'] ? htmlspecialchars($r['days_of_week']) : '<em>Once</em>' ?></small></td>
                            <td><?= (int)$r['repeat_hours']>0 ? '<small style="color:var(--st500);">'.((int)$r['repeat_hours']).'h</small>' : '—' ?></td>
                            <td><?= $r['send_reminder'] ? '<i class="fas fa-bell" style="color:var(--amber-text);"></i>' : '<i class="fas fa-bell-slash" style="color:var(--st300);opacity:.4;"></i>' ?></td>
                            <td><span class="chip chip-<?= $r['today_status'] ?>"><?= ucfirst($r['today_status']) ?></span></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="manage_routines.php?edit=<?= $r['id'] ?>" class="btn-ed"><i class="fas fa-edit"></i></a>
                                    <form action="routines_action.php" method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="routine_id" value="<?= $r['id'] ?>">
                                        <button class="btn-dl" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></button>
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
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($ai_prefill): ?>
    document.getElementById('formCard').scrollIntoView({ behavior:'smooth', block:'start' });
    <?php endif; ?>

    document.querySelectorAll('.day-l').forEach(l => {
        const inp = l.querySelector('input');
        inp.addEventListener('change', () => l.classList.toggle('checked', inp.checked));
    });

    document.querySelectorAll('select[name^="f_"]').forEach(s => s.addEventListener('change', () => s.form.submit()));

    document.getElementById('routineForm')?.addEventListener('submit', function(e) {
        if (!this.querySelector('[name="resident_id"]').value) {
            e.preventDefault(); alert('Please select a resident.'); return;
        }
        const sub = this.querySelector('button[type="submit"]');
        sub.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
        sub.disabled = true;
    });
});
</script>
</body>
</html>