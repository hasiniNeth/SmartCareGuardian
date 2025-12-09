<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php");
    exit();
}
include '../db_connection.php';
$caregiver_id = (int)$_SESSION['user_id'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header("Location: manage_routines.php?msg=Invalid id"); exit; }

// fetch routine
$stmt = $conn->prepare("SELECT * FROM routines WHERE id=? AND caregiver_id=?");
$stmt->bind_param("ii", $id, $caregiver_id);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$r) { header("Location: manage_routines.php?msg=Not found"); exit; }

// mark completed and set last_completed_date
$today = date('Y-m-d');
$u = $conn->prepare("UPDATE routines SET status='completed', last_completed_date=?, updated_at=NOW() WHERE id=? AND caregiver_id=?");
$u->bind_param("sii", $today, $id, $caregiver_id);
$u->execute();
$u->close();

// compute and set next_reminder if needed
if ((int)$r['repeat_hours'] > 0) {
    $hours = (int)$r['repeat_hours'];
    // find next reminder based on now + repeat_hours - 10 minutes
    $next = (new DateTime())->modify("+{$hours} hours")->modify('-10 minutes')->format('Y-m-d H:i:s');
    $s = $conn->prepare("UPDATE routines SET next_reminder=? WHERE id=? AND caregiver_id=?");
    $s->bind_param("sii", $next, $id, $caregiver_id);
    $s->execute();
    $s->close();
} elseif ($r['days_of_week']) {
    // compute next matching weekday at schedule_time then minus 10 minutes
    $hourMin = explode(':', $r['schedule_time']);
    $hour = intval($hourMin[0]);
    $min = intval($hourMin[1] ?? 0);
    $days = array_map('trim', explode(',', $r['days_of_week']));
    $candidate = null;
    for ($i = 1; $i <= 14; $i++) {
        $c = (new DateTime())->modify("+{$i} day");
        if (in_array($c->format('l'), $days)) {
            $c->setTime($hour, $min, 0);
            $candidate = $c;
            break;
        }
    }
    if ($candidate) {
        $nr = $candidate->modify('-10 minutes')->format('Y-m-d H:i:s');
        $s = $conn->prepare("UPDATE routines SET next_reminder=? WHERE id=? AND caregiver_id=?");
        $s->bind_param("sii", $nr, $id, $caregiver_id);
        $s->execute();
        $s->close();
    } else {
        // no next found - set NULL
        $s = $conn->prepare("UPDATE routines SET next_reminder=NULL WHERE id=? AND caregiver_id=?");
        $s->bind_param("ii", $id, $caregiver_id);
        $s->execute();
        $s->close();
    }
} else {
    // one-time routine -> no next_reminder
    $s = $conn->prepare("UPDATE routines SET next_reminder=NULL WHERE id=? AND caregiver_id=?");
    $s->bind_param("ii", $id, $caregiver_id);
    $s->execute();
    $s->close();
}

header("Location: manage_routines.php?msg=" . urlencode("Marked completed"));
exit;
