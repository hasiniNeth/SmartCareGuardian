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

$selected_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
    ? $_GET['date']
    : date('Y-m-d');

$is_today = $selected_date === date('Y-m-d');
$is_past  = $selected_date < date('Y-m-d');

$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

$allowed_status = ['all', 'taken', 'pending'];
$status_filter  = in_array($_GET['status'] ?? '', $allowed_status) ? $_GET['status'] : 'all';

$where_status = '';
if ($status_filter === 'taken')   $where_status = "AND m.taken = 1";
if ($status_filter === 'pending') $where_status = "AND m.taken = 0";

$med_stmt = $conn->prepare("
    SELECT
        m.medication_id,
        m.medication_name,
        m.dosage,
        m.frequency,
        m.medication_date,
        TIME_FORMAT(m.medication_time, '%h:%i %p') AS med_time_fmt,
        m.medication_time                           AS med_time_raw,
        m.taken,
        m.created_at
    FROM medications m
    WHERE m.resident_id = ?
      AND m.medication_date = ?
      $where_status
    ORDER BY m.medication_time ASC, m.medication_id ASC
");
$med_stmt->bind_param("is", $user_id, $selected_date);
$med_stmt->execute();
$medications = $med_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$med_stmt->close();

$cnt_stmt = $conn->prepare("
    SELECT COUNT(*) AS total, SUM(taken=1) AS taken_count, SUM(taken=0) AS pending_count
    FROM medications
    WHERE resident_id=? AND medication_date=?
");
$cnt_stmt->bind_param("is", $user_id, $selected_date);
$cnt_stmt->execute();
$day_counts  = $cnt_stmt->get_result()->fetch_assoc();
$cnt_stmt->close();

$total_day   = (int)($day_counts['total']         ?? 0);
$taken_day   = (int)($day_counts['taken_count']   ?? 0);
$pending_day = (int)($day_counts['pending_count'] ?? 0);
$progress    = $total_day > 0 ? round(100 * $taken_day / $total_day) : 0;

$adh_stmt = $conn->prepare("
    SELECT COUNT(*) AS total, SUM(taken=1) AS taken_count
    FROM medications
    WHERE resident_id=?
      AND medication_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
      AND medication_date <= CURDATE()
");
$adh_stmt->bind_param("i", $user_id);
$adh_stmt->execute();
$adh       = $adh_stmt->get_result()->fetch_assoc();
$adh_stmt->close();
$adh_total = (int)($adh['total']       ?? 0);
$adh_taken = (int)($adh['taken_count'] ?? 0);
$adh_pct   = $adh_total > 0 ? round(100 * $adh_taken / $adh_total) : 0;

$names_stmt = $conn->prepare("
    SELECT medication_name, dosage, frequency,
           MAX(medication_date) AS last_date,
           COUNT(*) AS total_doses, SUM(taken=1) AS taken_doses
    FROM medications
    WHERE resident_id=?
    GROUP BY medication_name, dosage, frequency
    ORDER BY medication_name ASC
");
$names_stmt->bind_param("i", $user_id);
$names_stmt->execute();
$all_meds = $names_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$names_stmt->close();

$sel_ts     = strtotime($selected_date);
$iso_dow    = (int)date('N', $sel_ts);
$week_start = date('Y-m-d', $sel_ts - ($iso_dow - 1) * 86400);
$week_days  = [];
for ($i = 0; $i < 7; $i++) {
    $d  = date('Y-m-d', strtotime($week_start . " +$i days"));
    $wc = $conn->prepare("SELECT COUNT(*) AS total, SUM(taken=1) AS done FROM medications WHERE resident_id=? AND medication_date=?");
    $wc->bind_param("is", $user_id, $d);
    $wc->execute();
    $wrow = $wc->get_result()->fetch_assoc();
    $wc->close();
    $week_days[] = [
        'date'        => $d,
        'label'       => date('D', strtotime($d)),
        'num'         => date('j', strtotime($d)),
        'total'       => (int)($wrow['total'] ?? 0),
        'done'        => (int)($wrow['done']  ?? 0),
        'is_today'    => $d === date('Y-m-d'),
        'is_selected' => $d === $selected_date,
    ];
}

$upcoming_stmt = $conn->prepare("
    SELECT medication_name, dosage, medication_date,
           TIME_FORMAT(medication_time,'%h:%i %p') AS med_time_fmt, taken
    FROM medications
    WHERE resident_id=?
      AND medication_date > CURDATE()
      AND medication_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY medication_date ASC, medication_time ASC
    LIMIT 10
");
$upcoming_stmt->bind_param("i", $user_id);
$upcoming_stmt->execute();
$upcoming_meds = $upcoming_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$upcoming_stmt->close();

$um = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id=? AND is_read=0");
$um->bind_param("i", $user_id); $um->execute();
$unread_messages = $um->get_result()->fetch_assoc()['cnt'] ?? 0;
$um->close();

$ua = $conn->prepare("SELECT COUNT(*) AS cnt FROM alerts WHERE resident_id=? AND resolved=0");
$ua->bind_param("i", $user_id); $ua->execute();
$unresolved_alerts = $ua->get_result()->fetch_assoc()['cnt'] ?? 0;
$ua->close();

function freq_label(?string $f): array {
    if (!$f) return ['', '#4A4540', '#F2F6EF'];
    $map = [
        'once_daily'   => ['Once Daily',   '#1A4870', '#DBEEFF'],
        'twice_daily'  => ['Twice Daily',  '#5A3A8A', '#EDE9FE'],
        'three_daily'  => ['3× Daily',     '#A06B2A', '#FEF0D8'],
        'four_daily'   => ['4× Daily',     '#6A2020', '#F5DADA'],
        'weekly'       => ['Weekly',       '#3A6830', '#DDEFD8'],
        'as_needed'    => ['As Needed',    '#4A4540', '#F2F6EF'],
        'with_food'    => ['With Food',    '#7A4010', '#FFEACC'],
        'before_sleep' => ['Before Sleep', '#1A3870', '#DAE8FF'],
    ];
    return $map[strtolower(trim($f))] ?? [ucwords(str_replace('_',' ',$f)), '#4A4540', '#F2F6EF'];
}

function time_of_day(string $t): string {
    $h = (int)explode(':', $t)[0];
    if ($h >= 5  && $h < 12) return 'Morning';
    if ($h >= 12 && $h < 17) return 'Afternoon';
    if ($h >= 17 && $h < 21) return 'Evening';
    return 'Night';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Medications – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════
           SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
           Medications · Very large fonts for elderly readability
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

            --st300: #B8B0A4;
            --st500: #7A7268;
            --st700: #4A4540;

            --radius-sm: 8px;
            --radius-md: 14px;
            --radius-lg: 22px;

            --shadow-soft: 0 2px 12px rgba(36,56,22,.07), 0 1px 3px rgba(36,56,22,.05);
            --shadow-card: 0 4px 24px rgba(36,56,22,.09), 0 1px 4px rgba(36,56,22,.06);
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--w50);
            background-image:
                radial-gradient(ellipse 70% 50% at 90% 0%, rgba(157,192,126,.08) 0%, transparent 55%),
                radial-gradient(ellipse 50% 40% at 0% 100%, rgba(122,166,88,.05) 0%, transparent 50%);
            color: var(--st700);
            font-size: 18px; /* Very large for elders */
            line-height: 1.75;
            min-height: 100vh;
            margin: 0; padding: 0;
        }

        h1, h2, h3, h4, h5 { font-family: 'Cormorant Garamond', serif; color: var(--s800); }

        /* ── Sidebar ─────────────────────────────────────────── */
        .sidebar {
            width: 240px; height: 100vh; position: fixed;
            background: var(--s800);
            display: flex; flex-direction: column;
            z-index: 1000; overflow: hidden;
        }

        .sidebar::before {
            content: ''; position: absolute; inset: 0;
            background-image:
                radial-gradient(ellipse 120% 60% at 50% -10%, rgba(157,192,126,.18) 0%, transparent 60%),
                radial-gradient(ellipse 80% 80% at 110% 110%, rgba(94,138,64,.15) 0%, transparent 55%);
            pointer-events: none;
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
            font-size: 17px; font-weight: 600; color: white;
            line-height: 1.1; margin: 0;
        }

        .sidebar-header small {
            font-size: 10px; color: rgba(255,255,255,.4);
            letter-spacing: .08em; text-transform: uppercase;
            font-weight: 500; display: block;
            margin-left: 44px; margin-top: 2px;
        }

        .sidebar-nav { flex: 1; overflow-y: auto; padding: 10px 0; position: relative; }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.15); border-radius: 3px; }

        .sidebar a {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 12px 11px 22px;
            color: rgba(255,255,255,.6); text-decoration: none;
            font-size: 15px; font-weight: 500; transition: all .2s;
            margin: 2px 10px; border-radius: var(--radius-sm);
            position: relative; min-height: 46px;
        }

        .sidebar a:hover { background: rgba(255,255,255,.1); color: white; }
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

        /* ── Layout ──────────────────────────────────────────── */
        .content { margin-left: 240px; padding: 28px; min-height: 100vh; }

        /* ── Topbar ──────────────────────────────────────────── */
        .topbar {
            background: white; border-radius: var(--radius-lg);
            padding: 20px 28px; box-shadow: var(--shadow-card);
            border: 1px solid rgba(196,217,180,.3);
            margin-bottom: 24px; display: flex;
            align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 12px;
        }

        .topbar h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 26px; font-weight: 500; color: var(--s800); margin: 0 0 3px 0;
        }

        .topbar p { font-size: 15px; color: var(--st300); margin: 0; }

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

        .btn-print:hover { opacity: .9; transform: translateY(-1px); }

        /* ── Panel ───────────────────────────────────────────── */
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

        /* ── Date navigator ──────────────────────────────────── */
        .date-nav-row {
            display: flex; align-items: center;
            justify-content: space-between; margin-bottom: 18px; gap: 8px;
        }

        .date-big {
            font-family: 'Cormorant Garamond', serif;
            font-size: 52px; font-weight: 600; color: var(--s800); line-height: 1;
        }

        .date-month-label { font-size: 15px; font-weight: 600; color: var(--s500); margin-top: 3px; }
        .date-dow-label   { font-size: 12px; font-weight: 600; color: var(--st300); text-transform: uppercase; letter-spacing: .07em; }

        .today-pill {
            display: inline-block; margin-top: 6px;
            background: rgba(122,166,88,.15); color: var(--s500);
            padding: 3px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;
        }

        .nav-arrow {
            width: 44px; height: 44px; border-radius: 50%;
            border: 2px solid var(--s200); background: white; color: var(--s500);
            display: flex; align-items: center; justify-content: center;
            text-decoration: none; transition: all .25s; font-size: 15px; flex-shrink: 0;
        }

        .nav-arrow:hover { background: var(--s400); color: white; border-color: var(--s400); transform: scale(1.08); }

        .btn-today {
            background: linear-gradient(135deg, var(--s300), var(--s500));
            color: white; border: none; border-radius: 20px;
            padding: 5px 14px; font-size: 13px; font-weight: 700;
            font-family: 'Outfit', sans-serif; cursor: pointer;
            text-decoration: none; transition: all .25s;
            display: inline-block; margin-top: 6px;
        }

        .btn-today:hover { opacity: .9; color: white; transform: translateY(-1px); }

        /* ── Week strip ──────────────────────────────────────── */
        .week-strip { display: grid; grid-template-columns: repeat(7,1fr); gap: 4px; }

        .week-day {
            text-align: center; padding: 8px 2px; border-radius: var(--radius-sm);
            text-decoration: none; transition: all .2s;
            border: 2px solid transparent; min-width: 0; overflow: hidden;
        }

        .week-day:hover { background: rgba(122,166,88,.1); border-color: var(--s200); }
        .week-day.is-today:not(.is-selected) { border-color: var(--s400); background: rgba(122,166,88,.06); }

        .week-day.is-selected {
            background: linear-gradient(135deg, var(--s400), var(--s600));
            box-shadow: 0 4px 12px rgba(94,138,64,.35);
        }

        .week-day.is-selected .wd-label,
        .week-day.is-selected .wd-num { color: white !important; }
        .week-day.is-selected .wd-month { color: rgba(255,255,255,.65) !important; }

        .wd-label { font-size: 9px; font-weight: 700; color: var(--st300); text-transform: uppercase; letter-spacing: 0; line-height: 1; margin-bottom: 3px; display: block; }
        .wd-num   { font-size: 15px; font-weight: 800; color: var(--s800); line-height: 1.2; display: block; }
        .wd-month { font-size: 8px; color: var(--st300); font-weight: 700; line-height: 1; display: block; }

        .wd-pip-row { display: flex; justify-content: center; gap: 3px; margin-top: 4px; min-height: 6px; }
        .wd-pip { width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
        .pip-done    { background: var(--s400); }
        .pip-pending { background: #D4A853; }
        .pip-empty   { background: var(--s100); }

        /* ── Progress ring ───────────────────────────────────── */
        .ring-wrap { position: relative; width: 90px; height: 90px; flex-shrink: 0; }
        .ring-wrap svg { transform: rotate(-90deg); }
        .ring-bg   { fill: none; stroke: var(--s100); stroke-width: 9; }
        .ring-fill { fill: none; stroke-width: 9; stroke-linecap: round; }
        .ring-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); text-align: center; }
        .ring-pct { font-size: 20px; font-weight: 800; color: var(--s800); line-height: 1; font-family: 'Outfit', sans-serif; }
        .ring-sub { font-size: 10px; color: var(--st300); font-weight: 600; }

        /* ── Stat chips ──────────────────────────────────────── */
        .stat-chips { display: flex; gap: 10px; flex-wrap: wrap; }

        .stat-chip {
            text-align: center; padding: 12px 14px;
            border-radius: var(--radius-md); flex: 1; min-width: 58px;
        }

        .chip-num { font-size: 26px; font-weight: 800; line-height: 1; font-family: 'Outfit', sans-serif; }
        .chip-lbl { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; opacity: .8; margin-top: 2px; }

        /* ── Adherence bar ───────────────────────────────────── */
        .adh-bar-wrap { background: var(--s100); border-radius: 20px; height: 10px; overflow: hidden; margin: 7px 0; }
        .adh-bar-fill { height: 100%; border-radius: 20px; transition: width .8s ease; }

        /* ── Filter tabs ─────────────────────────────────────── */
        .filter-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 22px; }

        .ftab {
            padding: 9px 20px; border-radius: 20px;
            font-size: 15px; font-weight: 700; cursor: pointer;
            border: 1.5px solid var(--s200); background: white;
            color: var(--s600); text-decoration: none; transition: all .2s;
        }

        .ftab:hover, .ftab.active {
            background: linear-gradient(135deg, var(--s300), var(--s500));
            color: white; border-color: transparent;
        }

        /* ── Medication cards ────────────────────────────────── */
        .med-card {
            background: var(--s50); border-radius: var(--radius-md);
            padding: 18px 20px; margin-bottom: 12px;
            border-left: 5px solid var(--s300);
            transition: all .25s; position: relative;
        }

        .med-card:hover { transform: translateX(5px); box-shadow: var(--shadow-soft); background: white; }
        .med-card.taken   { border-left-color: var(--s400); }
        .med-card.pending { border-left-color: #D4A853; }

        .med-card.taken .med-name {
            text-decoration: line-through;
            text-decoration-color: var(--st300);
            opacity: .65;
        }

        .med-name   { font-size: 19px; font-weight: 800; color: var(--s800); margin-bottom: 4px; }
        .med-dosage { font-size: 15px; color: var(--st500); margin-bottom: 10px; }
        .med-time   { font-size: 17px; font-weight: 700; color: var(--s500); }
        .med-tod    { font-size: 13px; color: var(--st300); margin-left: 6px; }

        .badge-taken {
            background: #DDEFD8; color: #3A6830; padding: 5px 14px;
            border-radius: 20px; font-size: 13px; font-weight: 700;
            display: inline-flex; align-items: center; gap: 5px;
        }

        .badge-pending {
            background: #FAECC8; color: #7A5010; padding: 5px 14px;
            border-radius: 20px; font-size: 13px; font-weight: 700;
            display: inline-flex; align-items: center; gap: 5px;
        }

        .freq-pill {
            display: inline-block; padding: 3px 11px;
            border-radius: 20px; font-size: 12px; font-weight: 700;
        }

        .status-icon {
            position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
            width: 38px; height: 38px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
        }

        /* ── Time-of-day header ──────────────────────────────── */
        .tod-header {
            display: flex; align-items: center; gap: 10px;
            margin: 18px 0 10px; padding-left: 2px;
        }

        .tod-header span {
            font-size: 13px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .06em; color: var(--st500);
        }

        .tod-line { flex: 1; height: 1px; background: var(--s100); }

        /* ── All medications summary ─────────────────────────── */
        .med-summary-row {
            display: flex; justify-content: space-between;
            align-items: center; padding: 14px 0;
            border-bottom: 1px solid var(--s50); flex-wrap: wrap; gap: 8px;
        }

        .med-summary-row:last-child { border-bottom: none; }

        .med-summary-name { font-size: 17px; font-weight: 700; color: var(--s800); }
        .med-summary-meta { font-size: 13px; color: var(--st300); margin-top: 3px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }

        .mini-bar { height: 6px; border-radius: 10px; background: var(--s100); width: 80px; overflow: hidden; }
        .mini-fill { height: 100%; border-radius: 10px; transition: width .8s ease; }

        /* ── Upcoming ────────────────────────────────────────── */
        .upcoming-row {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 0; border-bottom: 1px solid var(--s50);
        }

        .upcoming-row:last-child { border-bottom: none; }
        .upcoming-date { font-size: 13px; font-weight: 700; color: var(--s500); min-width: 80px; }
        .upcoming-name { font-size: 16px; font-weight: 700; color: var(--s800); }
        .upcoming-detail { font-size: 13px; color: var(--st300); }

        /* ── Section heading ─────────────────────────────────── */
        .section-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px; }

        .section-heading h4 { font-family: 'Cormorant Garamond', serif; font-size: 26px; font-weight: 500; color: var(--s800); margin: 0 0 3px 0; }
        .section-heading p  { font-size: 14px; color: var(--st300); margin: 0; }

        /* ── Badges ──────────────────────────────────────────── */
        .count-taken   { background: #DDEFD8; color: #3A6830; padding: 5px 14px; border-radius: 20px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }
        .count-pending { background: #FAECC8; color: #7A5010; padding: 5px 14px; border-radius: 20px; font-size: 14px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; }

        /* ── Empty state ─────────────────────────────────────── */
        .empty-state { text-align: center; padding: 50px 20px; }
        .empty-state i { font-size: 3.5rem; color: var(--s200); display: block; margin-bottom: 16px; animation: float 4s ease-in-out infinite; }
        .empty-state h5 { font-family: 'Outfit', sans-serif; font-size: 20px; font-weight: 700; color: var(--s700); margin-bottom: 8px; }
        .empty-state p  { font-size: 16px; color: var(--st300); }
        @keyframes float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-8px)} }

        .btn-show-all {
            background: linear-gradient(135deg, var(--s300), var(--s500));
            color: white; border: none; border-radius: 20px;
            padding: 9px 22px; font-size: 15px; font-weight: 700;
            font-family: 'Outfit', sans-serif; cursor: pointer;
            text-decoration: none; display: inline-block;
            transition: all .25s; margin-top: 12px;
        }

        .btn-show-all:hover { opacity: .9; color: white; }

        /* ── Print ───────────────────────────────────────────── */
        @media print {
            .sidebar, .no-print { display: none !important; }
            .content { margin-left: 0 !important; padding: 10px !important; }
            .med-card { break-inside: avoid; }
        }

        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .content { margin-left: 0; padding: 16px; }
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
        <a href="elder_health.php"><i class="fa-solid fa-heart-pulse"></i> My Health Data</a>
        <a href="elder_schedule.php"><i class="fa-solid fa-calendar-check"></i> Daily Schedule</a>
        <a href="elder_medications.php" class="active"><i class="fa-solid fa-pills"></i> My Medications</a>
        <a href="elder_appointments.php"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
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

<!-- ══ CONTENT ════════════════════════════════════════════════════════════ -->
<div class="content">

    <!-- Topbar -->
    <div class="topbar no-print">
        <div>
            <h4><i class="fas fa-pills me-2" style="font-size:22px;color:var(--s500);"></i>My Medications</h4>
            <p>
                <i class="fas fa-calendar me-1"></i>
                <?= $is_today ? 'Today — ' : '' ?><?= date('l, F j, Y', strtotime($selected_date)) ?>
            </p>
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

        <!-- ── Left column ─────────────────────────────────── -->
        <div class="col-lg-4">

            <!-- Date navigator + week strip -->
            <div class="panel">
                <div class="date-nav-row">
                    <a href="?date=<?= $prev_date ?>&status=<?= $status_filter ?>" class="nav-arrow">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <div class="text-center">
                        <div class="date-big"><?= date('d', strtotime($selected_date)) ?></div>
                        <div class="date-month-label"><?= date('F Y', strtotime($selected_date)) ?></div>
                        <div class="date-dow-label"><?= date('l', strtotime($selected_date)) ?></div>
                        <?php if ($is_today): ?>
                            <span class="today-pill">Today</span>
                        <?php else: ?>
                            <a href="elder_medications.php" class="btn-today">↩ Today</a>
                        <?php endif; ?>
                    </div>
                    <a href="?date=<?= $next_date ?>&status=<?= $status_filter ?>" class="nav-arrow">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                <div class="week-strip">
                    <?php foreach ($week_days as $wd): ?>
                    <a href="?date=<?= $wd['date'] ?>&status=<?= $status_filter ?>"
                       class="week-day <?= $wd['is_selected'] ? 'is-selected' : ($wd['is_today'] ? 'is-today' : '') ?>">
                        <span class="wd-label"><?= $wd['label'] ?></span>
                        <span class="wd-num"><?= $wd['num'] ?></span>
                        <?php if ((int)$wd['num'] === 1): ?>
                            <span class="wd-month"><?= date('M', strtotime($wd['date'])) ?></span>
                        <?php endif; ?>
                        <div class="wd-pip-row">
                            <?php if ($wd['total'] === 0): ?>
                                <div class="wd-pip pip-empty"></div>
                            <?php else: ?>
                                <?php if ($wd['done'] > 0): ?><div class="wd-pip pip-done"></div><?php endif; ?>
                                <?php if ($wd['done'] < $wd['total']): ?><div class="wd-pip pip-pending"></div><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Day progress ring -->
            <?php if ($total_day > 0):
                $r = 36; $circ = 2 * M_PI * $r;
                $offset = $circ * (1 - $progress / 100);
                $rc = $progress === 100 ? '#4A7C59' : ($progress >= 50 ? '#D4A853' : '#C87A7A');
            ?>
            <div class="panel">
                <div class="panel-title"><?= $is_today ? "Today's Progress" : date('M j', strtotime($selected_date)) . " Progress" ?></div>
                <div class="d-flex align-items-center gap-3">
                    <div class="ring-wrap">
                        <svg width="90" height="90" viewBox="0 0 90 90">
                            <circle class="ring-bg"   cx="45" cy="45" r="<?= $r ?>"/>
                            <circle class="ring-fill" cx="45" cy="45" r="<?= $r ?>"
                                stroke="<?= $rc ?>"
                                stroke-dasharray="<?= round($circ,2) ?>"
                                stroke-dashoffset="<?= round($offset,2) ?>"
                                id="progressRing"/>
                        </svg>
                        <div class="ring-center">
                            <div class="ring-pct"><?= $progress ?>%</div>
                            <div class="ring-sub">taken</div>
                        </div>
                    </div>
                    <div class="stat-chips flex-grow-1">
                        <div class="stat-chip" style="background:#DDEFD8;">
                            <div class="chip-num" style="color:#3A6830;"><?= $taken_day ?></div>
                            <div class="chip-lbl" style="color:#3A6830;">Taken</div>
                        </div>
                        <div class="stat-chip" style="background:#FAECC8;">
                            <div class="chip-num" style="color:#7A5010;"><?= $pending_day ?></div>
                            <div class="chip-lbl" style="color:#7A5010;">Pending</div>
                        </div>
                        <div class="stat-chip" style="background:var(--s50);">
                            <div class="chip-num" style="color:var(--s800);"><?= $total_day ?></div>
                            <div class="chip-lbl" style="color:var(--st500);">Total</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 30-day adherence -->
            <?php if ($adh_total > 0): ?>
            <div class="panel">
                <div class="panel-title">30-Day Adherence</div>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="font-size:32px;font-weight:800;color:<?= $adh_pct>=80?'#3A6830':($adh_pct>=50?'#7A5010':'#6A2020') ?>;font-family:'Outfit',sans-serif;line-height:1;"><?= $adh_pct ?>%</div>
                    <div>
                        <div style="font-size:16px;font-weight:700;color:var(--s800);"><?= $adh_taken ?> of <?= $adh_total ?> doses</div>
                        <div style="font-size:13px;color:var(--st300);">Last 30 days</div>
                    </div>
                </div>
                <div class="adh-bar-wrap">
                    <div class="adh-bar-fill" id="adhBar"
                         style="width:<?= $adh_pct ?>%;background:<?= $adh_pct>=80?'#4A7C59':($adh_pct>=50?'#D4A853':'#C87A7A') ?>;"></div>
                </div>
                <div style="font-size:13px;color:var(--st300);margin-top:8px;">
                    <?php if ($adh_pct >= 80): ?>
                        <i class="fas fa-star me-1" style="color:#D4A853;"></i>Excellent adherence — keep it up!
                    <?php elseif ($adh_pct >= 50): ?>
                        <i class="fas fa-exclamation-circle me-1" style="color:#D4A853;"></i>Room for improvement
                    <?php else: ?>
                        <i class="fas fa-triangle-exclamation me-1" style="color:#C87A7A;"></i>Please take medications as scheduled
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Upcoming next 7 days -->
            <?php if (!empty($upcoming_meds)): ?>
            <div class="panel no-print">
                <div class="panel-title"><i class="fas fa-calendar-days me-1"></i>Upcoming — Next 7 Days</div>
                <?php foreach ($upcoming_meds as $up): ?>
                <div class="upcoming-row">
                    <div class="upcoming-date"><?= date('D M j', strtotime($up['medication_date'])) ?></div>
                    <div class="flex-grow-1">
                        <div class="upcoming-name"><?= htmlspecialchars($up['medication_name']) ?></div>
                        <div class="upcoming-detail">
                            <?= $up['med_time_fmt'] ?><?= $up['dosage'] ? ' · ' . htmlspecialchars($up['dosage']) : '' ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div><!-- /col-4 -->

        <!-- ── Right column ──────────────────────────────────── -->
        <div class="col-lg-8">

            <!-- Day medication list -->
            <div class="panel">
                <div class="section-heading">
                    <div>
                        <h4><?= $is_today ? "Today's Medications" : date('l', strtotime($selected_date)) . "'s Medications" ?></h4>
                        <p>
                            <?= date('F j, Y', strtotime($selected_date)) ?>
                            <?php if ($total_day > 0): ?> · <?= $total_day ?> scheduled<?php endif; ?>
                        </p>
                    </div>
                    <?php if ($total_day > 0): ?>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if ($taken_day > 0): ?>
                            <span class="count-taken"><i class="fas fa-check"></i><?= $taken_day ?> taken</span>
                        <?php endif; ?>
                        <?php if ($pending_day > 0): ?>
                            <span class="count-pending"><i class="fas fa-clock"></i><?= $pending_day ?> pending</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Filter tabs -->
                <?php if ($total_day > 0): ?>
                <div class="filter-tabs no-print">
                    <a href="?date=<?= $selected_date ?>&status=all"     class="ftab <?= $status_filter==='all'     ? 'active' : '' ?>">All (<?= $total_day ?>)</a>
                    <a href="?date=<?= $selected_date ?>&status=taken"   class="ftab <?= $status_filter==='taken'   ? 'active' : '' ?>">Taken (<?= $taken_day ?>)</a>
                    <a href="?date=<?= $selected_date ?>&status=pending" class="ftab <?= $status_filter==='pending' ? 'active' : '' ?>">Pending (<?= $pending_day ?>)</a>
                </div>
                <?php endif; ?>

                <?php if (empty($medications)): ?>
                <div class="empty-state">
                    <i class="fas fa-pills"></i>
                    <h5>
                        <?php
                        if ($status_filter !== 'all') echo 'No ' . ucfirst($status_filter) . ' Medications';
                        elseif ($is_today)            echo 'No Medications Today';
                        else                          echo 'No Medications on ' . date('M j', strtotime($selected_date));
                        ?>
                    </h5>
                    <p>
                        <?= $status_filter !== 'all' ? 'Try "All" to see everything scheduled for this day.' : 'No medications are recorded for this date.' ?>
                    </p>
                    <?php if ($status_filter !== 'all'): ?>
                        <a href="?date=<?= $selected_date ?>&status=all" class="btn-show-all">Show All</a>
                    <?php endif; ?>
                </div>

                <?php else:
                    $grouped    = [];
                    foreach ($medications as $med) {
                        $grouped[time_of_day($med['med_time_raw'])][] = $med;
                    }
                    $tod_order  = ['Morning','Afternoon','Evening','Night'];
                    $tod_icons  = ['Morning'=>'fa-sun','Afternoon'=>'fa-cloud-sun','Evening'=>'fa-moon','Night'=>'fa-star-and-crescent'];
                    $tod_colors = ['Morning'=>'#A06B2A','Afternoon'=>'#1A4870','Evening'=>'#5A3A8A','Night'=>'#2A3A5A'];

                    foreach ($tod_order as $tod):
                        if (empty($grouped[$tod])) continue;
                ?>
                    <div class="tod-header">
                        <i class="fas <?= $tod_icons[$tod] ?>" style="color:<?= $tod_colors[$tod] ?>;font-size:15px;"></i>
                        <span><?= $tod ?></span>
                        <div class="tod-line"></div>
                    </div>

                    <?php foreach ($grouped[$tod] as $med):
                        $is_taken = (bool)$med['taken'];
                        [$fl, $fc, $fb] = freq_label($med['frequency']);
                    ?>
                    <div class="med-card <?= $is_taken ? 'taken' : 'pending' ?>">
                        <div class="status-icon" style="background:<?= $is_taken ? '#DDEFD8' : '#FAECC8' ?>;">
                            <i class="fas <?= $is_taken ? 'fa-check' : 'fa-clock' ?>"
                               style="color:<?= $is_taken ? '#3A6830' : '#7A5010' ?>;font-size:16px;"></i>
                        </div>
                        <div style="padding-right: 56px;">
                            <div class="med-name"><?= htmlspecialchars($med['medication_name']) ?></div>
                            <?php if ($med['dosage']): ?>
                                <div class="med-dosage"><?= htmlspecialchars($med['dosage']) ?></div>
                            <?php endif; ?>
                            <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
                                <span class="med-time"><i class="far fa-clock me-1"></i><?= $med['med_time_fmt'] ?></span>
                                <span class="med-tod"><?= $tod ?></span>
                                <?php if ($fl): ?>
                                    <span class="freq-pill" style="background:<?= $fb ?>;color:<?= $fc ?>;"><?= $fl ?></span>
                                <?php endif; ?>
                                <?php if ($is_taken): ?>
                                    <span class="badge-taken"><i class="fas fa-check"></i>Taken</span>
                                <?php else: ?>
                                    <span class="badge-pending"><i class="fas fa-clock"></i>Pending</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- All medications on record -->
            <?php if (!empty($all_meds)): ?>
            <div class="panel">
                <div class="section-heading">
                    <h4>All My Medications</h4>
                    <span style="font-size:14px;color:var(--st300);font-weight:600;"><?= count($all_meds) ?> on record</span>
                </div>
                <?php foreach ($all_meds as $am):
                    $am_pct   = $am['total_doses'] > 0 ? round(100 * $am['taken_doses'] / $am['total_doses']) : 0;
                    [$fl, $fc, $fb] = freq_label($am['frequency']);
                    $am_color = $am_pct >= 80 ? '#3A6830' : ($am_pct >= 50 ? '#7A5010' : '#6A2020');
                    $am_bar   = $am_pct >= 80 ? '#4A7C59' : ($am_pct >= 50 ? '#D4A853' : '#C87A7A');
                ?>
                <div class="med-summary-row">
                    <div style="flex:1;min-width:0;padding-right:14px;">
                        <div class="med-summary-name"><?= htmlspecialchars($am['medication_name']) ?></div>
                        <div class="med-summary-meta">
                            <?php if ($am['dosage']): ?><span><?= htmlspecialchars($am['dosage']) ?></span><?php endif; ?>
                            <?php if ($fl): ?><span class="freq-pill" style="background:<?= $fb ?>;color:<?= $fc ?>;"><?= $fl ?></span><?php endif; ?>
                            <span>Last: <?= date('M j, Y', strtotime($am['last_date'])) ?></span>
                        </div>
                    </div>
                    <div class="text-end" style="min-width:90px;">
                        <div style="font-size:15px;font-weight:800;color:<?= $am_color ?>;"><?= $am_pct ?>%</div>
                        <div class="mini-bar mt-1"><div class="mini-fill" style="width:<?= $am_pct ?>%;background:<?= $am_bar ?>;"></div></div>
                        <div style="font-size:12px;color:var(--st300);margin-top:3px;"><?= $am['taken_doses'] ?>/<?= $am['total_doses'] ?> doses</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Print footer -->
            <div class="d-none d-print-block mt-4 pt-3 border-top" style="font-size:13px;color:var(--st300);">
                <p>SmartCare Guardian — Medications for <?= htmlspecialchars($user_name) ?> — <?= date('F j, Y', strtotime($selected_date)) ?></p>
                <p>Printed on <?= date('F j, Y \a\t H:i') ?></p>
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
        const ring = document.getElementById('progressRing');
        if (ring) {
            const final = parseFloat(ring.getAttribute('stroke-dashoffset'));
            const total = parseFloat(ring.getAttribute('stroke-dasharray'));
            ring.style.strokeDashoffset = total;
            requestAnimationFrame(() => {
                ring.style.transition = 'stroke-dashoffset .9s ease';
                ring.style.strokeDashoffset = final;
            });
        }
        document.querySelectorAll('.adh-bar-fill, .mini-fill').forEach(bar => {
            const w = bar.style.width;
            bar.style.width = '0';
            requestAnimationFrame(() => {
                bar.style.transition = 'width .8s ease';
                bar.style.width = w;
            });
        });
    });
    <?php if ($is_today): ?>
    setTimeout(() => location.reload(), 300000);
    <?php endif; ?>
    document.addEventListener('DOMContentLoaded', function() {
        document.documentElement.style.fontSize = sz + 'px';
        if (localStorage.getItem('elderHighContrast') === 'true')
            document.body.classList.add('high-contrast');
    });
})();
</script>
</body>
</html>