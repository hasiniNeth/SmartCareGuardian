<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: ../login.php"); exit();
}
include '../db_connection.php';
$caregiver_id = (int)$_SESSION['user_id'];

$id   = isset($_GET['id'])   ? (int)$_GET['id']            : 0;
$date = isset($_GET['date']) ? $_GET['date']                : date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
if ($id <= 0) { header("Location: manage_routines.php?msg=Invalid+id"); exit; }

// Confirm routine belongs to caregiver
$chk = $conn->prepare("SELECT id, repeat_hours, days_of_week, schedule_time, send_reminder FROM routines WHERE id=? AND caregiver_id=?");
$chk->bind_param("ii", $id, $caregiver_id);
$chk->execute();
$r = $chk->get_result()->fetch_assoc(); $chk->close();
if (!$r) { header("Location: manage_routines.php?msg=Not+found"); exit; }

$now = date('Y-m-d H:i:s');

// Upsert into routine_logs for this date
$ups = $conn->prepare("
    INSERT INTO routine_logs (routine_id, log_date, status, completed_at, completed_by, created_at)
    VALUES (?, ?, 'completed', ?, ?, NOW())
    ON DUPLICATE KEY UPDATE status='completed', completed_at=?, completed_by=?
");
$ups->bind_param("issisi", $id, $date, $now, $caregiver_id, $now, $caregiver_id);
$ups->execute(); $ups->close();

// Update last_completed_date on the routine row itself
$upd = $conn->prepare("UPDATE routines SET last_completed_date=?, updated_at=NOW() WHERE id=? AND caregiver_id=?");
$upd->bind_param("sii", $date, $id, $caregiver_id);
$upd->execute(); $upd->close();

// Compute next_reminder if needed
if ($r['send_reminder']) {
    $next = null;
    if ((int)$r['repeat_hours'] > 0) {
        $next = (new DateTime())->modify('+'.(int)$r['repeat_hours'].' hours')->modify('-10 minutes')->format('Y-m-d H:i:s');
    } elseif ($r['days_of_week']) {
        [$h, $m] = explode(':', $r['schedule_time']);
        $days = array_map('trim', explode(',', $r['days_of_week']));
        $candidate = null;
        for ($i = 1; $i <= 14; $i++) {
            $c = (new DateTime())->modify("+$i day");
            if (in_array($c->format('l'), $days)) {
                $c->setTime((int)$h, (int)$m, 0);
                $candidate = $c;
                break;
            }
        }
        if ($candidate) $next = $candidate->modify('-10 minutes')->format('Y-m-d H:i:s');
    }
    $nr = $conn->prepare("UPDATE routines SET next_reminder=? WHERE id=? AND caregiver_id=?");
    $nr->bind_param("sii", $next, $id, $caregiver_id);
    $nr->execute(); $nr->close();
}

header("Location: manage_routines.php?msg=" . urlencode("Routine marked as completed ✓"));
exit;