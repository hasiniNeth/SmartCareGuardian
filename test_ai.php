<?php
/**
 * SmartCare Guardian — AI Connection Diagnostic
 * 
 * Place this file in: C:\wamp64\www\SmartCareGuardian\test_ai.php
 * Then visit:         http://localhost/SmartCareGuardian/test_ai.php
 * 
 * This will tell you EXACTLY why PHP can't reach Flask.
 */
?>
<!DOCTYPE html>
<html>
<head>
    <title>AI Connection Diagnostic</title>
    <style>
        body { font-family: monospace; background: #0f172a; color: #e2e8f0; padding: 30px; }
        h2   { color: #60a5fa; }
        .pass { color: #4ade80; }
        .fail { color: #f87171; }
        .warn { color: #fbbf24; }
        .info { color: #94a3b8; }
        .box  { background: #1e293b; border-radius: 8px; padding: 20px; margin: 15px 0; border-left: 4px solid #3b82f6; }
        .box.fail-box { border-left-color: #ef4444; }
        .box.pass-box { border-left-color: #22c55e; }
        pre   { background: #0f172a; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: .85rem; }
    </style>
</head>
<body>
<h2>🔍 SmartCare Guardian — AI API Diagnostic</h2>
<p class="info">Running checks to find why PHP can't connect to Flask...</p>

<?php

$api_url = 'http://127.0.0.1:5000';
$all_passed = true;

// ── TEST 1: cURL extension loaded ─────────────────────────────────────────
echo '<div class="box">';
echo '<strong>TEST 1: cURL Extension</strong><br>';
if (function_exists('curl_init')) {
    echo '<span class="pass">✓ cURL is enabled in PHP</span>';
} else {
    $all_passed = false;
    echo '<span class="fail">✗ cURL is NOT enabled</span><br>';
    echo '<span class="warn">FIX: Open php.ini, find ;extension=curl and remove the semicolon. Restart WAMP.</span>';
}
echo '</div>';

// ── TEST 2: Basic TCP connection to port 5000 ─────────────────────────────
echo '<div class="box">';
echo '<strong>TEST 2: TCP Connection to 127.0.0.1:5000</strong><br>';
$sock = @fsockopen('127.0.0.1', 5000, $errno, $errstr, 3);
if ($sock) {
    fclose($sock);
    echo '<span class="pass">✓ Port 5000 is open and reachable</span>';
} else {
    $all_passed = false;
    echo '<span class="fail">✗ Cannot reach port 5000 — Error: ' . $errstr . ' (' . $errno . ')</span><br>';
    echo '<span class="warn">FIX: Make sure Flask is running: python app.py</span><br>';
    echo '<span class="info">Also check Windows Firewall is not blocking port 5000.</span>';
}
echo '</div>';

// ── TEST 3: cURL GET to Flask root ────────────────────────────────────────
echo '<div class="box">';
echo '<strong>TEST 3: cURL GET → ' . $api_url . '/</strong><br>';
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $api_url . '/',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
]);
$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    $all_passed = false;
    echo '<span class="fail">✗ cURL error: ' . htmlspecialchars($curl_err) . '</span><br>';
    echo '<span class="warn">This usually means Flask is not running or firewall is blocking it.</span>';
} elseif ($http_code === 200) {
    echo '<span class="pass">✓ HTTP 200 OK — Flask responded!</span><br>';
    $data = json_decode($response, true);
    echo '<span class="info">Response: </span>';
    echo '<pre>' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . '</pre>';
} else {
    $all_passed = false;
    echo '<span class="fail">✗ HTTP ' . $http_code . '</span><br>';
    echo '<pre>' . htmlspecialchars($response) . '</pre>';
}
echo '</div>';

// ── TEST 4: POST /predict with sample data ────────────────────────────────
echo '<div class="box">';
echo '<strong>TEST 4: POST → ' . $api_url . '/predict (sample vitals)</strong><br>';

$sample = [
    'resident_id'              => 1,
    'blood_pressure_systolic'  => 145,
    'blood_pressure_diastolic' => 92,
    'blood_sugar'              => 160,
    'pulse'                    => 88,
    'weight'                   => 72.5,
    'temperature'              => 37.2,
    'oxygen_saturation'        => 96,
    'logged_at'                => date('Y-m-d H:i:s'),
];

$json = json_encode($sample);
$ch   = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $api_url . '/predict',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $json,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: ' . strlen($json)],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 3,
]);
$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    $all_passed = false;
    echo '<span class="fail">✗ cURL error: ' . htmlspecialchars($curl_err) . '</span>';
} elseif ($http_code === 200) {
    $data = json_decode($response, true);
    echo '<span class="pass">✓ Prediction received!</span><br><br>';
    echo '<strong>Risk Level: </strong>';
    $lvl = $data['data']['prediction']['risk_level'] ?? 'unknown';
    $pct = $data['data']['prediction']['risk_percentage'] ?? '?';
    $colors = ['low'=>'#4ade80','medium'=>'#fbbf24','high'=>'#f87171'];
    echo '<span style="color:' . ($colors[$lvl]??'#fff') . ';font-weight:bold;">' . strtoupper($lvl) . ' (' . $pct . '%)</span><br>';
    echo '<br><strong>Full response:</strong>';
    echo '<pre>' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . '</pre>';
} else {
    $all_passed = false;
    echo '<span class="fail">✗ HTTP ' . $http_code . '</span><br>';
    echo '<pre>' . htmlspecialchars($response) . '</pre>';
}
echo '</div>';

// ── TEST 5: Check ai_service.php path ────────────────────────────────────
echo '<div class="box">';
echo '<strong>TEST 5: ai_service.php File Location</strong><br>';
$paths = [
    __DIR__ . '/includes/ai_service.php',
    __DIR__ . '/../includes/ai_service.php',
    'C:/wamp64/www/SmartCareGuardian/includes/ai_service.php',
];
$found = false;
foreach ($paths as $p) {
    if (file_exists($p)) {
        echo '<span class="pass">✓ Found at: ' . $p . '</span><br>';
        $found = true;
        break;
    }
}
if (!$found) {
    $all_passed = false;
    echo '<span class="fail">✗ ai_service.php not found in expected locations</span><br>';
    echo '<span class="warn">Checked:</span><br>';
    foreach ($paths as $p) echo '<span class="info"> — ' . $p . '</span><br>';
    echo '<span class="warn">FIX: Make sure ai_service.php is saved in your /includes/ folder.</span>';
}
echo '</div>';

// ── TEST 6: php.ini allow_url_fopen ──────────────────────────────────────
echo '<div class="box">';
echo '<strong>TEST 6: PHP Configuration</strong><br>';
echo '<span class="info">allow_url_fopen: </span>';
echo ini_get('allow_url_fopen')
    ? '<span class="pass">✓ On</span>' 
    : '<span class="warn">Off (not critical for cURL)</span>';
echo '<br><span class="info">PHP Version: </span><span class="pass">' . PHP_VERSION . '</span>';
echo '<br><span class="info">cURL Version: </span>';
$cv = curl_version();
echo '<span class="pass">' . $cv['version'] . '</span>';
echo '</div>';

// ── SUMMARY ───────────────────────────────────────────────────────────────
echo '<div class="box ' . ($all_passed ? 'pass-box' : 'fail-box') . '">';
echo '<strong>SUMMARY</strong><br>';
if ($all_passed) {
    echo '<span class="pass" style="font-size:1.1rem;">✓ Everything is working! PHP can talk to Flask.</span><br>';
    echo '<span class="info">If your main page still shows "AI Offline", the issue is in the path to ai_service.php inside your dashboard file. Check the require_once path.</span>';
} else {
    echo '<span class="fail" style="font-size:1.1rem;">✗ One or more tests failed — see fixes above.</span><br><br>';
    echo '<strong style="color:#fbbf24;">Most common fixes:</strong><br>';
    echo '<span class="info">1. Make sure Flask is running: open a terminal and run <code>python app.py</code></span><br>';
    echo '<span class="info">2. Keep that terminal OPEN while using the website (Flask must stay running)</span><br>';
    echo '<span class="info">3. Check WAMP has cURL enabled (green icon → PHP → PHP extensions → php_curl)</span><br>';
    echo '<span class="info">4. Try http://127.0.0.1:5000 in your browser — you should see JSON</span><br>';
    echo '<span class="info">5. If browser works but PHP fails, Windows Firewall may be blocking loopback</span>';
}
echo '</div>';
?>

</body>
</html>