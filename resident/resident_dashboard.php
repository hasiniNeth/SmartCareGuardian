<?php
session_start();
include '../db_connection.php';

date_default_timezone_set('Asia/Colombo');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_med'])) {
    $med_id  = (int)$_POST['medication_id'];
    $taken   = (int)$_POST['taken'];
    $new_val = $taken ? 0 : 1;
    $upd = $conn->prepare("UPDATE medications SET taken = ? WHERE medication_id = ? AND resident_id = ?");
    $upd->bind_param("iii", $new_val, $med_id, $user_id);
    $upd->execute();
    $upd->close();
    header("Location: resident_dashboard.php");
    exit();
}

$stmt = $conn->prepare("SELECT r.emergency_contact, r.medical_conditions, TIMESTAMPDIFF(YEAR, r.dob, CURDATE()) AS age FROM residents r WHERE r.user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$resident_info = $stmt->get_result()->fetch_assoc() ?? ['age'=>null,'emergency_contact'=>null,'medical_conditions'=>null];
$stmt->close();

$cg_stmt = $conn->prepare("SELECT u.full_name, u.email FROM users u JOIN caregiver_assignments ca ON ca.caregiver_id = u.user_id WHERE ca.resident_id = ? LIMIT 1");
$cg_stmt->bind_param("i", $user_id);
$cg_stmt->execute();
$assigned_caregiver = $cg_stmt->get_result()->fetch_assoc();
$cg_stmt->close();

$hl_stmt = $conn->prepare("SELECT blood_pressure_systolic, blood_pressure_diastolic, blood_sugar, pulse, weight, temperature, oxygen_saturation, DATE_FORMAT(logged_at, '%b %d') AS date FROM health_logs WHERE resident_id = ? AND logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY logged_at DESC LIMIT 1");
$hl_stmt->bind_param("i", $user_id);
$hl_stmt->execute();
$latest_health = $hl_stmt->get_result()->fetch_assoc();
$hl_stmt->close();

$today = date('l');
$rt_stmt = $conn->prepare("SELECT r.id AS routine_id, r.routine_type, r.description, r.schedule_time, u.full_name AS caregiver_name, COALESCE(rl.status, 'pending') AS today_status FROM routines r JOIN users u ON u.user_id = r.caregiver_id LEFT JOIN routine_logs rl ON rl.routine_id = r.id AND rl.log_date = CURDATE() WHERE r.resident_id = ? AND r.status = 'pending' AND (r.repeat_hours > 0 OR r.days_of_week IS NULL OR FIND_IN_SET(?, r.days_of_week)) ORDER BY r.schedule_time");
$rt_stmt->bind_param("is", $user_id, $today);
$rt_stmt->execute();
$today_routines = $rt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rt_stmt->close();

$ap_stmt = $conn->prepare("SELECT title, description, DATE_FORMAT(appointment_date, '%b %d, %Y') AS apt_date, TIME_FORMAT(appointment_time, '%h:%i %p') AS apt_time, location, status FROM appointments WHERE resident_id = ? AND appointment_date >= CURDATE() ORDER BY appointment_date, appointment_time LIMIT 5");
$ap_stmt->bind_param("i", $user_id);
$ap_stmt->execute();
$upcoming_appointments = $ap_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$ap_stmt->close();

$msg_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND is_read = 0");
$msg_stmt->bind_param("i", $user_id);
$msg_stmt->execute();
$unread_messages = $msg_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$msg_stmt->close();

$al_stmt = $conn->prepare("SELECT alert_message, alert_type, DATE_FORMAT(created_at, '%b %d, %h:%i %p') AS time FROM alerts WHERE resident_id = ? AND resolved = 0 ORDER BY created_at DESC LIMIT 5");
$al_stmt->bind_param("i", $user_id);
$al_stmt->execute();
$recent_alerts = $al_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$al_stmt->close();
$unresolved_count = count($recent_alerts);

$med_stmt = $conn->prepare("SELECT medication_id, medication_name, dosage, frequency, TIME_FORMAT(medication_time, '%h:%i %p') AS med_time, taken FROM medications WHERE resident_id = ? AND medication_date = CURDATE() ORDER BY medication_time");
$med_stmt->bind_param("i", $user_id);
$med_stmt->execute();
$today_medications = $med_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$med_stmt->close();

$meds_pending  = count(array_filter($today_medications, fn($m) => !$m['taken']));
$meds_taken    = count($today_medications) - $meds_pending;
$routines_done = count(array_filter($today_routines,   fn($r) => $r['today_status'] === 'completed'));

$wellness_tips = [
    ["💧", "Stay hydrated — aim for 6–8 glasses of water today"],
    ["🚶", "Take a short 10-minute walk for better circulation"],
    ["😴", "Maintain a regular sleep schedule — same time each night"],
    ["🧘", "Light stretching for 5 minutes helps reduce stiffness"],
];

$ua = $conn->prepare("SELECT COUNT(*) AS cnt FROM alerts WHERE resident_id=? AND resolved=0");
$ua->bind_param("i", $user_id);
$ua->execute();
$unresolved_alerts = $ua->get_result()->fetch_assoc()['cnt'] ?? 0;
$ua->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════════
           SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
           Palette: Sage green · Warm parchment · Stone
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
            --s900: #131F0B;

            --w50:  #FDFAF5;
            --w100: #F7F1E5;
            --w200: #EDE0C8;
            --w300: #D9C9A8;
            --w400: #BFA882;
            --w500: #9E8660;

            --st300: #B8B0A4;
            --st500: #7A7268;
            --st700: #4A4540;

            --ok:   #4A7C59;
            --warn: #A06B2A;
            --risk: #8B3A3A;
            --info: #2C6E8A;

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
            font-size: 20px;
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
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
            font-size: 22px; color: white;
            box-shadow: 0 3px 10px rgba(0,0,0,.25);
            flex-shrink: 0;
        }

        .sidebar-header h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 23px;
            font-weight: 600;
            color: white;
            line-height: 1.1;
            letter-spacing: .01em;
            margin: 0;
        }

        .sidebar-header small {
            font-size: 16px;
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

        .sb-section-label {
            font-size: 15.5px;
            font-weight: 600;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: rgba(255,255,255,.3);
            padding: 16px 22px 5px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px 9px 22px;
            color: rgba(255,255,255,.6);
            text-decoration: none;
            font-size: 18px;
            font-weight: 500;
            transition: all .2s;
            margin: 1px 10px;
            border-radius: var(--radius-sm);
            position: relative;
        }

        .sidebar a:hover {
            background: rgba(255,255,255,.1);
            color: white;
        }

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

        .sidebar i { width: 18px; text-align: center; font-size: 13px; opacity: .85; }

        .sb-badge {
            margin-left: auto;
            background: #8B3A3A;
            color: white;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 20px;
            min-width: 18px;
            text-align: center;
        }

        .sidebar-info-card {
            margin: 10px 12px 4px;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: var(--radius-md);
            padding: 11px 13px;
            position: relative;
        }

        .sidebar-info-card .si-label {
            font-size: 16px; font-weight: 600; letter-spacing: .1em;
            text-transform: uppercase; color: rgba(255,255,255,.35); margin-bottom: 5px;
        }

        .sidebar-info-card .si-name  { font-size: 12px; font-weight: 600; color: rgba(255,255,255,.85); }
        .sidebar-info-card .si-email { font-size: 10px; color: rgba(255,255,255,.4); margin-top: 1px; }

        .sidebar-emergency {
            margin: 8px 12px 20px;
            background: rgba(139,58,58,.25);
            border: 1px solid rgba(200,100,100,.2);
            border-radius: var(--radius-md);
            padding: 11px 13px;
            text-align: center;
            position: relative;
        }

        .sidebar-emergency .se-label {
            font-size: 9px; letter-spacing: .1em; text-transform: uppercase;
            color: rgba(255,180,180,.5); margin-bottom: 4px;
        }

        .sidebar-emergency .se-contact { font-size: 11px; font-weight: 600; color: rgba(255,200,200,.8); margin-bottom: 8px; }

        .btn-emergency {
            background: rgba(200,60,60,.4);
            border: 1px solid rgba(255,120,120,.25);
            color: rgba(255,200,200,.9);
            border-radius: 20px;
            padding: 5px 14px;
            font-size: 10px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            width: 100%;
            transition: all .2s;
        }

        .btn-emergency:hover { background: rgba(200,60,60,.7); }

        .sidebar-footer {
            flex-shrink: 0;
            border-top: 1px solid rgba(255,255,255,.08);
            padding: 14px 10px;
            position: relative;
        }

        .sidebar-footer a {
            margin: 0;
            color: rgba(255,255,255,.5) !important;
        }

        .sidebar-footer a:hover { color: rgba(255,255,255,.8) !important; }

        /* ── Layout ──────────────────────────────────────────── */
        .content { margin-left: 240px; padding: 24px 26px; min-height: 100vh; }

        /* ── Topbar ──────────────────────────────────────────── */
        .topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .welcome-text {
            font-family: 'Cormorant Garamond', serif;
            font-size: 26px;
            font-weight: 500;
            color: var(--s800);
            line-height: 1.1;
            letter-spacing: -.01em;
            margin: 0 0 3px 0;
        }

        .welcome-text em { font-style: italic; color: var(--s500); }

        .welcome-sub {
            font-size: 12px;
            color: var(--st300);
            font-weight: 400;
            margin: 0;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-top: 4px;
            flex-wrap: wrap;
        }

        .date-chip {
            background: white;
            border: 1px solid var(--s100);
            border-radius: 20px;
            padding: 7px 14px;
            font-size: 11.5px;
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
            font-size: 11px;
            font-weight: 600;
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

        .logout-btn {
            background: linear-gradient(135deg, #C87A7A, #8B3A3A);
            border: none;
            border-radius: var(--radius-sm);
            color: white;
            padding: 7px 16px;
            font-weight: 600;
            font-size: 11px;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all .25s;
            box-shadow: var(--shadow-soft);
        }

        .logout-btn:hover { opacity: .9; box-shadow: var(--shadow-card); transform: translateY(-1px); }

        /* ── KPI Cards ───────────────────────────────────────── */
        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }

        .kpi-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 18px 18px 16px;
            box-shadow: var(--shadow-card);
            border: 1px solid rgba(196,217,180,.3);
            position: relative;
            overflow: hidden;
            transition: all .25s;
        }

        .kpi-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }

        .kpi-card.kpi-green::after { background: linear-gradient(90deg, var(--s300), var(--s500)); }
        .kpi-card.kpi-teal::after  { background: linear-gradient(90deg, #5BA4A4, #3A7A7A); }
        .kpi-card.kpi-rose::after  { background: linear-gradient(90deg, #C87A7A, #8B3A3A); }
        .kpi-card.kpi-amber::after { background: linear-gradient(90deg, #D4A853, #A06B2A); }

        .kpi-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lift); }

        .kpi-icon {
            width: 40px; height: 40px;
            border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px;
            margin-bottom: 14px;
        }

        .kpi-card.kpi-green .kpi-icon { background: linear-gradient(135deg, #C4D9B4, #9DC07E); color: var(--s700); }
        .kpi-card.kpi-teal  .kpi-icon { background: linear-gradient(135deg, #B0D4D4, #5BA4A4); color: #2A6A6A; }
        .kpi-card.kpi-rose  .kpi-icon { background: linear-gradient(135deg, #F0C8C8, #C87A7A); color: #6A2020; }
        .kpi-card.kpi-amber .kpi-icon { background: linear-gradient(135deg, #F0DFB0, #D4A853); color: #7A5010; }

        .kpi-num   { font-size: 28px; font-weight: 700; color: var(--s800); line-height: 1; margin-bottom: 3px; font-family: 'Outfit', sans-serif; letter-spacing: -.02em; }
        .kpi-label { font-size: 11.5px; font-weight: 500; color: var(--st300); margin-bottom: 8px; text-transform: uppercase; letter-spacing: .05em; }

        .kpi-trend {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10.5px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
            font-family: 'Outfit', sans-serif;
        }

        .trend-up      { background: #DDEFD8; color: #3A6830; }
        .trend-warn    { background: #FAECC8; color: #7A5010; }
        .trend-bad     { background: #F5DADA; color: #6A2020; }
        .trend-neutral { background: var(--w200); color: var(--st500); }

        /* ── Section cards ───────────────────────────────────── */
        .section-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            border: 1px solid rgba(196,217,180,.3);
            margin-bottom: 18px;
            overflow: hidden;
        }

        .section-header {
            padding: 13px 18px;
            border-bottom: 1px solid var(--s50);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-header-left {
            display: flex; align-items: center; gap: 9px;
        }

        .sh-icon {
            width: 30px; height: 30px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px;
        }

        .shi-green  { background: linear-gradient(135deg, #C4D9B4, #9DC07E); color: var(--s700); }
        .shi-teal   { background: linear-gradient(135deg, #B0D4D4, #5BA4A4); color: #2A6A6A; }
        .shi-amber  { background: linear-gradient(135deg, #F0DFB0, #D4A853); color: #7A5010; }
        .shi-rose   { background: linear-gradient(135deg, #F0C8C8, #C87A7A); color: #6A2020; }
        .shi-purple { background: linear-gradient(135deg, #D8C8F0, #9B7EC8); color: #4A2A80; }
        .shi-sage   { background: linear-gradient(135deg, var(--s100), var(--s300)); color: var(--s700); }
        .shi-sky    { background: linear-gradient(135deg, #BFD9F0, #6FA8D4); color: #1A4870; }

        .section-header h5 {
            font-family: 'Outfit', sans-serif;
            font-size: 13px;
            font-weight: 600;
            color: var(--s800);
            letter-spacing: -.01em;
            margin: 0;
        }

        .section-header a {
            font-size: 11px;
            color: var(--s400);
            font-weight: 600;
            text-decoration: none;
            display: flex; align-items: center; gap: 4px;
            transition: color .15s;
        }

        .section-header a:hover { color: var(--s600); }

        .count-chip {
            background: var(--s100);
            color: var(--s600);
            border-radius: 20px;
            font-size: 9.5px;
            font-weight: 700;
            padding: 2px 8px;
            letter-spacing: .03em;
        }

        .count-chip.chip-rose { background: #F5DADA; color: #6A2020; }

        .section-body { padding: 15px 18px; }

        .section-footer {
            padding: 10px 18px;
            border-top: 1px solid var(--s50);
            text-align: center;
        }

        .section-footer a {
            color: var(--s400);
            font-size: 11.5px;
            font-weight: 600;
            text-decoration: none;
            font-family: 'Outfit', sans-serif;
            transition: color .15s;
        }

        .section-footer a:hover { color: var(--s600); }

        /* ── Hero / Vitals strip ─────────────────────────────── */
        .hero-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            border: 1px solid rgba(196,217,180,.3);
            margin-bottom: 18px;
            overflow: hidden;
        }

        .hero-header {
            background: linear-gradient(105deg, var(--s700) 0%, var(--s500) 60%, var(--s400) 100%);
            padding: 18px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .hero-header-left {
            display: flex; align-items: center; gap: 12px;
        }

        .hero-icon-wrap {
            width: 38px; height: 38px; border-radius: 10px;
            background: rgba(255,255,255,.15);
            display: flex; align-items: center; justify-content: center;
            font-size: 17px; color: white;
        }

        .hero-header h3 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 17px; font-weight: 500; color: white; margin: 0;
        }

        .hero-header p { color: rgba(255,255,255,.6); font-size: 11px; margin: 0; }

        .hero-date-badge {
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 20px;
            padding: 5px 13px;
            font-size: 10.5px;
            font-weight: 600;
            color: rgba(255,255,255,.85);
            display: flex; align-items: center; gap: 5px;
        }

        .hero-body { padding: 0; }

        /* Health metrics grid */
        .health-metrics-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
        }

        .health-metric {
            padding: 18px 14px;
            text-align: center;
            border-right: 1px solid var(--s50);
            transition: all .2s;
        }

        .health-metric:last-child { border-right: none; }
        .health-metric:hover { background: var(--s50); }

        .metric-label { font-size: 9.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--st300); margin-bottom: 6px; }
        .metric-value { font-size: 22px; font-weight: 700; color: var(--s800); line-height: 1; margin-bottom: 2px; font-family: 'Outfit', sans-serif; letter-spacing: -.02em; }
        .metric-unit  { font-size: 10px; color: var(--st300); margin-bottom: 6px; }

        .metric-flag { display: inline-block; font-size: 9.5px; font-weight: 700; padding: 2px 8px; border-radius: 20px; }
        .flag-ok     { background: #DDEFD8; color: #3A6830; }
        .flag-high   { background: #F5DADA; color: #6A2020; }
        .flag-low    { background: #DAE8F5; color: #1A4870; }

        .vitals-footer {
            padding: 10px 18px;
            border-top: 1px solid var(--s50);
            text-align: center;
            font-size: 11px;
            color: var(--st300);
        }

        .vitals-footer a { color: var(--s400); font-weight: 600; text-decoration: none; }
        .vitals-footer a:hover { color: var(--s600); }

        /* Empty state */
        .empty-state { text-align: center; padding: 32px 20px; color: var(--st300); }
        .empty-state i { font-size: 2rem; color: var(--s200); margin-bottom: 10px; display: block; }
        .empty-state p { font-size: 12.5px; margin: 0; color: var(--st500); }

        /* ── Routine items ───────────────────────────────────── */
        .routine-item {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            padding: 10px 0;
            border-bottom: 1px solid var(--s50);
        }

        .routine-item:last-child { border-bottom: none; padding-bottom: 0; }

        .routine-time-col { width: 52px; flex-shrink: 0; text-align: right; padding-top: 2px; }
        .routine-time  { font-size: 11px; font-weight: 700; color: var(--s500); font-family: 'Outfit', sans-serif; }

        .routine-dot-col {
            display: flex; flex-direction: column; align-items: center;
            padding-top: 3px; flex-shrink: 0;
        }

        .rdot {
            width: 9px; height: 9px; border-radius: 50%;
            border: 2px solid var(--s300); background: white;
            flex-shrink: 0;
        }

        .rdot.rdot-done { background: var(--s400); border-color: var(--s400); }
        .rdot.rdot-skip { background: #D4A853; border-color: #D4A853; }
        .rdot.rdot-cancelled { background: #C87A7A; border-color: #C87A7A; }

        .rline { width: 1px; height: 24px; background: var(--s100); }

        .routine-info { flex: 1; }
        .routine-title { font-size: 12.5px; font-weight: 600; color: var(--s800); margin-bottom: 2px; }
        .routine-desc  { font-size: 10.5px; color: var(--st300); margin-bottom: 0; }
        .routine-with  { font-size: 10px; color: var(--st300); }

        .status-badge {
            flex-shrink: 0;
            display: inline-block;
            font-size: 9.5px;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 20px;
        }

        .sb-pending   { background: #FAECC8; color: #7A5010; }
        .sb-completed { background: #DDEFD8; color: #3A6830; }
        .sb-skipped   { background: #FAECC8; color: #7A5010; }
        .sb-cancelled { background: #F5DADA; color: #6A2020; }

        /* ── Medication cards ────────────────────────────────── */
        .medication-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--s50);
            transition: all .2s;
        }

        .medication-card:last-child { border-bottom: none; padding-bottom: 0; }
        .medication-card.taken { opacity: .75; }

        .med-pill-icon {
            width: 34px; height: 34px; border-radius: 9px;
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
        }

        .mpi-1 { background: linear-gradient(135deg, #F0E0B0, #D4B04A); color: #6A4A0A; }
        .mpi-2 { background: linear-gradient(135deg, #BFD9F0, #6FA8D4); color: #1A4870; }
        .mpi-3 { background: linear-gradient(135deg, #F0C8D8, #D47A9A); color: #6A1A3A; }

        .medication-info { flex: 1; }
        .medication-name { font-size: 12.5px; font-weight: 600; color: var(--s800); margin-bottom: 1px; }
        .medication-dose { font-size: 10.5px; color: var(--st300); }

        .medication-time-col { text-align: right; }
        .medication-time { font-size: 12px; font-weight: 700; color: var(--s600); font-family: 'Outfit', sans-serif; display: block; }

        .btn-mark-taken {
            margin-top: 3px;
            display: inline-block;
            font-size: 9px; font-weight: 700;
            padding: 2px 9px; border-radius: 20px;
            border: none; cursor: pointer;
            font-family: 'Outfit', sans-serif;
            transition: all .2s;
        }

        .btn-mark-taken.pending {
            background: linear-gradient(135deg, var(--s300), var(--s500));
            color: white;
        }

        .btn-mark-taken.taken { background: #DDEFD8; color: #3A6830; }
        .btn-mark-taken:hover { opacity: .85; transform: scale(1.04); }

        /* ── Alert feed ──────────────────────────────────────── */
        .alert-feed-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid var(--s50);
        }

        .alert-feed-item:last-child { border-bottom: none; padding-bottom: 0; }

        .alert-dot {
            width: 9px; height: 9px; border-radius: 50%;
            flex-shrink: 0; margin-top: 4px;
        }

        .dot-critical { background: #C87A7A; box-shadow: 0 0 0 3px rgba(200,122,122,.2); animation: alertpulse 2.2s ease-in-out infinite; }
        .dot-warning  { background: #D4A853; box-shadow: 0 0 0 3px rgba(212,168,83,.2); animation: alertpulsew 2.2s ease-in-out infinite; }
        .dot-health   { background: var(--s400); }
        .dot-general  { background: var(--st300); }

        @keyframes alertpulse  { 0%,100%{box-shadow:0 0 0 0 rgba(200,122,122,.4)}50%{box-shadow:0 0 0 5px rgba(200,122,122,0)} }
        @keyframes alertpulsew { 0%,100%{box-shadow:0 0 0 0 rgba(212,168,83,.4)}50%{box-shadow:0 0 0 4px rgba(212,168,83,0)} }

        .alert-body { flex: 1; }
        .alert-type-tag { font-size: 9px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 3px; }
        .att-critical { color: #8B3A3A; }
        .att-warning  { color: #A06B2A; }
        .att-health_warning { color: var(--s500); }
        .att-general  { color: var(--st300); }

        .alert-msg  { font-size: 12px; font-weight: 500; color: var(--s800); line-height: 1.4; }
        .alert-meta { font-size: 10px; color: var(--st300); margin-top: 2px; }

        .alert-type-chip {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .atc-critical       { background: #F5DADA; color: #6A2020; }
        .atc-warning        { background: #FAECC8; color: #7A5010; }
        .atc-health_warning { background: var(--s50); color: var(--s600); }
        .atc-general        { background: var(--w200); color: var(--st500); }

        /* ── Appointment items ───────────────────────────────── */
        .appt-item {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            padding: 10px 0;
            border-bottom: 1px solid var(--s50);
        }

        .appt-item:last-child { border-bottom: none; padding-bottom: 0; }

        .appt-date-block {
            width: 38px; flex-shrink: 0; text-align: center;
            background: var(--s50); border-radius: 9px;
            padding: 5px 4px;
            border: 1px solid var(--s100);
        }

        .adb-month { font-size: 8.5px; font-weight: 700; color: var(--s400); text-transform: uppercase; letter-spacing: .06em; }
        .adb-day   { font-size: 18px; font-weight: 700; color: var(--s700); line-height: 1; font-family: 'Outfit', sans-serif; }

        .appt-info { flex: 1; }
        .appt-title { font-size: 12.5px; font-weight: 600; color: var(--s800); margin-bottom: 2px; }
        .appt-meta  { font-size: 10.5px; color: var(--st300); }

        .badge-scheduled { background: var(--s100); color: var(--s600); padding: 2px 9px; border-radius: 10px; font-size: 9.5px; font-weight: 700; }
        .badge-completed { background: #DDEFD8; color: #3A6830; padding: 2px 9px; border-radius: 10px; font-size: 9.5px; font-weight: 700; }
        .badge-cancelled { background: #F5DADA; color: #6A2020; padding: 2px 9px; border-radius: 10px; font-size: 9.5px; font-weight: 700; }

        /* ── Wellness tips ───────────────────────────────────── */
        .wellness-item {
            display: flex; align-items: flex-start; gap: 11px;
            padding: 9px 0; border-bottom: 1px solid var(--s50);
        }

        .wellness-item:last-child { border-bottom: none; padding-bottom: 0; }

        .wellness-icon {
            width: 32px; height: 32px; border-radius: 9px;
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            background: linear-gradient(135deg, var(--s100), var(--s200));
        }

        .wellness-text { flex: 1; font-size: 12px; font-weight: 500; color: var(--s700); padding-top: 7px; line-height: 1.35; }

        /* ── Quick action buttons ────────────────────────────── */
        .qa-btn {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px 6px;
            border-bottom: 1px solid var(--s50);
            cursor: pointer;
            transition: all .15s;
            border-radius: var(--radius-sm);
            margin: 0 -6px;
            background: transparent;
            border-left: none; border-right: none; border-top: none;
            text-align: left;
            width: calc(100% + 12px);
            text-decoration: none;
        }

        .qa-btn:last-child { border-bottom: none; }
        .qa-btn:hover { background: var(--s50); }

        .qa-btn-icon {
            width: 36px; height: 36px; border-radius: 10px;
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
        }

        .qai-green  { background: linear-gradient(135deg, var(--s200), var(--s400)); color: var(--s800); }
        .qai-sky    { background: linear-gradient(135deg, #BFD9F0, #6FA8D4); color: #1A4870; }
        .qai-amber  { background: linear-gradient(135deg, #F0E0B0, #C8943A); color: #5A3808; }

        .qa-label    { font-size: 12.5px; font-weight: 600; color: var(--s800); }
        .qa-desc     { font-size: 10.5px; color: var(--st300); }
        .qa-arrow    { margin-left: auto; color: var(--s300); font-size: 10px; }

        /* Medical conditions note */
        .medical-note {
            background: #FFF7ED;
            border-left: 3px solid #D4A853;
            border-radius: var(--radius-sm);
            padding: 10px 13px;
            margin-top: 10px;
            font-size: 11.5px;
            color: #7A5010;
            font-weight: 500;
            line-height: 1.5;
        }

        /* ── Floating action buttons ─────────────────────────── */
        .quick-actions {
            position: fixed; bottom: 22px; right: 22px;
            z-index: 1000; display: flex; flex-direction: column; gap: 10px; align-items: center;
        }

        .action-fab {
            width: 50px; height: 50px; border-radius: 50%; border: none;
            background: linear-gradient(135deg, var(--s400), var(--s600));
            color: white; font-size: 1.1rem;
            cursor: pointer; box-shadow: 0 4px 16px rgba(74,110,48,.35);
            transition: all .3s; display: flex; align-items: center; justify-content: center;
        }

        .action-fab:hover { transform: scale(1.1); box-shadow: 0 6px 22px rgba(74,110,48,.5); }

        .action-fab.fab-emergency {
            background: linear-gradient(135deg, #C87A7A, #8B3A3A);
            box-shadow: 0 4px 16px rgba(139,58,58,.35);
        }

        .action-fab.fab-emergency:hover { box-shadow: 0 6px 22px rgba(139,58,58,.55); }

        .fab-badge {
            position: absolute; top: -3px; right: -3px;
            background: #8B3A3A; color: white; font-size: .6rem; font-weight: 800;
            width: 17px; height: 17px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid white;
        }

        /* ── High contrast mode ──────────────────────────────── */
        .high-contrast {
            background: #0a0a0a !important;
            color: #f0f0f0 !important;
        }

        .high-contrast .section-card,
        .high-contrast .hero-card,
        .high-contrast .kpi-card,
        .high-contrast .sidebar {
            background: #1a1a1a !important;
            border-color: #333 !important;
            color: #f0f0f0 !important;
        }

        .high-contrast .kpi-num,
        .high-contrast .kpi-label,
        .high-contrast .section-header h5,
        .high-contrast .routine-title,
        .high-contrast .medication-name,
        .high-contrast .alert-msg,
        .high-contrast .appt-title,
        .high-contrast .wellness-text,
        .high-contrast .qa-label,
        .high-contrast .welcome-text { color: #f0f0f0 !important; }

        .high-contrast .metric-value { color: #fff !important; }
        .high-contrast .health-metric { background: #2a2a2a !important; border-color: #333 !important; }
        .high-contrast .routine-item, .high-contrast .medication-card,
        .high-contrast .alert-feed-item, .high-contrast .appt-item,
        .high-contrast .wellness-item, .high-contrast .qa-btn { border-color: #333 !important; }

        /* ── Entry animations ────────────────────────────────── */
        .kpi-card { animation: fadeUp .4s ease both; }
        .kpi-card:nth-child(1) { animation-delay: .05s; }
        .kpi-card:nth-child(2) { animation-delay: .10s; }
        .kpi-card:nth-child(3) { animation-delay: .15s; }
        .kpi-card:nth-child(4) { animation-delay: .20s; }
        .hero-card { animation: fadeUp .4s .22s ease both; }
        .section-card { animation: fadeUp .4s .28s ease both; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Responsive ──────────────────────────────────────── */
        @media (max-width: 1200px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .health-metrics-grid { grid-template-columns: repeat(3, 1fr); }
            .health-metrics-grid .health-metric:nth-child(3) { border-right: none; }
        }

        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .content { margin-left: 0; padding: 14px; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .health-metrics-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<!-- ══ SIDEBAR ════════════════════════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sidebar-header">
        <div class="brand-mark">
            <div class="brand-icon"><i class="fas fa-leaf"></i></div>
            <h4>SmartCare<br>Guardian</h4>
        </div>
        <small>Resident Portal</small>
    </div>
    <div class="sidebar-nav">
        <a href="resident_dashboard.php" class="active"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="elder_profile.php"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="elder_health.php"><i class="fa-solid fa-heart-pulse"></i> My Health Data</a>
        <a href="resident_alerts.php">
            <i class="fa-solid fa-bell"></i> My Health Alerts
            <?php if ($unresolved_count > 0): ?><span class="sb-badge"><?= $unresolved_count ?></span><?php endif; ?>
        </a>
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

        <div class="sb-section-label">Care Team</div>

        <?php if ($assigned_caregiver): ?>
        <div class="sidebar-info-card">
            <div class="si-label"><i class="fas fa-user-nurse me-1"></i>Your Caregiver</div>
            <div class="si-name"><?= htmlspecialchars($assigned_caregiver['full_name']) ?></div>
            <div class="si-email"><?= htmlspecialchars($assigned_caregiver['email']) ?></div>
        </div>
        <?php endif; ?>

        <div class="sidebar-emergency">
            <div class="se-label"><i class="fas fa-phone-alt me-1"></i>Emergency Contact</div>
            <div class="se-contact"><?= htmlspecialchars($resident_info['emergency_contact'] ?? 'Not set') ?></div>
            <button class="btn-emergency" onclick="callEmergency()">
                <i class="fas fa-phone me-1"></i> Call Now
            </button>
        </div>
    </div>
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php" style="margin:0;">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════════════ -->
<div class="content">

    <!-- Topbar -->
    <div class="topbar">
        <div>
            <p class="welcome-text">Good day, <em><?= htmlspecialchars($user_name) ?></em> 🌿</p>
            <p class="welcome-sub">
                <i class="far fa-calendar me-1"></i><?= date('l, F j, Y') ?>
                <?php if ($resident_info['age']): ?>&nbsp;·&nbsp; <?= $resident_info['age'] ?> years old<?php endif; ?>
            </p>
        </div>
        <div class="topbar-actions">
            <div class="date-chip"><i class="fas fa-shield-heart"></i>Health Dashboard</div>
            <button class="topbar-btn" onclick="increaseFontSize()"><i class="fas fa-search-plus"></i>Larger</button>
            <button class="topbar-btn" onclick="decreaseFontSize()"><i class="fas fa-search-minus"></i>Smaller</button>
            <button class="topbar-btn" onclick="resetFontSize()"><i class="fas fa-redo"></i>Reset</button>
            <button class="topbar-btn" onclick="toggleHighContrast()"><i class="fas fa-adjust"></i>Contrast</button>
            <button class="topbar-btn" onclick="speakPage()"><i class="fas fa-volume-up"></i>Read</button>
        </div>
    </div>

    <!-- ── KPI Cards ──────────────────────────────────────────────────────── -->
    <div class="kpi-grid">

        <div class="kpi-card kpi-green">
            <div class="kpi-icon"><i class="fas fa-pills"></i></div>
            <div class="kpi-num"><?= $meds_taken ?>/<?= count($today_medications) ?></div>
            <div class="kpi-label">Medications Taken</div>
            <?php if ($meds_pending === 0 && count($today_medications) > 0): ?>
                <div class="kpi-trend trend-up"><i class="fas fa-check"></i>All taken</div>
            <?php elseif ($meds_pending > 0): ?>
                <div class="kpi-trend trend-warn"><i class="fas fa-clock"></i><?= $meds_pending ?> pending</div>
            <?php else: ?>
                <div class="kpi-trend trend-neutral"><i class="fas fa-minus"></i>None today</div>
            <?php endif; ?>
        </div>

        <div class="kpi-card kpi-teal">
            <div class="kpi-icon"><i class="fas fa-calendar-check"></i></div>
            <div class="kpi-num"><?= $routines_done ?>/<?= count($today_routines) ?></div>
            <div class="kpi-label">Routines Done</div>
            <?php $rt_pending = count($today_routines) - $routines_done; ?>
            <?php if ($rt_pending === 0 && count($today_routines) > 0): ?>
                <div class="kpi-trend trend-up"><i class="fas fa-check"></i>All complete</div>
            <?php elseif ($rt_pending > 0): ?>
                <div class="kpi-trend trend-warn"><i class="fas fa-clock"></i><?= $rt_pending ?> pending</div>
            <?php else: ?>
                <div class="kpi-trend trend-neutral"><i class="fas fa-minus"></i>None today</div>
            <?php endif; ?>
        </div>

        <div class="kpi-card kpi-rose">
            <div class="kpi-icon"><i class="fas fa-bell"></i></div>
            <div class="kpi-num"><?= $unresolved_count ?></div>
            <div class="kpi-label">Active Alerts</div>
            <?php if ($unresolved_count > 0): ?>
                <div class="kpi-trend trend-bad"><i class="fas fa-circle"></i>Needs attention</div>
            <?php else: ?>
                <div class="kpi-trend trend-up"><i class="fas fa-check"></i>All clear</div>
            <?php endif; ?>
        </div>

        <div class="kpi-card kpi-amber">
            <div class="kpi-icon"><i class="fas fa-comments"></i></div>
            <div class="kpi-num"><?= $unread_messages ?></div>
            <div class="kpi-label">Unread Messages</div>
            <?php if ($unread_messages > 0): ?>
                <div class="kpi-trend trend-warn"><i class="fas fa-envelope"></i>New messages</div>
            <?php else: ?>
                <div class="kpi-trend trend-up"><i class="fas fa-check"></i>All read</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Health Vitals Strip ────────────────────────────────────────────── -->
    <div class="hero-card">
        <div class="hero-header">
            <div class="hero-header-left">
                <div class="hero-icon-wrap"><i class="fas fa-heart-pulse"></i></div>
                <div>
                    <h3>Your Health &amp; Wellness Overview</h3>
                    <p>SmartCare Guardian is monitoring your health and supporting your daily routine.</p>
                </div>
            </div>
            <?php if ($latest_health): ?>
            <div class="hero-date-badge">
                <i class="fas fa-check-circle"></i> Updated <?= $latest_health['date'] ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="hero-body">
            <?php if ($latest_health):
                $bp_sys = $latest_health['blood_pressure_systolic'];
                $bp_dia = $latest_health['blood_pressure_diastolic'];
                $sugar  = $latest_health['blood_sugar'];
                $pulse  = $latest_health['pulse'];
                $temp   = $latest_health['temperature'];
                $o2     = $latest_health['oxygen_saturation'];
                $bp_flag  = ($bp_sys > 140 || $bp_sys < 90) ? ($bp_sys > 140 ? 'flag-high' : 'flag-low') : 'flag-ok';
                $bp_label = ($bp_sys > 140 || $bp_sys < 90) ? ($bp_sys > 140 ? 'High' : 'Low') : 'Normal';
                $sg_flag  = ($sugar > 180  || $sugar < 70)  ? ($sugar > 180  ? 'flag-high' : 'flag-low') : 'flag-ok';
                $sg_label = ($sugar > 180  || $sugar < 70)  ? ($sugar > 180  ? 'High' : 'Low') : 'Normal';
                $pu_flag  = ($pulse > 100  || $pulse < 50)  ? ($pulse > 100  ? 'flag-high' : 'flag-low') : 'flag-ok';
                $pu_label = ($pulse > 100  || $pulse < 50)  ? ($pulse > 100  ? 'High' : 'Low') : 'Normal';
                $o2_flag  = ($o2 < 95) ? 'flag-low'  : 'flag-ok';
                $o2_label = ($o2 < 95) ? 'Low' : 'Normal';
                $tmp_flag = ($temp > 37.8 || $temp < 36.0) ? ($temp > 37.8 ? 'flag-high' : 'flag-low') : 'flag-ok';
                $tmp_label= ($temp > 37.8 || $temp < 36.0) ? ($temp > 37.8 ? 'High' : 'Low') : 'Normal';
            ?>
            <div class="health-metrics-grid">
                <div class="health-metric">
                    <div class="metric-label">Blood Pressure</div>
                    <div class="metric-value" style="font-size:18px;"><?= $bp_sys ?>/<?= $bp_dia ?></div>
                    <div class="metric-unit">mmHg</div>
                    <span class="metric-flag <?= $bp_flag ?>"><?= $bp_label ?></span>
                </div>
                <div class="health-metric">
                    <div class="metric-label">Blood Sugar</div>
                    <div class="metric-value"><?= $sugar ?></div>
                    <div class="metric-unit">mg/dL</div>
                    <span class="metric-flag <?= $sg_flag ?>"><?= $sg_label ?></span>
                </div>
                <div class="health-metric">
                    <div class="metric-label">Heart Rate</div>
                    <div class="metric-value"><?= $pulse ?></div>
                    <div class="metric-unit">bpm</div>
                    <span class="metric-flag <?= $pu_flag ?>"><?= $pu_label ?></span>
                </div>
                <div class="health-metric">
                    <div class="metric-label">Oxygen</div>
                    <div class="metric-value"><?= $o2 ?></div>
                    <div class="metric-unit">SpO₂ %</div>
                    <span class="metric-flag <?= $o2_flag ?>"><?= $o2_label ?></span>
                </div>
                <div class="health-metric">
                    <div class="metric-label">Temperature</div>
                    <div class="metric-value"><?= $temp ?></div>
                    <div class="metric-unit">°C</div>
                    <span class="metric-flag <?= $tmp_flag ?>"><?= $tmp_label ?></span>
                </div>
            </div>
            <div class="vitals-footer">
                Last recorded: <?= $latest_health['date'] ?>
                &nbsp;·&nbsp;
                <a href="elder_health.php">View full history →</a>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-heart-pulse"></i>
                <p style="font-weight:600;color:var(--s700);margin-bottom:4px;">No recent health readings.</p>
                <p>Your caregiver will record your vitals soon.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Row: Schedule + Medications ───────────────────────────────────── -->
    <div class="row g-3 mb-3">

        <div class="col-md-6">
            <div class="section-card">
                <div class="section-header">
                    <div class="section-header-left">
                        <div class="sh-icon shi-sage"><i class="fas fa-calendar-day"></i></div>
                        <h5>Today's Schedule</h5>
                        <?php if (!empty($today_routines)): ?>
                            <span class="count-chip"><?= count($today_routines) ?> tasks</span>
                        <?php endif; ?>
                    </div>
                    <a href="elder_schedule.php">Full schedule <i class="fas fa-chevron-right"></i></a>
                </div>
                <div class="section-body">
                    <?php if (!empty($today_routines)):
                        $total = count($today_routines);
                        foreach ($today_routines as $i => $rt):
                            $st = $rt['today_status'];
                            $rdot_class = $st === 'completed' ? 'rdot-done' : ($st === 'skipped' ? 'rdot-skip' : ($st === 'cancelled' ? 'rdot-cancelled' : ''));
                        ?>
                        <div class="routine-item">
                            <div class="routine-time-col">
                                <div class="routine-time"><?= date('h:i A', strtotime($rt['schedule_time'])) ?></div>
                            </div>
                            <div class="routine-dot-col">
                                <div class="rdot <?= $rdot_class ?>"></div>
                                <?php if ($i < $total - 1): ?><div class="rline"></div><?php endif; ?>
                            </div>
                            <div class="routine-info">
                                <div class="routine-title"><?= ucfirst(str_replace('_',' ',$rt['routine_type'])) ?></div>
                                <?php if ($rt['description']): ?>
                                <div class="routine-desc"><?= htmlspecialchars($rt['description']) ?></div>
                                <?php endif; ?>
                                <div class="routine-with">With: <?= htmlspecialchars($rt['caregiver_name']) ?></div>
                            </div>
                            <span class="status-badge sb-<?= $st ?>"><?= ucfirst($st) ?></span>
                        </div>
                        <?php endforeach; else: ?>
                        <div class="empty-state"><i class="fas fa-calendar-check"></i><p>No routines scheduled for today.</p></div>
                    <?php endif; ?>
                </div>
                <div class="section-footer"><a href="elder_schedule.php"><i class="fas fa-calendar-alt me-1"></i>View Full Schedule</a></div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="section-card">
                <div class="section-header">
                    <div class="section-header-left">
                        <div class="sh-icon shi-teal"><i class="fas fa-pills"></i></div>
                        <h5>Today's Medications</h5>
                        <?php if (!empty($today_medications)): ?>
                            <span class="count-chip"><?= $meds_taken ?>/<?= count($today_medications) ?> taken</span>
                        <?php endif; ?>
                    </div>
                    <a href="elder_medications.php">History <i class="fas fa-chevron-right"></i></a>
                </div>
                <div class="section-body">
                    <?php if (!empty($today_medications)):
                        $icons = ['mpi-1','mpi-2','mpi-3'];
                        $pill_icons = ['fa-capsules','fa-tablets','fa-pills'];
                        foreach ($today_medications as $idx => $med):
                            $ic = $icons[$idx % 3];
                            $pi = $pill_icons[$idx % 3];
                        ?>
                        <div class="medication-card <?= $med['taken'] ? 'taken' : '' ?>">
                            <div class="med-pill-icon <?= $ic ?>"><i class="fas <?= $pi ?>"></i></div>
                            <div class="medication-info">
                                <div class="medication-name">
                                    <?php if ($med['taken']): ?><i class="fas fa-check-circle" style="color:#4A7C59;margin-right:3px;"></i><?php endif; ?>
                                    <?= htmlspecialchars($med['medication_name']) ?>
                                </div>
                                <div class="medication-dose"><?= htmlspecialchars($med['dosage']) ?> · <?= htmlspecialchars($med['frequency']) ?></div>
                            </div>
                            <div class="medication-time-col">
                                <span class="medication-time"><?= $med['med_time'] ?></span>
                                <form method="POST">
                                    <input type="hidden" name="medication_id" value="<?= $med['medication_id'] ?>">
                                    <input type="hidden" name="taken"         value="<?= $med['taken'] ?>">
                                    <button type="submit" name="toggle_med" class="btn-mark-taken <?= $med['taken'] ? 'taken' : 'pending' ?>">
                                        <?= $med['taken'] ? '✓ Taken' : 'Mark Taken' ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; else: ?>
                        <div class="empty-state"><i class="fas fa-prescription-bottle"></i><p>No medications scheduled for today.</p></div>
                    <?php endif; ?>
                </div>
                <div class="section-footer"><a href="elder_medications.php"><i class="fas fa-history me-1"></i>Medication History</a></div>
            </div>
        </div>
    </div>

    <!-- ── Row: Appointments + Alerts ────────────────────────────────────── -->
    <div class="row g-3 mb-3">

        <div class="col-md-6">
            <div class="section-card">
                <div class="section-header">
                    <div class="section-header-left">
                        <div class="sh-icon shi-purple"><i class="fas fa-calendar-days"></i></div>
                        <h5>Upcoming Appointments</h5>
                        <?php if (!empty($upcoming_appointments)): ?>
                            <span class="count-chip"><?= count($upcoming_appointments) ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="elder_appointments.php">View all <i class="fas fa-chevron-right"></i></a>
                </div>
                <div class="section-body">
                    <?php if (!empty($upcoming_appointments)):
                        foreach ($upcoming_appointments as $appt):
                            $parts = explode(' ', $appt['apt_date']); // e.g. "Mar 05, 2026"
                            $month = $parts[0] ?? '';
                            $day   = rtrim($parts[1] ?? '', ',');
                        ?>
                        <div class="appt-item">
                            <div class="appt-date-block">
                                <div class="adb-month"><?= $month ?></div>
                                <div class="adb-day"><?= $day ?></div>
                            </div>
                            <div class="appt-info">
                                <div class="appt-title"><?= htmlspecialchars($appt['title']) ?></div>
                                <div class="appt-meta">
                                    <i class="far fa-clock me-1"></i><?= $appt['apt_time'] ?>
                                    <?php if ($appt['location']): ?>&nbsp;·&nbsp;<i class="fas fa-location-dot me-1"></i><?= htmlspecialchars($appt['location']) ?><?php endif; ?>
                                </div>
                            </div>
                            <span class="badge-<?= $appt['status'] ?>"><?= ucfirst($appt['status']) ?></span>
                        </div>
                        <?php endforeach; else: ?>
                        <div class="empty-state"><i class="fas fa-calendar-days"></i><p>No upcoming appointments.</p></div>
                    <?php endif; ?>
                </div>
                <div class="section-footer"><a href="elder_appointments.php"><i class="fas fa-calendar-plus me-1"></i>View All Appointments</a></div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="section-card">
                <div class="section-header">
                    <div class="section-header-left">
                        <div class="sh-icon shi-rose"><i class="fas fa-bell"></i></div>
                        <h5>Health Alerts</h5>
                        <?php if ($unresolved_count > 0): ?>
                            <span class="count-chip chip-rose"><?= $unresolved_count ?> active</span>
                        <?php endif; ?>
                    </div>
                    <a href="resident_alerts.php">View all <i class="fas fa-chevron-right"></i></a>
                </div>
                <div class="section-body">
                    <?php if (!empty($recent_alerts)):
                        foreach ($recent_alerts as $alert):
                            $type = $alert['alert_type'] ?? 'general';
                            $dot  = match($type) { 'critical'=>'dot-critical','warning'=>'dot-warning','health_warning'=>'dot-health',default=>'dot-general' };
                        ?>
                        <div class="alert-feed-item">
                            <div class="alert-dot <?= $dot ?>"></div>
                            <div class="alert-body">
                                <div class="alert-type-tag att-<?= $type ?>"><?= strtoupper(str_replace('_',' ',$type)) ?></div>
                                <div class="alert-msg"><?= htmlspecialchars($alert['alert_message']) ?></div>
                                <div class="alert-meta">
                                    <span class="alert-type-chip atc-<?= $type ?>"><?= strtoupper(str_replace('_',' ',$type)) ?></span>
                                    &nbsp;<?= $alert['time'] ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle" style="color:#4A7C59;"></i>
                            <p style="color:#3A6830;font-weight:600;">All clear! No active alerts.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="section-footer"><a href="resident_alerts.php"><i class="fas fa-list me-1"></i>View All Alerts</a></div>
            </div>
        </div>
    </div>

    <!-- ── Row: Wellness Tips + Quick Actions ─────────────────────────────── -->
    <div class="row g-3">

        <div class="col-md-6">
            <div class="section-card">
                <div class="section-header">
                    <div class="section-header-left">
                        <div class="sh-icon shi-green"><i class="fas fa-spa"></i></div>
                        <h5>Daily Wellness Tips</h5>
                    </div>
                    <a href="#" onclick="showMoreTips();return false;">More tips <i class="fas fa-chevron-right"></i></a>
                </div>
                <div class="section-body">
                    <?php foreach ($wellness_tips as [$emoji, $text]): ?>
                    <div class="wellness-item">
                        <div class="wellness-icon"><?= $emoji ?></div>
                        <div class="wellness-text"><?= $text ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="section-card">
                <div class="section-header">
                    <div class="section-header-left">
                        <div class="sh-icon shi-amber"><i class="fas fa-bolt"></i></div>
                        <h5>Quick Actions</h5>
                    </div>
                </div>
                <div class="section-body">
                    <button class="qa-btn" onclick="callCaregiver()">
                        <div class="qa-btn-icon qai-green"><i class="fas fa-phone-alt"></i></div>
                        <div>
                            <div class="qa-label">Call Caregiver</div>
                            <div class="qa-desc">Contact your assigned caregiver</div>
                        </div>
                        <div class="qa-arrow"><i class="fas fa-chevron-right"></i></div>
                    </button>
                    <a href="elder_messages.php" class="qa-btn">
                        <div class="qa-btn-icon qai-sky"><i class="fas fa-comment-medical"></i></div>
                        <div>
                            <div class="qa-label">
                                Send Message
                                <?php if ($unread_messages > 0): ?>
                                    <span style="background:#F5DADA;color:#6A2020;padding:1px 7px;border-radius:20px;font-size:9px;font-weight:700;margin-left:5px;"><?= $unread_messages ?> new</span>
                                <?php endif; ?>
                            </div>
                            <div class="qa-desc">Message your care team</div>
                        </div>
                        <div class="qa-arrow"><i class="fas fa-chevron-right"></i></div>
                    </a>
                    <button class="qa-btn" onclick="requestHelp()">
                        <div class="qa-btn-icon qai-amber"><i class="fas fa-hands-helping"></i></div>
                        <div>
                            <div class="qa-label">Request Help</div>
                            <div class="qa-desc">Alert your care team immediately</div>
                        </div>
                        <div class="qa-arrow"><i class="fas fa-chevron-right"></i></div>
                    </button>
                    <?php if ($resident_info['medical_conditions']): ?>
                    <div class="medical-note">
                        <i class="fas fa-notes-medical me-1"></i><strong>Medical Conditions:</strong><br>
                        <?= htmlspecialchars($resident_info['medical_conditions']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div><!-- /content -->

<!-- ── Floating Action Buttons ─────────────────────────────────────────── -->
<div class="quick-actions">
    <button class="action-fab fab-emergency" onclick="callEmergency()" title="Emergency Call">
        <i class="fas fa-phone-alt"></i>
    </button>
    <div style="position:relative;">
        <button class="action-fab" onclick="location.href='elder_messages.php'" title="Messages">
            <i class="fas fa-comments"></i>
        </button>
        <?php if ($unread_messages > 0): ?><div class="fab-badge"><?= $unread_messages ?></div><?php endif; ?>
    </div>
    <button class="action-fab" onclick="speakPage()" title="Read Aloud">
        <i class="fas fa-volume-up"></i>
    </button>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const DEFAULT_FONT_SIZE = 14;
    let fontSize = parseInt(localStorage.getItem('elderFontSize')) || DEFAULT_FONT_SIZE;
    document.body.style.fontSize = fontSize + 'px';

    function increaseFontSize() {
        if (fontSize < 26) { fontSize += 2; applyFontSize(); showToast('Text size increased'); }
    }

    function decreaseFontSize() {
        if (fontSize > 10) { fontSize -= 2; applyFontSize(); showToast('Text size decreased'); }
    }

    function resetFontSize() {
        fontSize = DEFAULT_FONT_SIZE;
        applyFontSize();
        showToast('Text size reset to default');
    }

    function applyFontSize() {
        document.body.style.fontSize = fontSize + 'px';
        localStorage.setItem('elderFontSize', fontSize);
    }

    function toggleHighContrast() {
        document.body.classList.toggle('high-contrast');
        const on = document.body.classList.contains('high-contrast');
        localStorage.setItem('elderHighContrast', on);
        showToast(on ? 'High contrast enabled' : 'High contrast disabled');
    }

    function speakPage() {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
            const text = `Good day, <?= addslashes($user_name) ?>. Here is your health summary for today. `
                + `You have <?= count($today_medications) ?> medications today, <?= $meds_taken ?> taken. `
                + `You have <?= count($today_routines) ?> routines scheduled, <?= $routines_done ?> completed. `
                + `<?= $unread_messages > 0 ? "You have {$unread_messages} unread messages." : '' ?> `
                + `<?= $unresolved_count > 0 ? "You have {$unresolved_count} active health alerts." : 'No active health alerts.' ?> `
                + `Have a wonderful day!`;
            const s = new SpeechSynthesisUtterance(text);
            s.rate = 0.9; s.pitch = 1;
            window.speechSynthesis.speak(s);
            showToast('Reading your dashboard summary…');
        } else {
            showToast('Text-to-speech not supported in this browser');
        }
    }

    function callCaregiver() { showToast('Calling your assigned caregiver…'); }
    function requestHelp()   { showToast('Help request sent to your care team!'); }

    function callEmergency() {
        if (confirm('Are you sure you want to make an emergency call?')) {
            showToast('Connecting to emergency services…');
        }
    }

    function showMoreTips() {
        const tips = [
            "List 3 things you are grateful for today",
            "Stay connected with family and friends",
            "Enjoy a balanced diet with plenty of fruits and vegetables",
            "Listen to calming music or nature sounds",
            "Keep your mind active with puzzles or reading",
            "Sit by a window and enjoy some sunlight",
            "Take a few slow, deep breaths whenever you feel anxious"
        ];
        showToast('💡 ' + tips[Math.floor(Math.random() * tips.length)]);
    }

    function showToast(message) {
        const ex = document.getElementById('scg-toast');
        if (ex) ex.remove();
        const t = document.createElement('div');
        t.id = 'scg-toast';
        t.style.cssText = 'position:fixed;bottom:90px;right:22px;z-index:9999;max-width:300px;';
        t.innerHTML = `<div style="background:rgba(36,56,22,.95);color:rgba(255,255,255,.9);padding:12px 18px;border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.2);font-weight:600;font-family:Outfit,sans-serif;font-size:.88rem;line-height:1.4;">${message}</div>`;
        document.body.appendChild(t);
        setTimeout(() => { if (t.parentNode) t.remove(); }, 3000);
    }

    document.addEventListener('DOMContentLoaded', () => {
        const sf = localStorage.getItem('elderFontSize');
        const hc = localStorage.getItem('elderHighContrast');
        if (sf) { fontSize = parseInt(sf); document.body.style.fontSize = fontSize + 'px'; }
        if (hc === 'true') document.body.classList.add('high-contrast');
        if (!localStorage.getItem('elderFirstVisit')) {
            setTimeout(speakPage, 1200);
            localStorage.setItem('elderFirstVisit', 'true');
        }
    });

    // Auto-refresh every 5 minutes
    setTimeout(() => location.reload(), 300000);
</script>
</body>
</html>
