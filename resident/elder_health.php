<?php
session_start();
include '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];

$allowed_filters = ['all', 'week', 'month'];
$active_filter   = in_array($_GET['filter'] ?? '', $allowed_filters) ? $_GET['filter'] : 'all';

$limit  = 10;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$date_condition = '';
switch ($active_filter) {
    case 'week':  $date_condition = "AND DATE(hl.logged_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";  break;
    case 'month': $date_condition = "AND DATE(hl.logged_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"; break;
}

$cnt_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM health_logs hl WHERE hl.resident_id = ? $date_condition");
$cnt_stmt->bind_param("i", $user_id);
$cnt_stmt->execute();
$total_logs  = $cnt_stmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, (int)ceil($total_logs / $limit));
$cnt_stmt->close();
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $limit; }

$hl_stmt = $conn->prepare("
    SELECT hl.*,
           DATE_FORMAT(hl.logged_at, '%b %d, %Y')  AS log_date,
           DATE_FORMAT(hl.logged_at, '%h:%i %p')   AS log_time,
           COALESCE(u.full_name, 'Unknown')         AS caregiver_name
    FROM health_logs hl
    LEFT JOIN users u ON u.user_id = hl.caregiver_id
    WHERE hl.resident_id = ?
    $date_condition
    ORDER BY hl.logged_at DESC
    LIMIT ? OFFSET ?
");
$hl_stmt->bind_param("iii", $user_id, $limit, $offset);
$hl_stmt->execute();
$health_logs = $hl_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$hl_stmt->close();

$stat_stmt = $conn->prepare("
    SELECT
        ROUND(AVG(blood_pressure_systolic),1)  AS avg_bp_sys,
        ROUND(AVG(blood_pressure_diastolic),1) AS avg_bp_dia,
        ROUND(AVG(blood_sugar),1)              AS avg_sugar,
        ROUND(AVG(pulse),1)                    AS avg_pulse,
        ROUND(AVG(weight),1)                   AS avg_weight,
        ROUND(AVG(temperature),1)              AS avg_temp,
        ROUND(AVG(oxygen_saturation),1)        AS avg_o2,
        MAX(weight)      AS max_weight,
        MIN(weight)      AS min_weight,
        MAX(blood_sugar) AS max_sugar,
        MIN(blood_sugar) AS min_sugar
    FROM health_logs
    WHERE resident_id = ?
      AND logged_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stat_stmt->bind_param("i", $user_id);
$stat_stmt->execute();
$health_stats = $stat_stmt->get_result()->fetch_assoc();
$stat_stmt->close();

$lat_stmt = $conn->prepare("
    SELECT hl.*, COALESCE(u.full_name,'Unknown') AS caregiver_name
    FROM health_logs hl
    LEFT JOIN users u ON u.user_id = hl.caregiver_id
    WHERE hl.resident_id = ?
    ORDER BY hl.logged_at DESC
    LIMIT 1
");
$lat_stmt->bind_param("i", $user_id);
$lat_stmt->execute();
$latest_health = $lat_stmt->get_result()->fetch_assoc();
$lat_stmt->close();

$logged_today = $latest_health && date('Y-m-d', strtotime($latest_health['logged_at'])) === date('Y-m-d');

$trend_stmt = $conn->prepare("
    SELECT
        DATE(logged_at)                            AS log_date,
        ROUND(AVG(blood_pressure_systolic),1)      AS bp_sys,
        ROUND(AVG(blood_pressure_diastolic),1)     AS bp_dia,
        ROUND(AVG(blood_sugar),1)                  AS sugar,
        ROUND(AVG(pulse),1)                        AS pulse,
        ROUND(AVG(oxygen_saturation),1)            AS o2
    FROM health_logs
    WHERE resident_id = ?
      AND logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(logged_at)
    ORDER BY log_date ASC
");
$trend_stmt->bind_param("i", $user_id);
$trend_stmt->execute();
$health_trends = $trend_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$trend_stmt->close();

$exp_stmt = $conn->prepare("
    SELECT hl.*,
           DATE_FORMAT(hl.logged_at, '%b %d, %Y')  AS log_date,
           DATE_FORMAT(hl.logged_at, '%h:%i %p')   AS log_time,
           COALESCE(u.full_name,'Unknown')          AS caregiver_name
    FROM health_logs hl
    LEFT JOIN users u ON u.user_id = hl.caregiver_id
    WHERE hl.resident_id = ?
    ORDER BY hl.logged_at DESC
");
$exp_stmt->bind_param("i", $user_id);
$exp_stmt->execute();
$all_logs_for_export = $exp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$exp_stmt->close();

$um = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id=? AND is_read=0");
$um->bind_param("i", $user_id); $um->execute();
$unread_messages = $um->get_result()->fetch_assoc()['cnt'] ?? 0;
$um->close();

$ua = $conn->prepare("SELECT COUNT(*) AS cnt FROM alerts WHERE resident_id=? AND resolved=0");
$ua->bind_param("i", $user_id); $ua->execute();
$unresolved_alerts = $ua->get_result()->fetch_assoc()['cnt'] ?? 0;
$ua->close();

function vital_flag(string $type, $val): array {
    if ($val === null || $val === '') return ['—', 'text-muted', 'trend-stable'];
    $v = (float)$val;
    switch ($type) {
        case 'bp_sys':  return $v > 140 ? ['High','text-danger','trend-up'] : ($v < 90  ? ['Low','text-primary','trend-down'] : ['Normal','text-success','trend-stable']);
        case 'bp_dia':  return $v > 90  ? ['High','text-danger','trend-up'] : ($v < 60  ? ['Low','text-primary','trend-down'] : ['Normal','text-success','trend-stable']);
        case 'sugar':   return $v > 180 ? ['High','text-danger','trend-up'] : ($v < 70  ? ['Low','text-primary','trend-down'] : ['Normal','text-success','trend-stable']);
        case 'pulse':   return $v > 100 ? ['High','text-danger','trend-up'] : ($v < 50  ? ['Low','text-primary','trend-down'] : ['Normal','text-success','trend-stable']);
        case 'o2':      return $v < 95  ? ['Low','text-primary','trend-down'] : ['Normal','text-success','trend-stable'];
        case 'temp':    return $v > 37.8 ? ['High','text-danger','trend-up'] : ($v < 36.0 ? ['Low','text-primary','trend-down'] : ['Normal','text-success','trend-stable']);
    }
    return ['—','text-muted','trend-stable'];
}

function stat_dot(string $type, $val): string {
    [,$cls] = vital_flag($type, $val);
    if ($cls === 'text-danger')  return 'status-critical';
    if ($cls === 'text-primary') return 'status-warning';
    return 'status-normal';
}

$chart_labels = json_encode(array_map(fn($r) => date('M d', strtotime($r['log_date'])), $health_trends));
$chart_bp_sys = json_encode(array_column($health_trends, 'bp_sys'));
$chart_bp_dia = json_encode(array_column($health_trends, 'bp_dia'));
$chart_sugar  = json_encode(array_column($health_trends, 'sugar'));
$chart_pulse  = json_encode(array_column($health_trends, 'pulse'));
$chart_o2     = json_encode(array_column($health_trends, 'o2'));

$export_rows = array_map(fn($l) => [
    $l['log_date'], $l['log_time'],
    ($l['blood_pressure_systolic'] ?? '') . '/' . ($l['blood_pressure_diastolic'] ?? ''),
    $l['blood_sugar'] ?? '', $l['pulse'] ?? '', $l['weight'] ?? '',
    $l['temperature'] ?? '', $l['oxygen_saturation'] ?? '',
    str_replace('"', '""', $l['notes'] ?? ''),
    $l['caregiver_name'],
], $all_logs_for_export);
$export_json = json_encode($export_rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Health Data – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        /* ═══════════════════════════════════════════════════════════════
           SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
           Elder Health Data · Larger base font for readability
        ═══════════════════════════════════════════════════════════════ */
        :root {
            --s50:  #F2F6EF;
            --s100: #E3EDDB;
            --s200: #C4D9B4;
            --s300: #9DC07E;
            --s400: #7AA658;
            --s500: #5E8A40;
            --s600: #4A6E30;
            --s700: #365220;
            --s800: #243816;

            --w50:  #FDFAF5;
            --w100: #F7F1E5;
            --w200: #EDE0C8;

            --st300: #B8B0A4;
            --st500: #7A7268;
            --st700: #4A4540;

            --radius-sm: 8px;
            --radius-md: 14px;
            --radius-lg: 22px;

            --shadow-soft: 0 2px 12px rgba(36,56,22,.07), 0 1px 3px rgba(36,56,22,.05);
            --shadow-card: 0 4px 24px rgba(36,56,22,.09), 0 1px 4px rgba(36,56,22,.06);
            --shadow-lift: 0 12px 40px rgba(36,56,22,.12), 0 2px 8px rgba(36,56,22,.08);
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--w50);
            background-image:
                radial-gradient(ellipse 70% 50% at 90% 0%, rgba(157,192,126,.08) 0%, transparent 55%),
                radial-gradient(ellipse 50% 40% at 0% 100%, rgba(122,166,88,.05) 0%, transparent 50%);
            color: var(--st700);
            font-size: 17px; /* Larger for elders */
            line-height: 1.7;
            min-height: 100vh;
            margin: 0; padding: 0;
        }

        h1, h2, h3, h4, h5 {
            font-family: 'Cormorant Garamond', serif;
            color: var(--s800);
        }

        /* ── Sidebar ──────────────────────────────────────────── */
        .sidebar {
            width: 240px;
            height: 100vh;
            position: fixed;
            background: var(--s800);
            display: flex;
            flex-direction: column;
            z-index: 1000;
            overflow: hidden;
        }

        .sidebar::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(ellipse 120% 60% at 50% -10%, rgba(157,192,126,.18) 0%, transparent 60%),
                radial-gradient(ellipse 80% 80% at 110% 110%, rgba(94,138,64,.15) 0%, transparent 55%);
            pointer-events: none;
        }

        .sidebar-header {
            padding: 26px 20px 18px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            flex-shrink: 0;
            position: relative;
        }

        .brand-mark {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }

        .brand-icon {
            width: 34px; height: 34px;
            background: linear-gradient(135deg, var(--s300), var(--s500));
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; color: white;
            box-shadow: 0 3px 10px rgba(0,0,0,.25);
            flex-shrink: 0;
        }

        .sidebar-header h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 17px;
            font-weight: 600;
            color: white;
            line-height: 1.1;
            margin: 0;
        }

        .sidebar-header small {
            font-size: 10px;
            color: rgba(255,255,255,.4);
            letter-spacing: .08em;
            text-transform: uppercase;
            font-weight: 500;
            display: block;
            margin-left: 44px;
            margin-top: 2px;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 10px 0;
            position: relative;
        }

        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.15); border-radius: 3px; }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 12px 11px 22px;
            color: rgba(255,255,255,.6);
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            transition: all .2s;
            margin: 2px 10px;
            border-radius: var(--radius-sm);
            position: relative;
            min-height: 46px;
        }

        .sidebar a:hover { background: rgba(255,255,255,.1); color: white; }

        .sidebar a.active {
            background: rgba(157,192,126,.2);
            color: #C8E6A0;
        }

        .sidebar a.active::before {
            content: '';
            position: absolute;
            left: -10px; top: 20%; bottom: 20%;
            width: 3px;
            background: var(--s300);
            border-radius: 0 3px 3px 0;
        }

        .sidebar i { width: 20px; text-align: center; font-size: 15px; opacity: .85; }

        .sb-badge {
            margin-left: auto;
            background: #8B3A3A;
            color: white;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 20px;
        }

        /* Sidebar stats panel */
        .sidebar-stats {
            margin: 10px 12px;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: var(--radius-md);
            padding: 13px 14px;
            position: relative;
        }

        .sidebar-stats-title {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: rgba(255,255,255,.35);
            margin-bottom: 10px;
        }

        .ss-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 7px;
            font-size: 12px;
        }

        .ss-row:last-child { margin-bottom: 0; }
        .ss-val { font-weight: 700; color: rgba(255,255,255,.8); }

        .sidebar-footer {
            flex-shrink: 0;
            border-top: 1px solid rgba(255,255,255,.08);
            padding: 14px 10px;
            position: relative;
        }

        .sidebar-footer a { margin: 0; color: rgba(255,255,255,.5) !important; font-size: 15px; }
        .sidebar-footer a:hover { color: rgba(255,255,255,.8) !important; }

        /* Status dots */
        .status-indicator { width: 9px; height: 9px; border-radius: 50%; display: inline-block; margin-right: 6px; flex-shrink: 0; }
        .status-normal   { background: #4A7C59; }
        .status-warning  { background: #D4A853; }
        .status-critical { background: #C87A7A; }

        /* ── Layout ──────────────────────────────────────────── */
        .content { margin-left: 240px; padding: 28px; min-height: 100vh; }

        /* ── Topbar ──────────────────────────────────────────── */
        .topbar {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px 28px;
            box-shadow: var(--shadow-card);
            border: 1px solid rgba(196,217,180,.3);
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .topbar h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 24px;
            font-weight: 500;
            color: var(--s800);
            margin: 0 0 3px 0;
        }

        .topbar p { font-size: 13px; color: var(--st300); margin: 0; }

        .topbar-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        .export-btn {
            background: linear-gradient(135deg, var(--s400), var(--s600));
            border: none;
            border-radius: var(--radius-sm);
            color: white;
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all .25s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .export-btn:hover { opacity: .9; transform: translateY(-1px); box-shadow: var(--shadow-card); }

        .print-btn {
            background: white;
            border: 1.5px solid var(--s200);
            border-radius: var(--radius-sm);
            color: var(--s600);
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .print-btn:hover { background: var(--s50); border-color: var(--s300); }

        /* ── Section card ─────────────────────────────────────── */
        .health-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 26px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-card);
            border: 1px solid rgba(196,217,180,.3);
            animation: fadeUp .4s ease both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .health-card:nth-child(2) { animation-delay: .08s; }
        .health-card:nth-child(3) { animation-delay: .14s; }
        .health-card:nth-child(4) { animation-delay: .20s; }

        .card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 17px;
            font-weight: 700;
            color: var(--s800);
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .card-title i { color: var(--s400); font-size: 16px; }

        /* ── Current vitals ───────────────────────────────────── */
        .vitals-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            margin-bottom: 18px;
        }

        .metric-tile {
            border-radius: var(--radius-md);
            padding: 16px 10px;
            text-align: center;
            transition: all .2s;
        }

        .metric-tile:hover { transform: translateY(-2px); box-shadow: var(--shadow-soft); }

        .mt-bp     { background: linear-gradient(135deg, #E3F2FD, #BBDEFB); }
        .mt-sugar  { background: linear-gradient(135deg, #F3E5F5, #E1BEE7); }
        .mt-pulse  { background: linear-gradient(135deg, #FFEBEE, #FFCDD2); }
        .mt-o2     { background: linear-gradient(135deg, #E0F7FA, #B2EBF2); }
        .mt-temp   { background: linear-gradient(135deg, #E8F5E9, #C8E6C9); }
        .mt-weight { background: linear-gradient(135deg, #FFF3E0, #FFE0B2); }

        .metric-tile-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--st500); margin-bottom: 6px; }
        .metric-tile-value { font-size: 22px; font-weight: 800; color: var(--s800); line-height: 1; margin-bottom: 3px; font-family: 'Outfit', sans-serif; letter-spacing: -.02em; }
        .metric-tile-unit  { font-size: 11px; color: var(--st300); margin-bottom: 6px; }

        .metric-pill {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 20px;
        }

        .trend-stable { background: #DDEFD8; color: #3A6830; }
        .trend-up     { background: #F5DADA; color: #6A2020; }
        .trend-down   { background: #DAE8F5; color: #1A4870; }

        .recorded-by-strip {
            background: var(--s50);
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            font-size: 14px;
            color: var(--st500);
            margin-bottom: 10px;
        }

        .notes-box {
            background: #F0FDF4;
            border-left: 4px solid var(--s300);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            font-size: 15px;
            color: var(--s700);
            line-height: 1.6;
            margin-top: 10px;
        }

        .normal-ranges-note {
            font-size: 13px;
            color: var(--st300);
            margin: 10px 0 0 0;
        }

        /* ── Updated badge ────────────────────────────────────── */
        .update-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border-radius: 20px;
            padding: 5px 14px;
            font-size: 13px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
        }

        .badge-today  { background: #DDEFD8; color: #3A6830; }
        .badge-old    { background: #FAECC8; color: #7A5010; }

        /* ── Chart tabs ───────────────────────────────────────── */
        .chart-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }

        .ctab {
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            border: 1.5px solid var(--s200);
            background: white;
            color: var(--st500);
            font-family: 'Outfit', sans-serif;
            transition: all .2s;
        }

        .ctab.active {
            background: linear-gradient(135deg, var(--s300), var(--s500));
            color: white;
            border-color: transparent;
        }

        .chart-wrap { position: relative; height: 280px; }

        /* ── Filter bar ───────────────────────────────────────── */
        .filter-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 18px; }

        .filter-btn {
            padding: 9px 20px;
            border: 1.5px solid var(--s200);
            background: white;
            border-radius: 20px;
            color: var(--s600);
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            text-decoration: none;
            transition: all .2s;
            cursor: pointer;
        }

        .filter-btn:hover, .filter-btn.active {
            background: linear-gradient(135deg, var(--s300), var(--s500));
            color: white;
            border-color: transparent;
        }

        .export-btn-inline {
            background: linear-gradient(135deg, var(--s500), var(--s700));
            border: none;
            border-radius: 20px;
            color: white;
            padding: 9px 20px;
            font-size: 14px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            margin-left: auto;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .export-btn-inline:hover { opacity: .9; }

        /* ── Health log items ─────────────────────────────────── */
        .health-log-item {
            background: var(--s50);
            border-radius: var(--radius-md);
            padding: 18px 20px;
            margin-bottom: 12px;
            border-left: 4px solid var(--s300);
            transition: all .25s;
        }

        .health-log-item:hover { transform: translateX(5px); box-shadow: var(--shadow-soft); background: white; }
        .health-log-item.has-abnormal { border-left-color: #C87A7A; }

        .log-date {
            font-size: 14px;
            font-weight: 700;
            color: var(--s500);
            margin-bottom: 3px;
        }

        .log-caregiver { font-size: 12px; color: var(--st300); margin-bottom: 12px; }

        .vitals-row { display: flex; flex-wrap: wrap; gap: 7px; }

        .vital-sign {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: white;
            border: 1px solid var(--s100);
            padding: 6px 13px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
            color: var(--st700);
        }

        .vital-sign.abnormal {
            background: #FFF5F5;
            border-color: rgba(200,122,122,.3);
            color: #6A2020;
        }

        .vital-sign.low-val {
            background: #EFF7FF;
            border-color: rgba(107,170,212,.3);
            color: #1A4870;
        }

        .vital-value { font-weight: 800; color: var(--s700); }
        .vital-sign.abnormal .vital-value { color: #8B3A3A; }
        .vital-sign.low-val  .vital-value { color: #1A4870; }

        .abn-tag { font-size: 10px; font-weight: 800; color: #8B3A3A; background: #F5DADA; padding: 1px 5px; border-radius: 4px; }
        .low-tag { font-size: 10px; font-weight: 800; color: #1A4870; background: #DAE8F5; padding: 1px 5px; border-radius: 4px; }

        .log-badges { display: flex; gap: 6px; flex-wrap: wrap; }

        .log-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .lb-notes    { background: #EFF7FF; color: #1A4870; }
        .lb-abnormal { background: #F5DADA; color: #6A2020; }

        /* ── Pagination ───────────────────────────────────────── */
        .pagination { justify-content: center; margin-top: 20px; flex-wrap: wrap; }

        .page-link {
            border: 1.5px solid var(--s100);
            color: var(--s600);
            margin: 0 3px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 14px;
            padding: 8px 14px;
            transition: all .2s;
        }

        .page-link:hover { background: var(--s50); border-color: var(--s300); color: var(--s700); }
        .page-item.active .page-link { background: linear-gradient(135deg, var(--s300), var(--s500)); border-color: transparent; color: white; }

        /* ── Empty state ──────────────────────────────────────── */
        .empty-state { text-align: center; padding: 50px 20px; color: var(--st300); }
        .empty-state i { font-size: 3.5rem; color: var(--s200); margin-bottom: 16px; display: block; animation: gentleFloat 4s ease-in-out infinite; }
        .empty-state h5 { font-family: 'Outfit', sans-serif; font-size: 17px; font-weight: 700; color: var(--s700); margin-bottom: 8px; }
        .empty-state p { font-size: 15px; }

        @keyframes gentleFloat {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-10px); }
        }

        /* ── Print ────────────────────────────────────────────── */
        @media print {
            .no-print { display: none !important; }
            .sidebar  { display: none !important; }
            .content  { margin-left: 0 !important; padding: 10px !important; }
            .health-card { box-shadow: none !important; border: 1px solid #ddd !important; }
        }

        /* ── Responsive ──────────────────────────────────────── */
        @media (max-width: 1200px) { .vitals-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .content { margin-left: 0; padding: 16px; }
            .vitals-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<!-- ══ SIDEBAR ════════════════════════════════════════════════════════════ -->
<div class="sidebar no-print">
    <div class="sidebar-header">
        <div class="brand-mark">
            <div class="brand-icon"><i class="fas fa-leaf"></i></div>
            <h4>SmartCare<br>Guardian</h4>
        </div>
        <small>Resident Portal</small>
    </div>
    <div class="sidebar-nav">
        <a href="resident_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="elder_profile.php"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="elder_health.php" class="active"><i class="fa-solid fa-heart-pulse"></i> My Health Data</a>
        <a href="elder_schedule.php"><i class="fa-solid fa-calendar-check"></i> Daily Schedule</a>
        <a href="elder_medications.php"><i class="fa-solid fa-pills"></i> My Medications</a>
        <a href="elder_appointments.php"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="elder_messages.php">
            <i class="fa-solid fa-comments"></i> Messages
            <?php if ($unread_messages > 0): ?><span class="sb-badge"><?= $unread_messages ?></span><?php endif; ?>
        </a>
        <a href="resident_alerts.php">
            <i class="fa-solid fa-bell"></i> Health Alerts
            <?php if ($unresolved_alerts > 0): ?><span class="sb-badge"><?= $unresolved_alerts ?></span><?php endif; ?>
        </a>

        <?php if ($health_stats && $health_stats['avg_bp_sys']): ?>
        <div class="sidebar-stats mt-2">
            <div class="sidebar-stats-title">30-Day Averages</div>
            <?php foreach ([
                ['BP',     $health_stats['avg_bp_sys'] . '/' . $health_stats['avg_bp_dia'], stat_dot('bp_sys', $health_stats['avg_bp_sys'])],
                ['Sugar',  $health_stats['avg_sugar'] . ' mg/dL',                           stat_dot('sugar',  $health_stats['avg_sugar'])],
                ['Pulse',  $health_stats['avg_pulse'] . ' bpm',                             stat_dot('pulse',  $health_stats['avg_pulse'])],
                ['Weight', $health_stats['avg_weight'] . ' kg',                             'status-normal'],
            ] as [$lbl, $val, $dot]): ?>
                <div class="ss-row">
                    <span style="color:rgba(255,255,255,.5);font-size:12px;"><span class="status-indicator <?= $dot ?>"></span><?= $lbl ?></span>
                    <span class="ss-val"><?= $val ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="sidebar-footer">
        <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════════════ -->
<div class="content">

    <!-- Topbar -->
    <div class="topbar no-print">
        <div>
            <h4><i class="fas fa-heartbeat me-2" style="font-size:20px;color:var(--s500);"></i>My Health Data</h4>
            <p>Track and monitor your health vitals</p>
        </div>
        <div class="topbar-actions">
            <button class="export-btn" onclick="exportHealthData()"><i class="fas fa-download"></i>Export CSV</button>
            <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
        </div>
    </div>

    <!-- ── Current Vitals ──────────────────────────────────────────────── -->
    <div class="health-card">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div class="card-title"><i class="fas fa-heartbeat"></i>Current Health Status</div>
            <?php if ($latest_health): ?>
                <span class="update-badge <?= $logged_today ? 'badge-today' : 'badge-old' ?>">
                    <i class="fas fa-<?= $logged_today ? 'check-circle' : 'clock' ?>"></i>
                    <?= $logged_today ? 'Updated Today' : 'Last: ' . date('M d', strtotime($latest_health['logged_at'])) ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if ($latest_health):
            $bp_s  = $latest_health['blood_pressure_systolic'];
            $bp_d  = $latest_health['blood_pressure_diastolic'];
            $sugar = $latest_health['blood_sugar'];
            $pulse = $latest_health['pulse'];
            $wt    = $latest_health['weight'];
            $temp  = $latest_health['temperature'];
            $o2    = $latest_health['oxygen_saturation'];
            [$bp_lbl,, $bp_tc]  = vital_flag('bp_sys', $bp_s);
            [$sg_lbl,, $sg_tc]  = vital_flag('sugar',  $sugar);
            [$pu_lbl,, $pu_tc]  = vital_flag('pulse',  $pulse);
            [$o2_lbl,, $o2_tc]  = vital_flag('o2',     $o2);
            [$tm_lbl,, $tm_tc]  = vital_flag('temp',   $temp);
        ?>
        <div class="vitals-grid">
            <div class="metric-tile mt-bp">
                <div class="metric-tile-label">Blood Pressure</div>
                <div class="metric-tile-value" style="font-size:18px;"><?= $bp_s ?>/<?= $bp_d ?></div>
                <div class="metric-tile-unit">mmHg</div>
                <span class="metric-pill <?= $bp_tc ?>"><?= $bp_lbl ?></span>
            </div>
            <div class="metric-tile mt-sugar">
                <div class="metric-tile-label">Blood Sugar</div>
                <div class="metric-tile-value"><?= $sugar ?></div>
                <div class="metric-tile-unit">mg/dL</div>
                <span class="metric-pill <?= $sg_tc ?>"><?= $sg_lbl ?></span>
            </div>
            <div class="metric-tile mt-pulse">
                <div class="metric-tile-label">Heart Rate</div>
                <div class="metric-tile-value"><?= $pulse ?></div>
                <div class="metric-tile-unit">bpm</div>
                <span class="metric-pill <?= $pu_tc ?>"><?= $pu_lbl ?></span>
            </div>
            <div class="metric-tile mt-o2">
                <div class="metric-tile-label">Oxygen Sat.</div>
                <div class="metric-tile-value"><?= $o2 ?? '--' ?>%</div>
                <div class="metric-tile-unit">SpO₂</div>
                <?php if ($o2): ?><span class="metric-pill <?= $o2_tc ?>"><?= $o2_lbl ?></span><?php endif; ?>
            </div>
            <div class="metric-tile mt-temp">
                <div class="metric-tile-label">Temperature</div>
                <div class="metric-tile-value"><?= $temp ?? '--' ?>°C</div>
                <div class="metric-tile-unit">&nbsp;</div>
                <?php if ($temp): ?><span class="metric-pill <?= $tm_tc ?>"><?= $tm_lbl ?></span><?php endif; ?>
            </div>
            <div class="metric-tile mt-weight">
                <div class="metric-tile-label">Weight</div>
                <div class="metric-tile-value"><?= $wt ?? '--' ?></div>
                <div class="metric-tile-unit">kg</div>
            </div>
        </div>

        <div class="recorded-by-strip">
            <i class="fas fa-user-nurse me-2" style="color:var(--s400);"></i>
            Recorded by <strong><?= htmlspecialchars($latest_health['caregiver_name']) ?></strong>
            &nbsp;·&nbsp; <?= date('d M Y, H:i', strtotime($latest_health['logged_at'])) ?>
        </div>

        <?php if ($latest_health['notes']): ?>
        <div class="notes-box">
            <strong><i class="fas fa-sticky-note me-2"></i>Caregiver Notes:</strong><br>
            <?= nl2br(htmlspecialchars($latest_health['notes'])) ?>
        </div>
        <?php endif; ?>

        <p class="normal-ranges-note">
            <i class="fas fa-info-circle me-1"></i>Normal ranges:
            BP 90–140/60–90 mmHg &nbsp;·&nbsp; Sugar 70–180 mg/dL &nbsp;·&nbsp;
            Pulse 50–100 bpm &nbsp;·&nbsp; O₂ ≥95% &nbsp;·&nbsp; Temp 36.0–37.8 °C
        </p>

        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-heartbeat"></i>
            <h5>No Health Data Available</h5>
            <p>Your caregiver will record your health vitals soon.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── 7-Day Trends Chart ──────────────────────────────────────────── -->
    <?php if (!empty($health_trends)): ?>
    <div class="health-card">
        <div class="card-title"><i class="fas fa-chart-line"></i>Health Trends — Last 7 Days</div>
        <div class="chart-tabs no-print">
            <button class="ctab active" data-series="bp">Blood Pressure</button>
            <button class="ctab" data-series="sugar">Blood Sugar</button>
            <button class="ctab" data-series="pulse">Heart Rate</button>
            <button class="ctab" data-series="o2">Oxygen</button>
        </div>
        <div class="chart-wrap">
            <canvas id="trendsChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Health History ──────────────────────────────────────────────── -->
    <div class="health-card">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="card-title" style="margin-bottom:0;">
                <i class="fas fa-history"></i>Health History
                <span style="font-size:13px;font-weight:500;color:var(--st300);font-family:'Outfit',sans-serif;"><?= $total_logs ?> records</span>
            </div>
        </div>

        <div class="filter-bar no-print">
            <a href="?filter=all&page=1"   class="filter-btn <?= $active_filter==='all'   ? 'active' : '' ?>">All Records</a>
            <a href="?filter=week&page=1"  class="filter-btn <?= $active_filter==='week'  ? 'active' : '' ?>">Last 7 Days</a>
            <a href="?filter=month&page=1" class="filter-btn <?= $active_filter==='month' ? 'active' : '' ?>">Last 30 Days</a>
            <button class="export-btn-inline" onclick="exportHealthData()">
                <i class="fas fa-download"></i>Export All (<?= count($all_logs_for_export) ?>)
            </button>
        </div>

        <?php if (!empty($health_logs)):
            foreach ($health_logs as $log):
                [$bpL,,$bpT] = vital_flag('bp_sys', $log['blood_pressure_systolic']);
                [$sgL,,$sgT] = vital_flag('sugar',  $log['blood_sugar']);
                [$puL,,$puT] = vital_flag('pulse',  $log['pulse']);
                [$o2L,,$o2T] = vital_flag('o2',     $log['oxygen_saturation']);
                [$tmL,,$tmT] = vital_flag('temp',   $log['temperature']);
                $any_abnormal = in_array('trend-up',  [$bpT,$sgT,$puT,$tmT])
                             || in_array('trend-down', [$bpT,$sgT,$puT,$o2T,$tmT]);
        ?>
        <div class="health-log-item <?= $any_abnormal ? 'has-abnormal' : '' ?>">
            <div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
                <div>
                    <div class="log-date">
                        <i class="far fa-calendar me-1"></i><?= $log['log_date'] ?>
                        <i class="far fa-clock ms-2 me-1"></i><?= $log['log_time'] ?>
                    </div>
                    <div class="log-caregiver"><i class="fas fa-user-nurse me-1"></i>Recorded by: <?= htmlspecialchars($log['caregiver_name']) ?></div>
                </div>
                <div class="log-badges">
                    <?php if ($log['notes']): ?>
                        <span class="log-badge lb-notes"><i class="fas fa-sticky-note"></i>Notes</span>
                    <?php endif; ?>
                    <?php if ($any_abnormal): ?>
                        <span class="log-badge lb-abnormal"><i class="fas fa-exclamation-triangle"></i>Abnormal Value</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="vitals-row">
                <span class="vital-sign <?= $bpT==='trend-up' ? 'abnormal' : ($bpT==='trend-down' ? 'low-val' : '') ?>">
                    <i class="fas fa-tint" style="color:#4285F4;font-size:12px;"></i>
                    BP: <span class="vital-value"><?= $log['blood_pressure_systolic'] ?>/<?= $log['blood_pressure_diastolic'] ?></span>
                    <?php if ($bpT!=='trend-stable'): ?><span class="<?= $bpT==='trend-up'?'abn-tag':'low-tag' ?>"><?= $bpL ?></span><?php endif; ?>
                </span>
                <span class="vital-sign <?= $sgT==='trend-up' ? 'abnormal' : ($sgT==='trend-down' ? 'low-val' : '') ?>">
                    <i class="fas fa-syringe" style="color:#9C27B0;font-size:12px;"></i>
                    Sugar: <span class="vital-value"><?= $log['blood_sugar'] ?> mg/dL</span>
                    <?php if ($sgT!=='trend-stable'): ?><span class="<?= $sgT==='trend-up'?'abn-tag':'low-tag' ?>"><?= $sgL ?></span><?php endif; ?>
                </span>
                <span class="vital-sign <?= $puT==='trend-up' ? 'abnormal' : ($puT==='trend-down' ? 'low-val' : '') ?>">
                    <i class="fas fa-heart" style="color:#FF6B6B;font-size:12px;"></i>
                    Pulse: <span class="vital-value"><?= $log['pulse'] ?> bpm</span>
                    <?php if ($puT!=='trend-stable'): ?><span class="<?= $puT==='trend-up'?'abn-tag':'low-tag' ?>"><?= $puL ?></span><?php endif; ?>
                </span>
                <span class="vital-sign">
                    <i class="fas fa-weight" style="color:#FF9800;font-size:12px;"></i>
                    Weight: <span class="vital-value"><?= $log['weight'] ?> kg</span>
                </span>
                <?php if ($log['temperature']): ?>
                <span class="vital-sign <?= $tmT==='trend-up' ? 'abnormal' : ($tmT==='trend-down' ? 'low-val' : '') ?>">
                    <i class="fas fa-thermometer-half" style="color:#FF5722;font-size:12px;"></i>
                    Temp: <span class="vital-value"><?= $log['temperature'] ?>°C</span>
                    <?php if ($tmT!=='trend-stable'): ?><span class="<?= $tmT==='trend-up'?'abn-tag':'low-tag' ?>"><?= $tmL ?></span><?php endif; ?>
                </span>
                <?php endif; ?>
                <?php if ($log['oxygen_saturation']): ?>
                <span class="vital-sign <?= $o2T==='trend-down' ? 'low-val' : '' ?>">
                    <i class="fas fa-wind" style="color:#00BCD4;font-size:12px;"></i>
                    O₂: <span class="vital-value"><?= $log['oxygen_saturation'] ?>%</span>
                    <?php if ($o2T!=='trend-stable'): ?><span class="low-tag"><?= $o2L ?></span><?php endif; ?>
                </span>
                <?php endif; ?>
            </div>

            <?php if ($log['notes']): ?>
            <div class="notes-box" style="margin-top:12px;">
                <strong>Notes:</strong> <?= nl2br(htmlspecialchars($log['notes'])) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($total_pages > 1): ?>
        <nav aria-label="Health logs pagination">
            <ul class="pagination flex-wrap">
                <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?filter=<?= $active_filter ?>&page=<?= $page-1 ?>"><i class="fas fa-chevron-left"></i> Prev</a></li>
                <?php endif; ?>
                <?php for ($i=1; $i<=$total_pages; $i++): ?>
                    <li class="page-item <?= $i==$page?'active':'' ?>">
                        <a class="page-link" href="?filter=<?= $active_filter ?>&page=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <li class="page-item"><a class="page-link" href="?filter=<?= $active_filter ?>&page=<?= $page+1 ?>">Next <i class="fas fa-chevron-right"></i></a></li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>

        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-clipboard-list"></i>
            <h5>No Records Found</h5>
            <p>
                <?= $active_filter!=='all' ? 'No data in this period. Try "All Records" to see everything.' : 'Your caregiver will record your health vitals soon.' ?>
            </p>
            <?php if ($active_filter!=='all'): ?>
                <a href="?filter=all&page=1" class="filter-btn active mt-2 d-inline-block">Show All Records</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
<?php if (!empty($health_trends)): ?>
const chartLabels = <?= $chart_labels ?>;
const seriesData  = {
    bp: {
        datasets:[
            { label:'Systolic',  data:<?= $chart_bp_sys ?>, borderColor:'#C87A7A', backgroundColor:'rgba(200,122,122,.1)',  tension:.4, fill:true, pointRadius:4, pointBackgroundColor:'#C87A7A' },
            { label:'Diastolic', data:<?= $chart_bp_dia ?>, borderColor:'#D4A853', backgroundColor:'rgba(212,168,83,.1)', tension:.4, fill:true, pointRadius:4, pointBackgroundColor:'#D4A853' },
        ], yMin:50, yMax:180, yLabel:'mmHg',
    },
    sugar: {
        datasets:[{ label:'Blood Sugar', data:<?= $chart_sugar ?>, borderColor:'#9B7EC8', backgroundColor:'rgba(155,126,200,.1)', tension:.4, fill:true, pointRadius:4, pointBackgroundColor:'#9B7EC8' }],
        yMin:50, yMax:300, yLabel:'mg/dL',
    },
    pulse: {
        datasets:[{ label:'Heart Rate', data:<?= $chart_pulse ?>, borderColor:'#C87A7A', backgroundColor:'rgba(200,122,122,.1)', tension:.4, fill:true, pointRadius:4, pointBackgroundColor:'#C87A7A' }],
        yMin:30, yMax:150, yLabel:'bpm',
    },
    o2: {
        datasets:[{ label:'Oxygen Sat.', data:<?= $chart_o2 ?>, borderColor:'#5BA4A4', backgroundColor:'rgba(91,164,164,.1)', tension:.4, fill:true, pointRadius:4, pointBackgroundColor:'#5BA4A4' }],
        yMin:85, yMax:101, yLabel:'%',
    },
};

Chart.defaults.font.family = 'Outfit, sans-serif';
Chart.defaults.font.size = 13;
const ctx = document.getElementById('trendsChart').getContext('2d');
let trendsChart = new Chart(ctx, {
    type: 'line',
    data: { labels: chartLabels, datasets: seriesData.bp.datasets },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position:'top', labels:{ usePointStyle:true, padding:18, font:{size:13} } },
            tooltip: { mode:'index', intersect:false }
        },
        scales: {
            x: { grid:{ color:'rgba(36,56,22,.04)' }, ticks:{ font:{size:12} } },
            y: { min:50, max:180, title:{ display:true, text:'mmHg', font:{size:12} }, grid:{ color:'rgba(36,56,22,.04)' }, ticks:{ font:{size:12} } }
        }
    }
});

document.querySelectorAll('.ctab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.ctab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const s = seriesData[btn.dataset.series];
        trendsChart.data.datasets = s.datasets;
        trendsChart.options.scales.y.min = s.yMin;
        trendsChart.options.scales.y.max = s.yMax;
        trendsChart.options.scales.y.title.text = s.yLabel;
        trendsChart.update();
    });
});
<?php endif; ?>

function exportHealthData() {
    const rows = <?= $export_json ?>;
    let csv = "Date,Time,Blood Pressure,Blood Sugar,Heart Rate,Weight,Temperature,Oxygen,Notes,Recorded By\n";
    rows.forEach(r => { csv += r.map(v => `"${String(v??'').replace(/"/g,'""')}"`).join(',') + "\n"; });
    const blob = new Blob([csv], { type:'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'health-data-<?= date('Y-m-d') ?>.csv';
    document.body.appendChild(a); a.click();
    document.body.removeChild(a); URL.revokeObjectURL(url);
    showToast('<?= count($all_logs_for_export) ?> health records exported successfully');
}

function showToast(msg) {
    const existing = document.getElementById('ht');
    if (existing) existing.remove();
    const t = document.createElement('div');
    t.id = 'ht';
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;background:rgba(36,56,22,.95);color:rgba(255,255,255,.9);padding:14px 20px;border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.2);font-weight:600;font-family:Outfit,sans-serif;font-size:15px;max-width:300px;display:flex;align-items:center;gap:9px;';
    t.innerHTML = '<i class="fas fa-check-circle" style="color:#9DC07E;"></i>' + msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

setTimeout(() => location.reload(), 300000);
</script>
</body>
</html>