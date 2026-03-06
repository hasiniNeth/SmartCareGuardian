<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['caregiver','admin'])) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit();
}
header('Content-Type: application/json');
include '../db_connection.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/SmartCareGuardian/includes/ai_service.php';
$resident_id = (int)($_GET['resident_id'] ?? 0);
$limit = min((int)($_GET['limit'] ?? 30), 100);
if (!$resident_id) { echo json_encode(['success'=>false,'error'=>'Missing resident_id']); exit(); }
if ($_SESSION['role'] === 'caregiver') {
    $chk = $conn->prepare("SELECT resident_id FROM caregiver_assignments WHERE caregiver_id=? AND resident_id=? LIMIT 1");
    $chk->bind_param("ii",$_SESSION['user_id'],$resident_id); $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) { echo json_encode(['success'=>false,'error'=>'Access denied']); exit(); }
}
$ai = new AIService($conn);
echo json_encode(['success'=>true,'history'=>$ai->getHistory($resident_id,$limit)]);