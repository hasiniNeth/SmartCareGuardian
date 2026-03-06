<?php
/**
 * suggestion_action.php
 * =====================
 * AJAX endpoint — Accept / Dismiss / History for AI routine suggestions.
 * POST JSON: { action: 'accept'|'dismiss'|'history', suggestion_id?, resident_id? }
 */
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
header('Content-Type: application/json');
include '../db_connection.php';
require_once '../ai-service/rourine_suggestions/RoutineSuggestionAI.php';

$caregiver_id = (int)$_SESSION['user_id'];
$input        = json_decode(file_get_contents('php://input'), true) ?? [];
$action       = trim($input['action'] ?? '');
$sid          = (int)($input['suggestion_id'] ?? 0);
$rid          = (int)($input['resident_id']   ?? 0);
$ai           = new RoutineSuggestionAI($conn);

// ── Accept ────────────────────────────────────────────────────────────────────
if ($action === 'accept' && $sid) {
    echo json_encode(['success' => $ai->acceptSuggestion($sid, $caregiver_id)]);
    exit();
}

// ── Dismiss ───────────────────────────────────────────────────────────────────
if ($action === 'dismiss' && $sid) {
    echo json_encode(['success' => $ai->dismissSuggestion($sid, $caregiver_id)]);
    exit();
}

// ── History ───────────────────────────────────────────────────────────────────
if ($action === 'history' && $rid) {
    $stmt = $conn->prepare("
        SELECT s.id,
               s.routine_type,
               s.action         AS suggestion_action,
               s.title,
               s.description,
               s.confidence,
               s.suggested_time,
               s.time_conflict,
               s.status,
               s.generated_at,
               s.acted_at,
               u.full_name      AS resident_name
        FROM   ai_routine_suggestions s
        JOIN   residents r ON r.resident_id = s.resident_id
        JOIN   users u     ON u.user_id     = r.user_id
        WHERE  s.resident_id  = ?
          AND  s.caregiver_id = ?
        ORDER  BY s.generated_at DESC
        LIMIT  100
    ");
    $stmt->bind_param("ii", $rid, $caregiver_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $row['generated_at_fmt'] = $row['generated_at']
            ? date('d M Y, g:i A', strtotime($row['generated_at'])) : '—';
        $row['acted_at_fmt'] = $row['acted_at']
            ? date('d M Y, g:i A', strtotime($row['acted_at'])) : null;
        $row['confidence_pct'] = round((float)$row['confidence'] * 100);
    }
    unset($row);

    echo json_encode(['success' => true, 'history' => $rows]);
    exit();
}

echo json_encode(['error' => 'Invalid action or missing parameters']);
?>