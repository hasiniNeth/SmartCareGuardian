<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php");
    exit();
}

include '../db_connection.php';

$caregiver_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Get existing user data
$user_stmt = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $caregiver_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_data = $user_result->fetch_assoc();

// Get existing caregiver profile if exists
$profile_stmt = $conn->prepare("SELECT * FROM caregivers WHERE user_id = ?");
$profile_stmt->bind_param("i", $caregiver_id);
$profile_stmt->execute();
$profile_result = $profile_stmt->get_result();
$profile_data = $profile_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $gender = $_POST['gender'];
    $dob = $_POST['dob'];
    $experience_years = intval($_POST['experience_years']);
    $skills = trim($_POST['skills']);
    
    // Validate required fields
    if (empty($phone) || empty($address) || empty($gender) || empty($dob) || empty($experience_years)) {
        $message = "Please fill in all required fields.";
        $message_type = "error";
    } else {
        if ($profile_data) {
            // Update existing profile
            $update_stmt = $conn->prepare("
                UPDATE caregivers 
                SET phone = ?, address = ?, gender = ?, dob = ?, 
                    experience_years = ?, skills = ?, updated_at = NOW() 
                WHERE user_id = ?
            ");
            $update_stmt->bind_param("ssssisi", $phone, $address, $gender, $dob, $experience_years, $skills, $caregiver_id);
            
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
                INSERT INTO caregivers 
                (user_id, phone, address, gender, dob, experience_years, skills, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insert_stmt->bind_param("issssis", $caregiver_id, $phone, $address, $gender, $dob, $experience_years, $skills);
            
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Profile - SmartCare Guardian</title>
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
            width: 100%;
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
            width: 100%;
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
            
            .profile-body {
                padding: 20px;
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
        <a href="#" class="active"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="caregiver_residents.php"><i class="fa-solid fa-user-group"></i> My Residents</a>
        <a href="log_health.php"><i class="fa-solid fa-heart-pulse"></i> Log Health Data</a>
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
            <h4 class="brand-font mb-1">Update Your Profile</h4>
            <p class="text-muted mb-0">Complete your caregiver profile information</p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-sign-out-alt me-2"></i>Logout
            </button>
        </form>
    </div>

    <!-- Profile Completion Status -->
    <div class="completion-status">
        <h5 class="brand-font">Profile Completion</h5>
        <div class="completion-progress">
            <div class="completion-bar" style="width: <?php echo $profile_data ? '100%' : '30%'; ?>"></div>
        </div>
        <p class="mb-0">
            <?php if ($profile_data): ?>
                <i class="fas fa-check-circle text-success me-2"></i>Your profile is complete!
            <?php else: ?>
                <i class="fas fa-exclamation-circle text-warning me-2"></i>Please complete your profile to start using all features.
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
        </div>
        
        <div class="profile-body">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?>">
                    <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="profileForm">
                <div class="row">
                    <!-- Basic Information -->
                    <div class="col-md-6">
                        <h5 class="brand-font mb-4" style="color: var(--deep-emerald);">
                            <i class="fas fa-user me-2"></i>Basic Information
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
                            <label for="address" class="form-label required">
                                <i class="fas fa-home me-2"></i>Address
                            </label>
                            <div class="input-group-icon">
                                <i class="fas fa-home"></i>
                                <textarea class="form-control" id="address" name="address" 
                                          rows="3" placeholder="Enter your full address" required><?php echo htmlspecialchars($profile_data['address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="gender" class="form-label required">
                                <i class="fas fa-venus-mars me-2"></i>Gender
                            </label>
                            <select class="form-select" id="gender" name="gender" required>
                                <option value="">Select Gender</option>
                                <option value="Male" <?php echo ($profile_data['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($profile_data['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Professional Information -->
                    <div class="col-md-6">
                        <h5 class="brand-font mb-4" style="color: var(--deep-emerald);">
                            <i class="fas fa-briefcase me-2"></i>Professional Information
                        </h5>
                        
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
                        
                        <div class="mb-3">
                            <label for="experience_years" class="form-label required">
                                <i class="fas fa-clock me-2"></i>Years of Experience
                            </label>
                            <div class="input-group-icon">
                                <i class="fas fa-clock"></i>
                                <input type="number" class="form-control" id="experience_years" name="experience_years" 
                                       value="<?php echo htmlspecialchars($profile_data['experience_years'] ?? ''); ?>" 
                                       min="0" max="50" placeholder="Enter years of experience" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="skills" class="form-label">
                                <i class="fas fa-tools me-2"></i>Skills & Specializations
                            </label>
                            <div class="input-group-icon">
                                <i class="fas fa-tools"></i>
                                <textarea class="form-control" id="skills" name="skills" 
                                          rows="3" placeholder="Enter your skills (e.g., CPR Certified, Dementia Care, etc.)"><?php echo htmlspecialchars($profile_data['skills'] ?? ''); ?></textarea>
                            </div>
                            <small class="text-muted">Separate skills with commas</small>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-6">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i><span>
                                <?php echo $profile_data ? 'Update Profile' : 'Create Profile'; ?>
                            </span>
                        </button>
                    </div>
                    <div class="col-md-6">
                        <a href="caregiver_dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('profileForm').addEventListener('submit', function(e) {
    // Validate phone number
    const phone = document.getElementById('phone').value;
    const phoneRegex = /^[0-9+\-\s()]{10,15}$/;
    if (!phoneRegex.test(phone)) {
        e.preventDefault();
        alert('Please enter a valid phone number (10-15 digits).');
        return;
    }
    
    // Validate experience years
    const experience = parseInt(document.getElementById('experience_years').value);
    if (experience < 0 || experience > 50) {
        e.preventDefault();
        alert('Please enter a valid experience between 0 and 50 years.');
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
    
    // Calculate age
    const age = today.getFullYear() - dob.getFullYear();
    if (age < 18) {
        e.preventDefault();
        alert('Caregiver must be at least 18 years old.');
        return;
    }
    
    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><span>Processing...</span>';
    submitBtn.disabled = true;
});

// Auto-calculate experience based on date (optional enhancement)
document.getElementById('dob').addEventListener('change', function() {
    const dob = new Date(this.value);
    if (dob) {
        const today = new Date();
        let experience = today.getFullYear() - dob.getFullYear();
        experience = Math.max(0, experience - 18); // Minimum working age 18
        
        const expInput = document.getElementById('experience_years');
        if (!expInput.value) {
            expInput.value = experience;
        }
    }
});

// Auto-expand textareas
document.querySelectorAll('textarea').forEach(textarea => {
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
});
</script>

</body>
</html>