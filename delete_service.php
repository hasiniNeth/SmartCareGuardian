<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$id = intval($_GET['id']);
$conn->query("DELETE FROM services WHERE service_id=$id");

header("Location: manage_services.php");
exit();
?>
