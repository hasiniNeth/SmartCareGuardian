<?php
$ch = curl_init("http://192.168.100.186:5001/health");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$error    = curl_error($ch);
$code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Code: $code<br>Error: $error<br>Response: $response";
?>