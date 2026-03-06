<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

include 'db_connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/SmartCareGuardian/includes/ai_service.php';

// ── Period filter ──────────────────────────────────────────────────────────
$period = $_GET['period'] ?? '30';
$period = in_array($period, ['7','30','90']) ? $period : '30';

// ══════════════════════════════════════════════════════════════════════════
// CSV EXPORT HANDLER — must be before any HTML output
// ══════════════════════════════════════════════════════════════════════════
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];

    if ($export_type === 'health_logs') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="health_logs_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Resident Name', 'Caregiver', 'Date & Time', 'BP Systolic', 'BP Diastolic', 'Blood Sugar', 'Pulse', 'Weight (kg)', 'Temperature (°C)', 'Oxygen Sat (%)', 'Notes']);
        $rows = $conn->query("
            SELECT u.full_name AS resident, c.full_name AS caregiver,
                   hl.logged_at, hl.blood_pressure_systolic, hl.blood_pressure_diastolic,
                   hl.blood_sugar, hl.pulse, hl.weight, hl.temperature, hl.oxygen_saturation, hl.notes
            FROM health_logs hl
            JOIN users u ON hl.resident_id = u.user_id
            LEFT JOIN users c ON hl.caregiver_id = c.user_id
            WHERE hl.logged_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
            ORDER BY hl.logged_at DESC
        ")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['resident'], $r['caregiver'] ?? 'N/A',
                $r['logged_at'], $r['blood_pressure_systolic'], $r['blood_pressure_diastolic'],
                $r['blood_sugar'], $r['pulse'], $r['weight'], $r['temperature'],
                $r['oxygen_saturation'], $r['notes']
            ]);
        }
        fclose($out); exit();
    }

    if ($export_type === 'alerts') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="alerts_report_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Resident Name', 'Alert Type', 'Alert Message', 'Created At', 'Resolved']);
        $rows = $conn->query("
            SELECT u.full_name, a.alert_type, a.alert_message, a.created_at,
                   IF(a.resolved=1,'Yes','No') AS resolved
            FROM alerts a
            JOIN users u ON a.resident_id = u.user_id
            WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
            ORDER BY a.created_at DESC
        ")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            fputcsv($out, [$r['full_name'], $r['alert_type'], $r['alert_message'], $r['created_at'], $r['resolved']]);
        }
        fclose($out); exit();
    }

    if ($export_type === 'caregiver_performance') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="caregiver_performance_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Caregiver Name', 'Assigned Residents', 'Readings Logged', 'Routines Completed', 'Routines Skipped', 'Completion Rate (%)']);
        $rows = $conn->query("
            SELECT u.full_name,
                   COUNT(DISTINCT ca.resident_id) AS assigned,
                   COUNT(DISTINCT hl.log_id) AS readings,
                   SUM(CASE WHEN rl.status='completed' THEN 1 ELSE 0 END) AS completed,
                   SUM(CASE WHEN rl.status='skipped' THEN 1 ELSE 0 END) AS skipped,
                   ROUND(
                       100.0 * SUM(CASE WHEN rl.status='completed' THEN 1 ELSE 0 END) /
                       NULLIF(COUNT(rl.log_id),0), 1
                   ) AS rate
            FROM users u
            LEFT JOIN caregiver_assignments ca ON ca.caregiver_id = u.user_id
            LEFT JOIN health_logs hl ON hl.caregiver_id = u.user_id AND hl.logged_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
            LEFT JOIN routines r ON r.caregiver_id = u.user_id
            LEFT JOIN routine_logs rl ON rl.routine_id = r.id AND rl.log_date >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
            WHERE u.role='caregiver' AND u.status='active'
            GROUP BY u.user_id
        ")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            fputcsv($out, [$r['full_name'], $r['assigned'], $r['readings'], $r['completed'], $r['skipped'], $r['rate'] ?? 0]);
        }
        fclose($out); exit();
    }
}

// ══════════════════════════════════════════════════════════════════════════
// DATA QUERIES
// ══════════════════════════════════════════════════════════════════════════

$total_residents  = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='resident' AND status='active'")->fetch_assoc()['c'];
$total_caregivers = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='caregiver' AND status='active'")->fetch_assoc()['c'];
$total_logs       = $conn->query("SELECT COUNT(*) AS c FROM health_logs WHERE logged_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$total_alerts     = $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$unresolved_alerts= $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE resolved=0")->fetch_assoc()['c'];
$resolved_alerts  = $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE resolved=1 AND created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$alert_resolution_rate = $total_alerts > 0 ? round(($resolved_alerts / $total_alerts) * 100, 1) : 0;

$med_total  = $conn->query("SELECT COUNT(*) AS c FROM medications WHERE medication_date >= DATE_SUB(CURDATE(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$med_taken  = $conn->query("SELECT COUNT(*) AS c FROM medications WHERE taken=1 AND medication_date >= DATE_SUB(CURDATE(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$med_adherence = $med_total > 0 ? round(($med_taken / $med_total) * 100, 1) : 0;

$routine_total     = $conn->query("SELECT COUNT(*) AS c FROM routine_logs WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$routine_completed = $conn->query("SELECT COUNT(*) AS c FROM routine_logs WHERE status='completed' AND log_date >= DATE_SUB(CURDATE(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$routine_rate      = $routine_total > 0 ? round(($routine_completed / $routine_total) * 100, 1) : 0;

$alert_trend = $conn->query("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM alerts
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    GROUP BY DATE(created_at) ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);
$alert_days = array_column($alert_trend, 'day');
$alert_cnts = array_column($alert_trend, 'cnt');

$readings_trend = $conn->query("
    SELECT DATE(logged_at) AS day, COUNT(*) AS cnt
    FROM health_logs
    WHERE logged_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    GROUP BY DATE(logged_at) ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);
$reading_days = array_column($readings_trend, 'day');
$reading_cnts = array_column($readings_trend, 'cnt');

$vitals_trend = $conn->query("
    SELECT DATE(logged_at) AS day,
           ROUND(AVG(blood_pressure_systolic),1) AS avg_sys,
           ROUND(AVG(blood_sugar),1)              AS avg_sugar,
           ROUND(AVG(pulse),1)                    AS avg_pulse,
           ROUND(AVG(oxygen_saturation),1)         AS avg_o2
    FROM health_logs
    WHERE logged_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    GROUP BY DATE(logged_at) ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);
$vt_days  = array_column($vitals_trend, 'day');
$vt_sys   = array_column($vitals_trend, 'avg_sys');
$vt_sugar = array_column($vitals_trend, 'avg_sugar');
$vt_pulse = array_column($vitals_trend, 'avg_pulse');
$vt_o2    = array_column($vitals_trend, 'avg_o2');

$type_breakdown = $conn->query("
    SELECT alert_type, COUNT(*) AS cnt
    FROM alerts
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    GROUP BY alert_type ORDER BY cnt DESC
")->fetch_all(MYSQLI_ASSOC);
$type_labels = array_column($type_breakdown, 'alert_type');
$type_cnts   = array_column($type_breakdown, 'cnt');

$top_residents = $conn->query("
    SELECT u.full_name, COUNT(a.alert_id) AS cnt,
           SUM(CASE WHEN a.resolved=0 THEN 1 ELSE 0 END) AS unresolved
    FROM alerts a
    JOIN users u ON a.resident_id = u.user_id
    WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    GROUP BY a.resident_id ORDER BY cnt DESC LIMIT 8
")->fetch_all(MYSQLI_ASSOC);
$top_names = array_column($top_residents, 'full_name');
$top_cnts  = array_column($top_residents, 'cnt');

$caregiver_perf = $conn->query("
    SELECT u.full_name,
           COUNT(DISTINCT ca.resident_id) AS assigned_residents,
           COUNT(DISTINCT hl.log_id) AS readings_logged,
           COALESCE(SUM(CASE WHEN rl.status='completed' THEN 1 ELSE 0 END),0) AS routines_completed,
           COALESCE(SUM(CASE WHEN rl.status='skipped'   THEN 1 ELSE 0 END),0) AS routines_skipped,
           ROUND(
               100.0 * COALESCE(SUM(CASE WHEN rl.status='completed' THEN 1 ELSE 0 END),0) /
               NULLIF(COUNT(rl.log_id),0), 1
           ) AS completion_rate
    FROM users u
    LEFT JOIN caregiver_assignments ca ON ca.caregiver_id = u.user_id
    LEFT JOIN health_logs hl ON hl.caregiver_id = u.user_id
          AND hl.logged_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    LEFT JOIN routines r ON r.caregiver_id = u.user_id
    LEFT JOIN routine_logs rl ON rl.routine_id = r.id
          AND rl.log_date >= DATE_SUB(CURDATE(), INTERVAL {$period} DAY)
    WHERE u.role='caregiver' AND u.status='active'
    GROUP BY u.user_id
    ORDER BY readings_logged DESC
")->fetch_all(MYSQLI_ASSOC);

$routine_trend = $conn->query("
    SELECT log_date AS day,
           SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed,
           SUM(CASE WHEN status='skipped'   THEN 1 ELSE 0 END) AS skipped,
           SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END) AS pending
    FROM routine_logs
    WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL {$period} DAY)
    GROUP BY log_date ORDER BY log_date ASC
")->fetch_all(MYSQLI_ASSOC);
$rt_days      = array_column($routine_trend, 'day');
$rt_completed = array_column($routine_trend, 'completed');
$rt_skipped   = array_column($routine_trend, 'skipped');
$rt_pending   = array_column($routine_trend, 'pending');

$med_trend = $conn->query("
    SELECT medication_date AS day,
           COUNT(*) AS total,
           SUM(CASE WHEN taken=1 THEN 1 ELSE 0 END) AS taken_count
    FROM medications
    WHERE medication_date >= DATE_SUB(CURDATE(), INTERVAL {$period} DAY)
    GROUP BY medication_date ORDER BY medication_date ASC
")->fetch_all(MYSQLI_ASSOC);
$med_days   = array_column($med_trend, 'day');
$med_totals = array_column($med_trend, 'total');
$med_takens = array_column($med_trend, 'taken_count');

$res_trend = $conn->query("
    SELECT DATE(created_at) AS day,
           COUNT(*) AS total,
           SUM(CASE WHEN resolved=1 THEN 1 ELSE 0 END) AS resolved_cnt
    FROM alerts
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    GROUP BY DATE(created_at) ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);
$res_days     = array_column($res_trend, 'day');
$res_total    = array_column($res_trend, 'total');
$res_resolved = array_column($res_trend, 'resolved_cnt');

$high_events = $conn->query("
    SELECT u.full_name, hl.logged_at,
           hl.blood_pressure_systolic, hl.blood_sugar, hl.oxygen_saturation, hl.temperature
    FROM health_logs hl
    JOIN users u ON hl.resident_id = u.user_id
    WHERE hl.logged_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
      AND (hl.blood_pressure_systolic > 140 OR hl.blood_pressure_systolic < 90
        OR hl.blood_sugar < 70 OR hl.blood_sugar > 180
        OR hl.oxygen_saturation < 95
        OR hl.temperature > 37.8 OR hl.temperature < 36)
    ORDER BY hl.logged_at DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

$ai = new AIService();
$ai_online = $ai->checkStatus()['success'] ?? false;
$risk_counts = ['high'=>0,'medium'=>0,'low'=>0,'no_data'=>0];

$all_residents = $conn->query("
    SELECT u.user_id,
           hl.blood_pressure_systolic, hl.blood_pressure_diastolic,
           hl.blood_sugar, hl.pulse, hl.weight, hl.temperature,
           hl.oxygen_saturation, hl.logged_at AS last_log
    FROM users u
    LEFT JOIN (
        SELECT resident_id,
               blood_pressure_systolic, blood_pressure_diastolic,
               blood_sugar, pulse, weight, temperature, oxygen_saturation, logged_at,
               ROW_NUMBER() OVER (PARTITION BY resident_id ORDER BY logged_at DESC) AS rn
        FROM health_logs
    ) hl ON hl.resident_id = u.user_id AND hl.rn = 1
    WHERE u.role='resident' AND u.status='active'
")->fetch_all(MYSQLI_ASSOC);

if ($ai_online) {
    foreach ($all_residents as $r) {
        $rid = $r['user_id'];
        if (!$r['last_log']) { $risk_counts['no_data']++; continue; }
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
            $risk_counts[$resp['data']['data']['prediction']['risk_level']]++;
        } else {
            $s = 0;
            if (!empty($vitals['blood_pressure_systolic']) && ($vitals['blood_pressure_systolic']>140||$vitals['blood_pressure_systolic']<90)) $s+=2;
            if (!empty($vitals['blood_sugar']) && ($vitals['blood_sugar']>180||$vitals['blood_sugar']<70)) $s+=2;
            if (!empty($vitals['temperature']) && ($vitals['temperature']>37.8||$vitals['temperature']<36)) $s+=1;
            if (!empty($vitals['oxygen_saturation']) && $vitals['oxygen_saturation']<95) $s+=2;
            $pct = min($s*20, 95);
            $risk_counts[$pct>=60?'high':($pct>=30?'medium':'low')]++;
        }
    }
} else {
    foreach ($all_residents as $r) {
        if (!$r['last_log']) { $risk_counts['no_data']++; continue; }
        $s = 0;
        if (!empty($r['blood_pressure_systolic']) && ($r['blood_pressure_systolic']>140||$r['blood_pressure_systolic']<90)) $s+=2;
        if (!empty($r['blood_sugar']) && ($r['blood_sugar']>180||$r['blood_sugar']<70)) $s+=2;
        if (!empty($r['temperature']) && ($r['temperature']>37.8||$r['temperature']<36)) $s+=1;
        if (!empty($r['oxygen_saturation']) && $r['oxygen_saturation']<95) $s+=2;
        $pct = min($s*20, 95);
        $risk_counts[$pct>=60?'high':($pct>=30?'medium':'low')]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - SmartCare Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
/* ═══════════════════════════════════════════════════════════
   SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
   Reports & Analytics · Professional scale (15px base)
═══════════════════════════════════════════════════════════ */
:root{
    --s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;
    --s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;
    --s600:#4A6E30;--s700:#365220;--s800:#243816;
    --w50:#FDFAF5;--w100:#F7F1E5;
    --st300:#B8B0A4;--st500:#7A7268;--st700:#4A4540;
    --green-bg:#DDEFD8;--green-text:#3A6830;
    --amber-bg:#FDF3DC;--amber-text:#7A5520;
    --red-bg:#F5DADA;--red-text:#6A2020;
    --blue-bg:#DCE8F5;--blue-text:#1A3A5C;
    --radius-sm:8px;--radius-md:12px;--radius-lg:20px;
    --shadow-soft:0 2px 12px rgba(36,56,22,.07),0 1px 3px rgba(36,56,22,.05);
    --shadow-card:0 4px 24px rgba(36,56,22,.09),0 1px 4px rgba(36,56,22,.06);
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
.sidebar-footer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 8px;position:relative;}
.sidebar-footer a{margin:0;color:rgba(255,255,255,.5)!important;font-size:13.5px;}
.sidebar-footer a:hover{color:rgba(255,255,255,.8)!important;}

/* ── Layout ── */
.content{margin-left:240px;padding:24px;min-height:100vh;}

/* ── Topbar ── */
.topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);margin-bottom:22px;border:1px solid rgba(196,217,180,.3);}
.topbar h4{font-family:'Outfit',sans-serif;font-weight:700;font-size:18px;color:var(--s800);margin-bottom:3px;}
.topbar p{font-size:13px;color:var(--st300);margin:0;}
.logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;}
.logout-btn:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(139,58,58,.35);}

/* ── Period pills ── */
.period-pills{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;align-items:center;}
.period-pill{padding:7px 18px;border-radius:var(--radius-md);font-weight:700;font-family:'Outfit',sans-serif;font-size:13px;border:2px solid var(--s100);color:var(--s700);background:white;text-decoration:none;transition:all .2s;}
.period-pill:hover{border-color:var(--s400);color:var(--s500);}
.period-pill.active{background:linear-gradient(135deg,var(--s500),var(--s800));color:white;border-color:transparent;box-shadow:0 4px 14px rgba(94,138,64,.3);}

/* ── Export dropdown ── */
.export-group{margin-left:auto;}
.export-btn{background:linear-gradient(135deg,var(--s600),var(--s800));border:none;border-radius:var(--radius-md);color:white;padding:8px 18px;font-weight:700;font-family:'Outfit',sans-serif;font-size:13px;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:7px;}
.export-btn:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(74,110,48,.35);}
.export-menu{border-radius:var(--radius-md);border:1px solid var(--s100);box-shadow:var(--shadow-card);padding:6px 0;min-width:210px;background:white;}
.export-menu .dropdown-item{padding:9px 16px;font-weight:600;font-size:13px;color:var(--s800);display:flex;align-items:center;gap:9px;}
.export-menu .dropdown-item:hover{background:var(--s50);border-radius:var(--radius-sm);margin:0 4px;}
.export-menu .dropdown-item i{width:16px;color:var(--s500);font-size:13px;}

/* ── KPI cards ── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin-bottom:24px;}
.kpi-card{background:white;border-radius:var(--radius-lg);padding:18px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.25);text-align:center;transition:transform .2s;}
.kpi-card:hover{transform:translateY(-3px);}
.kpi-icon{width:48px;height:48px;border-radius:var(--radius-md);margin:0 auto 11px;display:flex;align-items:center;justify-content:center;font-size:18px;}
.ic-res    {background:linear-gradient(135deg,var(--s500),var(--s800));color:white;}
.ic-care   {background:linear-gradient(135deg,var(--s300),var(--s600));color:white;}
.ic-logs   {background:linear-gradient(135deg,#6B7FC8,#3A4A90);color:white;}
.ic-alerts {background:linear-gradient(135deg,#C87070,#8B3A3A);color:white;}
.ic-unres  {background:linear-gradient(135deg,#C89040,#7A5520);color:white;}
.ic-ai     {background:linear-gradient(135deg,#4A90A8,#1A5A70);color:white;}
.ic-med    {background:linear-gradient(135deg,#8B7AC8,#4A3A8B);color:white;}
.ic-routine{background:linear-gradient(135deg,var(--s400),var(--s700));color:white;}
.ic-resolve{background:linear-gradient(135deg,#C87A40,#8B4A1A);color:white;}
.kpi-val{font-size:28px;font-weight:800;color:var(--s800);line-height:1;font-family:'Outfit',sans-serif;}
.kpi-lbl{color:var(--st500);font-weight:700;font-size:12px;margin-top:3px;text-transform:uppercase;letter-spacing:.04em;}
.kpi-sub{font-size:11px;color:var(--st300);margin-top:2px;}

/* ── Section cards ── */
.section-card{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);margin-bottom:22px;overflow:hidden;border:1px solid rgba(196,217,180,.3);}
.section-header{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:14px 22px;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;}
.section-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
.section-header h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;position:relative;}
.section-header small{position:relative;opacity:.8;font-size:12px;}
.section-body{padding:20px;}
.chart-wrap    {position:relative;height:240px;}
.chart-wrap-sm {position:relative;height:200px;}
.chart-wrap-med{position:relative;height:260px;}

/* ── AI risk legend ── */
.risk-legend{display:flex;justify-content:center;gap:14px;flex-wrap:wrap;margin-top:12px;}
.rl-item{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:700;}
.rl-dot{width:12px;height:12px;border-radius:50%;}

/* ── Abnormal readings table ── */
.table th{background:var(--s50);color:var(--s700);font-size:11px;text-transform:uppercase;letter-spacing:.05em;font-weight:700;border-bottom:2px solid var(--s100);}
.table td{vertical-align:middle;font-size:13px;color:var(--st700);}
.badge-abn{background:var(--red-bg);color:var(--red-text);border-radius:var(--radius-sm);padding:2px 8px;font-weight:700;font-size:12px;}
.badge-ok {background:var(--green-bg);color:var(--green-text);border-radius:var(--radius-sm);padding:2px 8px;font-weight:700;font-size:12px;}

/* ── Top residents horizontal bars ── */
.top-bar  {display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.top-name {font-weight:600;min-width:135px;font-size:13px;color:var(--s800);}
.top-track{flex:1;height:9px;background:var(--s100);border-radius:6px;overflow:hidden;}
.top-fill {height:100%;background:linear-gradient(90deg,var(--s400),var(--s700));border-radius:6px;transition:width 1s ease;}
.top-count{font-weight:700;font-size:13px;color:var(--s800);min-width:30px;text-align:right;}
.unres-badge{background:var(--red-bg);color:var(--red-text);border-radius:var(--radius-sm);padding:1px 7px;font-size:11px;font-weight:700;margin-left:4px;}

/* ── Caregiver performance table ── */
.perf-table th{background:var(--s50);color:var(--s700);font-size:11px;text-transform:uppercase;letter-spacing:.05em;font-weight:700;padding:10px 14px;border-bottom:2px solid var(--s100);}
.perf-table td{font-size:13px;padding:10px 14px;vertical-align:middle;}
.perf-table tbody tr:hover td{background:var(--s50);}
.rate-bar-wrap{width:90px;height:7px;background:var(--s100);border-radius:6px;display:inline-block;overflow:hidden;vertical-align:middle;margin-right:6px;}
.rate-bar-fill{height:100%;border-radius:6px;}
.rate-high  {background:linear-gradient(90deg,var(--s400),var(--s700));}
.rate-medium{background:linear-gradient(90deg,#C89040,#7A5520);}
.rate-low   {background:linear-gradient(90deg,#C05050,#7A1A1A);}
.badge-readings{background:#EDE9F8;color:#4A2878;border-radius:var(--radius-sm);padding:2px 9px;font-size:12px;font-weight:700;}
.badge-assigned{background:var(--blue-bg);color:var(--blue-text);border-radius:var(--radius-sm);padding:2px 9px;font-size:12px;font-weight:700;}

/* ── Empty state ── */
.empty-state{text-align:center;padding:38px 20px;color:var(--st300);}
.empty-state i{font-size:2.2rem;margin-bottom:12px;display:block;color:var(--s200);}
.empty-state p{font-size:13px;margin:0;}

/* ── Pulse animation ── */
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
.pulse{animation:pulse 2s infinite;}

@media(max-width:768px){
    .sidebar{width:100%;height:auto;position:relative;}
    .content{margin-left:0;padding:15px;}
    .kpi-grid{grid-template-columns:repeat(2,1fr);}
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
        <small>Admin Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="admin_dashboard.php"><i class="fa-solid fa-gauge-high"></i>Dashboard</a>
        <a href="manage_users.php"><i class="fa-solid fa-users"></i>Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i>Manage Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i>Manage Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i>Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i>Manage Services</a>
        <a href="messages.php"><i class="fa-solid fa-envelope"></i>Messages</a>
        <a href="admin_alerts.php"><i class="fa-solid fa-bell"></i>Health Alerts</a>
        <a href="#" class="active"><i class="fa-solid fa-chart-line"></i>Reports & Analytics</a>
    </div>
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <!-- Topbar -->
    <div class="topbar d-flex justify-content-between align-items-center">
        <div>
            <h4>Reports &amp; Analytics</h4>
            <p>Facility-wide health trends, AI risk analysis &amp; performance reports</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span style="font-size:13px;color:var(--st500);">
                <?php if ($ai_online): ?>
                    <i class="fas fa-circle pulse me-1" style="color:var(--s500);font-size:10px;"></i>AI Online
                <?php else: ?>
                    <i class="fas fa-circle me-1" style="color:var(--red-text);font-size:10px;"></i>AI Offline
                <?php endif; ?>
            </span>
            <form action="/SmartCareGuardian/logout.php" method="POST">
                <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt me-2"></i>Logout</button>
            </form>
        </div>
    </div>

    <!-- Period selector + Export -->
    <div class="period-pills">
        <a href="?period=7"  class="period-pill <?= $period=='7' ?'active':''; ?>">Last 7 Days</a>
        <a href="?period=30" class="period-pill <?= $period=='30'?'active':''; ?>">Last 30 Days</a>
        <a href="?period=90" class="period-pill <?= $period=='90'?'active':''; ?>">Last 90 Days</a>

        <div class="export-group dropdown ms-auto">
            <button class="export-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-download"></i>Export CSV
            </button>
            <ul class="dropdown-menu export-menu">
                <li><a class="dropdown-item" href="?period=<?= $period ?>&export=health_logs"><i class="fas fa-notes-medical"></i>Health Logs Report</a></li>
                <li><a class="dropdown-item" href="?period=<?= $period ?>&export=alerts"><i class="fas fa-bell"></i>Alerts Report</a></li>
                <li><a class="dropdown-item" href="?period=<?= $period ?>&export=caregiver_performance"><i class="fas fa-user-nurse"></i>Caregiver Performance</a></li>
            </ul>
        </div>
    </div>

    <!-- ══ KPI CARDS ══ -->
    <div class="kpi-grid">
        <div class="kpi-card"><div class="kpi-icon ic-res"><i class="fas fa-users"></i></div><div class="kpi-val"><?= $total_residents ?></div><div class="kpi-lbl">Active Residents</div></div>
        <div class="kpi-card"><div class="kpi-icon ic-care"><i class="fas fa-user-nurse"></i></div><div class="kpi-val"><?= $total_caregivers ?></div><div class="kpi-lbl">Caregivers</div></div>
        <div class="kpi-card"><div class="kpi-icon ic-logs"><i class="fas fa-notes-medical"></i></div><div class="kpi-val"><?= $total_logs ?></div><div class="kpi-lbl">Readings Logged</div><div class="kpi-sub">Last <?= $period ?> days</div></div>
        <div class="kpi-card"><div class="kpi-icon ic-alerts"><i class="fas fa-bell"></i></div><div class="kpi-val"><?= $total_alerts ?></div><div class="kpi-lbl">Alerts Generated</div><div class="kpi-sub">Last <?= $period ?> days</div></div>
        <div class="kpi-card"><div class="kpi-icon ic-unres"><i class="fas fa-triangle-exclamation"></i></div><div class="kpi-val"><?= $unresolved_alerts ?></div><div class="kpi-lbl">Unresolved Alerts</div></div>
        <div class="kpi-card"><div class="kpi-icon ic-resolve"><i class="fas fa-check-double"></i></div><div class="kpi-val"><?= $alert_resolution_rate ?>%</div><div class="kpi-lbl">Resolution Rate</div><div class="kpi-sub">Last <?= $period ?> days</div></div>
        <div class="kpi-card"><div class="kpi-icon ic-med"><i class="fas fa-pills"></i></div><div class="kpi-val"><?= $med_adherence ?>%</div><div class="kpi-lbl">Med. Adherence</div><div class="kpi-sub"><?= $med_taken ?>/<?= $med_total ?> taken</div></div>
        <div class="kpi-card"><div class="kpi-icon ic-routine"><i class="fas fa-calendar-check"></i></div><div class="kpi-val"><?= $routine_rate ?>%</div><div class="kpi-lbl">Routine Completion</div><div class="kpi-sub"><?= $routine_completed ?>/<?= $routine_total ?> done</div></div>
        <div class="kpi-card"><div class="kpi-icon ic-ai"><i class="fas fa-brain"></i></div><div class="kpi-val"><?= $risk_counts['high'] ?></div><div class="kpi-lbl">High AI Risk</div><div class="kpi-sub"><?= $ai_online?'AI Online':'Rule-based' ?></div></div>
    </div>

    <!-- ══ ROW 1: Alert Trend + AI Risk ══ -->
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="fas fa-chart-line me-2"></i>Alert Trend &amp; Daily Readings</h5>
                    <small>Last <?= $period ?> days</small>
                </div>
                <div class="section-body">
                    <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="section-card h-100">
                <div class="section-header">
                    <h5><i class="fas fa-brain me-2"></i>AI Risk Distribution</h5>
                    <small>Current snapshot</small>
                </div>
                <div class="section-body">
                    <div class="chart-wrap-sm"><canvas id="riskDonut"></canvas></div>
                    <div class="risk-legend mt-2">
                        <div class="rl-item"><div class="rl-dot" style="background:#C05050;"></div>High (<?= $risk_counts['high'] ?>)</div>
                        <div class="rl-item"><div class="rl-dot" style="background:#C89040;"></div>Medium (<?= $risk_counts['medium'] ?>)</div>
                        <div class="rl-item"><div class="rl-dot" style="background:#5E8A40;"></div>Low (<?= $risk_counts['low'] ?>)</div>
                        <div class="rl-item"><div class="rl-dot" style="background:#B8B0A4;"></div>No Data (<?= $risk_counts['no_data'] ?>)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ ROW 2: Alert Resolution + Medication Adherence ══ -->
    <div class="row g-4 mt-0">
        <div class="col-lg-6">
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="fas fa-check-double me-2"></i>Alert Resolution Trend</h5>
                    <small>Daily resolved vs total</small>
                </div>
                <div class="section-body">
                    <div class="chart-wrap"><canvas id="resolutionChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="fas fa-pills me-2"></i>Medication Adherence Trend</h5>
                    <small>Daily taken vs scheduled</small>
                </div>
                <div class="section-body">
                    <div class="chart-wrap"><canvas id="medChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ Facility vitals trend ══ -->
    <div class="row g-4 mt-0">
        <div class="col-12">
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="fas fa-heartbeat me-2"></i>Facility-Wide Average Vitals Trend</h5>
                    <small>Daily averages across all residents</small>
                </div>
                <div class="section-body">
                    <div class="chart-wrap-med"><canvas id="vitalsChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ ROW 3: Routine Completion + Alert Type ══ -->
    <div class="row g-4 mt-0">
        <div class="col-lg-7">
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="fas fa-calendar-check me-2"></i>Routine Completion Trend</h5>
                    <small>Daily completed / skipped / pending</small>
                </div>
                <div class="section-body">
                    <?php if (empty($routine_trend)): ?>
                        <div class="empty-state"><i class="fas fa-calendar-xmark"></i><p>No routine data in this period.</p></div>
                    <?php else: ?>
                        <div class="chart-wrap"><canvas id="routineChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="fas fa-chart-pie me-2"></i>Alert Type Breakdown</h5>
                    <small>Last <?= $period ?> days</small>
                </div>
                <div class="section-body">
                    <?php if (empty($type_breakdown)): ?>
                        <div class="empty-state"><i class="fas fa-bell-slash"></i><p>No alerts in this period.</p></div>
                    <?php else: ?>
                        <div class="chart-wrap-sm"><canvas id="typeChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ ROW 4: Caregiver Performance ══ -->
    <div class="row g-4 mt-0">
        <div class="col-12">
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="fas fa-user-nurse me-2"></i>Caregiver Performance Report</h5>
                    <small>Last <?= $period ?> days — readings, routines &amp; completion rates</small>
                </div>
                <div class="section-body p-0">
                    <?php if (empty($caregiver_perf)): ?>
                        <div class="empty-state"><i class="fas fa-user-slash"></i><p>No caregiver data available.</p></div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 perf-table">
                            <thead>
                                <tr>
                                    <th style="padding:11px 20px;">Caregiver</th>
                                    <th>Assigned Residents</th>
                                    <th>Readings Logged</th>
                                    <th>Routines Completed</th>
                                    <th>Routines Skipped</th>
                                    <th>Completion Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($caregiver_perf as $cp):
                                $rate = $cp['completion_rate'] ?? 0;
                                $rate_class = $rate >= 70 ? 'rate-high' : ($rate >= 40 ? 'rate-medium' : 'rate-low');
                            ?>
                                <tr>
                                    <td style="padding:11px 20px;font-weight:700;color:var(--s800);">
                                        <i class="fas fa-user-nurse me-2" style="color:var(--s400);font-size:12px;"></i>
                                        <?= htmlspecialchars($cp['full_name']) ?>
                                    </td>
                                    <td><span class="badge-assigned"><?= $cp['assigned_residents'] ?> residents</span></td>
                                    <td><span class="badge-readings"><?= $cp['readings_logged'] ?> logs</span></td>
                                    <td style="color:var(--green-text);font-weight:700;"><?= $cp['routines_completed'] ?></td>
                                    <td style="color:var(--red-text);font-weight:700;"><?= $cp['routines_skipped'] ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rate-bar-wrap">
                                                <div class="rate-bar-fill <?= $rate_class ?>" style="width:<?= $rate ?>%;"></div>
                                            </div>
                                            <span style="font-weight:700;font-size:13px;color:var(--s800);"><?= $rate ?? 'N/A' ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ ROW 5: Top Residents + Abnormal Readings ══ -->
    <div class="row g-4 mt-0">
        <div class="col-md-5">
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="fas fa-ranking-star me-2"></i>Top Residents by Alert Count</h5>
                    <small>Last <?= $period ?> days</small>
                </div>
                <div class="section-body">
                    <?php if (empty($top_residents)): ?>
                        <div class="empty-state"><i class="fas fa-circle-check"></i><p>No alerts in this period.</p></div>
                    <?php else:
                        $max = max($top_cnts);
                        foreach ($top_residents as $tr): ?>
                        <div class="top-bar">
                            <div class="top-name"><?= htmlspecialchars($tr['full_name']) ?></div>
                            <div class="top-track">
                                <div class="top-fill" style="width:<?= $max>0?round($tr['cnt']/$max*100):0 ?>%"></div>
                            </div>
                            <div class="top-count">
                                <?= $tr['cnt'] ?>
                                <?php if ($tr['unresolved'] > 0): ?><span class="unres-badge"><?= $tr['unresolved'] ?> open</span><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="fas fa-triangle-exclamation me-2"></i>Recent Abnormal Readings</h5>
                    <small>Last 14 days — readings outside normal range</small>
                </div>
                <div class="section-body p-0">
                    <?php if (empty($high_events)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle" style="color:var(--s200);"></i>
                            <p>No abnormal readings in the last 14 days.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th style="padding:11px 18px;">Resident</th>
                                        <th>Date &amp; Time</th>
                                        <th>BP Sys</th>
                                        <th>Sugar</th>
                                        <th>O₂</th>
                                        <th>Temp</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($high_events as $ev):
                                    $sys_abn = $ev['blood_pressure_systolic']>140 || $ev['blood_pressure_systolic']<90;
                                    $sug_abn = $ev['blood_sugar']>180 || $ev['blood_sugar']<70;
                                    $o2_abn  = $ev['oxygen_saturation']<95;
                                    $tmp_abn = $ev['temperature']>37.8 || $ev['temperature']<36;
                                ?>
                                    <tr>
                                        <td style="padding:10px 18px;font-weight:700;color:var(--s800);"><?= htmlspecialchars($ev['full_name']) ?></td>
                                        <td>
                                            <span style="font-size:13px;"><?= date('d M Y', strtotime($ev['logged_at'])) ?></span><br>
                                            <span style="font-size:12px;color:var(--st300);"><?= date('H:i', strtotime($ev['logged_at'])) ?></span>
                                        </td>
                                        <td><span class="<?= $sys_abn?'badge-abn':'badge-ok' ?>"><?= $ev['blood_pressure_systolic'] ?></span></td>
                                        <td><span class="<?= $sug_abn?'badge-abn':'badge-ok' ?>"><?= $ev['blood_sugar'] ?></span></td>
                                        <td><span class="<?= $o2_abn?'badge-abn':'badge-ok' ?>"><?= $ev['oxygen_saturation'] ?>%</span></td>
                                        <td><span class="<?= $tmp_abn?'badge-abn':'badge-ok' ?>"><?= $ev['temperature'] ?>°C</span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
Chart.defaults.font.family = "'Outfit', sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#7A7268';

const gridColor    = 'rgba(196,217,180,0.3)';
const tooltipStyle = {
    backgroundColor: '#243816',
    titleColor: '#C4D9B4', bodyColor: '#E3EDDB',
    borderColor: '#4A6E30', borderWidth: 1,
    padding: 10, cornerRadius: 10,
};

// ── 1. Alert Trend + Daily Readings ─────────────────────────────────────
const allDays = [...new Set([
    ...<?= json_encode($alert_days) ?>,
    ...<?= json_encode($reading_days) ?>
])].sort();

const alertMap   = Object.fromEntries(<?= json_encode(array_map(null, $alert_days, $alert_cnts)) ?>.map(([d,c])=>[d,c]));
const readingMap = Object.fromEntries(<?= json_encode(array_map(null, $reading_days, $reading_cnts)) ?>.map(([d,c])=>[d,c]));

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: { labels: allDays, datasets: [
        { label:'Alerts Generated', data:allDays.map(d=>alertMap[d]||0),   borderColor:'#C05050', backgroundColor:'rgba(192,80,80,0.08)',  tension:.4, fill:true,  yAxisID:'yAlert', pointRadius:3 },
        { label:'Readings Logged',  data:allDays.map(d=>readingMap[d]||0), borderColor:'#5E8A40', backgroundColor:'rgba(94,138,64,0.07)', tension:.4, fill:false, yAxisID:'yRead',  pointRadius:3 },
    ]},
    options: {
        responsive:true, maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{ legend:{position:'bottom',labels:{boxWidth:11,padding:14}}, tooltip:tooltipStyle },
        scales:{
            x:{ grid:{color:gridColor}, ticks:{maxTicksLimit:10,color:'#B8B0A4'} },
            yAlert:{ position:'left',  title:{display:true,text:'Alerts',color:'#7A7268'},  grid:{color:gridColor}, min:0 },
            yRead: { position:'right', title:{display:true,text:'Readings',color:'#7A7268'},grid:{drawOnChartArea:false}, min:0 },
        }
    }
});

// ── 2. AI Risk Donut ─────────────────────────────────────────────────────
new Chart(document.getElementById('riskDonut'), {
    type: 'doughnut',
    data: {
        labels: ['High Risk','Medium Risk','Low Risk','No Data'],
        datasets:[{ data:[
            <?= $risk_counts['high'] ?>,
            <?= $risk_counts['medium'] ?>,
            <?= $risk_counts['low'] ?>,
            <?= $risk_counts['no_data'] ?>,
        ], backgroundColor:['#C05050','#C89040','#5E8A40','#B8B0A4'], borderWidth:0 }]
    },
    options:{
        responsive:true, maintainAspectRatio:false, cutout:'65%',
        plugins:{ legend:{display:false}, tooltip:tooltipStyle }
    }
});

// ── 3. Alert Resolution Trend ────────────────────────────────────────────
const resDays     = <?= json_encode($res_days) ?>;
const resTotal    = <?= json_encode($res_total) ?>;
const resResolved = <?= json_encode($res_resolved) ?>;

new Chart(document.getElementById('resolutionChart'), {
    type: 'bar',
    data: { labels: resDays, datasets: [
        { label:'Resolved',   data:resResolved, backgroundColor:'rgba(94,138,64,0.75)', borderRadius:4 },
        { label:'Unresolved', data:resTotal.map((t,i)=>t-resResolved[i]), backgroundColor:'rgba(192,80,80,0.55)', borderRadius:4 },
    ]},
    options:{
        responsive:true, maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{ legend:{position:'bottom',labels:{boxWidth:11,padding:14}}, tooltip:tooltipStyle },
        scales:{
            x:{ stacked:true, grid:{color:gridColor}, ticks:{maxTicksLimit:10,color:'#B8B0A4'} },
            y:{ stacked:true, grid:{color:gridColor}, min:0, title:{display:true,text:'Alerts',color:'#7A7268'} }
        }
    }
});

// ── 4. Medication Adherence Trend ────────────────────────────────────────
const medDays   = <?= json_encode($med_days) ?>;
const medTotals = <?= json_encode($med_totals) ?>;
const medTakens = <?= json_encode($med_takens) ?>;

new Chart(document.getElementById('medChart'), {
    type: 'line',
    data: { labels: medDays, datasets: [
        { label:'Scheduled', data:medTotals, borderColor:'#8B7AC8', backgroundColor:'rgba(139,122,200,0.08)', tension:.4, fill:true,  pointRadius:3 },
        { label:'Taken',     data:medTakens, borderColor:'#5E8A40', backgroundColor:'rgba(94,138,64,0.12)',   tension:.4, fill:true,  pointRadius:3 },
    ]},
    options:{
        responsive:true, maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{ legend:{position:'bottom',labels:{boxWidth:11,padding:14}}, tooltip:tooltipStyle },
        scales:{
            x:{ grid:{color:gridColor}, ticks:{maxTicksLimit:10,color:'#B8B0A4'} },
            y:{ grid:{color:gridColor}, min:0, title:{display:true,text:'Medications',color:'#7A7268'} }
        }
    }
});

// ── 5. Facility Vitals Trend ─────────────────────────────────────────────
new Chart(document.getElementById('vitalsChart'), {
    type: 'line',
    data: { labels: <?= json_encode($vt_days) ?>, datasets: [
        { label:'Avg BP Systolic', data:<?= json_encode($vt_sys) ?>,   borderColor:'#C05050', tension:.4, fill:false, pointRadius:2, yAxisID:'yBP' },
        { label:'Avg Blood Sugar', data:<?= json_encode($vt_sugar) ?>, borderColor:'#C89040', tension:.4, fill:false, pointRadius:2, yAxisID:'ySugar' },
        { label:'Avg Pulse',       data:<?= json_encode($vt_pulse) ?>, borderColor:'#6B7FC8', tension:.4, fill:false, pointRadius:2, yAxisID:'yPulse' },
        { label:'Avg O₂ Sat',      data:<?= json_encode($vt_o2) ?>,    borderColor:'#4A90A8', tension:.4, fill:false, pointRadius:2, yAxisID:'yO2' },
    ]},
    options:{
        responsive:true, maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{ legend:{position:'bottom',labels:{boxWidth:11,padding:14}}, tooltip:tooltipStyle },
        scales:{
            x: { grid:{color:gridColor}, ticks:{maxTicksLimit:10,color:'#B8B0A4'} },
            yBP:    { position:'left',  title:{display:true,text:'mmHg / mg/dL / bpm',color:'#7A7268'}, grid:{color:gridColor}, min:40 },
            ySugar: { display:false },
            yPulse: { display:false },
            yO2:    { position:'right', title:{display:true,text:'O₂ %',color:'#7A7268'}, grid:{drawOnChartArea:false}, min:85, max:100 },
        }
    }
});

// ── 6. Routine Completion Trend ──────────────────────────────────────────
<?php if (!empty($routine_trend)): ?>
new Chart(document.getElementById('routineChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($rt_days) ?>, datasets: [
        { label:'Completed', data:<?= json_encode($rt_completed) ?>, backgroundColor:'rgba(94,138,64,0.75)',  borderRadius:4 },
        { label:'Skipped',   data:<?= json_encode($rt_skipped) ?>,   backgroundColor:'rgba(192,80,80,0.6)',   borderRadius:4 },
        { label:'Pending',   data:<?= json_encode($rt_pending) ?>,   backgroundColor:'rgba(184,176,164,0.5)', borderRadius:4 },
    ]},
    options:{
        responsive:true, maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{ legend:{position:'bottom',labels:{boxWidth:11,padding:14}}, tooltip:tooltipStyle },
        scales:{
            x:{ stacked:true, grid:{color:gridColor}, ticks:{maxTicksLimit:10,color:'#B8B0A4'} },
            y:{ stacked:true, grid:{color:gridColor}, min:0, title:{display:true,text:'Routines',color:'#7A7268'} }
        }
    }
});
<?php endif; ?>

// ── 7. Alert Type Pie ────────────────────────────────────────────────────
<?php if (!empty($type_breakdown)): ?>
new Chart(document.getElementById('typeChart'), {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_map(fn($l)=>strtoupper(str_replace('_',' ',$l)), $type_labels)) ?>,
        datasets:[{ data:<?= json_encode($type_cnts) ?>, backgroundColor:['#5E8A40','#4A6E30','#C05050','#C89040','#4A90A8','#8B7AC8'], borderWidth:0 }]
    },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{position:'bottom',labels:{boxWidth:11,padding:10}}, tooltip:tooltipStyle } }
});
<?php endif; ?>

// ── Animate bars ─────────────────────────────────────────────────────────
document.querySelectorAll('.top-fill').forEach(bar => {
    const t = bar.style.width; bar.style.width = '0%';
    setTimeout(() => { bar.style.width = t; }, 300);
});
document.querySelectorAll('.rate-bar-fill').forEach(bar => {
    const t = bar.style.width; bar.style.width = '0%';
    setTimeout(() => { bar.style.width = t; bar.style.transition = 'width 0.9s ease'; }, 350);
});
</script>
</body>
</html>