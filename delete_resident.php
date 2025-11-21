<?php
session_start();
include 'db_connection.php';

// Only admin can delete
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_residents.php?msg=invalid");
    exit();
}

$resident_id = intval($_GET['id']);

// Delete resident
$stmt = $conn->prepare("DELETE FROM users WHERE user_id=? AND role='resident'");
$stmt->bind_param("i", $resident_id);

if ($stmt->execute()) {
    header("Location: manage_residents.php?msg=deleted");
} else {
    header("Location: manage_residents.php?msg=error");
}
exit();
?>
