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
        fclose($out);
        exit();
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
        fclose($out);
        exit();
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
        fclose($out);
        exit();
    }
}

// ══════════════════════════════════════════════════════════════════════════
// DATA QUERIES
// ══════════════════════════════════════════════════════════════════════════

// ── Facility-wide KPIs ─────────────────────────────────────────────────────
$total_residents  = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='resident' AND status='active'")->fetch_assoc()['c'];
$total_caregivers = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='caregiver' AND status='active'")->fetch_assoc()['c'];
$total_logs       = $conn->query("SELECT COUNT(*) AS c FROM health_logs WHERE logged_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$total_alerts     = $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$unresolved_alerts= $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE resolved=0")->fetch_assoc()['c'];
$resolved_alerts  = $conn->query("SELECT COUNT(*) AS c FROM alerts WHERE resolved=1 AND created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$alert_resolution_rate = $total_alerts > 0 ? round(($resolved_alerts / $total_alerts) * 100, 1) : 0;

// Medication adherence
$med_total  = $conn->query("SELECT COUNT(*) AS c FROM medications WHERE medication_date >= DATE_SUB(CURDATE(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$med_taken  = $conn->query("SELECT COUNT(*) AS c FROM medications WHERE taken=1 AND medication_date >= DATE_SUB(CURDATE(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$med_adherence = $med_total > 0 ? round(($med_taken / $med_total) * 100, 1) : 0;

// Routine completion rate
$routine_total     = $conn->query("SELECT COUNT(*) AS c FROM routine_logs WHERE log_date >= DATE_SUB(CURDATE(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$routine_completed = $conn->query("SELECT COUNT(*) AS c FROM routine_logs WHERE status='completed' AND log_date >= DATE_SUB(CURDATE(), INTERVAL {$period} DAY)")->fetch_assoc()['c'];
$routine_rate      = $routine_total > 0 ? round(($routine_completed / $routine_total) * 100, 1) : 0;

// ── Alert trend per day ────────────────────────────────────────────────────
$alert_trend = $conn->query("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM alerts
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    GROUP BY DATE(created_at) ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);
$alert_days = array_column($alert_trend, 'day');
$alert_cnts = array_column($alert_trend, 'cnt');

// ── Readings per day ───────────────────────────────────────────────────────
$readings_trend = $conn->query("
    SELECT DATE(logged_at) AS day, COUNT(*) AS cnt
    FROM health_logs
    WHERE logged_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    GROUP BY DATE(logged_at) ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);
$reading_days = array_column($readings_trend, 'day');
$reading_cnts = array_column($readings_trend, 'cnt');

// ── Avg vitals per day ─────────────────────────────────────────────────────
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

// ── Alert type breakdown ───────────────────────────────────────────────────
$type_breakdown = $conn->query("
    SELECT alert_type, COUNT(*) AS cnt
    FROM alerts
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)
    GROUP BY alert_type ORDER BY cnt DESC
")->fetch_all(MYSQLI_ASSOC);
$type_labels = array_column($type_breakdown, 'alert_type');
$type_cnts   = array_column($type_breakdown, 'cnt');

// ── Top residents by alert count ───────────────────────────────────────────
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

// ── Caregiver Performance ─────────────────────────────────────────────────
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

// ── Routine completion trend per day ──────────────────────────────────────
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

// ── Medication adherence trend ────────────────────────────────────────────
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

// ── Alert resolution trend ────────────────────────────────────────────────
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

// ── Recent abnormal readings ───────────────────────────────────────────────
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

// ── AI risk distribution ───────────────────────────────────────────────────
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
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Quicksand:wght@400;500;600&family=Jost:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --sage-green:#87A96B; --mint-cream:#F0FFF0; --seafoam:#9FE2BF;
            --forest-mist:#B8E0D2; --dusty-teal:#6D9B8E; --deep-emerald:#4A766E;
            --light-sage:#E8F5E8;
        }
        body { font-family:'Quicksand',sans-serif; background:linear-gradient(135deg,var(--mint-cream),var(--forest-mist)); margin:0; min-height:100vh; }
        h1,h2,h3,h4,h5 { font-family:'Playfair Display',serif; color:var(--deep-emerald); }
        .brand-font { font-family:'Jost',sans-serif; font-weight:600; }

        /* ── Sidebar ── */
        .sidebar { width:280px; height:100vh; position:fixed; background:linear-gradient(180deg,var(--sage-green),var(--dusty-teal)); color:white; box-shadow:4px 0 20px rgba(0,0,0,.1); z-index:1000; display:flex; flex-direction:column; }
        .sidebar-header { text-align:center; padding:30px 20px 20px; border-bottom:1px solid rgba(255,255,255,.2); flex-shrink:0; }
        .sidebar-nav { flex:1; overflow-y:auto; padding:20px 0; }
        .sidebar-footer { flex-shrink:0; border-top:1px solid rgba(255,255,255,.2); padding:20px; }
        .sidebar a { color:white; display:flex; align-items:center; padding:15px 25px; text-decoration:none; transition:all .3s; margin:5px 15px; border-radius:12px; font-weight:500; }
        .sidebar a:hover { background:rgba(255,255,255,.15); transform:translateX(5px); }
        .sidebar a.active { background:rgba(255,255,255,.25); box-shadow:0 4px 15px rgba(0,0,0,.1); }
        .sidebar i { width:25px; margin-right:12px; font-size:1.1rem; }

        /* ── Layout ── */
        .content { margin-left:280px; padding:30px; min-height:100vh; }
        .topbar { background:rgba(255,255,255,.95); backdrop-filter:blur(10px); border-radius:20px; padding:20px 30px; box-shadow:0 8px 32px rgba(0,0,0,.1); margin-bottom:28px; }
        .logout-btn { background:linear-gradient(135deg,#ff6b6b,#ee5a52); border:none; border-radius:50px; color:white; padding:10px 25px; font-weight:600; transition:all .3s; }
        .logout-btn:hover { transform:translateY(-2px); }

        /* ── Period pills ── */
        .period-pills { display:flex; gap:8px; margin-bottom:24px; flex-wrap:wrap; align-items:center; }
        .period-pill { padding:8px 20px; border-radius:25px; font-weight:700; font-family:'Jost',sans-serif; font-size:.85rem; border:2px solid var(--forest-mist); color:var(--deep-emerald); background:#fff; text-decoration:none; transition:all .2s; }
        .period-pill:hover { border-color:var(--sage-green); color:var(--sage-green); }
        .period-pill.active { background:linear-gradient(135deg,var(--sage-green),var(--dusty-teal)); color:#fff; border-color:transparent; }

        /* ── Export dropdown ── */
        .export-group { margin-left:auto; }
        .export-btn { background:linear-gradient(135deg,var(--deep-emerald),var(--dusty-teal)); border:none; border-radius:25px; color:#fff; padding:8px 20px; font-weight:700; font-family:'Jost',sans-serif; font-size:.85rem; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:8px; }
        .export-btn:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(74,118,110,.35); }
        .export-menu { border-radius:14px; border:none; box-shadow:0 12px 40px rgba(0,0,0,.15); padding:8px 0; min-width:220px; }
        .export-menu .dropdown-item { padding:10px 18px; font-weight:600; font-size:.87rem; color:var(--deep-emerald); display:flex; align-items:center; gap:10px; }
        .export-menu .dropdown-item:hover { background:var(--light-sage); border-radius:8px; margin:0 4px; }
        .export-menu .dropdown-item i { width:18px; color:var(--sage-green); }

        /* ── KPI cards ── */
        .kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(155px,1fr)); gap:16px; margin-bottom:28px; }
        .kpi-card { background:rgba(255,255,255,.95); border-radius:18px; padding:20px; box-shadow:0 6px 24px rgba(0,0,0,.07); text-align:center; transition:all .3s; }
        .kpi-card:hover { transform:translateY(-3px); box-shadow:0 12px 36px rgba(0,0,0,.1); }
        .kpi-icon { width:52px; height:52px; border-radius:14px; margin:0 auto 12px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; }
        .ic-res   { background:linear-gradient(135deg,var(--dusty-teal),var(--deep-emerald)); color:#fff; }
        .ic-care  { background:linear-gradient(135deg,var(--seafoam),var(--sage-green)); color:#fff; }
        .ic-logs  { background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; }
        .ic-alerts{ background:linear-gradient(135deg,#ff6b6b,#ee5a52); color:#fff; }
        .ic-unres { background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff; }
        .ic-ai    { background:linear-gradient(135deg,#06b6d4,#0891b2); color:#fff; }
        .ic-med   { background:linear-gradient(135deg,#a78bfa,#7c3aed); color:#fff; }
        .ic-routine{ background:linear-gradient(135deg,#34d399,#059669); color:#fff; }
        .ic-resolve{ background:linear-gradient(135deg,#fb923c,#ea580c); color:#fff; }
        .kpi-val  { font-size:1.9rem; font-weight:800; color:var(--deep-emerald); line-height:1; }
        .kpi-lbl  { color:var(--dusty-teal); font-weight:600; font-size:.8rem; margin-top:4px; }
        .kpi-sub  { font-size:.72rem; color:#94a3b8; margin-top:3px; }

        /* ── Section cards ── */
        .section-card { background:rgba(255,255,255,.95); border-radius:20px; box-shadow:0 8px 32px rgba(0,0,0,.07); margin-bottom:24px; overflow:hidden; }
        .section-header { background:linear-gradient(135deg,var(--sage-green),var(--dusty-teal)); color:white; padding:16px 24px; display:flex; align-items:center; justify-content:space-between; }
        .section-header h5 { color:white; margin:0; font-size:1rem; }
        .section-body { padding:22px; }
        .chart-wrap     { position:relative; height:240px; }
        .chart-wrap-sm  { position:relative; height:200px; }
        .chart-wrap-med { position:relative; height:260px; }

        /* ── AI risk legend ── */
        .risk-legend { display:flex; justify-content:center; gap:16px; flex-wrap:wrap; margin-top:12px; }
        .rl-item { display:flex; align-items:center; gap:6px; font-size:.82rem; font-weight:600; }
        .rl-dot  { width:14px; height:14px; border-radius:50%; }

        /* ── Abnormal readings table ── */
        .table th { background:var(--light-sage); color:var(--deep-emerald); font-size:.76rem; text-transform:uppercase; letter-spacing:.04em; font-weight:700; }
        .table td { vertical-align:middle; font-size:.87rem; }
        .badge-abn { background:#fef2f2; color:#dc2626; border-radius:8px; padding:2px 8px; font-weight:700; font-size:.77rem; }
        .badge-ok  { background:#edfdf4; color:#059669; border-radius:8px; padding:2px 8px; font-weight:700; font-size:.77rem; }

        /* ── Top residents horizontal bars ── */
        .top-bar  { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
        .top-name { font-weight:600; min-width:140px; font-size:.87rem; color:var(--deep-emerald); }
        .top-track{ flex:1; height:10px; background:#e2e8f0; border-radius:6px; overflow:hidden; }
        .top-fill { height:100%; background:linear-gradient(90deg,var(--sage-green),var(--dusty-teal)); border-radius:6px; transition:width 1s ease; }
        .top-count{ font-weight:700; font-size:.87rem; color:var(--deep-emerald); min-width:30px; text-align:right; }
        .unres-badge { background:#fef2f2; color:#dc2626; border-radius:8px; padding:1px 7px; font-size:.72rem; font-weight:700; margin-left:4px; }

        /* ── Caregiver performance table ── */
        .perf-table th { background:var(--light-sage); color:var(--deep-emerald); font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; font-weight:700; padding:10px 14px; }
        .perf-table td { font-size:.86rem; padding:10px 14px; vertical-align:middle; }
        .perf-table tr:hover td { background:#f8fffe; }
        .rate-bar-wrap { width:100px; height:8px; background:#e2e8f0; border-radius:6px; display:inline-block; overflow:hidden; vertical-align:middle; margin-right:6px; }
        .rate-bar-fill { height:100%; border-radius:6px; }
        .rate-high   { background:linear-gradient(90deg,#22c55e,#16a34a); }
        .rate-medium { background:linear-gradient(90deg,#f59e0b,#d97706); }
        .rate-low    { background:linear-gradient(90deg,#ef4444,#dc2626); }
        .badge-readings { background:#ede9fe; color:#7c3aed; border-radius:8px; padding:2px 9px; font-size:.77rem; font-weight:700; }
        .badge-assigned { background:#dbeafe; color:#1d4ed8; border-radius:8px; padding:2px 9px; font-size:.77rem; font-weight:700; }

        /* ── Progress ring for adherence ── */
        .ring-wrap { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:10px 0; }
        .ring-label { font-size:1.4rem; font-weight:800; color:var(--deep-emerald); margin-top:8px; }
        .ring-sub   { font-size:.8rem; color:var(--dusty-teal); font-weight:600; }

        /* ── Section tab nav ── */
        .section-tabs { display:flex; gap:6px; background:var(--light-sage); border-radius:12px; padding:5px; margin-bottom:18px; }
        .stab { padding:7px 16px; border-radius:9px; font-weight:600; font-size:.83rem; color:var(--dusty-teal); cursor:pointer; transition:all .2s; border:none; background:none; font-family:'Quicksand',sans-serif; }
        .stab.active { background:white; color:var(--deep-emerald); box-shadow:0 2px 8px rgba(0,0,0,.08); }

        /* ── Pulse animation ── */
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
        .pulse { animation:pulse 2s infinite; }

        /* ── Empty state ── */
        .empty-state { text-align:center; padding:40px 20px; color:#94a3b8; }
        .empty-state i { font-size:2.5rem; margin-bottom:12px; display:block; color:var(--forest-mist); }

        @media(max-width:768px){
            .sidebar{width:100%;height:auto;position:relative;}
            .content{margin-left:0;padding:15px;}
            .kpi-grid{grid-template-columns:repeat(2,1fr);}
        }
    </style>
</head>
<body>

<!-- ══════ SIDEBAR ══════ -->
<div class="sidebar">
    <div class="sidebar-header">
        <h4 class="brand-font mb-2"><i class="fa-solid fa-shield-heart me-2"></i>SmartCare Guardian</h4>
        <small class="opacity-75">Admin Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="admin_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="manage_users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i> Manage Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i> Manage Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i> Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i> Manage Services</a>
        <a href="messages.php"><i class="fa-solid fa-envelope"></i> Messages</a>
        <a href="admin_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="#" class="active"><i class="fa-solid fa-chart-line"></i> Reports & Analytics</a>
    </div>
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<!-- ══════ CONTENT ══════ -->
<div class="content">

    <!-- Top bar -->
    <div class="topbar d-flex justify-content-between align-items-center">
        <div>
            <h4 class="brand-font mb-1">Reports & Analytics</h4>
            <p class="text-muted mb-0">Facility-wide health trends, AI risk analysis &amp; performance reports</p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span style="font-size:.83rem;">
                <?php if ($ai_online): ?>
                    <i class="fas fa-circle pulse me-1" style="color:#22c55e;"></i>AI Online
                <?php else: ?>
                    <i class="fas fa-circle me-1" style="color:#ef4444;"></i>AI Offline
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

        <!-- Export dropdown -->
        <div class="export-group dropdown ms-auto">
            <button class="export-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-download"></i> Export CSV
            </button>
            <ul class="dropdown-menu export-menu">
                <li>
                    <a class="dropdown-item" href="?period=<?= $period ?>&export=health_logs">
                        <i class="fas fa-notes-medical"></i> Health Logs Report
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="?period=<?= $period ?>&export=alerts">
                        <i class="fas fa-bell"></i> Alerts Report
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="?period=<?= $period ?>&export=caregiver_performance">
                        <i class="fas fa-user-nurse"></i> Caregiver Performance Report
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- ══ KPI CARDS ══ -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon ic-res"><i class="fas fa-users"></i></div>
            <div class="kpi-val"><?= $total_residents; ?></div>
            <div class="kpi-lbl">Active Residents</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-care"><i class="fas fa-user-nurse"></i></div>
            <div class="kpi-val"><?= $total_caregivers; ?></div>
            <div class="kpi-lbl">Caregivers</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-logs"><i class="fas fa-notes-medical"></i></div>
            <div class="kpi-val"><?= $total_logs; ?></div>
            <div class="kpi-lbl">Readings Logged</div>
            <div class="kpi-sub">Last <?= $period ?> days</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-alerts"><i class="fas fa-bell"></i></div>
            <div class="kpi-val"><?= $total_alerts; ?></div>
            <div class="kpi-lbl">Alerts Generated</div>
            <div class="kpi-sub">Last <?= $period ?> days</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-unres"><i class="fas fa-triangle-exclamation"></i></div>
            <div class="kpi-val"><?= $unresolved_alerts; ?></div>
            <div class="kpi-lbl">Unresolved Alerts</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-resolve"><i class="fas fa-check-double"></i></div>
            <div class="kpi-val"><?= $alert_resolution_rate; ?>%</div>
            <div class="kpi-lbl">Alert Resolution Rate</div>
            <div class="kpi-sub">Last <?= $period ?> days</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-med"><i class="fas fa-pills"></i></div>
            <div class="kpi-val"><?= $med_adherence; ?>%</div>
            <div class="kpi-lbl">Medication Adherence</div>
            <div class="kpi-sub"><?= $med_taken ?>/<?= $med_total ?> taken</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-routine"><i class="fas fa-calendar-check"></i></div>
            <div class="kpi-val"><?= $routine_rate; ?>%</div>
            <div class="kpi-lbl">Routine Completion</div>
            <div class="kpi-sub"><?= $routine_completed ?>/<?= $routine_total ?> done</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon ic-ai"><i class="fas fa-brain"></i></div>
            <div class="kpi-val"><?= $risk_counts['high']; ?></div>
            <div class="kpi-lbl">High AI Risk Today</div>
            <div class="kpi-sub"><?= $ai_online?'AI Online':'Rule-based'; ?></div>
        </div>
    </div>

    <!-- ══ ROW 1: Alert Trend + AI Risk ══ -->
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="fas fa-chart-line me-2"></i>Alert Trend &amp; Daily Readings</h5>
                    <small style="opacity:.8;">Last <?= $period ?> days</small>
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
                    <small style="opacity:.8;">Current snapshot</small>
                </div>
                <div class="section-body">
                    <div class="chart-wrap-sm"><canvas id="riskDonut"></canvas></div>
                    <div class="risk-legend mt-2">
                        <div class="rl-item"><div class="rl-dot" style="background:#ef4444;"></div>High (<?= $risk_counts['high']; ?>)</div>
                        <div class="rl-item"><div class="rl-dot" style="background:#f59e0b;"></div>Medium (<?= $risk_counts['medium']; ?>)</div>
                        <div class="rl-item"><div class="rl-dot" style="background:#22c55e;"></div>Low (<?= $risk_counts['low']; ?>)</div>
                        <div class="rl-item"><div class="rl-dot" style="background:#94a3b8;"></div>No Data (<?= $risk_counts['no_data']; ?>)</div>
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
                    <small style="opacity:.8;">Daily resolved vs total</small>
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
                    <small style="opacity:.8;">Daily taken vs scheduled</small>
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
                    <small style="opacity:.8;">Daily averages across all residents</small>
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
                    <small style="opacity:.8;">Daily completed / skipped / pending</small>
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
                    <small style="opacity:.8;">Last <?= $period ?> days</small>
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

    <!-- ══ ROW 4: Caregiver Performance + Top Residents ══ -->
    <div class="row g-4 mt-0">
        <!-- Caregiver Performance -->
        <div class="col-12">
            <div class="section-card">
                <div class="section-header">
                    <h5><i class="fas fa-user-nurse me-2"></i>Caregiver Performance Report</h5>
                    <small style="opacity:.8;">Last <?= $period ?> days — readings, routines &amp; completion rates</small>
                </div>
                <div class="section-body p-0">
                    <?php if (empty($caregiver_perf)): ?>
                        <div class="empty-state"><i class="fas fa-user-slash"></i><p>No caregiver data available.</p></div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 perf-table">
                            <thead>
                                <tr>
                                    <th style="padding:12px 20px;">Caregiver</th>
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
                                    <td style="padding:12px 20px;font-weight:700;color:var(--deep-emerald);">
                                        <i class="fas fa-user-nurse me-2" style="color:var(--sage-green);"></i>
                                        <?= htmlspecialchars($cp['full_name']); ?>
                                    </td>
                                    <td><span class="badge-assigned"><?= $cp['assigned_residents']; ?> residents</span></td>
                                    <td><span class="badge-readings"><?= $cp['readings_logged']; ?> logs</span></td>
                                    <td style="color:#059669;font-weight:700;"><?= $cp['routines_completed']; ?></td>
                                    <td style="color:#dc2626;font-weight:700;"><?= $cp['routines_skipped']; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rate-bar-wrap">
                                                <div class="rate-bar-fill <?= $rate_class; ?>" style="width:<?= $rate; ?>%;"></div>
                                            </div>
                                            <span style="font-weight:700;font-size:.85rem;color:var(--deep-emerald);"><?= $rate ?? 'N/A'; ?>%</span>
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
                    <small style="opacity:.8;">Last <?= $period ?> days</small>
                </div>
                <div class="section-body">
                    <?php if (empty($top_residents)): ?>
                        <div class="empty-state"><i class="fas fa-circle-check"></i><p>No alerts in this period.</p></div>
                    <?php else:
                        $max = max($top_cnts);
                        foreach ($top_residents as $tr): ?>
                        <div class="top-bar">
                            <div class="top-name"><?= htmlspecialchars($tr['full_name']); ?></div>
                            <div class="top-track">
                                <div class="top-fill" style="width:<?= $max>0?round($tr['cnt']/$max*100):0; ?>%"></div>
                            </div>
                            <div class="top-count">
                                <?= $tr['cnt']; ?>
                                <?php if ($tr['unresolved'] > 0): ?>
                                    <span class="unres-badge"><?= $tr['unresolved']; ?> open</span>
                                <?php endif; ?>
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
                    <small style="opacity:.8;">Last 14 days — readings outside normal range</small>
                </div>
                <div class="section-body p-0">
                    <?php if (empty($high_events)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle" style="color:var(--forest-mist);"></i>
                            <p>No abnormal readings in the last 14 days. Great!</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th style="padding:12px 18px;">Resident</th>
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
                                        <td style="padding:10px 18px;font-weight:700;color:var(--deep-emerald);"><?= htmlspecialchars($ev['full_name']); ?></td>
                                        <td>
                                            <small><?= date('d M Y', strtotime($ev['logged_at'])); ?></small><br>
                                            <small class="text-muted"><?= date('H:i', strtotime($ev['logged_at'])); ?></small>
                                        </td>
                                        <td><span class="<?= $sys_abn?'badge-abn':'badge-ok'; ?>"><?= $ev['blood_pressure_systolic']; ?></span></td>
                                        <td><span class="<?= $sug_abn?'badge-abn':'badge-ok'; ?>"><?= $ev['blood_sugar']; ?></span></td>
                                        <td><span class="<?= $o2_abn?'badge-abn':'badge-ok'; ?>"><?= $ev['oxygen_saturation']; ?>%</span></td>
                                        <td><span class="<?= $tmp_abn?'badge-abn':'badge-ok'; ?>"><?= $ev['temperature']; ?>°C</span></td>
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
Chart.defaults.font.family = "'Quicksand', sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#64748b';

const gridColor    = 'rgba(0,0,0,0.05)';
const tooltipStyle = {
    backgroundColor: 'rgba(255,255,255,0.97)',
    titleColor: '#4A766E', bodyColor: '#475569',
    borderColor: '#B8E0D2', borderWidth: 1,
    padding: 10, cornerRadius: 10,
};

// ── 1. Alert Trend + Daily Readings ──────────────────────────────────────
const allDays = [...new Set([
    ...<?= json_encode($alert_days); ?>,
    ...<?= json_encode($reading_days); ?>
])].sort();

const alertMap   = Object.fromEntries(<?= json_encode(array_map(null, $alert_days, $alert_cnts)); ?>.map(([d,c])=>[d,c]));
const readingMap = Object.fromEntries(<?= json_encode(array_map(null, $reading_days, $reading_cnts)); ?>.map(([d,c])=>[d,c]));

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: { labels: allDays, datasets: [
        { label:'Alerts Generated', data:allDays.map(d=>alertMap[d]||0),   borderColor:'#ef4444', backgroundColor:'rgba(239,68,68,0.1)',  tension:.4, fill:true,  yAxisID:'yAlert', pointRadius:3 },
        { label:'Readings Logged',  data:allDays.map(d=>readingMap[d]||0), borderColor:'#22c55e', backgroundColor:'rgba(34,197,94,0.08)', tension:.4, fill:false, yAxisID:'yRead',  pointRadius:3 },
    ]},
    options: {
        responsive:true, maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{ legend:{position:'bottom',labels:{boxWidth:12,padding:14}}, tooltip:tooltipStyle },
        scales:{
            x:{ grid:{color:gridColor}, ticks:{maxTicksLimit:10} },
            yAlert:{ position:'left',  title:{display:true,text:'Alerts'},  grid:{color:gridColor}, min:0 },
            yRead: { position:'right', title:{display:true,text:'Readings'},grid:{drawOnChartArea:false}, min:0 },
        }
    }
});

// ── 2. AI Risk Donut ──────────────────────────────────────────────────────
new Chart(document.getElementById('riskDonut'), {
    type: 'doughnut',
    data: {
        labels: ['High Risk','Medium Risk','Low Risk','No Data'],
        datasets:[{ data:[
            <?= $risk_counts['high']; ?>,
            <?= $risk_counts['medium']; ?>,
            <?= $risk_counts['low']; ?>,
            <?= $risk_counts['no_data']; ?>,
        ], backgroundColor:['#ef4444','#f59e0b','#22c55e','#94a3b8'], borderWidth:0 }]
    },
    options:{
        responsive:true, maintainAspectRatio:false, cutout:'65%',
        plugins:{ legend:{display:false}, tooltip:tooltipStyle }
    }
});

// ── 3. Alert Resolution Trend ─────────────────────────────────────────────
const resDays     = <?= json_encode($res_days); ?>;
const resTotal    = <?= json_encode($res_total); ?>;
const resResolved = <?= json_encode($res_resolved); ?>;

new Chart(document.getElementById('resolutionChart'), {
    type: 'bar',
    data: { labels: resDays, datasets: [
        { label:'Resolved',     data:resResolved, backgroundColor:'rgba(34,197,94,0.7)',  borderRadius:5 },
        { label:'Unresolved',   data:resTotal.map((t,i)=>t-resResolved[i]), backgroundColor:'rgba(239,68,68,0.55)', borderRadius:5 },
    ]},
    options:{
        responsive:true, maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{ legend:{position:'bottom',labels:{boxWidth:12,padding:14}}, tooltip:tooltipStyle },
        scales:{
            x:{ stacked:true, grid:{color:gridColor}, ticks:{maxTicksLimit:10} },
            y:{ stacked:true, grid:{color:gridColor}, min:0, title:{display:true,text:'Alerts'} }
        }
    }
});

// ── 4. Medication Adherence Trend ─────────────────────────────────────────
const medDays   = <?= json_encode($med_days); ?>;
const medTotals = <?= json_encode($med_totals); ?>;
const medTakens = <?= json_encode($med_takens); ?>;

new Chart(document.getElementById('medChart'), {
    type: 'line',
    data: { labels: medDays, datasets: [
        { label:'Scheduled', data:medTotals, borderColor:'#a78bfa', backgroundColor:'rgba(167,139,250,0.1)', tension:.4, fill:true,  pointRadius:3 },
        { label:'Taken',     data:medTakens, borderColor:'#22c55e', backgroundColor:'rgba(34,197,94,0.15)',  tension:.4, fill:true,  pointRadius:3 },
    ]},
    options:{
        responsive:true, maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{ legend:{position:'bottom',labels:{boxWidth:12,padding:14}}, tooltip:tooltipStyle },
        scales:{
            x:{ grid:{color:gridColor}, ticks:{maxTicksLimit:10} },
            y:{ grid:{color:gridColor}, min:0, title:{display:true,text:'Medications'} }
        }
    }
});

// ── 5. Facility Vitals Trend ──────────────────────────────────────────────
new Chart(document.getElementById('vitalsChart'), {
    type: 'line',
    data: { labels: <?= json_encode($vt_days); ?>, datasets: [
        { label:'Avg BP Systolic', data:<?= json_encode($vt_sys); ?>,   borderColor:'#ef4444', tension:.4, fill:false, pointRadius:2, yAxisID:'yBP' },
        { label:'Avg Blood Sugar', data:<?= json_encode($vt_sugar); ?>, borderColor:'#f59e0b', tension:.4, fill:false, pointRadius:2, yAxisID:'ySugar' },
        { label:'Avg Pulse',       data:<?= json_encode($vt_pulse); ?>, borderColor:'#8b5cf6', tension:.4, fill:false, pointRadius:2, yAxisID:'yPulse' },
        { label:'Avg O₂ Sat',      data:<?= json_encode($vt_o2); ?>,    borderColor:'#06b6d4', tension:.4, fill:false, pointRadius:2, yAxisID:'yO2' },
    ]},
    options:{
        responsive:true, maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{ legend:{position:'bottom',labels:{boxWidth:12,padding:14}}, tooltip:tooltipStyle },
        scales:{
            x: { grid:{color:gridColor}, ticks:{maxTicksLimit:10} },
            yBP:    { position:'left',  title:{display:true,text:'mmHg / mg/dL / bpm'}, grid:{color:gridColor}, min:40  },
            ySugar: { display:false },
            yPulse: { display:false },
            yO2:    { position:'right', title:{display:true,text:'O₂ %'}, grid:{drawOnChartArea:false}, min:85, max:100 },
        }
    }
});

// ── 6. Routine Completion Trend ───────────────────────────────────────────
<?php if (!empty($routine_trend)): ?>
new Chart(document.getElementById('routineChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($rt_days); ?>, datasets: [
        { label:'Completed', data:<?= json_encode($rt_completed); ?>, backgroundColor:'rgba(34,197,94,0.75)',  borderRadius:4 },
        { label:'Skipped',   data:<?= json_encode($rt_skipped); ?>,   backgroundColor:'rgba(239,68,68,0.6)',   borderRadius:4 },
        { label:'Pending',   data:<?= json_encode($rt_pending); ?>,   backgroundColor:'rgba(148,163,184,0.5)', borderRadius:4 },
    ]},
    options:{
        responsive:true, maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        plugins:{ legend:{position:'bottom',labels:{boxWidth:12,padding:14}}, tooltip:tooltipStyle },
        scales:{
            x:{ stacked:true, grid:{color:gridColor}, ticks:{maxTicksLimit:10} },
            y:{ stacked:true, grid:{color:gridColor}, min:0, title:{display:true,text:'Routines'} }
        }
    }
});
<?php endif; ?>

// ── 7. Alert Type Pie ─────────────────────────────────────────────────────
<?php if (!empty($type_breakdown)): ?>
new Chart(document.getElementById('typeChart'), {
    type: 'pie',
    data: {
        labels: <?= json_encode(array_map(fn($l)=>strtoupper(str_replace('_',' ',$l)), $type_labels)); ?>,
        datasets:[{ data:<?= json_encode($type_cnts); ?>, backgroundColor:['#87A96B','#6D9B8E','#ef4444','#f59e0b','#06b6d4','#a78bfa'], borderWidth:0 }]
    },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{position:'bottom',labels:{boxWidth:12,padding:10}}, tooltip:tooltipStyle } }
});
<?php endif; ?>

// ── Animate top-resident bars ─────────────────────────────────────────────
document.querySelectorAll('.top-fill').forEach(bar => {
    const t = bar.style.width;
    bar.style.width = '0%';
    setTimeout(() => { bar.style.width = t; }, 300);
});

// ── Animate caregiver rate bars ───────────────────────────────────────────
document.querySelectorAll('.rate-bar-fill').forEach(bar => {
    const t = bar.style.width;
    bar.style.width = '0%';
    setTimeout(() => { bar.style.width = t; bar.style.transition = 'width 0.9s ease'; }, 350);
});
</script>
</body>
</html>