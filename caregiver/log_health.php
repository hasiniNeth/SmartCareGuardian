<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php");
    exit();
}

date_default_timezone_set('Asia/Colombo');

include '../db_connection.php';

$caregiver_id = $_SESSION['user_id'];
$caregiver_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$caregiver_stmt->bind_param("i", $caregiver_id);
$caregiver_stmt->execute();
$caregiver_result = $caregiver_stmt->get_result();
$caregiver = $caregiver_result->fetch_assoc();

$preselected_resident_id = isset($_GET['resident_id']) ? intval($_GET['resident_id']) : null;

$assigned_residents_stmt = $conn->prepare("SELECT u.user_id, u.full_name FROM users u JOIN caregiver_assignments ca ON u.user_id = ca.resident_id WHERE ca.caregiver_id = ? AND u.role = 'resident' AND u.status = 'active' ORDER BY u.full_name ASC");
$assigned_residents_stmt->bind_param("i", $caregiver_id);
$assigned_residents_stmt->execute();
$assigned_residents = $assigned_residents_stmt->get_result();

$success_message = '';
$error_message = '';
$validation_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resident_id = trim($_POST['resident_id'] ?? '');
    $blood_pressure_raw = trim($_POST['blood_pressure'] ?? '');
    $bp_systolic = null;
    $bp_diastolic = null;

    if (!empty($blood_pressure_raw)) {
        if (!preg_match('/^\d{2,3}\/\d{2,3}$/', $blood_pressure_raw)) {
            $validation_errors[] = "Blood pressure must be in format: systolic/diastolic (e.g., 120/80)";
        } else {
            [$bp_systolic, $bp_diastolic] = array_map('intval', explode('/', $blood_pressure_raw));
        }
    }
    $blood_sugar = trim($_POST['blood_sugar'] ?? '');
    $pulse = trim($_POST['pulse'] ?? '');
    $weight = trim($_POST['weight'] ?? '');
    $temperature = trim($_POST['temperature'] ?? '');
    $oxygen_saturation = trim($_POST['oxygen_saturation'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (empty($resident_id)) { $validation_errors[] = "Please select a resident."; }
    if (!empty($blood_sugar)) { $blood_sugar = floatval($blood_sugar); if ($blood_sugar < 0 || $blood_sugar > 500) $validation_errors[] = "Blood sugar must be between 0 and 500 mg/dL"; }
    if (!empty($pulse)) { $pulse = intval($pulse); if ($pulse < 0 || $pulse > 200) $validation_errors[] = "Pulse rate must be between 0 and 200 bpm"; }
    if (!empty($weight)) { $weight = floatval($weight); if ($weight < 0 || $weight > 300) $validation_errors[] = "Weight must be between 0 and 300 kg"; }
    if (!empty($temperature)) { $temperature = floatval($temperature); if ($temperature < 30 || $temperature > 45) $validation_errors[] = "Temperature must be between 30°C and 45°C"; }
    if (!empty($oxygen_saturation)) { $oxygen_saturation = intval($oxygen_saturation); if ($oxygen_saturation < 0 || $oxygen_saturation > 100) $validation_errors[] = "Oxygen saturation must be between 0% and 100%"; }
    if (empty($blood_pressure) && empty($blood_sugar) && empty($pulse) && empty($weight) && empty($temperature) && empty($oxygen_saturation)) {
        $validation_errors[] = "Please provide at least one health metric.";
    }

    if (empty($validation_errors)) {
        if ($bp_systolic === null && $bp_diastolic === null && empty($blood_sugar) && empty($pulse) && empty($weight) && empty($temperature) && empty($oxygen_saturation)) {
            $validation_errors[] = "Please provide at least one health metric.";
        }
        $blood_sugar = empty($blood_sugar) ? null : floatval($blood_sugar);
        $pulse = empty($pulse) ? null : intval($pulse);
        $weight = empty($weight) ? null : floatval($weight);
        $temperature = empty($temperature) ? null : floatval($temperature);
        $oxygen_saturation = empty($oxygen_saturation) ? null : intval($oxygen_saturation);
        $notes = empty($notes) ? null : $notes;

        $insert_stmt = $conn->prepare("INSERT INTO health_logs (resident_id, caregiver_id, blood_pressure_systolic, blood_pressure_diastolic, blood_sugar, pulse, weight, temperature, oxygen_saturation, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("iiiididdis", $resident_id, $caregiver_id, $bp_systolic, $bp_diastolic, $blood_sugar, $pulse, $weight, $temperature, $oxygen_saturation, $notes);

        if ($insert_stmt->execute()) {
            $success_message = "Health data logged successfully!";
            checkAbnormalValues($resident_id, $bp_systolic, $bp_diastolic, $blood_sugar, $pulse, $weight, $temperature, $oxygen_saturation, $conn);
            $_POST = array();
        } else {
            $error_message = "Error logging health data: " . $conn->error;
        }
    } else {
        $error_message = "Please fix the following errors:";
    }
}

function checkAbnormalValues($resident_id, $sys, $dia, $sugar, $pulse, $weight, $temp, $oxygen, $conn) {
    $alerts = [];
    if ($sys !== null && $dia !== null) {
        if ($sys > 140 || $dia > 90) $alerts[] = "High blood pressure: {$sys}/{$dia}";
        elseif ($sys < 90 || $dia < 60) $alerts[] = "Low blood pressure: {$sys}/{$dia}";
    }
    if ($sugar && ($sugar > 180 || $sugar < 70)) { $status = $sugar > 180 ? "High" : "Low"; $alerts[] = "{$status} blood sugar level: {$sugar} mg/dL (Normal: 70-180)"; }
    if ($pulse && ($pulse > 100 || $pulse < 60)) { $status = $pulse > 100 ? "High" : "Low"; $alerts[] = "{$status} pulse rate: {$pulse} bpm (Normal: 60-100)"; }
    if ($temp && ($temp > 37.5 || $temp < 36)) { $status = $temp > 37.5 ? "High" : "Low"; $alerts[] = "{$status} body temperature: {$temp}°C (Normal: 36-37.5)"; }
    if ($oxygen && $oxygen < 95) $alerts[] = "Low oxygen saturation: {$oxygen}% (Normal: 95-100)";
    foreach ($alerts as $alert_message) {
        $alert_stmt = $conn->prepare("INSERT INTO alerts (resident_id, alert_message, alert_type, created_at) VALUES (?, ?, 'health_warning', NOW())");
        $alert_stmt->bind_param("is", $resident_id, $alert_message);
        $alert_stmt->execute();
    }
    if (!empty($alerts)) { global $success_message; $success_message .= " " . count($alerts) . " health alert(s) generated for abnormal values."; }
}

$med_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM medications m JOIN caregiver_assignments ca ON ca.resident_id = m.resident_id WHERE ca.caregiver_id = ? AND m.taken = 0 AND m.medication_date = CURDATE()");
$med_stmt->bind_param("i", $caregiver_id);
$med_stmt->execute();
$pending_meds = $med_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$med_stmt->close();

$unread_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND is_read = 0");
$unread_stmt->bind_param("i", $caregiver_id);
$unread_stmt->execute();
$unread_messages = $unread_stmt->get_result()->fetch_assoc()['cnt'];
$unread_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Health Data – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════
           SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
           Log Health Data · Professional scale
        ═══════════════════════════════════════════════════════════ */
        :root {
            --s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;
            --s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;
            --s600:#4A6E30;--s700:#365220;--s800:#243816;
            --w50:#FDFAF5;--w100:#F7F1E5;
            --st300:#B8B0A4;--st500:#7A7268;--st700:#4A4540;
            --green-bg:#DDEFD8;--green-text:#3A6830;
            --amber-bg:#FAECC8;--amber-text:#7A5010;
            --red-bg:#F5DADA;  --red-text:#6A2020;
            --blue-bg:#DBEEFF; --blue-text:#1A4870;
            --radius-sm:8px;--radius-md:12px;--radius-lg:20px;
            --shadow-soft:0 2px 12px rgba(36,56,22,.07),0 1px 3px rgba(36,56,22,.05);
            --shadow-card:0 4px 24px rgba(36,56,22,.09),0 1px 4px rgba(36,56,22,.06);
            --shadow-lift:0 8px 32px rgba(36,56,22,.13),0 2px 8px rgba(36,56,22,.07);
        }
        *,*::before,*::after{box-sizing:border-box;}
        body{
            font-family:'Outfit',sans-serif;font-size:15px;line-height:1.6;
            background:var(--w50);
            background-image:
                radial-gradient(ellipse 70% 50% at 90% 0%,rgba(157,192,126,.09) 0%,transparent 55%),
                radial-gradient(ellipse 50% 40% at 0% 100%,rgba(122,166,88,.06) 0%,transparent 50%);
            color:var(--st700);min-height:100vh;margin:0;padding:0;
        }
        h1,h2,h3,h4,h5,h6{font-family:'Cormorant Garamond',serif;color:var(--s800);margin:0;}

        /* ── Sidebar ── */
        .sidebar{width:240px;height:100vh;position:fixed;background:var(--s800);display:flex;flex-direction:column;z-index:1000;overflow:hidden;}
        .sidebar::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:radial-gradient(ellipse 120% 60% at 50% -10%,rgba(157,192,126,.18) 0%,transparent 60%),radial-gradient(ellipse 80% 80% at 110% 110%,rgba(94,138,64,.15) 0%,transparent 55%);}
        .sidebar-header{padding:22px 18px 16px;border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0;position:relative;}
        .brand-mark{display:flex;align-items:center;gap:9px;margin-bottom:4px;}
        .brand-icon{width:32px;height:32px;background:linear-gradient(135deg,var(--s300),var(--s500));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;color:white;box-shadow:0 3px 10px rgba(0,0,0,.25);flex-shrink:0;}
        .sidebar-header h4{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:white;line-height:1.15;}
        .sidebar-header small{font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.08em;text-transform:uppercase;font-weight:500;display:block;margin-left:41px;margin-top:1px;}
        .sidebar-nav{flex:1;overflow-y:auto;padding:8px 0;position:relative;}
        .sidebar-nav::-webkit-scrollbar{width:4px;}.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:3px;}
        .sidebar a{display:flex;align-items:center;gap:9px;padding:10px 10px 10px 20px;color:rgba(255,255,255,.6);text-decoration:none;font-size:13.5px;font-weight:500;transition:all .2s;margin:1px 8px;border-radius:var(--radius-sm);position:relative;min-height:42px;}
        .sidebar a:hover{background:rgba(255,255,255,.10);color:white;}
        .sidebar a.active{background:rgba(157,192,126,.2);color:#C8E6A0;}
        .sidebar a.active::before{content:'';position:absolute;left:-8px;top:20%;bottom:20%;width:3px;background:var(--s300);border-radius:0 3px 3px 0;}
        .sidebar i{width:18px;text-align:center;font-size:13px;opacity:.85;flex-shrink:0;}
        .sb-badge,.msg-badge{margin-left:auto;background:#8B3A3A;color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;}
        .msg-badge{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:#8B3A3A;border-radius:50%;min-width:18px;height:18px;font-size:.65rem;display:flex;align-items:center;justify-content:center;padding:0 3px;}
        .sidebar-footer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 8px;position:relative;}
        .sidebar-footer a{margin:0;color:rgba(255,255,255,.5)!important;font-size:13.5px;}
        .sidebar-footer a:hover{color:rgba(255,255,255,.8)!important;}

        /* ── Layout ── */
        .content{margin-left:240px;padding:24px;min-height:100vh;}

        /* ── Topbar ── */
        .topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
        .topbar h4{font-size:22px;font-weight:500;color:var(--s800);margin-bottom:2px;}
        .topbar p{font-size:13px;color:var(--st300);margin:0;}
        .logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;}
        .logout-btn:hover{opacity:.9;transform:translateY(-1px);}

        /* ── Flash messages ── */
        .flash-msg{border-radius:var(--radius-md);border:none;padding:13px 18px;margin-bottom:18px;font-weight:600;font-size:14px;display:flex;align-items:flex-start;gap:10px;}
        .flash-msg ul{margin:6px 0 0 0;padding-left:18px;}
        .flash-msg li{font-size:13px;margin-bottom:2px;}
        .flash-success{background:var(--green-bg);color:var(--green-text);}
        .flash-error  {background:var(--red-bg);  color:var(--red-text);}
        .flash-info   {background:var(--blue-bg); color:var(--blue-text);}

        /* ── Form container ── */
        .form-container{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);overflow:hidden;}
        .form-header{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:20px 24px;position:relative;overflow:hidden;}
        .form-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
        .form-header h5{font-family:'Outfit',sans-serif;font-size:15px;font-weight:700;color:white;margin-bottom:3px;position:relative;}
        .form-header p{font-size:13px;color:rgba(255,255,255,.7);margin:0;position:relative;}
        .form-body{padding:24px 28px;}

        /* ── Form controls ── */
        .form-label{font-weight:700;color:var(--s800);font-size:13px;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
        .form-control,.form-select{border:2px solid var(--s100);border-radius:var(--radius-md);padding:10px 13px;font-size:14px;font-family:'Outfit',sans-serif;color:var(--st700);background:var(--w50);transition:all .2s;}
        .form-control:focus,.form-select:focus{border-color:var(--s400);box-shadow:0 0 0 3px rgba(122,166,88,.15);outline:none;transform:none;}
        .form-control::placeholder{color:var(--st300);}
        .input-group-icon{position:relative;}
        .input-group-icon .form-control{padding-left:40px;}
        .input-group-icon>i:not(.validation-icon i){position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--s400);z-index:3;font-size:13px;pointer-events:none;}

        /* ── Health metrics grid ── */
        .health-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin:20px 0;}

        /* ── Metric card ── */
        .metric-card{background:var(--s50);border-radius:var(--radius-md);padding:18px 16px;border-left:3px solid var(--s300);transition:all .2s;}
        .metric-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-soft);background:white;}
        .metric-icon{width:44px;height:44px;background:linear-gradient(135deg,var(--s400),var(--s600));border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;color:white;font-size:1.1rem;margin-bottom:12px;}
        .metric-label{font-weight:700;color:var(--s800);font-size:13px;margin-bottom:6px;}
        .metric-help{font-size:11px;color:var(--st300);margin-top:5px;}

        /* ── Validation ── */
        .is-invalid{border-color:var(--red-text)!important;background-color:#fff9f9!important;}
        .is-valid  {border-color:var(--s400)!important;}
        .invalid-feedback{display:none;width:100%;margin-top:4px;font-size:12px;color:var(--red-text);}
        .was-validated .form-control:invalid~.invalid-feedback,
        .was-validated .form-select:invalid~.invalid-feedback{display:block;}
        .validation-icon{position:absolute;right:12px;top:50%;transform:translateY(-50%);z-index:4;}
        .fa-check-circle{color:var(--s400);}
        .fa-exclamation-circle{color:var(--red-text);}

        /* ── Buttons ── */
        .btn-sage{background:linear-gradient(135deg,var(--s400),var(--s600));color:white;border:none;border-radius:var(--radius-md);padding:11px 22px;font-size:14px;font-weight:700;font-family:'Outfit',sans-serif;width:100%;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:7px;margin-top:8px;}
        .btn-sage:hover{opacity:.9;transform:translateY(-1px);box-shadow:var(--shadow-card);}
        .btn-outline-sage{background:transparent;border:2px solid var(--s200);border-radius:var(--radius-md);color:var(--s600);padding:11px 22px;font-size:14px;font-weight:700;font-family:'Outfit',sans-serif;width:100%;cursor:pointer;transition:all .2s;text-align:center;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:7px;margin-top:8px;}
        .btn-outline-sage:hover{background:var(--s50);border-color:var(--s300);color:var(--s700);}

        @media(max-width:768px){
            .sidebar{width:100%;height:auto;position:relative;}
            .content{margin-left:0;padding:14px;}
            .health-metrics{grid-template-columns:1fr;}
            .form-body{padding:16px;}
        }
    </style>
</head>
<body>

<!-- ══ SIDEBAR ════════════════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sidebar-header">
        <div class="brand-mark">
            <div class="brand-icon"><i class="fas fa-leaf"></i></div>
            <h4>SmartCare<br>Guardian</h4>
        </div>
        <small>Caregiver Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="caregiver_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="caregiver_profile.php"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="caregiver_residents.php"><i class="fa-solid fa-user-group"></i> My Residents</a>
        <a href="log_health.php" class="active"><i class="fa-solid fa-heart-pulse"></i> Log Health Data</a>
        <a href="manage_routines.php"><i class="fa-solid fa-calendar-check"></i> Manage Routines</a>
        <a href="manage_medications.php">
            <i class="fa-solid fa-pills"></i> Medications
            <?php if ($pending_meds > 0): ?><span class="sb-badge"><?= $pending_meds ?></span><?php endif; ?>
        </a>
        <a href="caregiver_appointments.php"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="caregiver_messages.php">
            <i class="fa-solid fa-comments"></i> Messages
            <?php if ($unread_messages > 0): ?><span class="msg-badge"><?= $unread_messages ?></span><?php endif; ?>
        </a>
        <a href="caregiver_ai_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="ai_suggestions.php"><i class="fa-solid fa-wand-magic-sparkles"></i> Routine Suggestions</a>
        <a href="caregiver_reports.php"><i class="fa-solid fa-chart-line"></i> Health Reports</a>
    </div>
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<!-- ══ MAIN CONTENT ════════════════════════════════════════════════ -->
<div class="content">

    <div class="topbar">
        <div>
            <h4><i class="fas fa-heart-pulse me-2" style="font-size:18px;color:var(--s500);"></i>Log Health Data</h4>
            <p>Record vital signs and health metrics for residents</p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt"></i>Logout</button>
        </form>
    </div>

    <div class="form-container">
        <div class="form-header">
            <h5><i class="fas fa-heartbeat me-2"></i>Health Data Entry</h5>
            <p>Fill in the health metrics for the selected resident</p>
        </div>

        <div class="form-body">

            <?php if ($success_message): ?>
                <div class="flash-msg flash-success"><i class="fas fa-check-circle"></i><span><?= $success_message ?></span></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="flash-msg flash-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= $error_message ?>
                        <?php if (!empty($validation_errors)): ?>
                            <ul class="mb-0 mt-1"><?php foreach ($validation_errors as $err): ?><li><?= $err ?></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <form method="POST" id="healthForm" class="needs-validation" novalidate>

                <!-- Resident selection -->
                <div class="mb-4">
                    <label for="resident_id" class="form-label"><i class="fas fa-user" style="color:var(--s400);"></i>Select Resident *</label>
                    <select class="form-select" id="resident_id" name="resident_id" required>
                        <option value="">Choose a resident…</option>
                        <?php
                        $assigned_residents->data_seek(0);
                        while ($resident = $assigned_residents->fetch_assoc()):
                            $selected = false;
                            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resident_id'])) {
                                $selected = ($_POST['resident_id'] == $resident['user_id']);
                            } elseif ($preselected_resident_id) {
                                $selected = ($preselected_resident_id == $resident['user_id']);
                            }
                        ?>
                            <option value="<?= $resident['user_id'] ?>" <?= $selected ? 'selected' : '' ?>><?= htmlspecialchars($resident['full_name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                    <div class="invalid-feedback">Please select a resident.</div>
                </div>

                <!-- Health metrics grid -->
                <div class="health-metrics">

                    <!-- Blood Pressure -->
                    <div class="metric-card">
                        <div class="metric-icon"><i class="fas fa-tachometer-alt"></i></div>
                        <div class="metric-label">Blood Pressure</div>
                        <div class="input-group-icon">
                            <i class="fas fa-heartbeat"></i>
                            <input type="text" class="form-control" name="blood_pressure"
                                   placeholder="e.g., 120/80" pattern="\d{2,3}\/\d{2,3}"
                                   value="<?= htmlspecialchars($_POST['blood_pressure'] ?? '') ?>">
                            <div class="validation-icon"><i class="fas fa-check-circle d-none"></i><i class="fas fa-exclamation-circle d-none"></i></div>
                        </div>
                        <div class="metric-help">Format: systolic/diastolic (e.g., 120/80)</div>
                        <div class="invalid-feedback">Enter blood pressure as systolic/diastolic (e.g., 120/80)</div>
                    </div>

                    <!-- Blood Sugar -->
                    <div class="metric-card">
                        <div class="metric-icon"><i class="fas fa-prescription-bottle"></i></div>
                        <div class="metric-label">Blood Sugar</div>
                        <div class="input-group-icon">
                            <i class="fas fa-tint"></i>
                            <input type="number" class="form-control" name="blood_sugar"
                                   placeholder="mg/dL" step="0.1" min="0" max="500"
                                   value="<?= htmlspecialchars($_POST['blood_sugar'] ?? '') ?>">
                            <div class="validation-icon"><i class="fas fa-check-circle d-none"></i><i class="fas fa-exclamation-circle d-none"></i></div>
                        </div>
                        <div class="metric-help">mg/dL (70–180 normal)</div>
                        <div class="invalid-feedback">Blood sugar must be between 0 and 500 mg/dL</div>
                    </div>

                    <!-- Pulse Rate -->
                    <div class="metric-card">
                        <div class="metric-icon"><i class="fas fa-heart"></i></div>
                        <div class="metric-label">Pulse Rate</div>
                        <div class="input-group-icon">
                            <i class="fas fa-heartbeat"></i>
                            <input type="number" class="form-control" name="pulse"
                                   placeholder="bpm" min="0" max="200"
                                   value="<?= htmlspecialchars($_POST['pulse'] ?? '') ?>">
                            <div class="validation-icon"><i class="fas fa-check-circle d-none"></i><i class="fas fa-exclamation-circle d-none"></i></div>
                        </div>
                        <div class="metric-help">Beats per minute (60–100 normal)</div>
                        <div class="invalid-feedback">Pulse rate must be between 0 and 200 bpm</div>
                    </div>

                    <!-- Weight -->
                    <div class="metric-card">
                        <div class="metric-icon"><i class="fas fa-weight"></i></div>
                        <div class="metric-label">Weight</div>
                        <div class="input-group-icon">
                            <i class="fas fa-balance-scale"></i>
                            <input type="number" class="form-control" name="weight"
                                   placeholder="kg" step="0.1" min="0" max="300"
                                   value="<?= htmlspecialchars($_POST['weight'] ?? '') ?>">
                            <div class="validation-icon"><i class="fas fa-check-circle d-none"></i><i class="fas fa-exclamation-circle d-none"></i></div>
                        </div>
                        <div class="metric-help">Kilograms</div>
                        <div class="invalid-feedback">Weight must be between 0 and 300 kg</div>
                    </div>

                    <!-- Temperature -->
                    <div class="metric-card">
                        <div class="metric-icon"><i class="fas fa-thermometer-half"></i></div>
                        <div class="metric-label">Temperature</div>
                        <div class="input-group-icon">
                            <i class="fas fa-temperature-high"></i>
                            <input type="number" class="form-control" name="temperature"
                                   placeholder="°C" step="0.1" min="30" max="45"
                                   value="<?= htmlspecialchars($_POST['temperature'] ?? '') ?>">
                            <div class="validation-icon"><i class="fas fa-check-circle d-none"></i><i class="fas fa-exclamation-circle d-none"></i></div>
                        </div>
                        <div class="metric-help">Celsius (36–37.5°C normal)</div>
                        <div class="invalid-feedback">Temperature must be between 30°C and 45°C</div>
                    </div>

                    <!-- Oxygen Saturation -->
                    <div class="metric-card">
                        <div class="metric-icon"><i class="fas fa-lungs"></i></div>
                        <div class="metric-label">Oxygen Saturation</div>
                        <div class="input-group-icon">
                            <i class="fas fa-wind"></i>
                            <input type="number" class="form-control" name="oxygen_saturation"
                                   placeholder="%" min="0" max="100"
                                   value="<?= htmlspecialchars($_POST['oxygen_saturation'] ?? '') ?>">
                            <div class="validation-icon"><i class="fas fa-check-circle d-none"></i><i class="fas fa-exclamation-circle d-none"></i></div>
                        </div>
                        <div class="metric-help">Percentage (95–100% normal)</div>
                        <div class="invalid-feedback">Oxygen saturation must be between 0% and 100%</div>
                    </div>

                </div><!-- /health-metrics -->

                <!-- Notes -->
                <div class="mb-4">
                    <label for="notes" class="form-label"><i class="fas fa-sticky-note" style="color:var(--s400);"></i>Additional Notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="4"
                              placeholder="Any additional observations, symptoms, or concerns…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>

                <!-- Info note -->
                <div class="flash-msg flash-info mb-4">
                    <i class="fas fa-info-circle"></i>
                    <span><strong>Note:</strong> Fields marked with * are required. Please provide at least one health metric.</span>
                </div>

                <!-- Submit -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <button type="submit" class="btn-sage"><i class="fas fa-save"></i>Save Health Data</button>
                    </div>
                    <div class="col-md-6">
                        <a href="caregiver_dashboard.php" class="btn-outline-sage"><i class="fas fa-arrow-left"></i>Back to Dashboard</a>
                    </div>
                </div>

            </form>
        </div><!-- /form-body -->
    </div><!-- /form-container -->
</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('healthForm');
        const healthInputs = document.querySelectorAll('input[name="blood_pressure"], input[name="blood_sugar"], input[name="pulse"], input[name="weight"], input[name="temperature"], input[name="oxygen_saturation"]');

        const residentSelect = document.getElementById('resident_id');
        if (residentSelect.value !== '' && healthInputs.length > 0) {
            healthInputs[0].focus();
        }

        healthInputs.forEach(input => {
            input.addEventListener('input', function() { validateField(this); checkAtLeastOneMetric(); });
            input.addEventListener('blur', function() { validateField(this); });
        });

        document.getElementById('resident_id').addEventListener('change', function() { validateField(this); });

        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
                validateField(document.getElementById('resident_id'));
                healthInputs.forEach(input => validateField(input));
                checkAtLeastOneMetric();
                form.classList.add('was-validated');
            } else {
                const hasData = Array.from(healthInputs).some(input => input.value.trim() !== '');
                if (!hasData) {
                    e.preventDefault();
                    showCustomAlert('Please provide at least one health metric.');
                    return;
                }
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving…';
                submitBtn.disabled = true;
            }
        });

        function validateField(field) {
            const validationIcon = field.parentElement.querySelector('.validation-icon');
            const checkIcon = validationIcon?.querySelector('.fa-check-circle');
            const exclamationIcon = validationIcon?.querySelector('.fa-exclamation-circle');
            field.classList.remove('is-valid', 'is-invalid');
            if (checkIcon) checkIcon.classList.add('d-none');
            if (exclamationIcon) exclamationIcon.classList.add('d-none');
            if (field.value.trim() === '') return;
            let isValid = true;
            if (field.name === 'blood_pressure') isValid = /^\d{2,3}\/\d{2,3}$/.test(field.value);
            else if (field.name === 'blood_sugar') { const v = parseFloat(field.value); isValid = !isNaN(v) && v >= 0 && v <= 500; }
            else if (field.name === 'pulse') { const v = parseInt(field.value); isValid = !isNaN(v) && v >= 0 && v <= 200; }
            else if (field.name === 'weight') { const v = parseFloat(field.value); isValid = !isNaN(v) && v >= 0 && v <= 300; }
            else if (field.name === 'temperature') { const v = parseFloat(field.value); isValid = !isNaN(v) && v >= 30 && v <= 45; }
            else if (field.name === 'oxygen_saturation') { const v = parseInt(field.value); isValid = !isNaN(v) && v >= 0 && v <= 100; }
            else if (field.name === 'resident_id') isValid = field.value !== '';
            if (isValid) { field.classList.add('is-valid'); if (checkIcon) checkIcon.classList.remove('d-none'); }
            else { field.classList.add('is-invalid'); if (exclamationIcon) exclamationIcon.classList.remove('d-none'); }
        }

        function checkAtLeastOneMetric() {
            const hasData = Array.from(healthInputs).some(input => input.value.trim() !== '');
            if (!hasData) console.log('Please provide at least one health metric');
        }

        function showCustomAlert(message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = 'flash-msg flash-error';
            alertDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i><span>${message}</span>`;
            const formBody = document.querySelector('.form-body');
            const existing = formBody.querySelector('.flash-error');
            if (existing) existing.remove();
            formBody.insertBefore(alertDiv, formBody.firstChild);
            setTimeout(() => { if (alertDiv.parentNode) alertDiv.remove(); }, 5000);
        }

        healthInputs.forEach(input => { if (input.value.trim() !== '') validateField(input); });
        if (document.getElementById('resident_id').value !== '') validateField(document.getElementById('resident_id'));
    });
</script>
</body>
</html>