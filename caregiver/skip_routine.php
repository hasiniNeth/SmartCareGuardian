<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: ../login.php"); exit();
}
include '../db_connection.php';
$caregiver_id = (int)$_SESSION['user_id'];

$id   = isset($_GET['id'])   ? (int)$_GET['id']   : 0;
$date = isset($_GET['date']) ? $_GET['date']       : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
if ($id <= 0) { header("Location: manage_routines.php?msg=Invalid+id"); exit; }

// Confirm ownership
$chk = $conn->prepare("SELECT id FROM routines WHERE id=? AND caregiver_id=?");
$chk->bind_param("ii", $id, $caregiver_id);
$chk->execute();
if (!$chk->get_result()->fetch_assoc()) { header("Location: manage_routines.php?msg=Not+found"); exit; }
$chk->close();

// Upsert skipped log
$ups = $conn->prepare("
    INSERT INTO routine_logs (routine_id, log_date, status, created_at)
    VALUES (?, ?, 'skipped', NOW())
    ON DUPLICATE KEY UPDATE status='skipped'
");
$ups->bind_param("is", $id, $date);
$ups->execute(); $ups->close();

header("Location: manage_routines.php?msg=" . urlencode("Routine skipped for today"));
exit;