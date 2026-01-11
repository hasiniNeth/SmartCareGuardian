<?php
session_start();
include '../db_connection.php';

// Check if user is logged in and is an elderly resident
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Get existing user data
$user_stmt = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();

// Get existing resident profile if exists
$profile_stmt = $conn->prepare("SELECT * FROM residents WHERE user_id = ?");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$profile_result = $profile_stmt->get_result();
$profile_data = $profile_result->fetch_assoc();

// Ensure profile_data is always an array
if (!$profile_data) {
    $profile_data = [
        'resident_id' => null,
        'phone' => null,
        'address' => null,
        'gender' => null,
        'dob' => null,
        'emergency_contact' => null,
        'medical_conditions' => null,
        'blood_type' => null,
        'primary_physician' => null,
        'allergies' => null,
        'dietary_restrictions' => null
    ];
}

// NOW define this safely
$has_profile = !empty($profile_data['resident_id']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $gender = $_POST['gender'];
    $dob = $_POST['dob'];
    $emergency_contact = trim($_POST['emergency_contact']);
    $medical_conditions = trim($_POST['medical_conditions']);
    $blood_type = $_POST['blood_type'];
    $primary_physician = trim($_POST['primary_physician']);
    $allergies = trim($_POST['allergies']);
    $dietary_restrictions = trim($_POST['dietary_restrictions']);
    
    // Validate required fields
    if (empty($phone) || empty($emergency_contact) || empty($dob)) {
        $message = "Please fill in all required fields.";
        $message_type = "error";
    } else {
        if ($has_profile) {
            // Update existing profile
            $update_stmt = $conn->prepare("
                UPDATE residents 
                SET phone = ?, address = ?, gender = ?, dob = ?, 
                    emergency_contact = ?, medical_conditions = ?, 
                    blood_type = ?, primary_physician = ?, allergies = ?,
                    dietary_restrictions = ?, updated_at = NOW() 
                WHERE user_id = ?
            ");
            $update_stmt->bind_param("ssssssssssi", 
                $phone, $address, $gender, $dob, $emergency_contact, 
                $medical_conditions, $blood_type, $primary_physician,
                $allergies, $dietary_restrictions, $user_id
            );
            
            if ($update_stmt->execute()) {
                $message = "Profile updated successfully!";
                $message_type = "success";
                // Refresh profile data
                $profile_stmt->execute();
                $profile_result = $profile_stmt->get_result();
                $profile_data = $profile_result->fetch_assoc();
            } else {
                $message = "Error updating profile. Please try again.";
                $message_type = "error";
            }
        } else {
            // Insert new profile
            $insert_stmt = $conn->prepare("
                INSERT INTO residents 
                (user_id, phone, address, gender, dob, emergency_contact, 
                 medical_conditions, blood_type, primary_physician, allergies,
                 dietary_restrictions, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insert_stmt->bind_param("issssssssss", 
                $user_id, $phone, $address, $gender, $dob, $emergency_contact,
                $medical_conditions, $blood_type, $primary_physician,
                $allergies, $dietary_restrictions
            );
            
            if ($insert_stmt->execute()) {
                $message = "Profile created successfully!";
                $message_type = "success";
                // Refresh profile data
                $profile_stmt->execute();
                $profile_result = $profile_stmt->get_result();
                $profile_data = $profile_result->fetch_assoc();
            } else {
                $message = "Error creating profile. Please try again.";
                $message_type = "error";
            }
        }
    }
}

// Get assigned caregiver info
$caregiver_info = null;
if ($has_profile) {
    $caregiver_stmt = $conn->prepare("
        SELECT u.full_name, u.email, c.phone, c.experience_years, c.skills
        FROM caregiver_assignments ca
        JOIN users u ON ca.caregiver_id = u.user_id
        JOIN caregivers c ON c.user_id = u.user_id
        WHERE ca.resident_id = ?
        ORDER BY ca.assigned_at DESC
        LIMIT 1
    ");
    $caregiver_stmt->bind_param("i", $profile_data['resident_id']);
    $caregiver_stmt->execute();
    $caregiver_result = $caregiver_stmt->get_result();
    $caregiver_info = $caregiver_result->fetch_assoc();
}

// Calculate age if DOB exists
$age = null;
if (!empty($profile_data['dob'])) {
    $dob = new DateTime($profile_data['dob']);
    $age = (new DateTime())->diff($dob)->y;
} else {
    $age = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - SmartCare Guardian</title>
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
            --calm-blue: #B8E0FF;
        }
        
        body {
            font-family: 'Quicksand', sans-serif;
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--calm-blue) 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-size: 16px;
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
            font-size: 16px;
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
            font-size: 16px;
        }
        
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
        }

        /* Profile Container */
        .profile-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .profile-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .profile-body {
            padding: 40px;
        }

        /* Form Styling */
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
        
        .form-label {
            color: var(--deep-emerald);
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .required::after {
            content: " *";
            color: #ff6b6b;
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

        /* Button Styling */
        .btn-primary {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            padding: 15px 30px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--dusty-teal), var(--sage-green));
            transition: left 0.4s ease;
        }
        
        .btn-primary:hover::before {
            left: 0;
        }
        
        .btn-primary span {
            position: relative;
            z-index: 2;
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(141, 182, 154, 0.4);
        }
        
        .btn-secondary {
            background: transparent;
            border: 2px solid var(--dusty-teal);
            color: var(--dusty-teal);
            padding: 15px 30px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 16px;
        }
        
        .btn-secondary:hover {
            background: var(--dusty-teal);
            color: white;
            transform: translateY(-2px);
        }

        /* Alerts */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
            font-size: 16px;
        }
        
        .alert-success {
            background: linear-gradient(135deg, var(--seafoam), var(--sage-green));
            color: white;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
        }

        /* Profile Completion Status */
        .completion-status {
            background: var(--light-sage);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            border-left: 4px solid var(--sage-green);
        }
        
        .completion-progress {
            height: 10px;
            background: var(--forest-mist);
            border-radius: 5px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .completion-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--sage-green), var(--dusty-teal));
            border-radius: 5px;
            transition: width 0.5s ease;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0 auto 20px auto;
        }
        
        /* Medical Info Cards */
        .medical-card {
            background: var(--light-sage);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid var(--sage-green);
        }
        
        .caregiver-card {
            background: linear-gradient(135deg, #E8F4FD, #D1ECF1);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            border: 1px solid #BEE5EB;
        }
        
        .emergency-card {
            background: linear-gradient(135deg, #FFE5E5, #FFCCCC);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            border: 2px solid #FF6B6B;
        }
        
        .info-item {
            margin-bottom: 15px;
        }
        
        .info-label {
            color: var(--dusty-teal);
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .info-value {
            color: var(--deep-emerald);
            font-size: 16px;
            font-weight: 500;
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
                padding: 20px;
            }
            
            .profile-body {
                padding: 20px;
            }
            
            body {
                font-size: 18px; /* Larger font for mobile */
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
        
        /* Accessibility: Larger click areas */
        .form-control, .form-select, .btn-primary, .btn-secondary {
            min-height: 48px;
        }
        
        .sidebar a {
            min-height: 50px;
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-header">
        <h4 class="brand-font mb-2"><i class="fa-solid fa-shield-heart me-2"></i>SmartCare Guardian</h4>
        <small class="opacity-75">Resident Portal</small>
    </div>
    
    <div class="sidebar-nav">
        <a href="resident_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="#" class="active"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="elder_health.php"><i class="fa-solid fa-heart-pulse"></i> Health Data</a>
        <a href="elder_schedule.php"><i class="fa-solid fa-calendar-check"></i> Daily Schedule</a>
        <a href="elder_medications.php"><i class="fa-solid fa-pills"></i> My Medications</a>
        <a href="elder_messages.php"><i class="fa-solid fa-comments"></i> Messages</a>
        <a href="elder_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
    </div>
    
    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-sidebar">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>
</div>

<!-- Main Content -->
<div class="content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div>
            <h4 class="brand-font mb-1">My Profile</h4>
            <p class="text-muted mb-0">Manage your personal and medical information</p>
        </div>
        <a href="../logout.php" class="logout-btn">
            <i class="fa-solid fa-sign-out-alt me-2"></i>Logout
        </a>
    </div>

    <!-- Profile Completion Status -->
    <div class="completion-status">
        <h5 class="brand-font">Profile Completion</h5>
        <div class="completion-progress">
            <?php
            $completion = 0;
            if ($profile_data) {
                $fields = ['phone', 'emergency_contact', 'dob', 'blood_type'];
                $filled = 0;
                foreach ($fields as $field) {
                    if (!empty($profile_data[$field])) $filled++;
                }
                $completion = ($filled / count($fields)) * 100;
            }
            ?>
            <div class="completion-bar" style="width: <?php echo $completion; ?>%"></div>
        </div>
        <p class="mb-0">
            <?php if ($completion == 100): ?>
                <i class="fas fa-check-circle text-success me-2"></i>Your profile is complete!
            <?php else: ?>
                <i class="fas fa-exclamation-circle text-warning me-2"></i>Please complete your profile for better care.
            <?php endif; ?>
        </p>
    </div>

    <div class="profile-container">
        <div class="profile-header">
            <div class="profile-avatar">
                <?php echo strtoupper(substr($user_data['full_name'], 0, 1)); ?>
            </div>
            <h4 class="brand-font mb-2"><?php echo htmlspecialchars($user_data['full_name']); ?></h4>
            <p class="mb-0"><?php echo htmlspecialchars($user_data['email']); ?></p>
            <?php if ($age): ?>
                <p class="mb-0 mt-2">
                    <i class="fas fa-birthday-cake me-1"></i><?php echo $age; ?> years old
                </p>
            <?php endif; ?>
        </div>
        
        <div class="profile-body">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <!-- View Mode (when not editing) -->
            <div id="viewMode" style="<?php echo isset($_GET['edit']) ? 'display: none;' : ''; ?>">
                <div class="row">
                    <!-- Personal Information -->
                    <div class="col-md-6 mb-4">
                        <div class="medical-card">
                            <h5 class="brand-font mb-4">
                                <i class="fas fa-user me-2"></i>Personal Information
                            </h5>
                            
                            <div class="info-item">
                                <div class="info-label">Phone Number</div>
                                <div class="info-value">
                                    <?php echo $profile_data['phone'] ?? '<span class="text-muted">Not provided</span>'; ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Address</div>
                                <div class="info-value">
                                    <?php echo $profile_data['address'] ?? '<span class="text-muted">Not provided</span>'; ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Gender</div>
                                <div class="info-value">
                                    <?php echo $profile_data['gender'] ?? '<span class="text-muted">Not specified</span>'; ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Date of Birth</div>
                                <div class="info-value">
                                    <?php 
                                    if ($profile_data['dob']) {
                                        echo date('F d, Y', strtotime($profile_data['dob'])) . ' (' . $age . ' years)';
                                    } else {
                                        echo '<span class="text-muted">Not provided</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Medical Information -->
                    <div class="col-md-6 mb-4">
                        <div class="medical-card">
                            <h5 class="brand-font mb-4">
                                <i class="fas fa-heartbeat me-2"></i>Medical Information
                            </h5>
                            
                            <div class="info-item">
                                <div class="info-label">Blood Type</div>
                                <div class="info-value">
                                    <?php echo $profile_data['blood_type'] ?? '<span class="text-muted">Not recorded</span>'; ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Primary Physician</div>
                                <div class="info-value">
                                    <?php echo $profile_data['primary_physician'] ?? '<span class="text-muted">Not assigned</span>'; ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Allergies</div>
                                <div class="info-value">
                                    <?php echo $profile_data['allergies'] ?? '<span class="text-muted">No known allergies</span>'; ?>
                                </div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label">Dietary Restrictions</div>
                                <div class="info-value">
                                    <?php echo $profile_data['dietary_restrictions'] ?? '<span class="text-muted">No restrictions</span>'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Emergency Contact -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="emergency-card">
                            <h5 class="brand-font mb-3">
                                <i class="fas fa-phone-alt me-2"></i>Emergency Contact
                            </h5>
                            <p class="fs-5 mb-3">
                                <?php echo $profile_data['emergency_contact'] ?? '<span class="text-muted">No emergency contact set</span>'; ?>
                            </p>
                            <?php if ($profile_data['emergency_contact']): ?>
                                <button class="btn btn-danger" onclick="callEmergency()">
                                    <i class="fas fa-phone me-1"></i> Call Emergency
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Medical Conditions -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="medical-card">
                            <h5 class="brand-font mb-3">Medical Conditions</h5>
                            <?php if ($profile_data['medical_conditions']): ?>
                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($profile_data['medical_conditions'])); ?></p>
                            <?php else: ?>
                                <p class="text-muted mb-0">No medical conditions recorded</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Assigned Caregiver -->
                <?php if ($caregiver_info): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="caregiver-card">
                            <h5 class="brand-font mb-3">
                                <i class="fas fa-user-nurse me-2"></i>Assigned Caregiver
                            </h5>
                            <div class="row">
                                <div class="col-md-8">
                                    <p class="mb-2"><strong><?php echo htmlspecialchars($caregiver_info['full_name']); ?></strong></p>
                                    <p class="mb-2">
                                        <i class="fas fa-envelope me-2 text-muted"></i>
                                        <?php echo htmlspecialchars($caregiver_info['email']); ?>
                                    </p>
                                    <p class="mb-2">
                                        <i class="fas fa-phone me-2 text-muted"></i>
                                        <?php echo htmlspecialchars($caregiver_info['phone']); ?>
                                    </p>
                                    <p class="mb-2">
                                        <i class="fas fa-briefcase me-2 text-muted"></i>
                                        <?php echo htmlspecialchars($caregiver_info['experience_years']); ?> years experience
                                    </p>
                                    <p class="mb-0">
                                        <i class="fas fa-tools me-2 text-muted"></i>
                                        Skills: <?php echo htmlspecialchars($caregiver_info['skills']); ?>
                                    </p>
                                </div>
                                <div class="col-md-4 text-md-end">
                                    <button class="btn btn-primary mb-2" onclick="sendMessageToCaregiver()">
                                        <i class="fas fa-comment-medical me-2"></i>Message
                                    </button>
                                    <button class="btn btn-secondary" onclick="callCaregiver()">
                                        <i class="fas fa-phone-alt me-2"></i>Call
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Action Buttons -->
                <div class="row">
                    <div class="col-md-6">
                        <button class="btn btn-primary w-100" onclick="toggleEditMode()">
                            <i class="fas fa-edit me-2"></i>Edit Profile
                        </button>
                    </div>
                    <div class="col-md-6">
                        <a href="elder_dashboard.php" class="btn btn-secondary w-100">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Edit Mode -->
            <div id="editMode" style="display: none;">
                <form method="POST" id="profileForm">
                    <div class="row">
                        <!-- Personal Information -->
                        <div class="col-md-6">
                            <h5 class="brand-font mb-4">
                                <i class="fas fa-user me-2"></i>Personal Information
                            </h5>
                            
                            <div class="mb-3">
                                <label for="phone" class="form-label required">
                                    <i class="fas fa-phone me-2"></i>Phone Number
                                </label>
                                <div class="input-group-icon">
                                    <i class="fas fa-phone"></i>
                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                           value="<?php echo htmlspecialchars($profile_data['phone'] ?? ''); ?>" 
                                           placeholder="Enter your phone number" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="address" class="form-label">
                                    <i class="fas fa-home me-2"></i>Address
                                </label>
                                <div class="input-group-icon">
                                    <i class="fas fa-home"></i>
                                    <textarea class="form-control" id="address" name="address" 
                                              rows="3" placeholder="Enter your full address"><?php echo htmlspecialchars($profile_data['address'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="gender" class="form-label">
                                    <i class="fas fa-venus-mars me-2"></i>Gender
                                </label>
                                <select class="form-select" id="gender" name="gender">
                                    <option value="">Select Gender</option>
                                    <option value="Male" <?php echo ($profile_data['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo ($profile_data['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo ($profile_data['gender'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="dob" class="form-label required">
                                    <i class="fas fa-birthday-cake me-2"></i>Date of Birth
                                </label>
                                <div class="input-group-icon">
                                    <i class="fas fa-calendar"></i>
                                    <input type="date" class="form-control" id="dob" name="dob" 
                                           value="<?php echo htmlspecialchars($profile_data['dob'] ?? ''); ?>" 
                                           max="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Medical Information -->
                        <div class="col-md-6">
                            <h5 class="brand-font mb-4">
                                <i class="fas fa-heartbeat me-2"></i>Medical Information
                            </h5>
                            
                            <div class="mb-3">
                                <label for="emergency_contact" class="form-label required">
                                    <i class="fas fa-phone-alt me-2"></i>Emergency Contact
                                </label>
                                <div class="input-group-icon">
                                    <i class="fas fa-phone-alt"></i>
                                    <input type="text" class="form-control" id="emergency_contact" name="emergency_contact" 
                                           value="<?php echo htmlspecialchars($profile_data['emergency_contact'] ?? ''); ?>" 
                                           placeholder="Name and phone number" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="blood_type" class="form-label">
                                    <i class="fas fa-tint me-2"></i>Blood Type
                                </label>
                                <select class="form-select" id="blood_type" name="blood_type">
                                    <option value="">Select Blood Type</option>
                                    <option value="A+" <?php echo ($profile_data['blood_type'] ?? '') == 'A+' ? 'selected' : ''; ?>>A+</option>
                                    <option value="A-" <?php echo ($profile_data['blood_type'] ?? '') == 'A-' ? 'selected' : ''; ?>>A-</option>
                                    <option value="B+" <?php echo ($profile_data['blood_type'] ?? '') == 'B+' ? 'selected' : ''; ?>>B+</option>
                                    <option value="B-" <?php echo ($profile_data['blood_type'] ?? '') == 'B-' ? 'selected' : ''; ?>>B-</option>
                                    <option value="AB+" <?php echo ($profile_data['blood_type'] ?? '') == 'AB+' ? 'selected' : ''; ?>>AB+</option>
                                    <option value="AB-" <?php echo ($profile_data['blood_type'] ?? '') == 'AB-' ? 'selected' : ''; ?>>AB-</option>
                                    <option value="O+" <?php echo ($profile_data['blood_type'] ?? '') == 'O+' ? 'selected' : ''; ?>>O+</option>
                                    <option value="O-" <?php echo ($profile_data['blood_type'] ?? '') == 'O-' ? 'selected' : ''; ?>>O-</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="primary_physician" class="form-label">
                                    <i class="fas fa-user-md me-2"></i>Primary Physician
                                </label>
                                <div class="input-group-icon">
                                    <i class="fas fa-user-md"></i>
                                    <input type="text" class="form-control" id="primary_physician" name="primary_physician" 
                                           value="<?php echo htmlspecialchars($profile_data['primary_physician'] ?? ''); ?>" 
                                           placeholder="Doctor's name">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="allergies" class="form-label">
                                <i class="fas fa-allergies me-2"></i>Allergies
                            </label>
                            <textarea class="form-control" id="allergies" name="allergies" 
                                      rows="3" placeholder="List any allergies (medication, food, etc.)"><?php echo htmlspecialchars($profile_data['allergies'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="dietary_restrictions" class="form-label">
                                <i class="fas fa-utensils me-2"></i>Dietary Restrictions
                            </label>
                            <textarea class="form-control" id="dietary_restrictions" name="dietary_restrictions" 
                                      rows="3" placeholder="List any dietary restrictions"><?php echo htmlspecialchars($profile_data['dietary_restrictions'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="medical_conditions" class="form-label">
                            <i class="fas fa-file-medical me-2"></i>Medical Conditions
                        </label>
                        <textarea class="form-control" id="medical_conditions" name="medical_conditions" 
                                  rows="4" placeholder="Describe any medical conditions, chronic illnesses, or special needs"><?php echo htmlspecialchars($profile_data['medical_conditions'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-save me-2"></i><span>
                                    <?php echo $has_profile ? 'Update Profile' : 'Create Profile'; ?>
                                </span>
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="btn btn-secondary w-100" onclick="toggleEditMode()">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle between view and edit modes
function toggleEditMode() {
    const viewMode = document.getElementById('viewMode');
    const editMode = document.getElementById('editMode');
    
    if (viewMode.style.display === 'none') {
        viewMode.style.display = 'block';
        editMode.style.display = 'none';
    } else {
        viewMode.style.display = 'none';
        editMode.style.display = 'block';
        // Scroll to top of edit form
        window.scrollTo({ top: editMode.offsetTop - 100, behavior: 'smooth' });
    }
}

// Emergency call function
function callEmergency() {
    if (confirm('Are you sure you want to make an emergency call?')) {
        alert('Connecting to emergency services...');
        // In real implementation, this would dial emergency number
    }
}

// Call caregiver function
function callCaregiver() {
    alert('Calling your assigned caregiver...');
    // In real implementation, this would initiate a phone call
}

// Send message to caregiver
function sendMessageToCaregiver() {
    window.location.href = 'elder_messages.php';
}

// Form validation
document.getElementById('profileForm').addEventListener('submit', function(e) {
    // Validate phone number
    const phone = document.getElementById('phone').value;
    const phoneRegex = /^[0-9+\-\s()]{10,15}$/;
    if (!phoneRegex.test(phone)) {
        e.preventDefault();
        alert('Please enter a valid phone number (10-15 digits).');
        return;
    }
    
    // Validate emergency contact
    const emergencyContact = document.getElementById('emergency_contact').value;
    if (emergencyContact.length < 5) {
        e.preventDefault();
        alert('Please enter a valid emergency contact with name and phone.');
        return;
    }
    
    // Validate date of birth
    const dob = new Date(document.getElementById('dob').value);
    const today = new Date();
    if (dob > today) {
        e.preventDefault();
        alert('Date of birth cannot be in the future.');
        return;
    }
    
    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><span>Processing...</span>';
    submitBtn.disabled = true;
});

// Auto-expand textareas
document.querySelectorAll('textarea').forEach(textarea => {
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
});

// Check if we should show edit mode on page load
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('edit')) {
        toggleEditMode();
    }
    
    // Calculate and show age based on DOB input
    const dobInput = document.getElementById('dob');
    if (dobInput) {
        dobInput.addEventListener('change', function() {
            if (this.value) {
                const dob = new Date(this.value);
                const today = new Date();
                let age = today.getFullYear() - dob.getFullYear();
                const monthDiff = today.getMonth() - dob.getMonth();
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
                    age--;
                }
                
                // Show age next to DOB field
                let ageDisplay = document.getElementById('ageDisplay');
                if (!ageDisplay) {
                    ageDisplay = document.createElement('small');
                    ageDisplay.id = 'ageDisplay';
                    ageDisplay.className = 'text-muted ms-2';
                    dobInput.parentNode.appendChild(ageDisplay);
                }
                ageDisplay.textContent = (`${age} years old`);
            }
        });
    }
});
</script>

</body>
</html>