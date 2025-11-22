<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: assign_caregiver.php");
    exit();
}

$id = intval($_GET['id']);

$stmt = $conn->prepare("DELETE FROM caregiver_assignments WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();

header("Location: assign_caregiver.php?msg=removed");
exit();
?>
