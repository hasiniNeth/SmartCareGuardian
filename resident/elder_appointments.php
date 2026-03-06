<?php
session_start();
include '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];

$allowed_views = ['upcoming', 'past', 'all'];
$view = in_array($_GET['view'] ?? '', $allowed_views) ? $_GET['view'] : 'upcoming';

$allowed_status = ['all', 'scheduled', 'completed', 'cancelled'];
$status_filter  = in_array($_GET['status'] ?? '', $allowed_status) ? $_GET['status'] : 'all';

$limit  = 10;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$date_where = '';
switch ($view) {
    case 'upcoming': $date_where = "AND a.appointment_date >= CURDATE()"; break;
    case 'past':     $date_where = "AND a.appointment_date < CURDATE()";  break;
}
$status_where = $status_filter !== 'all' ? "AND a.status = '$status_filter'" : '';

$cnt_stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM appointments a
    WHERE a.resident_id = ? $date_where $status_where
");
$cnt_stmt->bind_param("i", $user_id);
$cnt_stmt->execute();
$total_count = (int)$cnt_stmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, (int)ceil($total_count / $limit));
$cnt_stmt->close();
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $limit; }

$order = ($view === 'past') ? "DESC" : "ASC";

$apt_stmt = $conn->prepare("
    SELECT
        a.appointment_id,
        a.title,
        a.description,
        a.appointment_date,
        a.appointment_time,
        DATE_FORMAT(a.appointment_date, '%b %d, %Y')  AS date_fmt,
        DATE_FORMAT(a.appointment_date, '%Y-%m-%d')   AS date_iso,
        TIME_FORMAT(a.appointment_time, '%h:%i %p')   AS time_fmt,
        DAYNAME(a.appointment_date)                   AS day_name,
        DAY(a.appointment_date)                       AS day_num,
        MONTHNAME(a.appointment_date)                 AS month_name,
        a.location,
        a.status,
        a.created_at,
        u.full_name  AS caregiver_name,
        u.email      AS caregiver_email
    FROM appointments a
    JOIN users u ON u.user_id = a.caregiver_id
    WHERE a.resident_id = ? $date_where $status_where
    ORDER BY a.appointment_date $order, a.appointment_time $order
    LIMIT ? OFFSET ?
");
$apt_stmt->bind_param("iii", $user_id, $limit, $offset);
$apt_stmt->execute();
$appointments = $apt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$apt_stmt->close();

$sum_stmt = $conn->prepare("
    SELECT
        COUNT(*)                                           AS total,
        SUM(status = 'scheduled')                         AS scheduled,
        SUM(status = 'completed')                         AS completed,
        SUM(status = 'cancelled')                         AS cancelled,
        SUM(appointment_date >= CURDATE())                AS upcoming,
        SUM(appointment_date < CURDATE())                 AS past,
        SUM(appointment_date = CURDATE())                 AS today
    FROM appointments
    WHERE resident_id = ?
");
$sum_stmt->bind_param("i", $user_id);
$sum_stmt->execute();
$summary = $sum_stmt->get_result()->fetch_assoc();
$sum_stmt->close();

$next_stmt = $conn->prepare("
    SELECT
        a.*,
        DATE_FORMAT(a.appointment_date, '%b %d, %Y')  AS date_fmt,
        TIME_FORMAT(a.appointment_time, '%h:%i %p')   AS time_fmt,
        DAYNAME(a.appointment_date)                   AS day_name,
        DATEDIFF(a.appointment_date, CURDATE())        AS days_away,
        u.full_name AS caregiver_name
    FROM appointments a
    JOIN users u ON u.user_id = a.caregiver_id
    WHERE a.resident_id = ?
      AND a.appointment_date >= CURDATE()
      AND a.status = 'scheduled'
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
    LIMIT 1
");
$next_stmt->bind_param("i", $user_id);
$next_stmt->execute();
$next_apt = $next_stmt->get_result()->fetch_assoc();
$next_stmt->close();

$cal_month = isset($_GET['cal']) && preg_match('/^\d{4}-\d{2}$/', $_GET['cal'])
    ? $_GET['cal']
    : date('Y-m');
$cal_year  = (int)explode('-', $cal_month)[0];
$cal_mon   = (int)explode('-', $cal_month)[1];
$cal_prev  = date('Y-m', mktime(0,0,0,$cal_mon-1,1,$cal_year));
$cal_next  = date('Y-m', mktime(0,0,0,$cal_mon+1,1,$cal_year));

$cal_stmt = $conn->prepare("
    SELECT DAY(appointment_date) AS day_num, status, COUNT(*) AS cnt
    FROM appointments
    WHERE resident_id = ?
      AND YEAR(appointment_date)  = ?
      AND MONTH(appointment_date) = ?
    GROUP BY DAY(appointment_date), status
");
$cal_stmt->bind_param("iii", $user_id, $cal_year, $cal_mon);
$cal_stmt->execute();
$cal_rows = $cal_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$cal_stmt->close();

$cal_data = [];
foreach ($cal_rows as $cr) {
    $d = (int)$cr['day_num'];
    $cal_data[$d][$cr['status']] = (int)$cr['cnt'];
}

$um = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id=? AND is_read=0");
$um->bind_param("i", $user_id); $um->execute();
$unread_messages = $um->get_result()->fetch_assoc()['cnt'] ?? 0;
$um->close();

$ua = $conn->prepare("SELECT COUNT(*) AS cnt FROM alerts WHERE resident_id=? AND resolved=0");
$ua->bind_param("i", $user_id); $ua->execute();
$unresolved_alerts = $ua->get_result()->fetch_assoc()['cnt'] ?? 0;
$ua->close();

$status_cfg = [
    'scheduled' => ['label'=>'Scheduled', 'icon'=>'fa-calendar-check', 'color'=>'#1A4870', 'bg'=>'#DBEEFF', 'border'=>'#1A4870'],
    'completed' => ['label'=>'Completed', 'icon'=>'fa-circle-check',   'color'=>'#3A6830', 'bg'=>'#DDEFD8', 'border'=>'#4A7C59'],
    'cancelled' => ['label'=>'Cancelled', 'icon'=>'fa-circle-xmark',   'color'=>'#6A2020', 'bg'=>'#F5DADA', 'border'=>'#C87A7A'],
];
function apt_cfg(array $cfg, string $status): array {
    return $cfg[$status] ?? ['label'=>ucfirst($status),'icon'=>'fa-circle','color'=>'#4A4540','bg'=>'#F2F6EF','border'=>'#B8B0A4'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Appointments – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════
           SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
           Appointments · Very large fonts for elderly readability
        ═══════════════════════════════════════════════════════════ */
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
            --w200: #EDE5D0;

            --st300: #B8B0A4;
            --st500: #7A7268;
            --st700: #4A4540;

            /* Status palette */
            --blue-bg:   #DBEEFF;
            --blue-text: #1A4870;
            --green-bg:  #DDEFD8;
            --green-text:#3A6830;
            --red-bg:    #F5DADA;
            --red-text:  #6A2020;
            --amber-bg:  #FAECC8;
            --amber-text:#7A5010;

            --radius-sm: 8px;
            --radius-md: 14px;
            --radius-lg: 22px;
            --shadow-soft: 0 2px 12px rgba(36,56,22,.07), 0 1px 3px rgba(36,56,22,.05);
            --shadow-card: 0 4px 24px rgba(36,56,22,.09), 0 1px 4px rgba(36,56,22,.06);
            --shadow-lift: 0 8px 32px rgba(36,56,22,.13), 0 2px 8px rgba(36,56,22,.07);
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--w50);
            background-image:
                radial-gradient(ellipse 70% 50% at 90% 0%,  rgba(157,192,126,.09) 0%, transparent 55%),
                radial-gradient(ellipse 50% 40% at 0%  100%, rgba(122,166,88,.06)  0%, transparent 50%);
            color: var(--st700);
            font-size: 18px;       /* Large base for elders */
            line-height: 1.75;
            min-height: 100vh;
            margin: 0; padding: 0;
        }

        h1,h2,h3,h4,h5 {
            font-family: 'Cormorant Garamond', serif;
            color: var(--s800); margin: 0;
        }

        /* ── Sidebar ─────────────────────────────────────── */
        .sidebar {
            width: 240px; height: 100vh; position: fixed;
            background: var(--s800);
            display: flex; flex-direction: column;
            z-index: 1000; overflow: hidden;
        }
        .sidebar::before {
            content: ''; position: absolute; inset: 0; pointer-events: none;
            background-image:
                radial-gradient(ellipse 120% 60% at 50% -10%, rgba(157,192,126,.18) 0%, transparent 60%),
                radial-gradient(ellipse 80%  80% at 110% 110%, rgba(94,138,64,.15) 0%, transparent 55%);
        }
        .sidebar-header {
            padding: 26px 20px 18px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            flex-shrink: 0; position: relative;
        }
        .brand-mark { display: flex; align-items: center; gap: 10px; margin-bottom: 5px; }
        .brand-icon {
            width: 34px; height: 34px;
            background: linear-gradient(135deg, var(--s300), var(--s500));
            border-radius: 9px; display: flex; align-items: center;
            justify-content: center; font-size: 15px; color: white;
            box-shadow: 0 3px 10px rgba(0,0,0,.25); flex-shrink: 0;
        }
        .sidebar-header h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 17px; font-weight: 600; color: white; line-height: 1.1;
        }
        .sidebar-header small {
            font-size: 10px; color: rgba(255,255,255,.4);
            letter-spacing: .08em; text-transform: uppercase;
            font-weight: 500; display: block; margin-left: 44px; margin-top: 2px;
        }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 10px 0; position: relative; }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.15); border-radius: 3px; }
        .sidebar a {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 12px 11px 22px; color: rgba(255,255,255,.6);
            text-decoration: none; font-size: 15px; font-weight: 500;
            transition: all .2s; margin: 2px 10px;
            border-radius: var(--radius-sm); position: relative; min-height: 46px;
        }
        .sidebar a:hover  { background: rgba(255,255,255,.1);  color: white; }
        .sidebar a.active { background: rgba(157,192,126,.2); color: #C8E6A0; }
        .sidebar a.active::before {
            content: ''; position: absolute;
            left: -10px; top: 20%; bottom: 20%;
            width: 3px; background: var(--s300);
            border-radius: 0 3px 3px 0;
        }
        .sidebar i { width: 20px; text-align: center; font-size: 15px; opacity: .85; }
        .sb-badge {
            margin-left: auto; background: #8B3A3A; color: white;
            font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 20px;
        }
        .sidebar-footer {
            flex-shrink: 0; border-top: 1px solid rgba(255,255,255,.08);
            padding: 14px 10px; position: relative;
        }
        .sidebar-footer a { margin: 0; color: rgba(255,255,255,.5) !important; font-size: 15px; }
        .sidebar-footer a:hover { color: rgba(255,255,255,.8) !important; }

        /* ── Layout ──────────────────────────────────────── */
        .content { margin-left: 240px; padding: 28px; min-height: 100vh; }

        /* ── Topbar ──────────────────────────────────────── */
        .topbar {
            background: white; border-radius: var(--radius-lg);
            padding: 20px 28px; box-shadow: var(--shadow-card);
            border: 1px solid rgba(196,217,180,.3);
            margin-bottom: 24px; display: flex;
            align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 12px;
        }
        .topbar h4 { font-size: 26px; font-weight: 500; color: var(--s800); margin-bottom: 3px; }
        .topbar p  { font-size: 15px; color: var(--st300); margin: 0; }
        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-top: 4px;
            flex-wrap: wrap;
          flex-wrap: wrap; gap: 8px; align-items: center; }

        .date-chip {
            background: white;
            border: 1px solid var(--s100);
            border-radius: 20px;
            padding: 7px 14px;
            font-size: 1rem;
            font-weight: 600;
            color: var(--s600);
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: var(--shadow-soft);
        }

        .topbar-btn {
            background: white;
            border: 1px solid var(--s100);
            border-radius: var(--radius-sm);
            padding: 7px 12px;
            font-size: 1rem;
            font-weight: 400;
            color: var(--st500);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: var(--shadow-soft);
            transition: all .2s;
            font-family: 'Outfit', sans-serif;
        }

        .topbar-btn:hover { background: var(--s50); border-color: var(--s200); color: var(--s600); }
        .btn-print {
            background: linear-gradient(135deg, var(--s400), var(--s600));
            border: none; border-radius: var(--radius-sm); color: white;
            padding: 11px 22px; font-size: 15px; font-weight: 700;
            font-family: 'Outfit', sans-serif; cursor: pointer;
            transition: all .25s; display: inline-flex; align-items: center; gap: 7px;
        }
        .btn-print:hover { opacity: .9; transform: translateY(-1px); box-shadow: var(--shadow-card); }

        /* ── Section card ────────────────────────────────── */
        .panel {
            background: white; border-radius: var(--radius-lg);
            padding: 22px; box-shadow: var(--shadow-card);
            border: 1px solid rgba(196,217,180,.3);
            margin-bottom: 20px; animation: fadeUp .4s ease both;
        }
        @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }

        .panel-title {
            font-family: 'Outfit', sans-serif; font-size: 12px;
            font-weight: 700; color: var(--st300);
            text-transform: uppercase; letter-spacing: .08em; margin-bottom: 14px;
        }

        /* ── Hero card — next appointment ────────────────── */
        .hero-card {
            background: var(--s800); border-radius: var(--radius-lg);
            padding: 26px 24px; color: white; margin-bottom: 20px;
            box-shadow: var(--shadow-lift); position: relative; overflow: hidden;
        }
        .hero-card::before {
            content: ''; position: absolute; inset: 0; pointer-events: none;
            background-image:
                radial-gradient(ellipse 140% 80% at 110% -20%, rgba(157,192,126,.22) 0%, transparent 55%),
                radial-gradient(ellipse 80%  90% at -10% 110%, rgba(94,138,64,.12)  0%, transparent 55%);
        }
        .hero-inner { position: relative; z-index: 1; }
        .hero-eyebrow {
            font-size: 11px; font-weight: 700; letter-spacing: .1em;
            text-transform: uppercase; color: rgba(255,255,255,.5); margin-bottom: 10px;
        }
        .hero-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 24px; font-weight: 600; color: white; line-height: 1.2; margin-bottom: 14px;
        }
        .hero-row {
            display: flex; align-items: center; gap: 8px;
            font-size: 16px; color: rgba(255,255,255,.8); margin-bottom: 5px;
        }
        .hero-row i { color: var(--s300); font-size: 14px; width: 16px; }
        .hero-divider {
            height: 1px; background: rgba(255,255,255,.1);
            margin: 14px 0;
        }
        .hero-desc { font-size: 15px; color: rgba(255,255,255,.7); line-height: 1.6; }
        .hero-caregiver-badge {
            display: inline-flex; align-items: center; gap: 7px;
            background: rgba(255,255,255,.12); border-radius: var(--radius-sm);
            padding: 8px 14px; margin-top: 14px;
            font-size: 14px; color: rgba(255,255,255,.9); font-weight: 600;
        }
        .hero-caregiver-badge i { color: var(--s300); }

        /* Days-away counter inside hero */
        .hero-days-bubble {
            position: absolute; top: 22px; right: 22px; z-index: 1;
            text-align: center;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: var(--radius-md);
            padding: 10px 14px; min-width: 64px;
        }
        .hdb-num   { font-family: 'Cormorant Garamond', serif; font-size: 34px; font-weight: 600; color: white; line-height: 1; }
        .hdb-label { font-size: 11px; font-weight: 700; color: rgba(255,255,255,.55); text-transform: uppercase; letter-spacing: .06em; margin-top: 2px; }

        /* No-appointments fallback */
        .hero-empty {
            background: linear-gradient(135deg, var(--s600), var(--s800));
            border-radius: var(--radius-lg); padding: 28px 22px;
            text-align: center; margin-bottom: 20px;
            border: 1px solid rgba(196,217,180,.15);
        }
        .hero-empty i   { font-size: 2.4rem; color: rgba(255,255,255,.3); display: block; margin-bottom: 10px; }
        .hero-empty p   { font-size: 16px; color: rgba(255,255,255,.55); margin: 0; font-weight: 500; }

        /* ── Summary stat chips ──────────────────────────── */
        .stat-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 10px; }
        .stat-chip { text-align: center; padding: 16px 10px; border-radius: var(--radius-md); }
        .chip-num  { font-size: 28px; font-weight: 800; line-height: 1; font-family: 'Outfit', sans-serif; }
        .chip-lbl  { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; opacity: .8; margin-top: 3px; }

        /* Today alert strip */
        .today-alert {
            background: var(--amber-bg); color: var(--amber-text);
            border-radius: var(--radius-sm); padding: 11px 14px;
            font-size: 15px; font-weight: 700; margin-top: 12px;
            display: flex; align-items: center; gap: 8px;
        }

        /* ── Mini calendar ───────────────────────────────── */
        .cal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .cal-nav {
            width: 34px; height: 34px; border-radius: 50%;
            border: 2px solid var(--s200); background: white; color: var(--s500);
            display: flex; align-items: center; justify-content: center;
            text-decoration: none; font-size: 12px; transition: all .2s;
        }
        .cal-nav:hover { background: var(--s400); color: white; border-color: var(--s400); }
        .cal-title { font-family: 'Outfit', sans-serif; font-weight: 800; font-size: 15px; color: var(--s800); }
        .cal-grid  { display: grid; grid-template-columns: repeat(7,1fr); gap: 2px; }
        .cal-dow   {
            text-align: center; font-size: 10px; font-weight: 800;
            color: var(--st300); text-transform: uppercase; padding: 4px 0;
        }
        .cal-day {
            text-align: center; padding: 5px 2px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 700; color: var(--s800);
            cursor: default; min-width: 0; line-height: 1.5; position: relative;
        }
        .cal-day.today    { background: rgba(122,166,88,.15); border: 2px solid var(--s400); }
        .cal-day.has-apt  { cursor: pointer; }
        .cal-day.has-apt:hover { background: rgba(26,72,112,.08); }
        .cal-day.empty    { color: transparent; pointer-events: none; }
        .cal-dot-row { display: flex; justify-content: center; gap: 2px; margin-top: 2px; }
        .cal-dot { width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }

        /* Calendar legend */
        .cal-legend { display: flex; gap: 14px; margin-top: 12px; flex-wrap: wrap; }
        .cal-legend span {
            display: flex; align-items: center; gap: 5px;
            font-size: 12px; color: var(--st500); font-weight: 600;
        }
        .cal-legend-dot { width: 8px; height: 8px; border-radius: 50%; }

        /* ── View / filter tabs ──────────────────────────── */
        .tab-row { display: flex; gap: 7px; flex-wrap: wrap; margin-bottom: 18px; }
        .vtab {
            padding: 9px 18px; border-radius: 20px;
            font-size: 15px; font-weight: 700; cursor: pointer;
            border: 1.5px solid var(--s200); background: white;
            color: var(--s600); text-decoration: none; transition: all .2s;
        }
        .vtab:hover { background: var(--s50); border-color: var(--s300); }
        .vtab.active {
            background: linear-gradient(135deg, var(--s400), var(--s600));
            color: white; border-color: transparent;
        }
        .vtab.success.active {
            background: linear-gradient(135deg, #4A7C59, #3A6830);
            border-color: transparent;
        }
        .vtab.danger.active {
            background: linear-gradient(135deg, #C87A7A, #6A2020);
            border-color: transparent;
        }

        /* Smaller secondary filter tabs */
        .vtab-sm {
            padding: 7px 14px; border-radius: 20px;
            font-size: 13px; font-weight: 700; cursor: pointer;
            border: 1.5px solid var(--s100); background: white;
            color: var(--st500); text-decoration: none; transition: all .2s;
        }
        .vtab-sm:hover { background: var(--s50); }
        .vtab-sm.active {
            background: linear-gradient(135deg, var(--s400), var(--s600));
            color: white; border-color: transparent;
        }
        .vtab-sm.success.active { background: linear-gradient(135deg,#4A7C59,#3A6830); color: white; border-color:transparent; }
        .vtab-sm.danger.active  { background: linear-gradient(135deg,#C87A7A,#6A2020); color: white; border-color:transparent; }

        /* ── Date divider label ──────────────────────────── */
        .divider-label {
            font-size: 13px; font-weight: 700; color: var(--st300);
            text-transform: uppercase; letter-spacing: .07em;
            padding: 8px 0 11px; border-bottom: 1px solid var(--s100);
            margin-bottom: 12px; display: flex; align-items: center; gap: 7px;
        }
        .divider-label i { color: var(--s400); }

        /* ── Appointment cards ───────────────────────────── */
        .apt-card {
            background: var(--s50); border-radius: var(--radius-lg);
            padding: 20px 22px; margin-bottom: 12px;
            border-left: 5px solid var(--s200);
            transition: all .25s; display: flex; gap: 18px;
            box-shadow: var(--shadow-soft);
        }
        .apt-card:hover { transform: translateX(5px); box-shadow: var(--shadow-card); background: white; }
        .apt-card.scheduled { border-left-color: #1A4870; }
        .apt-card.completed { border-left-color: var(--s500); }
        .apt-card.cancelled { border-left-color: #C87A7A; opacity: .85; }

        /* Date block */
        .apt-date-block {
            flex-shrink: 0; width: 62px; text-align: center;
            background: white; border-radius: var(--radius-md);
            padding: 12px 6px;
            border: 1px solid var(--s200);
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            box-shadow: var(--shadow-soft);
        }
        .adb-day   {
            font-family: 'Cormorant Garamond', serif;
            font-size: 28px; font-weight: 600; color: var(--s800); line-height: 1;
        }
        .adb-month {
            font-size: 11px; font-weight: 800; color: var(--s500);
            text-transform: uppercase; letter-spacing: .05em; margin-top: 2px;
        }
        .adb-dow {
            font-size: 10px; color: var(--st300); font-weight: 700;
            text-transform: uppercase; margin-top: 3px; letter-spacing: .04em;
        }

        /* Card body */
        .apt-body { flex: 1; min-width: 0; }
        .apt-title {
            font-size: 18px; font-weight: 700; color: var(--s800);
            margin-bottom: 5px; line-height: 1.3;
        }
        .apt-desc {
            font-size: 15px; color: var(--st500); margin-bottom: 10px; line-height: 1.6;
        }
        .apt-meta { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; }
        .apt-meta-item {
            display: flex; align-items: center; gap: 6px;
            font-size: 14px; color: var(--st500); font-weight: 500;
        }
        .apt-meta-item i { font-size: 13px; color: var(--s500); width: 14px; }

        /* Status badge */
        .apt-status {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 14px; border-radius: 20px;
            font-size: 13px; font-weight: 700;
        }

        /* Days-away chip */
        .days-chip {
            font-size: 13px; font-weight: 800;
            padding: 4px 12px; border-radius: 20px;
        }
        .days-today    { background: var(--amber-bg); color: var(--amber-text); }
        .days-soon     { background: var(--blue-bg);  color: var(--blue-text);  }
        .days-upcoming { background: var(--green-bg); color: var(--green-text); }
        .days-past     { background: var(--s100);     color: var(--st500);      }

        /* ── Section heading ─────────────────────────────── */
        .section-heading {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;
        }
        .section-heading h4 { font-size: 26px; font-weight: 500; color: var(--s800); margin-bottom: 3px; }
        .section-heading p  { font-size: 14px; color: var(--st300); margin: 0; }

        /* ── Pagination ──────────────────────────────────── */
        .pagination { justify-content: center; margin-top: 22px; gap: 4px; }
        .page-link {
            border: 1.5px solid var(--s200); color: var(--s700);
            border-radius: var(--radius-sm) !important;
            font-weight: 700; font-size: 15px;
            padding: 8px 16px; transition: all .2s;
        }
        .page-link:hover { background: var(--s50); color: var(--s800); }
        .page-item.active .page-link {
            background: linear-gradient(135deg, var(--s400), var(--s600));
            border-color: transparent; color: white;
        }

        /* ── Empty state ─────────────────────────────────── */
        .empty-state { text-align: center; padding: 50px 20px; }
        .empty-state i {
            font-size: 3.8rem; color: var(--s200);
            display: block; margin-bottom: 18px;
            animation: float 4s ease-in-out infinite;
        }
        .empty-state h5 {
            font-family: 'Outfit', sans-serif; font-size: 20px;
            font-weight: 700; color: var(--s700); margin-bottom: 8px;
        }
        .empty-state p { font-size: 16px; color: var(--st300); }
        @keyframes float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-8px)} }

        .link-show-all {
            color: var(--s500); font-weight: 700; text-decoration: none;
        }
        .link-show-all:hover { color: var(--s700); text-decoration: underline; }

        /* ── Print ───────────────────────────────────────── */
        @media print {
            .sidebar, .no-print { display: none !important; }
            .content { margin-left: 0 !important; padding: 10px !important; }
            .apt-card { break-inside: avoid; }
        }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .content { margin-left: 0; padding: 16px; }
            .stat-grid { grid-template-columns: repeat(4,1fr); }
        }
    </style>
</head>
<body>

<!-- ══ SIDEBAR ════════════════════════════════════════════════════ -->
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
        <a href="elder_health.php"><i class="fa-solid fa-heart-pulse"></i> My Health Data</a>
        <a href="elder_schedule.php"><i class="fa-solid fa-calendar-check"></i> Daily Schedule</a>
        <a href="elder_medications.php"><i class="fa-solid fa-pills"></i> My Medications</a>
        <a href="elder_appointments.php" class="active"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="elder_messages.php">
            <i class="fa-solid fa-comments"></i> Messages
            <?php if ($unread_messages > 0): ?><span class="sb-badge"><?= $unread_messages ?></span><?php endif; ?>
        </a>
        <a href="resident_alerts.php">
            <i class="fa-solid fa-bell"></i> Health Alerts
            <?php if ($unresolved_alerts > 0): ?><span class="sb-badge"><?= $unresolved_alerts ?></span><?php endif; ?>
        </a>
    </div>
    <div class="sidebar-footer">
        <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <!-- Topbar -->
    <div class="topbar no-print">
        <div>
            <h4><i class="fas fa-calendar-days me-2" style="font-size:22px;color:var(--s500);"></i>My Appointments</h4>
            <p><i class="fas fa-calendar me-1"></i>View and track your scheduled appointments</p>
        </div>
        <div class="topbar-actions">
            <button class="topbar-btn" onclick="increaseFontSize()"><i class="fas fa-search-plus"></i>Larger</button>
            <button class="topbar-btn" onclick="decreaseFontSize()"><i class="fas fa-search-minus"></i>Smaller</button>
            <button class="topbar-btn" onclick="resetFontSize()"><i class="fas fa-redo"></i>Reset</button>
            <button class="topbar-btn" onclick="toggleHighContrast()"><i class="fas fa-adjust"></i>Contrast</button>
        </div>
        <button class="btn-print" onclick="window.print()">
            <i class="fas fa-print"></i>Print
        </button>
    </div>

    <div class="row g-4">

        <!-- ── Left column ──────────────────────────────── -->
        <div class="col-lg-4">

            <!-- Next appointment hero -->
            <?php if ($next_apt): ?>
            <div class="hero-card">
                <div class="hero-inner">
                    <div class="hero-eyebrow"><i class="fas fa-calendar-check me-1"></i>Next Appointment</div>
                    <div class="hero-title"><?= htmlspecialchars($next_apt['title']) ?></div>
                    <div class="hero-row"><i class="fas fa-calendar-day"></i><?= $next_apt['date_fmt'] ?> &nbsp;·&nbsp; <?= $next_apt['day_name'] ?></div>
                    <div class="hero-row"><i class="fas fa-clock"></i><?= $next_apt['time_fmt'] ?></div>
                    <?php if ($next_apt['location']): ?>
                        <div class="hero-row"><i class="fas fa-location-dot"></i><?= htmlspecialchars($next_apt['location']) ?></div>
                    <?php endif; ?>
                    <?php if ($next_apt['description']): ?>
                        <div class="hero-divider"></div>
                        <div class="hero-desc"><?= htmlspecialchars($next_apt['description']) ?></div>
                    <?php endif; ?>
                    <div class="hero-caregiver-badge">
                        <i class="fas fa-user-nurse"></i>
                        <?= htmlspecialchars($next_apt['caregiver_name']) ?>
                    </div>
                </div>
                <!-- Days-away bubble -->
                <div class="hero-days-bubble">
                    <div class="hdb-num"><?= max(0, (int)$next_apt['days_away']) ?></div>
                    <div class="hdb-label"><?= (int)$next_apt['days_away'] === 0 ? 'today' : 'days away' ?></div>
                </div>
            </div>

            <?php else: ?>
            <div class="hero-empty">
                <i class="fas fa-calendar-check"></i>
                <p>No upcoming appointments scheduled</p>
            </div>
            <?php endif; ?>

            <!-- Summary stats -->
            <div class="panel">
                <div class="panel-title">Summary</div>
                <div class="stat-grid">
                    <div class="stat-chip" style="background:var(--blue-bg);">
                        <div class="chip-num" style="color:var(--blue-text);"><?= (int)$summary['upcoming'] ?></div>
                        <div class="chip-lbl" style="color:var(--blue-text);">Upcoming</div>
                    </div>
                    <div class="stat-chip" style="background:var(--green-bg);">
                        <div class="chip-num" style="color:var(--green-text);"><?= (int)$summary['completed'] ?></div>
                        <div class="chip-lbl" style="color:var(--green-text);">Completed</div>
                    </div>
                    <div class="stat-chip" style="background:var(--red-bg);">
                        <div class="chip-num" style="color:var(--red-text);"><?= (int)$summary['cancelled'] ?></div>
                        <div class="chip-lbl" style="color:var(--red-text);">Cancelled</div>
                    </div>
                    <div class="stat-chip" style="background:var(--s50);">
                        <div class="chip-num" style="color:var(--s800);"><?= (int)$summary['total'] ?></div>
                        <div class="chip-lbl" style="color:var(--st500);">Total</div>
                    </div>
                </div>
                <?php if ((int)$summary['today'] > 0): ?>
                <div class="today-alert">
                    <i class="fas fa-bell"></i>
                    <?= (int)$summary['today'] ?> appointment<?= $summary['today'] > 1 ? 's' : '' ?> today!
                </div>
                <?php endif; ?>
            </div>

            <!-- Mini calendar -->
            <div class="panel no-print">
                <div class="cal-header">
                    <a href="?view=<?= $view ?>&status=<?= $status_filter ?>&cal=<?= $cal_prev ?>" class="cal-nav">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <div class="cal-title"><?= date('F Y', mktime(0,0,0,$cal_mon,1,$cal_year)) ?></div>
                    <a href="?view=<?= $view ?>&status=<?= $status_filter ?>&cal=<?= $cal_next ?>" class="cal-nav">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                <div class="cal-grid mb-1">
                    <?php foreach (['Mo','Tu','We','Th','Fr','Sa','Su'] as $dow): ?>
                        <div class="cal-dow"><?= $dow ?></div>
                    <?php endforeach; ?>
                </div>
                <?php
                $first_dow  = (int)date('N', mktime(0,0,0,$cal_mon,1,$cal_year));
                $days_month = (int)date('t', mktime(0,0,0,$cal_mon,1,$cal_year));
                $today_num  = (date('Y') == $cal_year && date('n') == $cal_mon) ? (int)date('j') : -1;
                ?>
                <div class="cal-grid">
                    <?php
                    for ($e = 1; $e < $first_dow; $e++):
                    ?><div class="cal-day empty"></div><?php endfor;
                    for ($d = 1; $d <= $days_month; $d++):
                        $has = !empty($cal_data[$d]);
                        $is_td = ($d === $today_num);
                        $classes = 'cal-day' . ($is_td ? ' today' : '') . ($has ? ' has-apt' : '');
                        $dot_html = '';
                        if ($has) {
                            foreach ($cal_data[$d] as $st => $cnt) {
                                $dot_color = ['scheduled'=>'#1A4870','completed'=>'#4A7C59','cancelled'=>'#C87A7A'][$st] ?? '#B8B0A4';
                                $dot_html .= "<div class='cal-dot' style='background:$dot_color;'></div>";
                            }
                        }
                        $apt_date = sprintf('%04d-%02d-%02d', $cal_year, $cal_mon, $d);
                    ?>
                    <div class="<?= $classes ?>"
                         <?= $has ? "onclick=\"location.href='?view=all&status=all&cal=$cal_month&jump=$apt_date'\"" : '' ?>>
                        <?= $d ?>
                        <?php if ($dot_html): ?>
                            <div class="cal-dot-row"><?= $dot_html ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="cal-legend">
                    <span><span class="cal-legend-dot" style="background:#1A4870;"></span>Scheduled</span>
                    <span><span class="cal-legend-dot" style="background:#4A7C59;"></span>Completed</span>
                    <span><span class="cal-legend-dot" style="background:#C87A7A;"></span>Cancelled</span>
                </div>
            </div>

        </div><!-- /col-4 -->

        <!-- ── Right column ──────────────────────────────── -->
        <div class="col-lg-8">
            <div class="panel">
                <div class="section-heading">
                    <div>
                        <h4>Appointments</h4>
                        <p><?= $total_count ?> result<?= $total_count !== 1 ? 's' : '' ?></p>
                    </div>
                </div>

                <!-- View tabs -->
                <div class="tab-row no-print">
                    <a href="?view=upcoming&status=<?= $status_filter ?>&cal=<?= $cal_month ?>"
                       class="vtab <?= $view==='upcoming' ? 'active' : '' ?>">
                        Upcoming
                        <span style="opacity:.6;font-size:13px;">(<?= (int)$summary['upcoming'] ?>)</span>
                    </a>
                    <a href="?view=past&status=<?= $status_filter ?>&cal=<?= $cal_month ?>"
                       class="vtab <?= $view==='past' ? 'active' : '' ?>">
                        Past
                        <span style="opacity:.6;font-size:13px;">(<?= (int)$summary['past'] ?>)</span>
                    </a>
                    <a href="?view=all&status=<?= $status_filter ?>&cal=<?= $cal_month ?>"
                       class="vtab <?= $view==='all' ? 'active' : '' ?>">
                        All
                        <span style="opacity:.6;font-size:13px;">(<?= (int)$summary['total'] ?>)</span>
                    </a>
                </div>

                <!-- Status filter tabs -->
                <div class="tab-row no-print" style="margin-top:-10px; margin-bottom:20px;">
                    <a href="?view=<?= $view ?>&status=all&cal=<?= $cal_month ?>"
                       class="vtab-sm <?= $status_filter==='all'       ? 'active' : '' ?>">All Statuses</a>
                    <a href="?view=<?= $view ?>&status=scheduled&cal=<?= $cal_month ?>"
                       class="vtab-sm <?= $status_filter==='scheduled' ? 'active' : '' ?>">Scheduled</a>
                    <a href="?view=<?= $view ?>&status=completed&cal=<?= $cal_month ?>"
                       class="vtab-sm success <?= $status_filter==='completed' ? 'active' : '' ?>">Completed</a>
                    <a href="?view=<?= $view ?>&status=cancelled&cal=<?= $cal_month ?>"
                       class="vtab-sm danger <?= $status_filter==='cancelled'  ? 'active' : '' ?>">Cancelled</a>
                </div>

                <?php if (empty($appointments)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-days"></i>
                    <h5>No Appointments Found</h5>
                    <p>
                        <?php if ($view === 'upcoming' && $status_filter === 'all'): ?>
                            You have no upcoming appointments scheduled. Your caregiver will add them when needed.
                        <?php elseif ($status_filter !== 'all'): ?>
                            No <?= $status_filter ?> appointments in this view.
                            <a href="?view=<?= $view ?>&status=all&cal=<?= $cal_month ?>" class="link-show-all">Show all statuses</a>
                        <?php else: ?>
                            No appointments found for this period.
                        <?php endif; ?>
                    </p>
                </div>

                <?php else:
                    $grouped = [];
                    foreach ($appointments as $apt) {
                        $grouped[$apt['date_iso']][] = $apt;
                    }
                    foreach ($grouped as $date_iso => $apts):
                        $is_today_grp = $date_iso === date('Y-m-d');
                        $is_past_grp  = $date_iso < date('Y-m-d');
                        $grp_label    = $is_today_grp
                            ? 'Today — ' . date('F j, Y', strtotime($date_iso))
                            : date('l, F j, Y', strtotime($date_iso));
                ?>
                    <div class="divider-label">
                        <?php if ($is_today_grp): ?>
                            <i class="fas fa-circle" style="font-size:8px;color:var(--s400);vertical-align:middle;"></i>
                        <?php elseif ($is_past_grp): ?>
                            <i class="far fa-clock"></i>
                        <?php else: ?>
                            <i class="fas fa-calendar-days"></i>
                        <?php endif; ?>
                        <?= $grp_label ?>
                    </div>

                    <?php foreach ($apts as $apt):
                        $cfg = apt_cfg($status_cfg, $apt['status']);
                        $days_away = (int)ceil((strtotime($apt['date_iso']) - strtotime(date('Y-m-d'))) / 86400);
                        if ($days_away === 0)                       { $chip_class = 'days-today';    $chip_txt = 'Today'; }
                        elseif ($days_away === 1)                   { $chip_class = 'days-soon';     $chip_txt = 'Tomorrow'; }
                        elseif ($days_away > 1 && $days_away <= 7) { $chip_class = 'days-soon';     $chip_txt = "In $days_away days"; }
                        elseif ($days_away > 7)                    { $chip_class = 'days-upcoming'; $chip_txt = "In $days_away days"; }
                        else                                        { $chip_class = 'days-past';     $chip_txt = abs($days_away) . ' days ago'; }
                    ?>
                    <div class="apt-card <?= $apt['status'] ?>">
                        <!-- Date block -->
                        <div class="apt-date-block">
                            <div class="adb-day"><?= $apt['day_num'] ?></div>
                            <div class="adb-month"><?= strtoupper(substr($apt['month_name'],0,3)) ?></div>
                            <div class="adb-dow"><?= strtoupper(substr($apt['day_name'],0,3)) ?></div>
                        </div>
                        <!-- Body -->
                        <div class="apt-body">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                                <div class="apt-title"><?= htmlspecialchars($apt['title']) ?></div>
                                <div class="d-flex gap-2 flex-wrap align-items-center">
                                    <?php if ($apt['status'] === 'scheduled' && $days_away >= 0): ?>
                                        <span class="days-chip <?= $chip_class ?>"><?= $chip_txt ?></span>
                                    <?php endif; ?>
                                    <span class="apt-status"
                                          style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;">
                                        <i class="fas <?= $cfg['icon'] ?>"></i><?= $cfg['label'] ?>
                                    </span>
                                </div>
                            </div>

                            <?php if ($apt['description']): ?>
                                <div class="apt-desc"><?= htmlspecialchars($apt['description']) ?></div>
                            <?php endif; ?>

                            <div class="apt-meta">
                                <div class="apt-meta-item">
                                    <i class="far fa-clock"></i>
                                    <span><?= $apt['time_fmt'] ?></span>
                                </div>
                                <?php if ($apt['location']): ?>
                                <div class="apt-meta-item">
                                    <i class="fas fa-location-dot"></i>
                                    <span><?= htmlspecialchars($apt['location']) ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="apt-meta-item">
                                    <i class="fas fa-user-nurse"></i>
                                    <span><?= htmlspecialchars($apt['caregiver_name']) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php endif; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Appointments pagination">
                    <ul class="pagination flex-wrap">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?view=<?= $view ?>&status=<?= $status_filter ?>&cal=<?= $cal_month ?>&page=<?= $page-1 ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= $i==$page ? 'active' : '' ?>">
                                <a class="page-link" href="?view=<?= $view ?>&status=<?= $status_filter ?>&cal=<?= $cal_month ?>&page=<?= $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?view=<?= $view ?>&status=<?= $status_filter ?>&cal=<?= $cal_month ?>&page=<?= $page+1 ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>

            </div><!-- /panel -->

            <!-- Print footer -->
            <div class="d-none d-print-block mt-4 pt-3 border-top" style="font-size:13px;color:var(--st300);">
                <p>SmartCare Guardian — Appointments for <?= htmlspecialchars($user_name) ?> — Printed <?= date('F j, Y \a\t H:i') ?></p>
            </div>

        </div><!-- /col-8 -->
    </div><!-- /row -->
</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function() {
    var BASE = 12, MIN = 10, MAX = 18;
    var sz = parseInt(localStorage.getItem('elderFontSize')) || BASE;
    if (isNaN(sz) || sz < MIN || sz > MAX) sz = BASE;
    document.documentElement.style.fontSize = sz + 'px';

    function save(v) {
        sz = v;
        document.documentElement.style.fontSize = sz + 'px';
        localStorage.setItem('elderFontSize', String(sz));
    }
    function toast(msg) {
        var old = document.getElementById('_acc_t');
        if (old) old.remove();
        var d = document.createElement('div');
        d.id = '_acc_t';
        d.style.cssText = 'position:fixed;bottom:28px;right:22px;z-index:99999;pointer-events:none;';
        d.innerHTML = '<div style="background:rgba(36,56,22,.96);color:#fff;padding:12px 20px;'
            + 'border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.28);'
            + 'font-weight:700;font-family:Outfit,sans-serif;font-size:16px;">' + msg + '</div>';
        document.body.appendChild(d);
        setTimeout(function() { if (d && d.parentNode) d.remove(); }, 2500);
    }
    window.increaseFontSize = function() {
        if (sz < MAX) { save(sz + 2); toast('Text enlarged (' + sz + 'px)'); }
        else toast('Maximum size reached');
    };
    window.decreaseFontSize = function() {
        if (sz > MIN) { save(sz - 2); toast('Text reduced (' + sz + 'px)'); }
        else toast('Minimum size reached');
    };
    window.resetFontSize = function() {
        save(BASE); toast('Text size reset');
    };
    window.toggleHighContrast = function() {
        document.body.classList.toggle('high-contrast');
        var on = document.body.classList.contains('high-contrast');
        localStorage.setItem('elderHighContrast', on ? 'true' : 'false');
        toast(on ? 'High contrast on' : 'High contrast off');
    };
    document.addEventListener('DOMContentLoaded', () => {
        const jump = new URLSearchParams(location.search).get('jump');
        if (jump) {
            document.querySelectorAll('.divider-label').forEach(el => {
                if (el.textContent.includes(new Date(jump + 'T00:00:00').toLocaleDateString('en-US', {month:'long',day:'numeric',year:'numeric'}))) {
                    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }
    });
    document.addEventListener('DOMContentLoaded', function() {
        document.documentElement.style.fontSize = sz + 'px';
        if (localStorage.getItem('elderHighContrast') === 'true')
            document.body.classList.add('high-contrast');
    });
})();
</script>
</body>
</html>