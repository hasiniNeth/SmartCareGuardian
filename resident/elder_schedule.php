<?php
session_start();
include '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];

$selected_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
    ? $_GET['date']
    : date('Y-m-d');

$selected_dow  = date('l', strtotime($selected_date));
$is_today      = $selected_date === date('Y-m-d');
$is_past       = $selected_date < date('Y-m-d');

$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

$rt_stmt = $conn->prepare("
    SELECT
        r.id             AS routine_id,
        r.routine_type,
        r.description,
        r.schedule_time,
        r.days_of_week,
        r.repeat_hours,
        r.send_reminder,
        r.last_completed_date,
        u.full_name      AS caregiver_name,
        u.user_id        AS caregiver_id,
        COALESCE(rl.status, 'pending')   AS log_status,
        rl.log_id,
        rl.completed_at,
        rl.notes         AS log_notes
    FROM routines r
    JOIN  users u  ON u.user_id  = r.caregiver_id
    LEFT JOIN routine_logs rl
           ON rl.routine_id = r.id
          AND rl.log_date   = ?
    WHERE r.resident_id = ?
      AND r.status = 'pending'
      AND (
            r.days_of_week IS NULL
            OR FIND_IN_SET(?, r.days_of_week)
          )
    ORDER BY r.schedule_time ASC, r.id ASC
");
$rt_stmt->bind_param("sis", $selected_date, $user_id, $selected_dow);
$rt_stmt->execute();
$routines = $rt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rt_stmt->close();

$total     = count($routines);
$completed = count(array_filter($routines, fn($r) => $r['log_status'] === 'completed'));
$skipped   = count(array_filter($routines, fn($r) => $r['log_status'] === 'skipped'));
$pending   = count(array_filter($routines, fn($r) => $r['log_status'] === 'pending'));
$progress  = $total > 0 ? round(100 * $completed / $total) : 0;

$sel_ts     = strtotime($selected_date);
$iso_dow    = (int)date('N', $sel_ts);
$week_start = date('Y-m-d', $sel_ts - ($iso_dow - 1) * 86400);
$week_days  = [];
for ($i = 0; $i < 7; $i++) {
    $d   = date('Y-m-d', strtotime($week_start . " +$i days"));
    $dow = date('l', strtotime($d));
    $wc  = $conn->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN rl.status='completed' THEN 1 ELSE 0 END) AS done
        FROM routines r
        LEFT JOIN routine_logs rl ON rl.routine_id=r.id AND rl.log_date=?
        WHERE r.resident_id=?
          AND r.status='pending'
          AND (r.days_of_week IS NULL OR FIND_IN_SET(?,r.days_of_week))
    ");
    $wc->bind_param("sis", $d, $user_id, $dow);
    $wc->execute();
    $wrow = $wc->get_result()->fetch_assoc();
    $wc->close();
    $week_days[] = [
        'date'        => $d,
        'label'       => date('D', strtotime($d)),
        'num'         => date('j', strtotime($d)),
        'total'       => (int)$wrow['total'],
        'done'        => (int)$wrow['done'],
        'is_today'    => $d === date('Y-m-d'),
        'is_selected' => $d === $selected_date,
        'is_past'     => $d < date('Y-m-d'),
    ];
}

$um = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id=? AND is_read=0");
$um->bind_param("i", $user_id); $um->execute();
$unread_messages = $um->get_result()->fetch_assoc()['cnt'] ?? 0;
$um->close();

$ua = $conn->prepare("SELECT COUNT(*) AS cnt FROM alerts WHERE resident_id=? AND resolved=0");
$ua->bind_param("i", $user_id); $ua->execute();
$unresolved_alerts = $ua->get_result()->fetch_assoc()['cnt'] ?? 0;
$ua->close();

$med_stmt = $conn->prepare("
    SELECT medication_name, dosage, TIME_FORMAT(medication_time,'%h:%i %p') AS med_time, taken
    FROM medications
    WHERE resident_id=? AND medication_date=?
    ORDER BY medication_time
");
$med_stmt->bind_param("is", $user_id, $selected_date);
$med_stmt->execute();
$day_meds = $med_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$med_stmt->close();

$type_cfg = [
    'meal'          => ['icon'=>'fa-utensils',       'color'=>'#A06B2A', 'bg'=>'#FEF0D8', 'label'=>'Meal'],
    'exercise'      => ['icon'=>'fa-person-walking', 'color'=>'#3A6830', 'bg'=>'#DDEFD8', 'label'=>'Exercise'],
    'personal_care' => ['icon'=>'fa-soap',           'color'=>'#1A4870', 'bg'=>'#DBEEFF', 'label'=>'Personal Care'],
    'other'         => ['icon'=>'fa-circle-dot',     'color'=>'#5A3A8A', 'bg'=>'#EDE9FE', 'label'=>'Other'],
];
function type_cfg_get(array $cfg, string $type): array {
    return $cfg[$type] ?? $cfg['other'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Schedule – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════
           SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
           Daily Schedule · Very large fonts for elderly readability
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

        h1, h2, h3, h4, h5 {
            font-family: 'Cormorant Garamond', serif;
            color: var(--s800);
        }

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
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; color: white;
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
            color: rgba(255,255,255,.6);
            text-decoration: none; font-size: 15px; font-weight: 500;
            transition: all .2s; margin: 2px 10px;
            border-radius: var(--radius-sm); position: relative; min-height: 46px;
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

        .btn-print {
            background: linear-gradient(135deg, var(--s400), var(--s600));
            border: none; border-radius: var(--radius-sm);
            color: white; padding: 11px 22px;
            font-size: 15px; font-weight: 700;
            font-family: 'Outfit', sans-serif; cursor: pointer;
            transition: all .25s; display: inline-flex;
            align-items: center; gap: 7px;
        }

        .btn-print:hover { opacity: .9; transform: translateY(-1px); box-shadow: var(--shadow-card); }

        /* ── Section card ────────────────────────────────────── */
        .section-card {
            background: white; border-radius: var(--radius-lg);
            padding: 22px; box-shadow: var(--shadow-card);
            border: 1px solid rgba(196,217,180,.3);
            margin-bottom: 20px;
        }

        /* ── Date navigator ──────────────────────────────────── */
        .date-nav-controls {
            display: flex; align-items: center;
            justify-content: space-between; margin-bottom: 20px; gap: 8px;
        }

        .date-big {
            font-family: 'Cormorant Garamond', serif;
            font-size: 52px; font-weight: 600; color: var(--s800);
            line-height: 1;
        }

        .date-month-label {
            font-family: 'Outfit', sans-serif;
            font-size: 15px; font-weight: 600; color: var(--s500); margin-top: 3px;
        }

        .date-dow-label {
            font-size: 12px; font-weight: 600;
            color: var(--st300); text-transform: uppercase; letter-spacing: .07em;
        }

        .today-pill {
            display: inline-block; margin-top: 6px;
            background: rgba(122,166,88,.15); color: var(--s500);
            padding: 3px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 700;
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
            padding: 6px 16px; font-size: 13px; font-weight: 700;
            font-family: 'Outfit', sans-serif; cursor: pointer;
            text-decoration: none; transition: all .25s;
            display: inline-block; margin-top: 7px;
        }

        .btn-today:hover { opacity: .9; color: white; transform: translateY(-1px); }

        /* ── Week strip ──────────────────────────────────────── */
        .week-strip { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }

        .week-day {
            text-align: center; padding: 8px 2px;
            border-radius: var(--radius-sm); text-decoration: none;
            transition: all .2s; border: 2px solid transparent;
            min-width: 0; overflow: hidden; position: relative;
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

        .wd-label {
            font-size: 9px; font-weight: 700; color: var(--st300);
            text-transform: uppercase; letter-spacing: 0;
            line-height: 1; margin-bottom: 3px; display: block;
        }

        .wd-num {
            font-size: 15px; font-weight: 800; color: var(--s800);
            line-height: 1.2; display: block;
        }

        .wd-month { font-size: 8px; color: var(--st300); font-weight: 700; line-height: 1; display: block; }

        .wd-pip-row { display: flex; justify-content: center; gap: 3px; margin-top: 4px; min-height: 6px; }
        .wd-pip { width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
        .pip-done    { background: var(--s400); }
        .pip-pending { background: #D4A853; }
        .pip-empty   { background: var(--s100); }

        /* ── Progress ring card ──────────────────────────────── */
        .progress-card {
            display: flex; align-items: center;
            gap: 24px; flex-wrap: wrap;
        }

        .progress-ring-wrap { position: relative; width: 96px; height: 96px; flex-shrink: 0; }
        .progress-ring-wrap svg { transform: rotate(-90deg); }
        .ring-bg   { fill: none; stroke: var(--s100); stroke-width: 9; }
        .ring-fill { fill: none; stroke-width: 9; stroke-linecap: round; transition: stroke-dashoffset .8s ease; }
        .ring-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); text-align: center; }
        .ring-pct  { font-size: 20px; font-weight: 800; color: var(--s800); line-height: 1; font-family: 'Outfit', sans-serif; }
        .ring-sub  { font-size: 10px; color: var(--st300); font-weight: 600; }

        .progress-stats { display: flex; gap: 18px; flex-wrap: wrap; }

        .ps-item { text-align: center; }
        .ps-num  { font-size: 28px; font-weight: 800; color: var(--s800); line-height: 1; font-family: 'Outfit', sans-serif; }
        .ps-label { font-size: 12px; color: var(--st300); font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin-top: 2px; }

        /* ── Timeline ────────────────────────────────────────── */
        .timeline { position: relative; }

        .timeline::before {
            content: ''; position: absolute;
            left: 24px; top: 14px; bottom: 14px;
            width: 2px; background: linear-gradient(180deg, var(--s200), transparent);
        }

        .routine-card {
            background: white; border-radius: var(--radius-lg);
            padding: 20px 22px 20px 68px;
            margin-bottom: 14px; box-shadow: var(--shadow-soft);
            border: 1px solid var(--s100);
            transition: all .25s; position: relative;
        }

        .routine-card:hover { transform: translateX(5px); box-shadow: var(--shadow-card); }

        .routine-card.completed { border-left: 5px solid var(--s400); }
        .routine-card.skipped   { border-left: 5px solid #D4A853; }
        .routine-card.cancelled { border-left: 5px solid #C87A7A; }
        .routine-card.pending   { border-left: 5px solid var(--s200); }

        .tl-dot {
            position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
            width: 24px; height: 24px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 10px; color: white; font-weight: 800;
            box-shadow: 0 2px 8px rgba(0,0,0,.18); z-index: 1;
        }

        /* Type pill */
        .type-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 13px; border-radius: 20px;
            font-size: 13px; font-weight: 700; margin-bottom: 6px;
        }

        /* Repeat badge */
        .repeat-badge {
            font-size: 12px; color: #5A3A8A; background: #EDE9FE;
            padding: 3px 10px; border-radius: 20px; font-weight: 700;
        }

        /* Status badge */
        .status-badge {
            display: inline-block; padding: 5px 14px;
            border-radius: 20px; font-size: 13px; font-weight: 700;
        }

        .sb-completed { background: #DDEFD8; color: #3A6830; }
        .sb-skipped   { background: #FAECC8; color: #7A5010; }
        .sb-cancelled { background: #F5DADA; color: #6A2020; }
        .sb-pending   { background: var(--s50); color: var(--st500); }

        .routine-time  { font-size: 15px; color: var(--s500); font-weight: 700; }
        .routine-title { font-size: 18px; font-weight: 700; color: var(--s800); margin: 3px 0 2px; }
        .routine-desc  { font-size: 16px; color: var(--st500); margin-bottom: 8px; line-height: 1.5; }
        .caregiver-tag { font-size: 14px; color: var(--st300); }
        .days-tag      { font-size: 13px; color: var(--st300); margin-top: 3px; }
        .completed-at  { font-size: 13px; color: var(--s400); font-weight: 700; margin-top: 5px; }

        .log-notes-box {
            background: var(--s50); border-left: 3px solid var(--s300);
            border-radius: var(--radius-sm); padding: 10px 14px;
            margin-top: 10px; font-size: 15px; color: var(--st500); line-height: 1.6;
        }

        /* ── Medications glance ───────────────────────────────── */
        .card-title {
            font-family: 'Outfit', sans-serif; font-size: 15px; font-weight: 700;
            color: var(--s800); margin: 0 0 16px 0;
            display: flex; align-items: center; gap: 8px;
        }

        .card-title i { color: var(--s400); font-size: 15px; }

        .med-row {
            display: flex; justify-content: space-between;
            align-items: center; padding: 12px 0;
            border-bottom: 1px solid var(--s50);
        }

        .med-row:last-of-type { border-bottom: none; }

        .med-name   { font-size: 16px; font-weight: 700; color: var(--s800); }
        .med-dosage { font-size: 13px; color: var(--st300); margin-top: 2px; }
        .med-time   { font-size: 15px; font-weight: 800; color: var(--s500); margin-bottom: 4px; text-align: right; }

        .badge-taken {
            background: #DDEFD8; color: #3A6830;
            padding: 4px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 700;
            display: inline-flex; align-items: center; gap: 4px;
        }

        .badge-pending {
            background: #FAECC8; color: #7A5010;
            padding: 4px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 700;
            display: inline-flex; align-items: center; gap: 4px;
        }

        .btn-manage-meds {
            display: block; width: 100%; margin-top: 14px;
            background: linear-gradient(135deg, var(--s400), var(--s600));
            color: white; text-align: center; text-decoration: none;
            padding: 12px; border-radius: var(--radius-sm);
            font-size: 15px; font-weight: 700;
            transition: all .25s;
        }

        .btn-manage-meds:hover { opacity: .9; color: white; transform: translateY(-1px); }

        /* ── Legend card ─────────────────────────────────────── */
        .legend-icon {
            width: 32px; height: 32px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        /* ── Empty state ─────────────────────────────────────── */
        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state i {
            font-size: 4rem; color: var(--s200);
            display: block; margin-bottom: 18px;
            animation: float 4s ease-in-out infinite;
        }

        .empty-state h5 {
            font-family: 'Outfit', sans-serif;
            font-size: 20px; font-weight: 700; color: var(--s700); margin-bottom: 8px;
        }

        .empty-state p { font-size: 16px; color: var(--st300); }

        @keyframes float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-10px)} }

        .btn-back {
            background: linear-gradient(135deg, var(--s300), var(--s500));
            color: white; border: none; border-radius: 20px;
            padding: 10px 24px; font-size: 15px; font-weight: 700;
            font-family: 'Outfit', sans-serif; cursor: pointer;
            text-decoration: none; transition: all .25s;
            display: inline-flex; align-items: center; gap: 7px;
        }

        .btn-back:hover { opacity: .9; color: white; }

        /* Live / Past / Upcoming badges */
        .day-status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 16px; border-radius: 20px;
            font-size: 14px; font-weight: 700;
        }

        /* ── Animations ──────────────────────────────────────── */
        .section-card { animation: fadeUp .4s ease both; }
        @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }

        /* ── Print ───────────────────────────────────────────── */
        @media print {
            .sidebar, .no-print { display: none !important; }
            .content { margin-left: 0 !important; padding: 10px !important; }
        }

        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .content { margin-left: 0; padding: 16px; }
            .timeline::before { left: 14px; }
            .routine-card { padding-left: 50px; }
            .tl-dot { left: 4px; }
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
        <a href="elder_schedule.php" class="active"><i class="fa-solid fa-calendar-check"></i> Daily Schedule</a>
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
            <h4><i class="fas fa-calendar-check me-2" style="font-size:22px;color:var(--s500);"></i>Daily Schedule</h4>
            <p>
                <i class="fas fa-calendar me-1"></i>
                <?= $is_today ? 'Today — ' : '' ?><?= date('l, F j, Y', strtotime($selected_date)) ?>
            </p>
        </div>
        <button class="btn-print" onclick="window.print()">
            <i class="fas fa-print"></i>Print
        </button>
    </div>

    <div class="row g-4">

        <!-- ── Left column ─────────────────────────────────── -->
        <div class="col-lg-4">

            <!-- Date Navigator -->
            <div class="section-card">
                <div class="date-nav-controls">
                    <a href="?date=<?= $prev_date ?>" class="nav-arrow" title="Previous day">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <div class="text-center">
                        <div class="date-big"><?= date('d', strtotime($selected_date)) ?></div>
                        <div class="date-month-label"><?= date('F Y', strtotime($selected_date)) ?></div>
                        <div class="date-dow-label"><?= date('l', strtotime($selected_date)) ?></div>
                        <?php if ($is_today): ?>
                            <span class="today-pill">Today</span>
                        <?php else: ?>
                            <a href="elder_schedule.php" class="btn-today">↩ Today</a>
                        <?php endif; ?>
                    </div>
                    <a href="?date=<?= $next_date ?>" class="nav-arrow" title="Next day">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>

                <!-- Week strip -->
                <div class="week-strip">
                    <?php foreach ($week_days as $wd): ?>
                    <a href="?date=<?= $wd['date'] ?>"
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

            <!-- Progress card -->
            <?php if ($total > 0):
                $r = 38; $circ = 2 * M_PI * $r;
                $offset = $circ * (1 - $progress / 100);
                $ring_color = $progress >= 80 ? '#4A7C59' : ($progress >= 40 ? '#D4A853' : '#C87A7A');
            ?>
            <div class="section-card">
                <div class="progress-card">
                    <div class="progress-ring-wrap">
                        <svg width="96" height="96" viewBox="0 0 96 96">
                            <circle class="ring-bg"   cx="48" cy="48" r="<?= $r ?>"/>
                            <circle class="ring-fill" cx="48" cy="48" r="<?= $r ?>"
                                stroke="<?= $ring_color ?>"
                                stroke-dasharray="<?= round($circ, 2) ?>"
                                stroke-dashoffset="<?= round($offset, 2) ?>"/>
                        </svg>
                        <div class="ring-center">
                            <div class="ring-pct"><?= $progress ?>%</div>
                            <div class="ring-sub">done</div>
                        </div>
                    </div>
                    <div class="progress-stats">
                        <div class="ps-item">
                            <div class="ps-num" style="color:#4A7C59;"><?= $completed ?></div>
                            <div class="ps-label">Done</div>
                        </div>
                        <div class="ps-item">
                            <div class="ps-num" style="color:#D4A853;"><?= $pending ?></div>
                            <div class="ps-label">Pending</div>
                        </div>
                        <div class="ps-item">
                            <div class="ps-num" style="color:var(--st300);"><?= $skipped ?></div>
                            <div class="ps-label">Skipped</div>
                        </div>
                        <div class="ps-item">
                            <div class="ps-num"><?= $total ?></div>
                            <div class="ps-label">Total</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Medications glance -->
            <?php if (!empty($day_meds)): ?>
            <div class="section-card">
                <div class="card-title">
                    <i class="fas fa-pills"></i>
                    Medications <?= $is_today ? 'Today' : date('M j', strtotime($selected_date)) ?>
                </div>
                <?php foreach ($day_meds as $med): ?>
                <div class="med-row">
                    <div>
                        <div class="med-name"><?= htmlspecialchars($med['medication_name']) ?></div>
                        <div class="med-dosage"><?= htmlspecialchars($med['dosage']) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="med-time"><?= $med['med_time'] ?></div>
                        <?php if ($med['taken']): ?>
                            <span class="badge-taken"><i class="fas fa-check"></i>Taken</span>
                        <?php else: ?>
                            <span class="badge-pending"><i class="fas fa-clock"></i>Pending</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <a href="elder_medications.php" class="btn-manage-meds">
                    <i class="fas fa-pills me-2"></i>Manage Medications
                </a>
            </div>
            <?php endif; ?>

            <!-- Legend -->
            <div class="section-card">
                <div class="card-title"><i class="fas fa-circle-info"></i>Routine Types</div>
                <?php foreach ($type_cfg as $type => $cfg): ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="legend-icon" style="background:<?= $cfg['bg'] ?>;">
                        <i class="fas <?= $cfg['icon'] ?>" style="color:<?= $cfg['color'] ?>;font-size:14px;"></i>
                    </div>
                    <span style="font-size:16px;font-weight:600;color:var(--s800);"><?= $cfg['label'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>

        </div><!-- /col-4 -->

        <!-- ── Right column: timeline ────────────────────────── -->
        <div class="col-lg-8">

            <!-- Heading -->
            <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
                <h4 style="font-size:26px;font-weight:500;margin:0;">
                    <?= $is_today ? "Today's Routines" : date('l', strtotime($selected_date)) . "'s Routines" ?>
                </h4>
                <?php if ($is_today): ?>
                    <span class="day-status-badge" style="background:#DDEFD8;color:#3A6830;">
                        <i class="fas fa-circle" style="font-size:8px;"></i>Live
                    </span>
                <?php elseif ($is_past): ?>
                    <span class="day-status-badge" style="background:var(--s100);color:var(--st500);">Past Day</span>
                <?php else: ?>
                    <span class="day-status-badge" style="background:#EDE9FE;color:#5A3A8A;">Upcoming</span>
                <?php endif; ?>
            </div>

            <?php if (empty($routines)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-day"></i>
                <h5>No Routines Scheduled</h5>
                <p>
                    <?php if ($is_today): ?>
                        You have no routines scheduled for today. Enjoy your free day!
                    <?php else: ?>
                        No routines are scheduled for <?= date('l, F j', strtotime($selected_date)) ?>.
                    <?php endif; ?>
                </p>
                <a href="resident_dashboard.php" class="btn-back mt-2">
                    <i class="fas fa-arrow-left"></i>Back to Dashboard
                </a>
            </div>

            <?php else: ?>
            <div class="timeline">
                <?php foreach ($routines as $rt):
                    $cfg   = type_cfg_get($type_cfg, $rt['routine_type']);
                    $lstat = $rt['log_status'];
                    $dot_colors = [
                        'completed' => '#4A7C59',
                        'skipped'   => '#D4A853',
                        'cancelled' => '#C87A7A',
                        'pending'   => '#C4D9B4',
                    ];
                    $dot_color_map = [
                        'completed' => '#4A7C59',
                        'skipped'   => '#D4A853',
                        'cancelled' => '#C87A7A',
                        'pending'   => '#C4D9B4',
                    ];
                    $dot_color = $dot_color_map[$lstat] ?? '#C4D9B4';
                    $time_fmt  = date('h:i A', strtotime($rt['schedule_time']));
                ?>
                <div class="routine-card <?= $lstat ?>">
                    <div class="tl-dot" style="background:<?= $dot_color ?>;">
                        <?php if ($lstat === 'completed'): ?>
                            <i class="fas fa-check" style="font-size:10px;"></i>
                        <?php elseif ($lstat === 'skipped'): ?>
                            <i class="fas fa-forward" style="font-size:9px;"></i>
                        <?php elseif ($lstat === 'cancelled'): ?>
                            <i class="fas fa-times" style="font-size:10px;"></i>
                        <?php else: ?>
                            <i class="fas fa-circle" style="font-size:7px;"></i>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <span class="type-pill" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;">
                                <i class="fas <?= $cfg['icon'] ?>"></i><?= $cfg['label'] ?>
                            </span>
                            <?php if ($rt['repeat_hours']): ?>
                                <span class="repeat-badge ms-1">
                                    <i class="fas fa-repeat me-1"></i>Every <?= $rt['repeat_hours'] ?>h
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="routine-time"><i class="far fa-clock me-1"></i><?= $time_fmt ?></span>
                            <span class="status-badge sb-<?= $lstat ?>"><?= ucfirst($lstat) ?></span>
                        </div>
                    </div>

                    <div class="routine-title"><?= ucfirst(str_replace('_', ' ', $rt['routine_type'])) ?></div>
                    <?php if ($rt['description']): ?>
                        <div class="routine-desc"><?= htmlspecialchars($rt['description']) ?></div>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap gap-3 mt-1">
                        <span class="caregiver-tag"><i class="fas fa-user-nurse me-1"></i><?= htmlspecialchars($rt['caregiver_name']) ?></span>
                        <?php if ($rt['days_of_week']): ?>
                            <span class="days-tag"><i class="fas fa-calendar-week me-1"></i><?= htmlspecialchars($rt['days_of_week']) ?></span>
                        <?php endif; ?>
                        <?php if ($rt['send_reminder']): ?>
                            <span class="days-tag" style="color:#5A3A8A;"><i class="fas fa-bell me-1"></i>Reminders on</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($lstat === 'completed' && $rt['completed_at']): ?>
                    <div class="completed-at">
                        <i class="fas fa-check-circle me-1"></i>
                        Completed at <?= date('h:i A', strtotime($rt['completed_at'])) ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($rt['log_notes']): ?>
                    <div class="log-notes-box">
                        <strong><i class="fas fa-sticky-note me-1"></i>Note:</strong>
                        <?= nl2br(htmlspecialchars($rt['log_notes'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Print footer -->
            <div class="d-none d-print-block mt-4 pt-4 border-top" style="font-size:13px;color:var(--st300);">
                <p>SmartCare Guardian — Daily Schedule for <?= htmlspecialchars($user_name) ?> — <?= date('l, F j, Y', strtotime($selected_date)) ?></p>
                <p>Printed on <?= date('F j, Y \a\t H:i') ?></p>
            </div>

        </div><!-- /col-8 -->
    </div><!-- /row -->
</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const fill = document.querySelector('.ring-fill');
    if (fill) {
        const final = fill.getAttribute('stroke-dashoffset');
        fill.style.strokeDashoffset = fill.getAttribute('stroke-dasharray');
        requestAnimationFrame(() => {
            fill.style.transition = 'stroke-dashoffset 1s ease';
            fill.style.strokeDashoffset = final;
        });
    }
});
<?php if ($is_today): ?>
setTimeout(() => location.reload(), 300000);
<?php endif; ?>
</script>
</body>
</html>