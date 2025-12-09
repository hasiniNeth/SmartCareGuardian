<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php");
    exit();
}
include '../db_connection.php';
$caregiver_id = (int)$_SESSION['user_id'];

function normalize_days($arr){
    if (!$arr || !is_array($arr)) return null;
    $clean = array_filter(array_map('trim', $arr));
    return empty($clean) ? null : implode(',', $clean);
}

$action = $_POST['action'] ?? '';
if (!$action) {
    header("Location: manage_routines.php?msg=" . urlencode("No action specified"));
    exit;
}

$resident_id = (int)($_POST['resident_id'] ?? 0);
$routine_type = trim($_POST['routine_type'] ?? '');
$description = trim($_POST['description'] ?? '');
$schedule_time = $_POST['schedule_time'] ?? '';
$days_of_week = normalize_days($_POST['days_of_week'] ?? []);
$repeat_hours = max(0, (int)($_POST['repeat_hours'] ?? 0));
$send_reminder = isset($_POST['send_reminder']) ? 1 : 0;

// Basic validation (for add/edit)
if (in_array($action, ['add', 'edit'])) {
    $errors = [];
    if ($resident_id <= 0) $errors[] = 'Select a resident.';
    if ($routine_type === '') $errors[] = 'Select routine type.';
    if ($description === '') $errors[] = 'Description required.';
    if ($schedule_time === '') $errors[] = 'Schedule time required.';
    if (!preg_match('/^\d{2}:\d{2}$/', $schedule_time)) $errors[] = 'Schedule time invalid.';

    if ($repeat_hours < 0) $errors[] = 'Repeat hours cannot be negative.';
    if ($send_reminder && $resident_id <= 0) $errors[] = 'Resident is required for sending reminders.';

    if ($errors) {
        $m = urlencode(implode(' ', $errors));
        header("Location: manage_routines.php?msg={$m}");
        exit;
    }

    // compute next_reminder if send_reminder
    $next_reminder = null;
    if ($send_reminder) {
        // next_reminder is 10 minutes before the next occurrence
        $now = new DateTime();
        [$hour, $min] = explode(':', $schedule_time);
        $hour = (int)$hour; $min = (int)$min;

        if ($days_of_week) {
            // find next weekday occurrence within next 14 days
            $days = array_map('trim', explode(',', $days_of_week));
            $candidate = null;
            for ($i = 0; $i < 14; $i++) {
                $check = (new DateTime())->modify("+$i day");
                if (in_array($check->format('l'), $days)) {
                    $check->setTime($hour, $min, 0);
                    if ($check > $now) { $candidate = $check; break; }
                }
            }
            if (!$candidate) { // fallback: tomorrow at time
                $candidate = (new DateTime())->modify('+1 day')->setTime($hour, $min, 0);
            }
            // subtract 10 minutes
            $candidate->modify('-10 minutes');
            $next_reminder = $candidate->format('Y-m-d H:i:s');
        } else {
            // no days_of_week: treat as next occurrence today/time or tomorrow
            $candidate = (new DateTime())->setTime($hour, $min, 0);
            if ($candidate <= new DateTime()) $candidate->modify('+1 day');
            $candidate->modify('-10 minutes');
            $next_reminder = $candidate->format('Y-m-d H:i:s');
        }
    }

    if ($action === 'add') {
        $sql = "INSERT INTO routines (resident_id, caregiver_id, routine_type, description, schedule_time, days_of_week, repeat_hours, send_reminder, next_reminder, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
        $stmt = $conn->prepare($sql);
        // types: resident_id(i), caregiver_id(i), routine_type(s), description(s), schedule_time(s), days_of_week(s), repeat_hours(i), send_reminder(i), next_reminder(s)
        $types = "iissssiis";
        // ensure next_reminder variable is string or null
        $nr = $next_reminder;
        if (!$stmt) {
            header("Location: manage_routines.php?msg=" . urlencode("DB prepare error: ".$conn->error));
            exit;
        }
        $stmt->bind_param($types, $resident_id, $caregiver_id, $routine_type, $description, $schedule_time, $days_of_week, $repeat_hours, $send_reminder, $nr);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: manage_routines.php?msg=" . urlencode("Routine added successfully"));
            exit;
        } else {
            $err = $stmt->error;
            $stmt->close();
            header("Location: manage_routines.php?msg=" . urlencode("DB error: ".$err));
            exit;
        }
    } else { // edit
        $routine_id = (int)($_POST['routine_id'] ?? 0);
        if ($routine_id <= 0) {
            header("Location: manage_routines.php?msg=" . urlencode("Invalid routine id"));
            exit;
        }

        // If next_reminder is null -> set next_reminder=NULL in DB; else set to value.
        if ($next_reminder === null) {
            $sql = "UPDATE routines SET resident_id=?, routine_type=?, description=?, schedule_time=?, days_of_week=?, repeat_hours=?, send_reminder=?, next_reminder=NULL, updated_at=NOW()
                    WHERE id=? AND caregiver_id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) { header("Location: manage_routines.php?msg=" . urlencode("DB prepare error: ".$conn->error)); exit; }
            $stmt->bind_param("issssiiii", $resident_id, $routine_type, $description, $schedule_time, $days_of_week, $repeat_hours, $send_reminder, $routine_id, $caregiver_id);
        } else {
            $sql = "UPDATE routines SET resident_id=?, routine_type=?, description=?, schedule_time=?, days_of_week=?, repeat_hours=?, send_reminder=?, next_reminder=?, updated_at=NOW()
                    WHERE id=? AND caregiver_id=?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) { header("Location: manage_routines.php?msg=" . urlencode("DB prepare error: ".$conn->error)); exit; }
            $stmt->bind_param("issssiissi", $resident_id, $routine_type, $description, $schedule_time, $days_of_week, $repeat_hours, $send_reminder, $next_reminder, $routine_id, $caregiver_id);
        }

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: manage_routines.php?msg=" . urlencode("Routine updated successfully"));
            exit;
        } else {
            $err = $stmt->error;
            $stmt->close();
            header("Location: manage_routines.php?msg=" . urlencode("DB error: ".$err));
            exit;
        }
    }
}

// Delete action
if ($action === 'delete') {
    $rid = (int)($_POST['routine_id'] ?? 0);
    if ($rid <= 0) {
        header("Location: manage_routines.php?msg=" . urlencode("Invalid routine id"));
        exit;
    }
    $d = $conn->prepare("DELETE FROM routines WHERE id = ? AND caregiver_id = ?");
    $d->bind_param("ii", $rid, $caregiver_id);
    if ($d->execute()) {
        $d->close();
        header("Location: manage_routines.php?msg=" . urlencode("Routine deleted"));
        exit;
    } else {
        $err = $d->error;
        $d->close();
        header("Location: manage_routines.php?msg=" . urlencode("DB error: ".$err));
        exit;
    }
}

// fallback
header("Location: manage_routines.php");
exit;
