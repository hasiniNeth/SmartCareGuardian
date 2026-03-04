<?php
echo "curl enabled: " . (function_exists('curl_init') ? 'YES' : 'NO') . "<br>";

$ch = curl_init("http://localhost:5001/health");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$error    = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode <br>";
echo "Error: $error <br>";
echo "Response: $response <br>";
?>