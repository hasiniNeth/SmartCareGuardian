<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: ../login.php"); exit();
}
include '../db_connection.php';
$caregiver_id = (int)$_SESSION['user_id'];

function normalize_days(array $arr): ?string {
    $valid = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $clean = array_filter($arr, fn($d) => in_array(trim($d), $valid));
    $clean = array_map('trim', $clean);
    return empty($clean) ? null : implode(',', $clean);
}

function redirect(string $msg): void {
    header("Location: manage_routines.php?msg=" . urlencode($msg)); exit;
}

$action = trim($_POST['action'] ?? '');
if (!$action) redirect('No action specified');

// ── DELETE ────────────────────────────────────────────────────────
if ($action === 'delete') {
    $rid = (int)($_POST['routine_id'] ?? 0);
    if ($rid <= 0) redirect('Invalid routine id');

    // Delete logs first (FK constraint safe)
    $dl = $conn->prepare("DELETE FROM routine_logs WHERE routine_id=?");
    $dl->bind_param("i", $rid); $dl->execute(); $dl->close();

    $d = $conn->prepare("DELETE FROM routines WHERE id=? AND caregiver_id=?");
    $d->bind_param("ii", $rid, $caregiver_id);
    $d->execute() ? redirect('Routine deleted') : redirect('DB error: '.$d->error);
    $d->close(); exit;
}

// ── ADD / EDIT ────────────────────────────────────────────────────
if (!in_array($action, ['add','edit'])) redirect('Unknown action');

$resident_id   = (int)($_POST['resident_id'] ?? 0);
$routine_type  = trim($_POST['routine_type'] ?? '');
$description   = trim($_POST['description'] ?? '');
$schedule_time = trim($_POST['schedule_time'] ?? '');
$days_of_week  = normalize_days((array)($_POST['days_of_week'] ?? []));
$repeat_hours  = max(0, (int)($_POST['repeat_hours'] ?? 0));
$send_reminder = isset($_POST['send_reminder']) ? 1 : 0;

// Allowed types — medication is NOT allowed here
$allowed_types = ['meal','exercise','personal_care','other'];

$errors = [];
if ($resident_id <= 0)          $errors[] = 'Select a resident.';
if (!in_array($routine_type, $allowed_types)) $errors[] = 'Invalid routine type. Use the Medications page for medication scheduling.';
if ($description === '')        $errors[] = 'Description is required.';
if ($schedule_time === '')      $errors[] = 'Schedule time is required.';
if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $schedule_time)) $errors[] = 'Schedule time format invalid.';

if ($errors) redirect(implode(' ', $errors));

// ── Compute next_reminder ─────────────────────────────────────────
$next_reminder = null;
if ($send_reminder) {
    [$h, $m] = explode(':', $schedule_time);
    $h = (int)$h; $m = (int)$m;
    $now = new DateTime();

    if ($days_of_week) {
        $days    = array_map('trim', explode(',', $days_of_week));
        $candidate = null;
        for ($i = 0; $i < 14; $i++) {
            $check = (new DateTime())->modify("+$i day");
            if (in_array($check->format('l'), $days)) {
                $check->setTime($h, $m, 0);
                if ($check > $now) { $candidate = $check; break; }
            }
        }
        if (!$candidate) {
            $candidate = (new DateTime())->modify('+1 day')->setTime($h, $m, 0);
        }
        $candidate->modify('-10 minutes');
        $next_reminder = $candidate->format('Y-m-d H:i:s');
    } else {
        $candidate = (new DateTime())->setTime($h, $m, 0);
        if ($candidate <= $now) $candidate->modify('+1 day');
        $candidate->modify('-10 minutes');
        $next_reminder = $candidate->format('Y-m-d H:i:s');
    }
}

// ── ADD ───────────────────────────────────────────────────────────
if ($action === 'add') {
    $stmt = $conn->prepare("
        INSERT INTO routines
            (resident_id, caregiver_id, routine_type, description, schedule_time,
             days_of_week, repeat_hours, send_reminder, next_reminder, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    if (!$stmt) redirect('DB prepare error: '.$conn->error);
    $stmt->bind_param("iissssiis",
        $resident_id, $caregiver_id, $routine_type, $description, $schedule_time,
        $days_of_week, $repeat_hours, $send_reminder, $next_reminder
    );
    $stmt->execute() ? redirect('Routine added successfully ✓') : redirect('DB error: '.$stmt->error);
    $stmt->close(); exit;
}

// ── EDIT ──────────────────────────────────────────────────────────
$routine_id = (int)($_POST['routine_id'] ?? 0);
if ($routine_id <= 0) redirect('Invalid routine id');

if ($next_reminder === null) {
    $stmt = $conn->prepare("
        UPDATE routines
        SET resident_id=?, routine_type=?, description=?, schedule_time=?,
            days_of_week=?, repeat_hours=?, send_reminder=?, next_reminder=NULL, updated_at=NOW()
        WHERE id=? AND caregiver_id=?
    ");
    if (!$stmt) redirect('DB prepare error: '.$conn->error);
    $stmt->bind_param("issssiiii",
        $resident_id, $routine_type, $description, $schedule_time,
        $days_of_week, $repeat_hours, $send_reminder, $routine_id, $caregiver_id
    );
} else {
    $stmt = $conn->prepare("
        UPDATE routines
        SET resident_id=?, routine_type=?, description=?, schedule_time=?,
            days_of_week=?, repeat_hours=?, send_reminder=?, next_reminder=?, updated_at=NOW()
        WHERE id=? AND caregiver_id=?
    ");
    if (!$stmt) redirect('DB prepare error: '.$conn->error);
    $stmt->bind_param("issssiiisi",
        $resident_id, $routine_type, $description, $schedule_time,
        $days_of_week, $repeat_hours, $send_reminder, $next_reminder, $routine_id, $caregiver_id
    );
}

$stmt->execute() ? redirect('Routine updated successfully ✓') : redirect('DB error: '.$stmt->error);
$stmt->close(); exit;