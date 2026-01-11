<?php
session_start();
include '../db_connection.php';

// Check if user is logged in and is an elderly resident
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];

// Get resident's basic info
$stmt = $conn->prepare("
    SELECT 
        r.emergency_contact,
        r.medical_conditions,
        TIMESTAMPDIFF(YEAR, r.dob, CURDATE()) AS age
    FROM residents r
    WHERE r.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$resident_info = $stmt->get_result()->fetch_assoc();

if (!$resident_info) {
    $resident_info = [
        'age' => null,
        'emergency_contact' => null,
        'medical_conditions' => null
    ];
}

// Get recent health logs (last 7 days)
$stmt = $conn->prepare("
    SELECT 
        blood_pressure_systolic	,
        blood_pressure_diastolic,
        blood_sugar,
        pulse,
        weight,
        DATE_FORMAT(logged_at, '%b %d') AS date
    FROM health_logs
    WHERE resident_id = ? 
      AND logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY logged_at DESC
    LIMIT 7
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$health_logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get today's routines
$today = date('l'); // Monday, Tuesday, etc.

$stmt = $conn->prepare("
    SELECT 
        r.routine_type,
        r.description,
        r.schedule_time,
        r.status,
        c.full_name AS caregiver_name
    FROM routines r
    JOIN users c ON r.caregiver_id = c.user_id
    WHERE r.resident_id = ?
      AND (
            r.days_of_week IS NULL
            OR FIND_IN_SET(?, r.days_of_week)
          )
    ORDER BY r.schedule_time
");
$stmt->bind_param("is", $user_id, $today);
$stmt->execute();
$today_routines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get upcoming appointments
$stmt = $conn->prepare("
    SELECT 
        title,
        description,
        appointment_date,
        TIME_FORMAT(appointment_time, '%h:%i %p') AS time,
        location,
        status
    FROM appointments 
    WHERE resident_id = ? 
      AND appointment_date >= CURDATE()
    ORDER BY appointment_date, appointment_time
    LIMIT 5
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$upcoming_appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get unread messages count
$stmt = $conn->prepare("
    SELECT COUNT(*) as unread_count
    FROM messages 
    WHERE receiver_id = ? 
    AND is_read = 0
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$unread_result = $stmt->get_result();
$unread_messages = $unread_result->fetch_assoc()['unread_count'];

// Get recent alerts
$stmt = $conn->prepare("
    SELECT alert_message, alert_type, DATE_FORMAT(created_at, '%b %d, %h:%i %p') as time
    FROM alerts 
    WHERE resident_id = ? 
    AND resolved = 0
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_alerts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get medications for today
$stmt = $conn->prepare("
    SELECT medication_name, dosage, frequency, TIME_FORMAT(medication_time, '%h:%i %p') as time, taken
    FROM medications 
    WHERE resident_id = ? 
    AND medication_date = CURDATE()
    ORDER BY medication_time
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$today_medications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get wellness tips
$wellness_tips = [
    "Stay hydrated throughout the day",
    "Take short walks for better circulation",
    "Practice deep breathing exercises",
    "Maintain a regular sleep schedule",
    "Engage in light stretching daily"
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Quicksand:wght@300;400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" rel="stylesheet">
    <style>
        :root {
            --sage-green: #87A96B;
            --mint-cream: #F0FFF0;
            --seafoam: #9FE2BF;
            --forest-mist: #B8E0D2;
            --dusty-teal: #6D9B8E;
            --deep-emerald: #4A766E;
            --calm-blue: #B8E0FF;
            --soft-purple: #D8BFD8;
        }
        
        body {
            font-family: 'Quicksand', sans-serif;
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--calm-blue) 100%);
            min-height: 100vh;
            color: #5A5A5A;
            font-size: 16px;
            line-height: 1.6;
        }
        
        h1, h2, h3, h4, h5 {
            font-family: 'Playfair Display', serif;
            color: var(--deep-emerald);
        }
        
        .brand-font {
            font-family: 'Jost', sans-serif;
            font-weight: 600;
        }
        
        .elder-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 30px 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .welcome-section {
            text-align: center;
            padding: 20px 0;
        }
        
        .welcome-icon {
            font-size: 4rem;
            color: var(--deep-emerald);
            margin-bottom: 20px;
            animation: gentleFloat 4s ease-in-out infinite;
        }
        
        @keyframes gentleFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .dashboard-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(0,0,0,0.12);
        }
        
        .card-icon {
            font-size: 2.5rem;
            color: var(--sage-green);
            margin-bottom: 15px;
        }
        
        .health-metric {
            background: linear-gradient(135deg, var(--forest-mist), var(--seafoam));
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            margin: 10px 0;
            color: var(--deep-emerald);
        }
        
        .metric-value {
            font-size: 2.2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .metric-label {
            font-size: 14px;
            opacity: 0.8;
        }
        
        .routine-item {
            background: var(--mint-cream);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 12px;
            border-left: 4px solid var(--sage-green);
            transition: all 0.3s ease;
        }
        
        .routine-item:hover {
            background: white;
            transform: translateX(5px);
        }
        
        .routine-time {
            color: var(--dusty-teal);
            font-weight: bold;
            font-size: 14px;
        }
        
        .routine-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-pending { background: #FFF3CD; color: #856404; }
        .status-completed { background: #D1E7DD; color: #0F5132; }
        .status-missed { background: #F8D7DA; color: #721C24; }
        
        .medication-card {
            background: linear-gradient(135deg, #E8F4FD, #D1ECF1);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid #BEE5EB;
        }
        
        .medication-time {
            color: var(--deep-emerald);
            font-weight: bold;
            font-size: 18px;
        }
        
        .alert-badge {
            background: linear-gradient(135deg, #FF6B6B, #FF8E8E);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .wellness-tip {
            background: linear-gradient(135deg, var(--soft-purple), #E6E6FA);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            color: #5D4A66;
            border-left: 5px solid var(--sage-green);
        }
        
        .emergency-contact {
            background: linear-gradient(135deg, #FFE5E5, #FFCCCC);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            color: #721C24;
            border: 2px solid #FF6B6B;
        }
        
        .btn-large {
            padding: 15px 30px;
            font-size: 18px;
            border-radius: 50px;
            font-weight: 600;
            margin: 10px 5px;
        }
        
        .btn-call {
            background: linear-gradient(135deg, #4CAF50, #45a049);
            border: none;
            color: white;
        }
        
        .btn-message {
            background: linear-gradient(135deg, #2196F3, #0b7dda);
            border: none;
            color: white;
        }
        
        .btn-help {
            background: linear-gradient(135deg, #FF9800, #f57c00);
            border: none;
            color: white;
        }
        
        .nav-btn {
            display: block;
            width: 100%;
            padding: 15px;
            margin-bottom: 10px;
            text-align: left;
            background: white;
            border: none;
            border-radius: 12px;
            color: var(--deep-emerald);
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .nav-btn:hover {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            transform: translateX(10px);
        }
        
        .nav-btn i {
            width: 30px;
            text-align: center;
            margin-right: 10px;
        }
        
        .quick-actions {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .action-btn {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            border: none;
            font-size: 20px;
            box-shadow: 0 4px 15px rgba(135, 169, 107, 0.4);
            transition: all 0.3s ease;
            margin-bottom: 10px;
        }
        
        .action-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(135, 169, 107, 0.6);
        }
        
        .message-bubble {
            background: white;
            border-radius: 20px;
            padding: 15px;
            margin: 10px 0;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            max-width: 80%;
        }
        
        .message-bubble.sent {
            margin-left: auto;
            background: linear-gradient(135deg, var(--forest-mist), var(--seafoam));
        }
        
        .time-display {
            font-size: 12px;
            color: var(--dusty-teal);
            margin-top: 5px;
        }
        
        @media (max-width: 768px) {
            .welcome-icon {
                font-size: 3rem;
            }
            
            .dashboard-card {
                padding: 20px;
            }
            
            .btn-large {
                padding: 12px 20px;
                font-size: 16px;
            }
        }
        
        .font-large {
            font-size: 18px;
        }
        
        .high-contrast {
            color: #000000;
        }
        
        .simple-layout {
            background: white !important;
            border: 2px solid var(--forest-mist) !important;
        }
    </style>
</head>
<body>
    <!-- Elder Header -->
    <div class="elder-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-5 fw-bold mb-2">Welcome, <?php echo htmlspecialchars($user_name); ?>!</h1>
                    <p class="mb-0">
                        <i class="fas fa-user-circle me-2"></i>Your Personal Health Dashboard
                        <?php if ($resident_info['age']): ?>
                            <span class="ms-3">
                                <i class="fas fa-birthday-cake me-1"></i><?php echo $resident_info['age']; ?> years
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="d-flex flex-wrap justify-content-md-end gap-2">
                        <button class="btn btn-light btn-sm" onclick="increaseFontSize()">
                            <i class="fas fa-search-plus"></i> Larger
                        </button>

                        <button class="btn btn-light btn-sm" onclick="decreaseFontSize()">
                            <i class="fas fa-search-minus"></i> Smaller
                        </button>

                        <button class="btn btn-light btn-sm" onclick="resetFontSize()">
                            <i class="fas fa-undo"></i> Normal
                        </button>
                        <button class="btn btn-light btn-sm" onclick="toggleHighContrast()">
                            <i class="fas fa-adjust"></i> High Contrast
                        </button>
                        <a href="/SmartCareGuardian/logout.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Dashboard -->
    <div class="container py-4">
        <div class="row">
            <!-- Left Navigation - Simplified for Elderly -->
            <div class="col-lg-3 mb-4">
                <div class="dashboard-card simple-layout">
                    <h4 class="brand-font mb-4">
                        <i class="fas fa-compass me-2"></i>Quick Access
                    </h4>

                    <button class="nav-btn" onclick="location.href='elder_profile.php'">
                        <i class="fas fa-user-circle"></i> My Profile
                    </button>
                    
                    <button class="nav-btn" onclick="location.href='elder_health.php'">
                        <i class="fas fa-heartbeat"></i> My Health Data
                    </button>
                    
                    <button class="nav-btn" onclick="location.href='elder_schedule.php'">
                        <i class="fas fa-calendar-alt"></i> Daily Schedule
                    </button>

                    <button class="nav-btn" onclick="location.href='elder_medications.php'">
                        <i class="fas fa-pills"></i> My Medications
                    </button>
                    
                    <button class="nav-btn" onclick="location.href='elder_messages.php'">
                        <i class="fas fa-comments"></i> Messages
                        <?php if ($unread_messages > 0): ?>
                            <span class="badge bg-danger float-end"><?php echo $unread_messages; ?></span>
                        <?php endif; ?>
                    </button>
                    
                    <hr class="my-4">
                    
                    <!-- Emergency Contact -->
                    <div class="emergency-contact mt-4">
                        <h5 class="brand-font">
                            <i class="fas fa-phone-alt me-2"></i>Emergency Contact
                        </h5>
                        <p class="mb-2 font-large"><?php echo htmlspecialchars($resident_info['emergency_contact'] ?? 'Not set'); ?></p>
                        <button class="btn btn-danger btn-sm mt-2">
                            <i class="fas fa-phone me-1"></i> Call Now
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Main Content Area -->
            <div class="col-lg-9">
                <div class="row">
                    <!-- Welcome & Health Overview -->
                    <div class="col-md-12 mb-4">
                        <div class="dashboard-card">
                            <div class="welcome-section">
                                <div class="welcome-icon">
                                    <i class="fas fa-shield-heart"></i>
                                </div>
                                <h3 class="brand-font mb-3">Your Health & Wellness</h3>
                                <p class="mb-4">SmartCare Guardian is monitoring your health and helping you maintain your wellness routine.</p>
                                
                                <!-- Health Metrics Overview -->
                                <div class="row mt-4">
                                    <?php if (!empty($health_logs)): 
                                        $latest_health = $health_logs[0];
                                    ?>
                                        <div class="col-md-3 col-6">
                                            <div class="health-metric">
                                                <div class="metric-value">
                                                    <?php
                                                    if (!empty($latest_health['bp_systolic']) && !empty($latest_health['bp_diastolic'])) {
                                                        echo htmlspecialchars($latest_health['bp_systolic']) . '/' . htmlspecialchars($latest_health['bp_diastolic']);
                                                    } else {
                                                        echo '--';
                                                    }
                                                    ?>
                                                </div>
                                                <div class="metric-label">Blood Pressure (mmHg)</div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="health-metric">
                                                <div class="metric-value"><?php echo htmlspecialchars($latest_health['blood_sugar'] ?? '--'); ?></div>
                                                <div class="metric-label">Blood Sugar</div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="health-metric">
                                                <div class="metric-value"><?php echo htmlspecialchars($latest_health['pulse'] ?? '--'); ?> bpm</div>
                                                <div class="metric-label">Heart Rate</div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 col-6">
                                            <div class="health-metric">
                                                <div class="metric-value"><?php echo htmlspecialchars($latest_health['weight'] ?? '--'); ?> kg</div>
                                                <div class="metric-label">Weight</div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="col-12 text-center">
                                            <p class="text-muted">No recent health data available.</p>
                                            <p class="small">Your caregiver will update your health metrics regularly.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Today's Schedule -->
                    <div class="col-md-6 mb-4">
                        <div class="dashboard-card">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h4 class="brand-font mb-0">
                                    <i class="fas fa-calendar-day me-2"></i>Today's Schedule
                                </h4>
                                <span class="badge bg-primary">
                                    <?php echo date('F j, Y'); ?>
                                </span>
                            </div>
                            
                            <?php if (!empty($today_routines)): ?>
                                <?php foreach ($today_routines as $routine): ?>
                                    <div class="routine-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h5 class="mb-1">
                                                    <?php echo ucfirst(str_replace('_', ' ', $routine['routine_type'])); ?>
                                                </h5>
                                                <p class="small text-muted mb-1">
                                                    <?php echo htmlspecialchars($routine['description']); ?>
                                                </p>
                                                <div class="routine-time">
                                                    <i class="far fa-clock me-1"></i><?php echo date('h:i A', strtotime($routine['schedule_time'])); ?>
                                                </div>
                                                <p class="small mb-0 text-muted">
                                                    With: <?php echo htmlspecialchars($routine['caregiver_name']); ?>
                                                </p>
                                            </div>
                                            <span class="routine-status status-<?php echo strtolower($routine['status']); ?>">
                                                <?php echo $routine['status']; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-calendar-check fa-2x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No scheduled routines for today.</p>
                                </div>
                            <?php endif; ?>
                            
                            <button class="btn btn-outline-primary w-100 mt-3" onclick="location.href='elder_schedule.php'">
                                <i class="fas fa-plus me-1"></i> View Full Schedule
                            </button>
                        </div>
                    </div>
                    
                    <!-- Medications for Today -->
                    <div class="col-md-6 mb-4">
                        <div class="dashboard-card">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h4 class="brand-font mb-0">
                                    <i class="fas fa-pills me-2"></i>Today's Medications
                                </h4>
                                <span class="badge bg-success"><?php echo count($today_medications); ?> medications</span>
                            </div>
                            
                            <?php if (!empty($today_medications)): ?>
                                <?php foreach ($today_medications as $med): ?>
                                    <div class="medication-card">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="mb-1"><?php echo htmlspecialchars($med['medication_name']); ?></h5>
                                                <p class="mb-1">Dosage: <?php echo htmlspecialchars($med['dosage']); ?></p>
                                                <p class="mb-0 small text-muted">Frequency: <?php echo htmlspecialchars($med['frequency']); ?></p>
                                            </div>
                                            <div class="text-end">
                                                <div class="medication-time"><?php echo $med['time']; ?></div>
                                                <?php if ($med['taken']): ?>
                                                    <span class="badge bg-success">Taken</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Pending</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-prescription-bottle-alt fa-2x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">No medications scheduled for today.</p>
                                </div>
                            <?php endif; ?>
                            
                            <button class="btn btn-outline-success w-100 mt-3" onclick="location.href='elder_medications.php'">
                                <i class="fas fa-history me-1"></i> Medication History
                            </button>
                        </div>
                    </div>
                    
                    <!-- Recent Alerts -->
                    <div class="col-md-6 mb-4">
                        <div class="dashboard-card">
                            <h4 class="brand-font mb-4">
                                <i class="fas fa-bell me-2"></i>Health Alerts
                            </h4>
                            
                            <?php if (!empty($recent_alerts)): ?>
                                <?php foreach ($recent_alerts as $alert): ?>
                                    <div class="alert-item mb-3 p-3 rounded" style="background: #FFF3CD; border-left: 4px solid #FFC107;">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <span class="alert-badge"><?php echo strtoupper($alert['alert_type']); ?></span>
                                                <p class="mb-1"><?php echo htmlspecialchars($alert['alert_message']); ?></p>
                                                <small class="text-muted">
                                                    <i class="far fa-clock me-1"></i><?php echo $alert['time']; ?>
                                                </small>
                                            </div>
                                            <i class="fas fa-exclamation-triangle text-warning"></i>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-check-circle fa-2x text-success mb-3"></i>
                                    <p class="text-success mb-0">All clear! No recent alerts.</p>
                                </div>
                            <?php endif; ?>
                            
                            <button class="btn btn-outline-warning w-100" onclick="location.href='elder_alerts.php'">
                                <i class="fas fa-list me-1"></i> View All Alerts
                            </button>
                        </div>
                    </div>
                    
                    <!-- Wellness Tips -->
                    <div class="col-md-6 mb-4">
                        <div class="dashboard-card">
                            <h4 class="brand-font mb-4">
                                <i class="fas fa-spa me-2"></i>Daily Wellness Tips
                            </h4>
                            
                            <?php foreach ($wellness_tips as $index => $tip): ?>
                                <div class="wellness-tip">
                                    <div class="d-flex align-items-start">
                                        <span class="badge bg-primary rounded-circle me-3" style="width: 30px; height: 30px; line-height: 30px;">
                                            <?php echo $index + 1; ?>
                                        </span>
                                        <p class="mb-0 font-large"><?php echo $tip; ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <button class="btn btn-outline-info w-100 mt-3" onclick="showMoreTips()">
                                <i class="fas fa-lightbulb me-1"></i> More Wellness Tips
                            </button>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="col-12 mb-4">
                        <div class="dashboard-card">
                            <h4 class="brand-font mb-4">
                                <i class="fas fa-bolt me-2"></i>Quick Actions
                            </h4>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <button class="btn btn-call btn-large w-100" onclick="callCaregiver()">
                                        <i class="fas fa-phone-alt me-2"></i>Call Caregiver
                                    </button>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <button class="btn btn-message btn-large w-100" onclick="location.href='elder_messages.php'">
                                        <i class="fas fa-comment-medical me-2"></i>Send Message
                                        <?php if ($unread_messages > 0): ?>
                                            <span class="badge bg-danger ms-2"><?php echo $unread_messages; ?> new</span>
                                        <?php endif; ?>
                                    </button>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <button class="btn btn-help btn-large w-100" onclick="requestHelp()">
                                        <i class="fas fa-hands-helping me-2"></i>Request Help
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Floating Action Buttons -->
    <div class="quick-actions">
        <button class="action-btn" onclick="callEmergency()" title="Emergency Call">
            <i class="fas fa-phone-alt"></i>
        </button>
        <button class="action-btn" onclick="location.href='elder_messages.php'" title="Messages">
            <i class="fas fa-comments">
                <?php if ($unread_messages > 0): ?>
                    <span class="badge bg-danger position-absolute top-0 start-100 translate-middle" style="font-size: 10px; padding: 2px 4px;">
                        <?php echo $unread_messages; ?>
                    </span>
                <?php endif; ?>
            </i>
        </button>
        <button class="action-btn" onclick="speakPage()" title="Read Aloud">
            <i class="fas fa-volume-up"></i>
        </button>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Accessibility features
       let defaultFontSize = 16;
        let fontSize = parseInt(localStorage.getItem('elderFontSize')) || defaultFontSize;

        document.body.style.fontSize = fontSize + 'px';

        function increaseFontSize() {
            if (fontSize < 24) { // prevent too large text
                fontSize += 2;
                applyFontSize();
                showToast('Text size increased');
            }
        }

        function decreaseFontSize() {
            if (fontSize > 12) { // prevent too small text
                fontSize -= 2;
                applyFontSize();
                showToast('Text size decreased');
            }
        }

        function resetFontSize() {
            fontSize = defaultFontSize;
            applyFontSize();
            showToast('Text size reset to normal');
        }

        function applyFontSize() {
            document.body.style.fontSize = fontSize + 'px';
            localStorage.setItem('elderFontSize', fontSize);
        }
        
        function toggleHighContrast() {
            document.body.classList.toggle('high-contrast');
            const isActive = document.body.classList.contains('high-contrast');
            localStorage.setItem('elderHighContrast', isActive);
            showToast(isActive ? 'High contrast enabled' : 'High contrast disabled');
        }
        
        function speakPage() {
            if ('speechSynthesis' in window) {
                const speech = new SpeechSynthesisUtterance();
                speech.text = "Welcome to your SmartCare Guardian dashboard. Here you can see your health information, daily schedule, and medications.";
                speech.rate = 0.9;
                speech.pitch = 1;
                window.speechSynthesis.speak(speech);
                showToast('Reading page content');
            } else {
                showToast('Text-to-speech not supported');
            }
        }
        
        function callCaregiver() {
            showToast('Calling your assigned caregiver...');
            // In real implementation, this would initiate a phone call
        }
        
        function callEmergency() {
            if (confirm('Are you sure you want to make an emergency call?')) {
                showToast('Connecting to emergency services...');
                // In real implementation, this would dial emergency number
            }
        }
        
        function requestHelp() {
            showToast('Help request sent to caregivers');
            // In real implementation, this would send a help request to caregivers
        }
        
        function showMoreTips() {
            const tips = [
                "Practice gratitude by listing 3 things you're thankful for",
                "Stay connected with family and friends",
                "Enjoy a balanced diet with plenty of fruits and vegetables",
                "Listen to calming music or nature sounds",
                "Keep your mind active with puzzles or reading"
            ];
            const randomTip = tips[Math.floor(Math.random() * tips.length)];
            alert('💡 Wellness Tip: ' + randomTip);
        }
        
        function showToast(message) {
            // Create toast element
            const toast = document.createElement('div');
            toast.className = 'position-fixed bottom-0 end-0 p-3';
            toast.innerHTML = `
                <div class="toast show" role="alert">
                    <div class="toast-body">
                        <i class="fas fa-check-circle text-success me-2"></i>
                        ${message}
                    </div>
                </div>
            `;
            document.body.appendChild(toast);
            
            // Remove after 3 seconds
            setTimeout(() => {
                toast.remove();
            }, 3000);
        }
        
        // Load saved preferences
        document.addEventListener('DOMContentLoaded', function() {
            const savedFontSize = localStorage.getItem('elderFontSize');
            const savedHighContrast = localStorage.getItem('elderHighContrast');
            
            if (savedFontSize) {
                fontSize = parseInt(savedFontSize);
                document.body.style.fontSize = fontSize + 'px';
            }
            
            if (savedHighContrast === 'true') {
                document.body.classList.add('high-contrast');
            }
            
            // Auto-read welcome message for first visit
            if (!localStorage.getItem('elderFirstVisit')) {
                setTimeout(speakPage, 1000);
                localStorage.setItem('elderFirstVisit', 'true');
            }
        });
        
        // Auto-update every 5 minutes for real-time data
        setInterval(() => {
            // Refresh only the data sections
            location.reload();
        }, 300000); // 5 minutes
    </script>
</body>
</html>