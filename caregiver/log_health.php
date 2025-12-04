<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php");
    exit();
}

include '../db_connection.php';

// Get caregiver info
$caregiver_id = $_SESSION['user_id'];
$caregiver_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$caregiver_stmt->bind_param("i", $caregiver_id);
$caregiver_stmt->execute();
$caregiver_result = $caregiver_stmt->get_result();
$caregiver = $caregiver_result->fetch_assoc();

// Check if resident_id is passed via URL parameter (from residents grid)
$preselected_resident_id = isset($_GET['resident_id']) ? intval($_GET['resident_id']) : null;

// Get assigned residents for dropdown
$assigned_residents_stmt = $conn->prepare("
    SELECT u.user_id, u.full_name 
    FROM users u 
    JOIN caregiver_assignments ca ON u.user_id = ca.resident_id 
    WHERE ca.caregiver_id = ? AND u.role = 'resident' AND u.status = 'active'
    ORDER BY u.full_name ASC
");
$assigned_residents_stmt->bind_param("i", $caregiver_id);
$assigned_residents_stmt->execute();
$assigned_residents = $assigned_residents_stmt->get_result();

// Handle form submission
$success_message = '';
$error_message = '';
$validation_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $resident_id = trim($_POST['resident_id'] ?? '');
    $blood_pressure = trim($_POST['blood_pressure'] ?? '');
    $blood_sugar = trim($_POST['blood_sugar'] ?? '');
    $pulse = trim($_POST['pulse'] ?? '');
    $weight = trim($_POST['weight'] ?? '');
    $temperature = trim($_POST['temperature'] ?? '');
    $oxygen_saturation = trim($_POST['oxygen_saturation'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Server-side validation
    if (empty($resident_id)) {
        $validation_errors[] = "Please select a resident.";
    }
    
    // Validate blood pressure format
    if (!empty($blood_pressure) && !preg_match('/^\d{2,3}\/\d{2,3}$/', $blood_pressure)) {
        $validation_errors[] = "Blood pressure must be in format: systolic/diastolic (e.g., 120/80)";
    }
    
    // Validate blood sugar range
    if (!empty($blood_sugar)) {
        $blood_sugar = floatval($blood_sugar);
        if ($blood_sugar < 0 || $blood_sugar > 500) {
            $validation_errors[] = "Blood sugar must be between 0 and 500 mg/dL";
        }
    }
    
    // Validate pulse range
    if (!empty($pulse)) {
        $pulse = intval($pulse);
        if ($pulse < 0 || $pulse > 200) {
            $validation_errors[] = "Pulse rate must be between 0 and 200 bpm";
        }
    }
    
    // Validate weight range
    if (!empty($weight)) {
        $weight = floatval($weight);
        if ($weight < 0 || $weight > 300) {
            $validation_errors[] = "Weight must be between 0 and 300 kg";
        }
    }
    
    // Validate temperature range
    if (!empty($temperature)) {
        $temperature = floatval($temperature);
        if ($temperature < 30 || $temperature > 45) {
            $validation_errors[] = "Temperature must be between 30°C and 45°C";
        }
    }
    
    // Validate oxygen saturation range
    if (!empty($oxygen_saturation)) {
        $oxygen_saturation = intval($oxygen_saturation);
        if ($oxygen_saturation < 0 || $oxygen_saturation > 100) {
            $validation_errors[] = "Oxygen saturation must be between 0% and 100%";
        }
    }
    
    // Check if at least one health metric is provided
    if (empty($blood_pressure) && empty($blood_sugar) && empty($pulse) && 
        empty($weight) && empty($temperature) && empty($oxygen_saturation)) {
        $validation_errors[] = "Please provide at least one health metric.";
    }
    
    // If no validation errors, proceed with database operations
    if (empty($validation_errors)) {
        // Create health_logs table if it doesn't exist
        $create_table_sql = "
            CREATE TABLE IF NOT EXISTS health_logs (
                log_id INT PRIMARY KEY AUTO_INCREMENT,
                resident_id INT NOT NULL,
                caregiver_id INT NOT NULL,
                blood_pressure VARCHAR(20),
                blood_sugar DECIMAL(5,2),
                pulse INT,
                weight DECIMAL(5,2),
                temperature DECIMAL(4,2),
                oxygen_saturation INT,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (resident_id) REFERENCES users(user_id),
                FOREIGN KEY (caregiver_id) REFERENCES users(user_id)
            )
        ";
        
        if ($conn->query($create_table_sql)) {
            // Prepare data for insertion (convert empty strings to NULL)
            $blood_pressure = empty($blood_pressure) ? null : $blood_pressure;
            $blood_sugar = empty($blood_sugar) ? null : floatval($blood_sugar);
            $pulse = empty($pulse) ? null : intval($pulse);
            $weight = empty($weight) ? null : floatval($weight);
            $temperature = empty($temperature) ? null : floatval($temperature);
            $oxygen_saturation = empty($oxygen_saturation) ? null : intval($oxygen_saturation);
            $notes = empty($notes) ? null : $notes;
            
            // Insert health data
            $insert_stmt = $conn->prepare("
                INSERT INTO health_logs 
                (resident_id, caregiver_id, blood_pressure, blood_sugar, pulse, weight, temperature, oxygen_saturation, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Use 'd' for double/decimal parameters
            $insert_stmt->bind_param(
                "iisdiddis", 
                $resident_id, 
                $caregiver_id, 
                $blood_pressure, 
                $blood_sugar, 
                $pulse, 
                $weight, 
                $temperature, 
                $oxygen_saturation, 
                $notes
            );
            
            if ($insert_stmt->execute()) {
                $success_message = "Health data logged successfully!";
                
                // Check for abnormal values and create alerts
                checkAbnormalValues($resident_id, $blood_pressure, $blood_sugar, $pulse, $weight, $temperature, $oxygen_saturation, $conn);
                
                // Clear form data after successful submission
                $_POST = array();
                
            } else {
                $error_message = "Error logging health data: " . $conn->error;
            }
        } else {
            $error_message = "Error creating health logs table: " . $conn->error;
        }
    } else {
        $error_message = "Please fix the following errors:";
    }
}

// Function to check for abnormal values and create alerts
function checkAbnormalValues($resident_id, $bp, $sugar, $pulse, $weight, $temp, $oxygen, $conn) {
    $alerts = [];
    
    // Blood Pressure check (systolic/diastolic)
    if ($bp) {
        $bp_parts = explode('/', $bp);
        if (count($bp_parts) == 2) {
            $systolic = intval($bp_parts[0]);
            $diastolic = intval($bp_parts[1]);
            
            if ($systolic > 140 || $diastolic > 90) {
                $alerts[] = "High blood pressure: $bp (Normal: <140/90)";
            } elseif ($systolic < 90 || $diastolic < 60) {
                $alerts[] = "Low blood pressure: $bp (Normal: >90/60)";
            }
        }
    }
    
    // Blood Sugar check
    if ($sugar && ($sugar > 180 || $sugar < 70)) {
        $status = $sugar > 180 ? "High" : "Low";
        $alerts[] = "{$status} blood sugar level: {$sugar} mg/dL (Normal: 70-180)";
    }
    
    // Pulse check
    if ($pulse && ($pulse > 100 || $pulse < 60)) {
        $status = $pulse > 100 ? "High" : "Low";
        $alerts[] = "{$status} pulse rate: {$pulse} bpm (Normal: 60-100)";
    }
    
    // Temperature check
    if ($temp && ($temp > 37.5 || $temp < 36)) {
        $status = $temp > 37.5 ? "High" : "Low";
        $alerts[] = "{$status} body temperature: {$temp}°C (Normal: 36-37.5)";
    }
    
    // Oxygen saturation check
    if ($oxygen && $oxygen < 95) {
        $alerts[] = "Low oxygen saturation: {$oxygen}% (Normal: 95-100)";
    }
    
    // Create alerts for abnormal values
    foreach ($alerts as $alert_message) {
        $alert_stmt = $conn->prepare("
            INSERT INTO alerts (resident_id, alert_message, alert_type, created_at) 
            VALUES (?, ?, 'health_warning', NOW())
        ");
        $alert_stmt->bind_param("is", $resident_id, $alert_message);
        $alert_stmt->execute();
    }
    
    // If alerts were created, update the success message
    if (!empty($alerts)) {
        global $success_message;
        $success_message .= " " . count($alerts) . " health alert(s) generated for abnormal values.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Health Data - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Quicksand:wght@300;400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
       :root {
            --sage-green: #87A96B;
            --mint-cream: #F0FFF0;
            --seafoam: #9FE2BF;
            --forest-mist: #B8E0D2;
            --dusty-teal: #6D9B8E;
            --deep-emerald: #4A766E;
            --light-sage: #E8F5E8;
        }
        
        body {
            font-family: 'Quicksand', sans-serif;
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        h1, h2, h3, h4, h5 {
            font-family: 'Playfair Display', serif;
            color: var(--deep-emerald);
        }
        
        .brand-font {
            font-family: 'Jost', sans-serif;
            font-weight: 600;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            height: 100vh;
            position: fixed;
            background: linear-gradient(180deg, var(--sage-green) 0%, var(--dusty-teal) 100%);
            color: white;
            box-shadow: 4px 0 20px rgba(0,0,0,0.1);
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-header {
            text-align: center;
            padding: 30px 20px 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            flex-shrink: 0;
        }
        
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 20px 0;
        }
        
        .sidebar-footer {
            flex-shrink: 0;
            border-top: 1px solid rgba(255,255,255,0.2);
            padding: 20px;
        }
        
        .sidebar a {
            color: white;
            display: flex;
            align-items: center;
            padding: 15px 25px;
            text-decoration: none;
            transition: all 0.3s ease;
            margin: 5px 15px;
            border-radius: 12px;
            font-weight: 500;
        }
        
        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }
        
        .sidebar a.active {
            background: rgba(255, 255, 255, 0.25);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .sidebar i {
            width: 25px;
            margin-right: 12px;
            font-size: 1.1rem;
        }

        /* Main Content */
        .content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
        }
        
        .topbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 20px 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .logout-btn {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            border: none;
            border-radius: 50px;
            color: white;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
        }

        /* Form Container */
        .form-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }
        
        .form-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 25px 30px;
        }
        
        .form-body {
            padding: 30px;
        }

        /* Form Styles */
        .form-label {
            font-weight: 600;
            color: var(--deep-emerald);
            margin-bottom: 8px;
        }
        
        .form-control, .form-select {
            border: 2px solid var(--forest-mist);
            border-radius: 12px;
            padding: 12px 15px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
            transform: translateY(-2px);
        }
        
        .input-group-icon {
            position: relative;
        }
        
        .input-group-icon .form-control {
            padding-left: 45px;
        }
        
        .input-group-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dusty-teal);
            z-index: 3;
        }

        /* Health Metrics Grid */
        .health-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }
        
        .metric-card {
            background: var(--light-sage);
            border-radius: 15px;
            padding: 20px;
            border-left: 4px solid var(--sage-green);
            transition: all 0.3s ease;
        }
        
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .metric-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
            margin-bottom: 15px;
        }
        
        .metric-label {
            font-weight: 600;
            color: var(--deep-emerald);
            margin-bottom: 5px;
        }
        
        .metric-help {
            font-size: 0.8rem;
            color: var(--dusty-teal);
        }

        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            border-radius: 12px;
            color: white;
            padding: 15px 30px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 10px;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(141, 182, 154, 0.4);
        }
        
        .btn-secondary {
            background: transparent;
            border: 2px solid var(--forest-mist);
            border-radius: 12px;
            color: var(--deep-emerald);
            padding: 15px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 10px;
        }
        
        .btn-secondary:hover {
            background: var(--forest-mist);
            transform: translateY(-3px);
        }

        /* Alerts */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: linear-gradient(135deg, var(--seafoam), var(--sage-green));
            color: white;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            
            .content {
                margin-left: 0;
            }
            
            .health-metrics {
                grid-template-columns: 1fr;
            }
        }

        /* Custom scrollbar */
        .sidebar-nav::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar-nav::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
        }
        
        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 3px;
        }
        
        .sidebar-nav::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.5);
        }
        
        /* Validation Styles */
        .is-invalid {
            border-color: #ff6b6b !important;
            background-color: #fff5f5 !important;
        }
        
        .is-valid {
            border-color: var(--sage-green) !important;
        }
        
        .invalid-feedback {
            display: none;
            width: 100%;
            margin-top: 0.25rem;
            font-size: 0.875rem;
            color: #ff6b6b;
        }
        
        .was-validated .form-control:invalid ~ .invalid-feedback,
        .was-validated .form-select:invalid ~ .invalid-feedback {
            display: block;
        }
        
        .validation-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 4;
        }
        
        .fa-check-circle {
            color: var(--sage-green);
        }
        
        .fa-exclamation-circle {
            color: #ff6b6b;
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-header">
        <h4 class="brand-font mb-2"><i class="fa-solid fa-shield-heart me-2"></i>SmartCare Guardian</h4>
        <small class="opacity-75">Caregiver Panel</small>
    </div>
    
    <div class="sidebar-nav">
        <a href="caregiver_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="caregiver_residents.php"><i class="fa-solid fa-user-group"></i> My Residents</a>
        <a href="#" class="active"><i class="fa-solid fa-heart-pulse"></i> Log Health Data</a>
        <a href="manage_routines.php"><i class="fa-solid fa-calendar-check"></i> Manage Routines</a>
        <a href="caregiver_appointments.php"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="caregiver_messages.php"><i class="fa-solid fa-comments"></i> Messages</a>
        <a href="caregiver_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="caregiver_reports.php"><i class="fa-solid fa-chart-line"></i> Health Reports</a>
    </div>
    
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php" class="logout-sidebar">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>
</div>

<!-- Main Content -->
<div class="content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div>
            <h4 class="brand-font mb-1">Log Health Data</h4>
            <p class="text-muted mb-0">Record vital signs and health metrics for residents</p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-sign-out-alt me-2"></i>Logout
            </button>
        </form>
    </div>

    <div class="form-container">
        <div class="form-header">
            <h5 class="brand-font mb-2"><i class="fas fa-heartbeat me-2"></i>Health Data Entry</h5>
            <p class="mb-0">Fill in the health metrics for the selected resident</p>
        </div>
        
        <div class="form-body">
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                    <?php if (!empty($validation_errors)): ?>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($validation_errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="healthForm" class="needs-validation" novalidate>
                <!-- Resident Selection -->
                <div class="mb-4">
                    <label for="resident_id" class="form-label">
                        <i class="fas fa-user me-2"></i>Select Resident *
                    </label>
                    <select class="form-select" id="resident_id" name="resident_id" required>
                        <option value="">Choose a resident...</option>
                        <?php 
                        $assigned_residents->data_seek(0); // Reset pointer
                        while ($resident = $assigned_residents->fetch_assoc()): 
                            // Check if this resident should be selected
                            $selected = false;
                            
                            // Priority 1: Form submission (POST)
                            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resident_id'])) {
                                $selected = ($_POST['resident_id'] == $resident['user_id']);
                            }
                            // Priority 2: URL parameter (from residents grid)
                            elseif ($preselected_resident_id) {
                                $selected = ($preselected_resident_id == $resident['user_id']);
                            }
                        ?>
                            <option value="<?php echo $resident['user_id']; ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($resident['full_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <div class="invalid-feedback">
                        Please select a resident.
                    </div>
                </div>

                <!-- Health Metrics Grid -->
                <div class="health-metrics">
                    <!-- Blood Pressure -->
                    <div class="metric-card">
                        <div class="metric-icon">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <div class="metric-label">Blood Pressure</div>
                        <div class="input-group-icon">
                            <i class="fas fa-heartbeat"></i>
                            <input type="text" class="form-control" name="blood_pressure" 
                                   placeholder="e.g., 120/80" pattern="\d{2,3}\/\d{2,3}"
                                   value="<?php echo htmlspecialchars($_POST['blood_pressure'] ?? ''); ?>">
                            <div class="validation-icon">
                                <i class="fas fa-check-circle d-none"></i>
                                <i class="fas fa-exclamation-circle d-none"></i>
                            </div>
                        </div>
                        <div class="metric-help">Format: systolic/diastolic (e.g., 120/80)</div>
                        <div class="invalid-feedback">
                            Please enter blood pressure in format: systolic/diastolic (e.g., 120/80)
                        </div>
                    </div>

                    <!-- Blood Sugar -->
                    <div class="metric-card">
                        <div class="metric-icon">
                            <i class="fas fa-prescription-bottle"></i>
                        </div>
                        <div class="metric-label">Blood Sugar</div>
                        <div class="input-group-icon">
                            <i class="fas fa-tint"></i>
                            <input type="number" class="form-control" name="blood_sugar" 
                                   placeholder="mg/dL" step="0.1" min="0" max="500"
                                   value="<?php echo htmlspecialchars($_POST['blood_sugar'] ?? ''); ?>">
                            <div class="validation-icon">
                                <i class="fas fa-check-circle d-none"></i>
                                <i class="fas fa-exclamation-circle d-none"></i>
                            </div>
                        </div>
                        <div class="metric-help">mg/dL (70-180 normal)</div>
                        <div class="invalid-feedback">
                            Blood sugar must be between 0 and 500 mg/dL
                        </div>
                    </div>

                    <!-- Pulse Rate -->
                    <div class="metric-card">
                        <div class="metric-icon">
                            <i class="fas fa-heart"></i>
                        </div>
                        <div class="metric-label">Pulse Rate</div>
                        <div class="input-group-icon">
                            <i class="fas fa-heartbeat"></i>
                            <input type="number" class="form-control" name="pulse" 
                                   placeholder="bpm" min="0" max="200"
                                   value="<?php echo htmlspecialchars($_POST['pulse'] ?? ''); ?>">
                            <div class="validation-icon">
                                <i class="fas fa-check-circle d-none"></i>
                                <i class="fas fa-exclamation-circle d-none"></i>
                            </div>
                        </div>
                        <div class="metric-help">Beats per minute (60-100 normal)</div>
                        <div class="invalid-feedback">
                            Pulse rate must be between 0 and 200 bpm
                        </div>
                    </div>

                    <!-- Weight -->
                    <div class="metric-card">
                        <div class="metric-icon">
                            <i class="fas fa-weight"></i>
                        </div>
                        <div class="metric-label">Weight</div>
                        <div class="input-group-icon">
                            <i class="fas fa-balance-scale"></i>
                            <input type="number" class="form-control" name="weight" 
                                   placeholder="kg" step="0.1" min="0" max="300"
                                   value="<?php echo htmlspecialchars($_POST['weight'] ?? ''); ?>">
                            <div class="validation-icon">
                                <i class="fas fa-check-circle d-none"></i>
                                <i class="fas fa-exclamation-circle d-none"></i>
                            </div>
                        </div>
                        <div class="metric-help">Kilograms</div>
                        <div class="invalid-feedback">
                            Weight must be between 0 and 300 kg
                        </div>
                    </div>

                    <!-- Temperature -->
                    <div class="metric-card">
                        <div class="metric-icon">
                            <i class="fas fa-thermometer-half"></i>
                        </div>
                        <div class="metric-label">Temperature</div>
                        <div class="input-group-icon">
                            <i class="fas fa-temperature-high"></i>
                            <input type="number" class="form-control" name="temperature" 
                                   placeholder="°C" step="0.1" min="30" max="45"
                                   value="<?php echo htmlspecialchars($_POST['temperature'] ?? ''); ?>">
                            <div class="validation-icon">
                                <i class="fas fa-check-circle d-none"></i>
                                <i class="fas fa-exclamation-circle d-none"></i>
                            </div>
                        </div>
                        <div class="metric-help">Celsius (36-37.5°C normal)</div>
                        <div class="invalid-feedback">
                            Temperature must be between 30°C and 45°C
                        </div>
                    </div>

                    <!-- Oxygen Saturation -->
                    <div class="metric-card">
                        <div class="metric-icon">
                            <i class="fas fa-lungs"></i>
                        </div>
                        <div class="metric-label">Oxygen Saturation</div>
                        <div class="input-group-icon">
                            <i class="fas fa-wind"></i>
                            <input type="number" class="form-control" name="oxygen_saturation" 
                                   placeholder="%" min="0" max="100"
                                   value="<?php echo htmlspecialchars($_POST['oxygen_saturation'] ?? ''); ?>">
                            <div class="validation-icon">
                                <i class="fas fa-check-circle d-none"></i>
                                <i class="fas fa-exclamation-circle d-none"></i>
                            </div>
                        </div>
                        <div class="metric-help">Percentage (95-100% normal)</div>
                        <div class="invalid-feedback">
                            Oxygen saturation must be between 0% and 100%
                        </div>
                    </div>
                </div>

                <!-- Additional Notes -->
                <div class="mb-4">
                    <label for="notes" class="form-label">
                        <i class="fas fa-sticky-note me-2"></i>Additional Notes
                    </label>
                    <textarea class="form-control" id="notes" name="notes" rows="4" 
                              placeholder="Any additional observations, symptoms, or concerns..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                </div>

                <!-- Required Fields Note -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Note:</strong> Fields marked with * are required. Please provide at least one health metric.
                </div>

                <!-- Submit Buttons -->
                <div class="row">
                    <div class="col-md-6">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save me-2"></i>Save Health Data
                        </button>
                    </div>
                    <div class="col-md-6">
                        <a href="caregiver_dashboard.php" class="btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Comprehensive form validation
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('healthForm');
        const healthInputs = document.querySelectorAll('input[name="blood_pressure"], input[name="blood_sugar"], input[name="pulse"], input[name="weight"], input[name="temperature"], input[name="oxygen_saturation"]');
        
        // Auto-focus on the first health input if resident is pre-selected
        const residentSelect = document.getElementById('resident_id');
        if (residentSelect.value !== '') {
            // Resident is pre-selected, focus on first health input
            if (healthInputs.length > 0) {
                healthInputs[0].focus();
            }
        }

        // Real-time validation for each input
        healthInputs.forEach(input => {
            input.addEventListener('input', function() {
                validateField(this);
                checkAtLeastOneMetric();
            });
            
            input.addEventListener('blur', function() {
                validateField(this);
            });
        });
        
        // Resident selection validation
        document.getElementById('resident_id').addEventListener('change', function() {
            validateField(this);
        });
        
        // Form submission validation
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
                
                // Validate all fields
                validateField(document.getElementById('resident_id'));
                healthInputs.forEach(input => validateField(input));
                checkAtLeastOneMetric();
                
                // Show validation messages
                form.classList.add('was-validated');
            } else {
                // Check if at least one health metric is provided
                const hasData = Array.from(healthInputs).some(input => input.value.trim() !== '');
                if (!hasData) {
                    e.preventDefault();
                    showCustomAlert('Please provide at least one health metric.');
                    return;
                }
                
                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
                submitBtn.disabled = true;
            }
        });
        
        function validateField(field) {
            const validationIcon = field.parentElement.querySelector('.validation-icon');
            const checkIcon = validationIcon?.querySelector('.fa-check-circle');
            const exclamationIcon = validationIcon?.querySelector('.fa-exclamation-circle');
            
            // Remove previous validation states
            field.classList.remove('is-valid', 'is-invalid');
            if (checkIcon) checkIcon.classList.add('d-none');
            if (exclamationIcon) exclamationIcon.classList.add('d-none');
            
            if (field.value.trim() === '') {
                // Empty field - no validation state
                return;
            }
            
            let isValid = true;
            
            // Field-specific validation
            if (field.name === 'blood_pressure') {
                isValid = /^\d{2,3}\/\d{2,3}$/.test(field.value);
            } else if (field.name === 'blood_sugar') {
                const value = parseFloat(field.value);
                isValid = !isNaN(value) && value >= 0 && value <= 500;
            } else if (field.name === 'pulse') {
                const value = parseInt(field.value);
                isValid = !isNaN(value) && value >= 0 && value <= 200;
            } else if (field.name === 'weight') {
                const value = parseFloat(field.value);
                isValid = !isNaN(value) && value >= 0 && value <= 300;
            } else if (field.name === 'temperature') {
                const value = parseFloat(field.value);
                isValid = !isNaN(value) && value >= 30 && value <= 45;
            } else if (field.name === 'oxygen_saturation') {
                const value = parseInt(field.value);
                isValid = !isNaN(value) && value >= 0 && value <= 100;
            } else if (field.name === 'resident_id') {
                isValid = field.value !== '';
            }
            
            // Apply validation state
            if (isValid) {
                field.classList.add('is-valid');
                if (checkIcon) checkIcon.classList.remove('d-none');
            } else {
                field.classList.add('is-invalid');
                if (exclamationIcon) exclamationIcon.classList.remove('d-none');
            }
        }
        
        function checkAtLeastOneMetric() {
            const hasData = Array.from(healthInputs).some(input => input.value.trim() !== '');
            const residentSelected = document.getElementById('resident_id').value !== '';
            
            if (!hasData && residentSelected) {
                // Show warning but don't prevent form submission yet
                console.log('Please provide at least one health metric');
            }
        }
        
        function showCustomAlert(message) {
            // Create a custom alert div
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-warning alert-dismissible fade show';
            alertDiv.innerHTML = `
                <i class="fas fa-exclamation-triangle me-2"></i>${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            // Insert after the form header
            const formBody = document.querySelector('.form-body');
            const existingAlert = formBody.querySelector('.alert-warning');
            if (existingAlert) {
                existingAlert.remove();
            }
            formBody.insertBefore(alertDiv, formBody.firstChild);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }
        
        // Initialize validation on page load for pre-filled values
        healthInputs.forEach(input => {
            if (input.value.trim() !== '') {
                validateField(input);
            }
        });
        if (document.getElementById('resident_id').value !== '') {
            validateField(document.getElementById('resident_id'));
        }
    });
</script>

</body>
</html>