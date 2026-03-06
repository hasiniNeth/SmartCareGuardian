<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php");
    exit();
}
date_default_timezone_set('Asia/Colombo');

include '../db_connection.php';

$caregiver_id = $_SESSION['user_id'];

$cg_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$cg_stmt->bind_param("i", $caregiver_id);
$cg_stmt->execute();
$caregiver = $cg_stmt->get_result()->fetch_assoc();
$cg_stmt->close();

$res_stmt = $conn->prepare("SELECT u.user_id, u.full_name FROM users u JOIN caregiver_assignments ca ON ca.resident_id = u.user_id WHERE ca.caregiver_id = ? AND u.role = 'resident' ORDER BY u.full_name");
$res_stmt->bind_param("i", $caregiver_id);
$res_stmt->execute();
$residents = $res_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$res_stmt->close();

$assigned_ids = array_column($residents, 'user_id');

$filter_resident = isset($_GET['resident_id']) && $_GET['resident_id'] !== '' ? (int)$_GET['resident_id'] : null;
$filter_from     = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$filter_to       = $_GET['to']   ?? date('Y-m-d');

if ($filter_resident && !in_array($filter_resident, $assigned_ids)) { $filter_resident = null; }
$query_ids = $filter_resident ? [$filter_resident] : $assigned_ids;

if (empty($query_ids)) {
    $health_logs = $vitals_chart = $med_stats = $routine_stats = $appt_stats = $alert_stats = [];
    $summary = ['logs'=>0,'meds_taken'=>0,'meds_total'=>0,'routines_done'=>0,'routines_total'=>0,'appts'=>0,'alerts'=>0];
} else {
    $ph    = implode(',', array_fill(0, count($query_ids), '?'));
    $types = str_repeat('i', count($query_ids));

    $s1 = $conn->prepare("SELECT COUNT(*) AS cnt FROM health_logs WHERE resident_id IN ($ph) AND DATE(logged_at) BETWEEN ? AND ?");
    $s1->bind_param($types.'ss', ...[...$query_ids, $filter_from, $filter_to]);
    $s1->execute(); $logs_count = $s1->get_result()->fetch_assoc()['cnt']; $s1->close();

    $s2 = $conn->prepare("SELECT COUNT(*) AS total, SUM(taken) AS taken_count FROM medications WHERE resident_id IN ($ph) AND medication_date BETWEEN ? AND ?");
    $s2->bind_param($types.'ss', ...[...$query_ids, $filter_from, $filter_to]);
    $s2->execute(); $med_row = $s2->get_result()->fetch_assoc(); $s2->close();

    $s3 = $conn->prepare("SELECT COUNT(*) AS total, SUM(rl.status = 'completed') AS done FROM routine_logs rl JOIN routines r ON r.id = rl.routine_id WHERE r.resident_id IN ($ph) AND rl.log_date BETWEEN ? AND ?");
    $s3->bind_param($types.'ss', ...[...$query_ids, $filter_from, $filter_to]);
    $s3->execute(); $routine_row = $s3->get_result()->fetch_assoc(); $s3->close();

    $s4 = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE resident_id IN ($ph) AND appointment_date BETWEEN ? AND ?");
    $s4->bind_param($types.'ss', ...[...$query_ids, $filter_from, $filter_to]);
    $s4->execute(); $appt_count = $s4->get_result()->fetch_assoc()['cnt']; $s4->close();

    $s5 = $conn->prepare("SELECT COUNT(*) AS cnt FROM alerts WHERE resident_id IN ($ph) AND DATE(created_at) BETWEEN ? AND ?");
    $s5->bind_param($types.'ss', ...[...$query_ids, $filter_from, $filter_to]);
    $s5->execute(); $alert_count = $s5->get_result()->fetch_assoc()['cnt']; $s5->close();

    $summary = [
        'logs'           => (int)$logs_count,
        'meds_taken'     => (int)($med_row['taken_count'] ?? 0),
        'meds_total'     => (int)($med_row['total'] ?? 0),
        'routines_done'  => (int)($routine_row['done'] ?? 0),
        'routines_total' => (int)($routine_row['total'] ?? 0),
        'appts'          => (int)$appt_count,
        'alerts'         => (int)$alert_count,
    ];

    $vc = $conn->prepare("SELECT DATE(logged_at) AS log_date, ROUND(AVG(blood_pressure_systolic),1) AS avg_bp_sys, ROUND(AVG(blood_pressure_diastolic),1) AS avg_bp_dia, ROUND(AVG(blood_sugar),1) AS avg_sugar, ROUND(AVG(pulse),1) AS avg_pulse, ROUND(AVG(temperature),1) AS avg_temp, ROUND(AVG(oxygen_saturation),1) AS avg_o2 FROM health_logs WHERE resident_id IN ($ph) AND DATE(logged_at) BETWEEN ? AND ? GROUP BY DATE(logged_at) ORDER BY DATE(logged_at) ASC");
    $vc->bind_param($types.'ss', ...[...$query_ids, $filter_from, $filter_to]);
    $vc->execute(); $vitals_chart = $vc->get_result()->fetch_all(MYSQLI_ASSOC); $vc->close();

    $mc = $conn->prepare("SELECT u.full_name, COUNT(*) AS total, SUM(m.taken) AS taken_count, ROUND(100 * SUM(m.taken) / NULLIF(COUNT(*),0), 1) AS adherence_pct FROM medications m JOIN users u ON u.user_id = m.resident_id WHERE m.resident_id IN ($ph) AND m.medication_date BETWEEN ? AND ? GROUP BY m.resident_id, u.full_name ORDER BY u.full_name");
    $mc->bind_param($types.'ss', ...[...$query_ids, $filter_from, $filter_to]);
    $mc->execute(); $med_stats = $mc->get_result()->fetch_all(MYSQLI_ASSOC); $mc->close();

    $rc = $conn->prepare("SELECT r.routine_type, COUNT(*) AS total, SUM(rl.status = 'completed') AS completed, SUM(rl.status = 'skipped') AS skipped FROM routine_logs rl JOIN routines r ON r.id = rl.routine_id WHERE r.resident_id IN ($ph) AND rl.log_date BETWEEN ? AND ? GROUP BY r.routine_type ORDER BY total DESC");
    $rc->bind_param($types.'ss', ...[...$query_ids, $filter_from, $filter_to]);
    $rc->execute(); $routine_stats = $rc->get_result()->fetch_all(MYSQLI_ASSOC); $rc->close();

    $ac = $conn->prepare("SELECT a.status, COUNT(*) AS cnt, a.title, a.appointment_date, a.appointment_time, u.full_name AS resident_name FROM appointments a JOIN users u ON u.user_id = a.resident_id WHERE a.resident_id IN ($ph) AND a.appointment_date BETWEEN ? AND ? ORDER BY a.appointment_date DESC LIMIT 20");
    $ac->bind_param($types.'ss', ...[...$query_ids, $filter_from, $filter_to]);
    $ac->execute(); $appt_list = $ac->get_result()->fetch_all(MYSQLI_ASSOC); $ac->close();

    $asc = $conn->prepare("SELECT status, COUNT(*) AS cnt FROM appointments WHERE resident_id IN ($ph) AND appointment_date BETWEEN ? AND ? GROUP BY status");
    $asc->bind_param($types.'ss', ...[...$query_ids, $filter_from, $filter_to]);
    $asc->execute(); $appt_status_rows = $asc->get_result()->fetch_all(MYSQLI_ASSOC); $asc->close();
    $appt_by_status = [];
    foreach ($appt_status_rows as $row) $appt_by_status[$row['status']] = $row['cnt'];

    $alc = $conn->prepare("SELECT alert_type, COUNT(*) AS cnt, SUM(resolved) AS resolved_cnt FROM alerts WHERE resident_id IN ($ph) AND DATE(created_at) BETWEEN ? AND ? GROUP BY alert_type ORDER BY cnt DESC");
    $alc->bind_param($types.'ss', ...[...$query_ids, $filter_from, $filter_to]);
    $alc->execute(); $alert_stats = $alc->get_result()->fetch_all(MYSQLI_ASSOC); $alc->close();

    $hl = $conn->prepare("SELECT hl.*, u.full_name AS resident_name FROM health_logs hl JOIN users u ON u.user_id = hl.resident_id WHERE hl.resident_id IN ($ph) AND DATE(hl.logged_at) BETWEEN ? AND ? ORDER BY hl.logged_at DESC LIMIT 15");
    $hl->bind_param($types.'ss', ...[...$query_ids, $filter_from, $filter_to]);
    $hl->execute(); $health_logs = $hl->get_result()->fetch_all(MYSQLI_ASSOC); $hl->close();
}

$pending_meds = 0;
if (!empty($assigned_ids)) {
    $pm_ph = implode(',', array_fill(0, count($assigned_ids), '?'));
    $pm_types = str_repeat('i', count($assigned_ids));
    $pm = $conn->prepare("SELECT COUNT(*) AS cnt FROM medications WHERE resident_id IN ($pm_ph) AND taken=0 AND medication_date=CURDATE()");
    $pm->bind_param($pm_types, ...$assigned_ids);
    $pm->execute(); $pending_meds = $pm->get_result()->fetch_assoc()['cnt'] ?? 0; $pm->close();
}

$um = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id=? AND is_read=0");
$um->bind_param("i", $caregiver_id);
$um->execute(); $unread_msgs = $um->get_result()->fetch_assoc()['cnt'] ?? 0; $um->close();

$chart_labels  = json_encode(array_column($vitals_chart, 'log_date'));
$chart_bp_sys  = json_encode(array_column($vitals_chart, 'avg_bp_sys'));
$chart_bp_dia  = json_encode(array_column($vitals_chart, 'avg_bp_dia'));
$chart_sugar   = json_encode(array_column($vitals_chart, 'avg_sugar'));
$chart_pulse   = json_encode(array_column($vitals_chart, 'avg_pulse'));
$chart_o2      = json_encode(array_column($vitals_chart, 'avg_o2'));
$chart_temp    = json_encode(array_column($vitals_chart, 'avg_temp'));
$med_names     = json_encode(array_column($med_stats, 'full_name'));
$med_pcts      = json_encode(array_column($med_stats, 'adherence_pct'));
$routine_labels    = json_encode(array_map(fn($r) => ucfirst(str_replace('_',' ',$r['routine_type'])), $routine_stats));
$routine_completed = json_encode(array_column($routine_stats, 'completed'));
$routine_skipped   = json_encode(array_column($routine_stats, 'skipped'));
$routine_pending   = json_encode(array_map(fn($r) => $r['total'] - $r['completed'] - $r['skipped'], $routine_stats));

$unread_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND is_read = 0");
$unread_stmt->bind_param("i", $caregiver_id);
$unread_stmt->execute();
$unread_messages = $unread_stmt->get_result()->fetch_assoc()['cnt'];
$unread_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Reports – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
    /* ═══════════════════════════════════════════════════════════
       SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
       Health Reports · Professional scale (15px base)
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
    .topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;}
    .topbar h4{font-size:22px;font-weight:500;color:var(--s800);margin-bottom:2px;}
    .topbar p{font-size:13px;color:var(--st300);margin:0;}
    .logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;}
    .logout-btn:hover{opacity:.9;transform:translateY(-1px);}
    .btn-reset{background:white;border:2px solid var(--s100);border-radius:var(--radius-md);padding:8px 18px;color:var(--st500);font-weight:700;font-family:'Outfit',sans-serif;font-size:13px;transition:all .2s;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
    .btn-reset:hover{border-color:var(--s400);color:var(--s700);}

    /* ── Filter bar ── */
    .filter-bar{background:white;border-radius:var(--radius-lg);padding:18px 22px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
    .filter-bar label{font-weight:700;font-size:12px;color:var(--s800);margin-bottom:4px;display:block;text-transform:uppercase;letter-spacing:.05em;}
    .filter-bar select,
    .filter-bar input[type=date]{border:2px solid var(--s100);border-radius:var(--radius-md);padding:8px 12px;font-size:13px;font-family:'Outfit',sans-serif;background:var(--w50);color:var(--st700);transition:border-color .2s;}
    .filter-bar select:focus,
    .filter-bar input[type=date]:focus{border-color:var(--s400);outline:none;}
    .btn-filter{background:linear-gradient(135deg,var(--s400),var(--s700));border:none;border-radius:var(--radius-md);padding:10px 22px;color:white;font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;}
    .btn-filter:hover{opacity:.9;transform:translateY(-1px);}

    /* ── Stats grid ── */
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:16px;margin-bottom:22px;}
    .stat-card{background:white;border-radius:var(--radius-lg);padding:18px 16px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);text-align:center;transition:transform .2s;position:relative;overflow:hidden;}
    .stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lift);}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:var(--bar-color,linear-gradient(90deg,var(--s400),var(--s700)));}
    .stat-icon{width:48px;height:48px;border-radius:var(--radius-md);margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;}
    .stat-number{font-size:1.8rem;font-weight:800;color:var(--s800);line-height:1;}
    .stat-sub  {font-size:11px;color:var(--st300);margin-top:2px;}
    .stat-label{font-size:12px;font-weight:700;color:var(--st500);margin-top:5px;}

    /* ── Section cards ── */
    .section-card{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;overflow:hidden;}
    .section-header{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:15px 22px;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;}
    .section-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
    .section-header h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;position:relative;display:flex;align-items:center;gap:7px;}
    .section-header span{color:rgba(255,255,255,.75);font-size:12px;position:relative;}
    .section-body{padding:20px 22px;}

    /* ── Chart wrappers ── */
    .chart-wrap   {position:relative;height:280px;}
    .chart-wrap-sm{position:relative;height:220px;}

    /* ── Medication adherence bars ── */
    .adh-row  {margin-bottom:14px;}
    .adh-name {font-weight:700;font-size:13px;color:var(--s800);margin-bottom:4px;}
    .adh-track{height:10px;background:var(--s100);border-radius:8px;overflow:hidden;}
    .adh-fill {height:100%;border-radius:8px;transition:width 1s ease;}
    .adh-label{font-size:12px;color:var(--st300);margin-top:3px;}

    /* ── Routine type badges ── */
    .rt-badge       {display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;}
    .rt-meal        {background:var(--amber-bg);color:var(--amber-text);}
    .rt-exercise    {background:var(--green-bg); color:var(--green-text);}
    .rt-personal_care{background:var(--blue-bg);color:var(--blue-text);}
    .rt-other       {background:var(--s50);      color:var(--s700);}

    /* ── Report table ── */
    .report-table{width:100%;border-collapse:collapse;font-size:13px;}
    .report-table th{background:var(--s50);color:var(--s800);font-weight:700;padding:10px 13px;text-align:left;border-bottom:2px solid var(--s100);font-family:'Outfit',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.05em;}
    .report-table td{padding:9px 13px;border-bottom:1px solid var(--s50);color:var(--st700);}
    .report-table tr:last-child td{border-bottom:none;}
    .report-table tr:hover td{background:var(--s50);}
    .report-table tbody tr{transition:background .15s;}

    /* vitals coloring */
    .vital-high{color:var(--red-text);font-weight:700;}
    .vital-low {color:var(--blue-text);font-weight:700;}
    .vital-ok  {color:var(--green-text);}

    /* appt status */
    .badge-scheduled{background:var(--blue-bg); color:var(--blue-text); padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;}
    .badge-completed{background:var(--green-bg);color:var(--green-text);padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;}
    .badge-cancelled{background:var(--red-bg);  color:var(--red-text);  padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;}

    /* ── Alert breakdown ── */
    .alert-row{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--s50);}
    .alert-row:last-child{border-bottom:none;}
    .alert-type-pill{padding:4px 11px;border-radius:20px;font-size:11px;font-weight:700;min-width:120px;text-align:center;}
    .atp-health_warning{background:var(--green-bg);color:var(--green-text);}
    .atp-critical      {background:var(--red-bg);  color:var(--red-text);}
    .atp-warning       {background:var(--amber-bg);color:var(--amber-text);}
    .atp-general       {background:var(--s50);     color:var(--st500);}
    .alert-bar-track{flex:1;height:7px;background:var(--s100);border-radius:6px;overflow:hidden;}
    .alert-bar-fill {height:100%;border-radius:6px;background:linear-gradient(90deg,#C87A7A,#8B3A3A);}
    .alert-nums{font-size:12px;color:var(--st300);white-space:nowrap;}

    /* ── Empty state ── */
    .empty-state{text-align:center;padding:44px 20px;color:var(--st300);}
    .empty-state i{font-size:2.8rem;margin-bottom:12px;display:block;opacity:.25;}

    /* ── Chart tabs ── */
    .chart-tabs{display:flex;gap:7px;margin-bottom:16px;flex-wrap:wrap;}
    .ctab{padding:6px 15px;border-radius:25px;font-size:12px;font-weight:700;cursor:pointer;border:2px solid var(--s100);background:white;color:var(--st500);font-family:'Outfit',sans-serif;transition:all .2s;}
    .ctab.active{background:linear-gradient(135deg,var(--s500),var(--s800));color:white;border-color:transparent;}

    /* ── Reveal animation ── */
    .reveal{opacity:0;transform:translateY(14px);transition:opacity .5s ease,transform .5s ease;}
    .revealed{opacity:1;transform:none;}

    /* ── Print ── */
    .no-print{}
    @media print{
        .sidebar,.filter-bar,.no-print,.topbar{display:none!important;}
        .content{margin-left:0!important;padding:10px!important;}
        .section-card{box-shadow:none!important;border:1px solid #ddd!important;}
        .chart-wrap,.chart-wrap-sm{height:200px!important;}
    }

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
        <a href="ai_suggestions.php"><i class="fa-solid fa-wand-magic-sparkles"></i>Routine Suggestions</a>
        <a href="caregiver_reports.php" class="active"><i class="fa-solid fa-chart-line"></i>Health Reports</a>
    </div>
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <div class="topbar d-flex justify-content-between align-items-center no-print">
        <div>
            <h4><i class="fas fa-chart-line me-2" style="font-size:18px;color:var(--s500);"></i>Health Reports</h4>
            <p>Vitals trends, medication adherence &amp; care summaries</p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn-reset"><i class="fas fa-print"></i>Print</button>
            <form action="/SmartCareGuardian/logout.php" method="POST" class="d-inline">
                <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt me-2"></i>Logout</button>
            </form>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" class="filter-bar no-print">
        <div>
            <label>Resident</label>
            <select name="resident_id">
                <option value="">All Residents</option>
                <?php foreach ($residents as $r): ?>
                    <option value="<?= $r['user_id'] ?>" <?= $filter_resident == $r['user_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($filter_from) ?>"></div>
        <div><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($filter_to) ?>"></div>
        <div>
            <label>Quick Range</label>
            <select id="quick-range" onchange="applyQuickRange(this.value)">
                <option value="">Custom</option>
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
                <option value="90">Last 3 months</option>
            </select>
        </div>
        <div class="d-flex gap-2 align-items-end">
            <button type="submit" class="btn-filter"><i class="fas fa-filter me-1"></i>Apply</button>
            <a href="caregiver_reports.php" class="btn-reset">Reset</a>
        </div>
    </form>

    <?php if (empty($residents)): ?>
    <div class="section-card">
        <div class="section-body">
            <div class="empty-state">
                <i class="fas fa-users-slash"></i>
                <h5 style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--st500);">No Residents Assigned</h5>
                <p>Reports will appear once residents are assigned to you.</p>
            </div>
        </div>
    </div>
    <?php else: ?>

    <!-- Summary Stats -->
    <div class="stats-grid reveal">
        <div class="stat-card" style="--bar-color:linear-gradient(90deg,var(--s400),var(--s600))">
            <div class="stat-icon" style="background:linear-gradient(135deg,var(--s400),var(--s600));color:white;"><i class="fas fa-heart-pulse"></i></div>
            <div class="stat-number"><?= $summary['logs'] ?></div>
            <div class="stat-label">Health Readings</div>
            <div class="stat-sub">in selected period</div>
        </div>

        <?php $adh_pct = $summary['meds_total'] > 0 ? round(100 * $summary['meds_taken'] / $summary['meds_total']) : 0; ?>
        <div class="stat-card" style="--bar-color:linear-gradient(90deg,#22c55e,#15803d)">
            <div class="stat-icon" style="background:linear-gradient(135deg,#22c55e,#15803d);color:white;"><i class="fas fa-pills"></i></div>
            <div class="stat-number"><?= $adh_pct ?>%</div>
            <div class="stat-label">Med Adherence</div>
            <div class="stat-sub"><?= $summary['meds_taken'] ?> / <?= $summary['meds_total'] ?> doses taken</div>
        </div>

        <?php $rt_pct = $summary['routines_total'] > 0 ? round(100 * $summary['routines_done'] / $summary['routines_total']) : 0; ?>
        <div class="stat-card" style="--bar-color:linear-gradient(90deg,#3b82f6,#1d4ed8)">
            <div class="stat-icon" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:white;"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-number"><?= $rt_pct ?>%</div>
            <div class="stat-label">Routine Completion</div>
            <div class="stat-sub"><?= $summary['routines_done'] ?> / <?= $summary['routines_total'] ?> tasks done</div>
        </div>

        <div class="stat-card" style="--bar-color:linear-gradient(90deg,#8b5cf6,#6d28d9)">
            <div class="stat-icon" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:white;"><i class="fas fa-calendar-days"></i></div>
            <div class="stat-number"><?= $summary['appts'] ?></div>
            <div class="stat-label">Appointments</div>
            <div class="stat-sub"><?= ($appt_by_status['completed'] ?? 0) ?> completed</div>
        </div>

        <div class="stat-card" style="--bar-color:linear-gradient(90deg,#f59e0b,#d97706)">
            <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:white;"><i class="fas fa-bell"></i></div>
            <div class="stat-number"><?= $summary['alerts'] ?></div>
            <div class="stat-label">Health Alerts</div>
            <div class="stat-sub">in selected period</div>
        </div>
    </div>

    <!-- Vitals Trend Chart -->
    <div class="section-card reveal">
        <div class="section-header">
            <h5><i class="fas fa-chart-line"></i>Vitals Trends Over Time</h5>
            <span><?= htmlspecialchars($filter_from) ?> → <?= htmlspecialchars($filter_to) ?></span>
        </div>
        <div class="section-body">
            <?php if (empty($vitals_chart)): ?>
                <div class="empty-state"><i class="fas fa-chart-line"></i><p>No health readings logged in this period.</p></div>
            <?php else: ?>
                <div class="chart-tabs no-print">
                    <button class="ctab active" data-chart="bp">Blood Pressure</button>
                    <button class="ctab" data-chart="sugar">Blood Sugar</button>
                    <button class="ctab" data-chart="pulse">Pulse</button>
                    <button class="ctab" data-chart="o2">Oxygen</button>
                    <button class="ctab" data-chart="temp">Temperature</button>
                </div>
                <div class="chart-wrap"><canvas id="vitalsChart"></canvas></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Med Adherence + Routine Completion -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="section-card h-100 reveal">
                <div class="section-header">
                    <h5><i class="fas fa-pills"></i>Medication Adherence</h5>
                    <span>by resident</span>
                </div>
                <div class="section-body">
                    <?php if (empty($med_stats)): ?>
                        <div class="empty-state"><i class="fas fa-pills"></i><p>No medication records in this period.</p></div>
                    <?php else: ?>
                        <div class="chart-wrap-sm mb-4"><canvas id="medChart"></canvas></div>
                        <?php foreach ($med_stats as $m):
                            $pct = (float)($m['adherence_pct'] ?? 0);
                            $color = $pct >= 80 ? '#22c55e' : ($pct >= 50 ? '#f59e0b' : '#ef4444');
                        ?>
                        <div class="adh-row">
                            <div class="adh-name"><?= htmlspecialchars($m['full_name']) ?></div>
                            <div class="adh-track"><div class="adh-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div></div>
                            <div class="adh-label"><?= $pct ?>% — <?= $m['taken_count'] ?>/<?= $m['total'] ?> doses taken</div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="section-card h-100 reveal">
                <div class="section-header">
                    <h5><i class="fas fa-calendar-check"></i>Routine Completion</h5>
                    <span>by type</span>
                </div>
                <div class="section-body">
                    <?php if (empty($routine_stats)): ?>
                        <div class="empty-state"><i class="fas fa-calendar-check"></i><p>No routine activity in this period.</p></div>
                    <?php else: ?>
                        <div class="chart-wrap-sm mb-4"><canvas id="routineChart"></canvas></div>
                        <table class="report-table">
                            <thead><tr><th>Type</th><th>Total</th><th>Done</th><th>Skipped</th><th>Rate</th></tr></thead>
                            <tbody>
                                <?php foreach ($routine_stats as $rt):
                                    $rate = $rt['total'] > 0 ? round(100 * $rt['completed'] / $rt['total']) : 0;
                                ?>
                                <tr>
                                    <td><span class="rt-badge rt-<?= $rt['routine_type'] ?>"><?= ucfirst(str_replace('_',' ',$rt['routine_type'])) ?></span></td>
                                    <td><?= $rt['total'] ?></td>
                                    <td style="color:var(--green-text);font-weight:700;"><?= $rt['completed'] ?></td>
                                    <td style="color:var(--amber-text);font-weight:700;"><?= $rt['skipped'] ?></td>
                                    <td><span style="color:<?= $rate>=80?'var(--green-text)':($rate>=50?'var(--amber-text)':'var(--red-text)') ?>;font-weight:800;"><?= $rate ?>%</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Health Readings -->
    <div class="section-card reveal">
        <div class="section-header">
            <h5><i class="fas fa-table"></i>Recent Health Readings</h5>
            <span>Latest 15 entries</span>
        </div>
        <div class="section-body" style="overflow-x:auto;">
            <?php if (empty($health_logs)): ?>
                <div class="empty-state"><i class="fas fa-file-medical"></i><p>No health readings found for this period.</p></div>
            <?php else: ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <?php if (!$filter_resident): ?><th>Resident</th><?php endif; ?>
                        <th>BP (sys/dia)</th><th>Sugar</th><th>Pulse</th><th>Temp °C</th><th>O₂ %</th><th>Weight kg</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($health_logs as $log):
                        $bp_class  = ($log['blood_pressure_systolic'] > 140 || $log['blood_pressure_systolic'] < 90) ? 'vital-high' : 'vital-ok';
                        $sug_class = ($log['blood_sugar'] > 180 || $log['blood_sugar'] < 70) ? 'vital-high' : 'vital-ok';
                        $pul_class = ($log['pulse'] > 100 || $log['pulse'] < 50) ? 'vital-high' : 'vital-ok';
                        $tmp_class = ($log['temperature'] > 37.8 || $log['temperature'] < 36.0) ? 'vital-high' : 'vital-ok';
                        $o2_class  = ($log['oxygen_saturation'] < 95) ? 'vital-high' : 'vital-ok';
                    ?>
                    <tr>
                        <td><?= date('d M Y, H:i', strtotime($log['logged_at'])) ?></td>
                        <?php if (!$filter_resident): ?><td><?= htmlspecialchars($log['resident_name']) ?></td><?php endif; ?>
                        <td class="<?= $bp_class ?>"><?= $log['blood_pressure_systolic'] ?>/<?= $log['blood_pressure_diastolic'] ?></td>
                        <td class="<?= $sug_class ?>"><?= $log['blood_sugar'] ?></td>
                        <td class="<?= $pul_class ?>"><?= $log['pulse'] ?></td>
                        <td class="<?= $tmp_class ?>"><?= $log['temperature'] ?></td>
                        <td class="<?= $o2_class ?>"><?= $log['oxygen_saturation'] ?></td>
                        <td><?= $log['weight'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="color:var(--st300);font-size:12px;margin-top:12px;margin-bottom:0;">
                <i class="fas fa-circle me-1" style="color:var(--red-text);font-size:9px;"></i>Red values indicate out-of-normal-range readings.
                Normal: BP 90–140/60–90 mmHg · Sugar 70–180 mg/dL · Pulse 50–100 bpm · Temp 36.0–37.8 °C · O₂ ≥95%
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Appointments + Alerts -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="section-card h-100 reveal">
                <div class="section-header">
                    <h5><i class="fas fa-calendar-days"></i>Appointments</h5>
                    <div style="font-size:12px;opacity:.85;display:flex;gap:10px;position:relative;">
                        <span><i class="fas fa-circle" style="color:var(--blue-bg);"></i> <?= ($appt_by_status['scheduled'] ?? 0) ?> Scheduled</span>
                        <span><i class="fas fa-circle" style="color:var(--green-bg);"></i> <?= ($appt_by_status['completed'] ?? 0) ?> Done</span>
                        <span><i class="fas fa-circle" style="color:var(--red-bg);"></i> <?= ($appt_by_status['cancelled'] ?? 0) ?> Cancelled</span>
                    </div>
                </div>
                <div class="section-body" style="overflow-x:auto;">
                    <?php if (empty($appt_list)): ?>
                        <div class="empty-state"><i class="fas fa-calendar-days"></i><p>No appointments in this period.</p></div>
                    <?php else: ?>
                    <table class="report-table">
                        <thead><tr><th>Date</th><?php if (!$filter_resident): ?><th>Resident</th><?php endif; ?><th>Title</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($appt_list as $appt): ?>
                            <tr>
                                <td><?= date('d M', strtotime($appt['appointment_date'])) ?><br><small style="color:var(--st300);"><?= date('H:i', strtotime($appt['appointment_time'])) ?></small></td>
                                <?php if (!$filter_resident): ?><td><?= htmlspecialchars($appt['resident_name']) ?></td><?php endif; ?>
                                <td><?= htmlspecialchars($appt['title']) ?></td>
                                <td><span class="badge-<?= $appt['status'] ?>"><?= ucfirst($appt['status']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="section-card h-100 reveal">
                <div class="section-header">
                    <h5><i class="fas fa-bell"></i>Alert Breakdown</h5>
                    <span><?= count($alert_stats) ?> type(s)</span>
                </div>
                <div class="section-body">
                    <?php if (empty($alert_stats)): ?>
                        <div class="empty-state"><i class="fas fa-check-circle"></i><p>No health alerts in this period. All clear!</p></div>
                    <?php else:
                        $max_alerts = max(array_column($alert_stats, 'cnt'));
                    ?>
                        <?php foreach ($alert_stats as $al):
                            $bar_pct = $max_alerts > 0 ? round(100 * $al['cnt'] / $max_alerts) : 0;
                            $type_class = 'atp-' . ($al['alert_type'] ?? 'general');
                        ?>
                        <div class="alert-row">
                            <span class="alert-type-pill <?= htmlspecialchars($type_class) ?>"><?= htmlspecialchars(strtoupper(str_replace('_',' ',$al['alert_type']))) ?></span>
                            <div class="alert-bar-track"><div class="alert-bar-fill" style="width:<?= $bar_pct ?>%"></div></div>
                            <div class="alert-nums"><?= $al['cnt'] ?> total &nbsp;·&nbsp;<span style="color:var(--green-text);"><?= $al['resolved_cnt'] ?> resolved</span></div>
                        </div>
                        <?php endforeach; ?>
                        <p style="color:var(--st300);font-size:12px;margin-top:12px;margin-bottom:0;">
                            Total: <?= $summary['alerts'] ?> alerts ·
                            Resolved: <?= array_sum(array_column($alert_stats,'resolved_cnt')) ?> ·
                            Pending: <?= $summary['alerts'] - array_sum(array_column($alert_stats,'resolved_cnt')) ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>

</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const revealEls = document.querySelectorAll('.reveal');
const revealObs = new IntersectionObserver(entries => {
    entries.forEach((e, i) => {
        if (e.isIntersecting) { setTimeout(() => e.target.classList.add('revealed'), i * 80); revealObs.unobserve(e.target); }
    });
}, { threshold: 0.05 });
revealEls.forEach(el => revealObs.observe(el));

function applyQuickRange(days) {
    if (!days) return;
    const to = new Date(), from = new Date();
    from.setDate(from.getDate() - parseInt(days));
    const fmt = d => d.toISOString().split('T')[0];
    document.querySelector('[name=from]').value = fmt(from);
    document.querySelector('[name=to]').value   = fmt(to);
}

Chart.defaults.font.family = 'Outfit, sans-serif';
Chart.defaults.color = '#7A7268';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.padding = 16;

<?php if (!empty($vitals_chart)): ?>
const labels = <?= $chart_labels ?>;
const bpSys  = <?= $chart_bp_sys ?>;
const bpDia  = <?= $chart_bp_dia ?>;
const sugar  = <?= $chart_sugar ?>;
const pulse  = <?= $chart_pulse ?>;
const o2     = <?= $chart_o2 ?>;
const temp   = <?= $chart_temp ?>;

const chartDatasets = {
    bp:    { datasets:[{ label:'BP Systolic',  data:bpSys, borderColor:'#8B3A3A', backgroundColor:'rgba(139,58,58,.1)', tension:.4, fill:true, pointRadius:4 },{ label:'BP Diastolic', data:bpDia, borderColor:'#A07830', backgroundColor:'rgba(160,120,48,.1)', tension:.4, fill:true, pointRadius:4 }], yLabel:'mmHg', yMin:60, yMax:180 },
    sugar: { datasets:[{ label:'Blood Sugar',  data:sugar, borderColor:'#5A3A7A', backgroundColor:'rgba(90,58,122,.1)', tension:.4, fill:true, pointRadius:4 }], yLabel:'mg/dL', yMin:50, yMax:250 },
    pulse: { datasets:[{ label:'Pulse',        data:pulse, borderColor:'#8B3A6A', backgroundColor:'rgba(139,58,106,.1)', tension:.4, fill:true, pointRadius:4 }], yLabel:'bpm', yMin:40, yMax:140 },
    o2:    { datasets:[{ label:'Oxygen Sat.',  data:o2,    borderColor:'var(--blue-text)', backgroundColor:'rgba(26,72,112,.08)', tension:.4, fill:true, pointRadius:4 }], yLabel:'%', yMin:85, yMax:101 },
    temp:  { datasets:[{ label:'Temperature',  data:temp,  borderColor:'#A07830', backgroundColor:'rgba(160,120,48,.1)', tension:.4, fill:true, pointRadius:4 }], yLabel:'°C', yMin:35, yMax:40 },
};

const vitalsCtx = document.getElementById('vitalsChart').getContext('2d');
let vitalsChart = new Chart(vitalsCtx, {
    type: 'line',
    data: { labels, ...chartDatasets.bp },
    options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ position:'top' }, tooltip:{ mode:'index', intersect:false } },
        scales:{ x:{ grid:{ color:'rgba(36,56,22,.04)' } }, y:{ min:chartDatasets.bp.yMin, max:chartDatasets.bp.yMax, title:{ display:true, text:chartDatasets.bp.yLabel }, grid:{ color:'rgba(36,56,22,.04)' } } },
        interaction:{ mode:'nearest', axis:'x', intersect:false }
    }
});

document.querySelectorAll('.ctab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.ctab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const d = chartDatasets[btn.dataset.chart];
        vitalsChart.data.datasets = d.datasets;
        vitalsChart.options.scales.y.min = d.yMin;
        vitalsChart.options.scales.y.max = d.yMax;
        vitalsChart.options.scales.y.title.text = d.yLabel;
        vitalsChart.update();
    });
});
<?php endif; ?>

<?php if (!empty($med_stats)): ?>
const medCtx = document.getElementById('medChart').getContext('2d');
new Chart(medCtx, {
    type:'bar',
    data:{ labels:<?= $med_names ?>, datasets:[{ label:'Adherence %', data:<?= $med_pcts ?>, backgroundColor:<?= $med_pcts ?>.map(v=>v>=80?'rgba(58,104,48,.75)':v>=50?'rgba(160,120,48,.75)':'rgba(106,32,32,.75)'), borderRadius:8, borderSkipped:false }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:false } }, scales:{ y:{ min:0, max:100, title:{ display:true, text:'%' }, grid:{ color:'rgba(36,56,22,.04)' } }, x:{ grid:{ display:false } } } }
});
<?php endif; ?>

<?php if (!empty($routine_stats)): ?>
const rtCtx = document.getElementById('routineChart').getContext('2d');
new Chart(rtCtx, {
    type:'bar',
    data:{ labels:<?= $routine_labels ?>, datasets:[
        { label:'Completed', data:<?= $routine_completed ?>, backgroundColor:'rgba(58,104,48,.75)', borderRadius:4 },
        { label:'Skipped',   data:<?= $routine_skipped ?>,   backgroundColor:'rgba(160,120,48,.75)', borderRadius:4 },
        { label:'Pending',   data:<?= $routine_pending ?>,   backgroundColor:'rgba(184,176,164,.6)', borderRadius:4 },
    ]},
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'top' } }, scales:{ x:{ stacked:true, grid:{ display:false } }, y:{ stacked:true, grid:{ color:'rgba(36,56,22,.04)' } } } }
});
<?php endif; ?>

document.querySelectorAll('.adh-fill').forEach(bar => {
    const t = bar.style.width; bar.style.width = '0%';
    setTimeout(() => bar.style.width = t, 500);
});
document.querySelectorAll('.alert-bar-fill').forEach(bar => {
    const t = bar.style.width; bar.style.width = '0%';
    setTimeout(() => bar.style.width = t, 600);
});
</script>
</body>
</html>