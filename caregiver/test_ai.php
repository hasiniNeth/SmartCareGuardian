<?php
session_start();
include '../db_connection.php';
require_once '../ai-service/rourine_suggestions/RoutineSuggestionAI.php';

echo "<h3>Step 1 — Testing API URL directly</h3>";
$ch = curl_init("http://192.168.100.186:5001/suggest");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(["test" => true]),
    CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    CURLOPT_TIMEOUT        => 10,
]);
$response = curl_exec($ch);
$code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);
echo "Code: $code | Error: $err<br>";
echo "Response: <pre>$response</pre>";

echo "<h3>Step 2 — getSuggestionsForResident</h3>";
$ai = new RoutineSuggestionAI($conn);
$result = $ai->getSuggestionsForResident(1, 5);
echo "<pre>"; print_r($result); echo "</pre>";

echo "<h3>Step 3 — Check DB for cached suggestions</h3>";
$rows = $conn->query("SELECT id, routine_type, status, generated_at FROM ai_routine_suggestions WHERE resident_id = 1")->fetch_all(MYSQLI_ASSOC);
echo "<pre>"; print_r($rows); echo "</pre>";
?>