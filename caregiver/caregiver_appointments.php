<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php");
    exit();
}

include '../db_connection.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../vendor/autoload.php';

// Get caregiver info
$caregiver_id = $_SESSION['user_id'];
$caregiver_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$caregiver_stmt->bind_param("i", $caregiver_id);
$caregiver_stmt->execute();
$caregiver_result = $caregiver_stmt->get_result();
$caregiver = $caregiver_result->fetch_assoc();

// Handle appointment creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_appointment'])) {
    $resident_id = $_POST['resident_id'];
    $title = $_POST['title'];
    $description = $_POST['description'];
    $appointment_date = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $location = $_POST['location'];
    
    $stmt = $conn->prepare("
        INSERT INTO appointments 
        (resident_id, caregiver_id, title, description, appointment_date, appointment_time, location, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')");
    $stmt->bind_param("iisssss",$resident_id,$caregiver_id,$title,$description,$appointment_date,$appointment_time,$location);    
    if ($stmt->execute()) {

        // Fetch resident email + name
        $emailStmt = $conn->prepare("
            SELECT full_name, email 
            FROM users 
            WHERE user_id = ? AND role = 'resident'
        ");
        $emailStmt->bind_param("i", $resident_id);
        $emailStmt->execute();
        $resident = $emailStmt->get_result()->fetch_assoc();
        $emailStmt->close();

        if ($resident && !empty($resident['email'])) {
            try {
                $mail = new PHPMailer(true);

                // SMTP configuration (Gmail example)
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'smartcareguardian@gmail.com';       
                $mail->Password   = 'yvryblsvyfsspjjn';           
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                // Email headers
                $mail->setFrom('smartcareguardian@gmail.com', 'SmartCare Guardian');
                $mail->addAddress($resident['email'], $resident['full_name']);

                // Email content
                $mail->isHTML(true);
                $mail->Subject = 'New Appointment Scheduled';
                $mail->Body = "
                    <h3>Hello {$resident['full_name']},</h3>
                    <p>A new appointment has been scheduled for you.</p>

                    <p><strong>Title:</strong> {$title}</p>
                    <p><strong>Date:</strong> " . date('F j, Y', strtotime($appointment_date)) . "</p>
                    <p><strong>Time:</strong> " . date('g:i A', strtotime($appointment_time)) . "</p>
                    <p><strong>Location:</strong> {$location}</p>

                    <p>Please be ready on time.</p>
                    <br>
                    <p>— SmartCare Guardian</p>
                ";

                $mail->send();
            } catch (Exception $e) {
                // Optional: log email error (do NOT break appointment creation)
                error_log("Appointment email failed: " . $mail->ErrorInfo);
            }
        }

        $success = "Appointment created successfully!";
    } else {
        $error = "Error creating appointment.";
    }
}

// Handle appointment status update
if (isset($_GET['update_status'])) {
    $appointment_id = $_GET['update_status'];
    $status = $_GET['status'];
    
    $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ? AND caregiver_id = ?");
    $stmt->bind_param("sii", $status, $appointment_id, $caregiver_id);
    $stmt->execute();
}

// Handle appointment deletion
if (isset($_GET['delete_appointment'])) {
    $appointment_id = $_GET['delete_appointment'];
    
    $stmt = $conn->prepare("DELETE FROM appointments WHERE appointment_id = ? AND caregiver_id = ?");
    $stmt->bind_param("ii", $appointment_id, $caregiver_id);
    $stmt->execute();
}

// Get assigned elders for dropdown
$assigned_residents_stmt = $conn->prepare("
    SELECT u.user_id, u.full_name
    FROM users u
    JOIN caregiver_assignments ca ON u.user_id = ca.resident_id
    WHERE ca.caregiver_id = ?
      AND u.role = 'resident'
      AND u.status = 'active'
    ORDER BY u.full_name ASC
");
$assigned_residents_stmt->bind_param("i", $caregiver_id);
$assigned_residents_stmt->execute();
$assigned_residents = $assigned_residents_stmt->get_result();



// Get appointments
$appointments_stmt = $conn->prepare("
    SELECT a.*, u.full_name AS resident_name
    FROM appointments a
    JOIN users u ON a.resident_id = u.user_id
    WHERE a.caregiver_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");
$appointments_stmt->bind_param("i", $caregiver_id);
$appointments_stmt->execute();
$appointments = $appointments_stmt->get_result();

// Get today's appointments
$today = date('Y-m-d');
$today_appointments_stmt = $conn->prepare("
    SELECT a.*, u.full_name AS resident_name
    FROM appointments a
    JOIN users u ON a.resident_id = u.user_id
    WHERE a.caregiver_id = ?
      AND a.appointment_date = ?
    ORDER BY a.appointment_time ASC
");
$today_appointments_stmt->bind_param("is", $caregiver_id, $today);
$today_appointments_stmt->execute();
$today_appointments = $today_appointments_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Quicksand:wght@300;400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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

        /* Appointment Cards */
        .appointment-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
            border-left: 4px solid;
        }
        
        .appointment-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .appointment-card.scheduled { border-left-color: var(--sage-green); }
        .appointment-card.completed { border-left-color: var(--dusty-teal); }
        .appointment-card.cancelled { border-left-color: #ff6b6b; }
        
        .appointment-date {
            font-size: 0.9rem;
            color: var(--dusty-teal);
            font-weight: 600;
        }
        
        .appointment-time {
            background: var(--light-sage);
            color: var(--deep-emerald);
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.75rem;
        }
        
        .badge-scheduled { background: linear-gradient(135deg, var(--seafoam), var(--sage-green)) !important; color: white; }
        .badge-completed { background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal)) !important; color: white; }
        .badge-cancelled { background: linear-gradient(135deg, #ff6b6b, #ee5a52) !important; color: white; }

        /* Form Styling */
        .form-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 30px;
        }
        
        .form-control, .form-select {
            border: 2px solid var(--forest-mist);
            border-radius: 12px;
            padding: 12px 15px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
            transform: translateY(-1px);
        }
        
        .form-label {
            color: var(--deep-emerald);
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            border-radius: 50px;
            padding: 12px 30px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(141, 182, 154, 0.4);
        }
        
        .btn-outline-primary {
            border: 2px solid var(--sage-green);
            color: var(--sage-green);
            border-radius: 50px;
            padding: 8px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background: var(--sage-green);
            color: white;
            transform: translateY(-2px);
        }

        /* Alert Messages */
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

        /* Calendar View */
        .calendar-day {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .calendar-day:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }
        
        .calendar-day.today {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
        }
        
        .calendar-day.has-appointments {
            border: 2px solid var(--sage-green);
        }
        
        .day-number {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .day-name {
            font-size: 0.85rem;
            color: var(--dusty-teal);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn-sm {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
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
            
            .action-buttons {
                flex-direction: column;
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
        <a href="caregiver_profile.php"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="caregiver_residents.php"><i class="fa-solid fa-user-group"></i> My Residents</a>
        <a href="log_health.php"><i class="fa-solid fa-heart-pulse"></i> Log Health Data</a>
        <a href="manage_routines.php"><i class="fa-solid fa-calendar-check"></i> Manage Routines</a>
        <a href="#" class="active"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="caregiver_messages.php"><i class="fa-solid fa-comments"></i> Messages</a>
        <a href="caregiver_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="caregiver_reports.php"><i class="fa-solid fa-chart-line"></i> Health Reports</a>
    </div>
    
    <div class="sidebar-footer">
        <a href="logout.php" class="logout-sidebar">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>
</div>

<!-- Main Content -->
<div class="content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div>
            <h4 class="brand-font mb-1">Appointment Management</h4>
            <p class="text-muted mb-0">Schedule and manage resident appointments</p>
        </div>
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-sign-out-alt me-2"></i>Logout
            </button>
        </form>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Create Appointment Form -->
    <div class="form-container">
        <h5 class="brand-font mb-4"><i class="fas fa-calendar-plus me-2"></i>Schedule New Appointment</h5>
        <form method="POST" id="appointmentForm">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Select Resident</label>
                    <select name="resident_id" class="form-select" required>
                        <option value="">Choose a resident...</option>
                        <?php while ($resident = $assigned_residents->fetch_assoc()): ?>
                            <option value="<?php echo $resident['user_id']; ?>">
                                <?php echo htmlspecialchars($resident['full_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Appointment Title</label>
                    <input type="text" name="title" class="form-control" placeholder="Enter appointment title" required>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Enter appointment details" required></textarea>
            </div>
            
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Date</label>
                    <input type="date" name="appointment_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Time</label>
                    <input type="time" name="appointment_time" class="form-control" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Location</label>
                    <input type="text" name="location" class="form-control" placeholder="Enter location" required>
                </div>
            </div>
            
            <button type="submit" name="create_appointment" class="btn btn-primary">
                <i class="fas fa-calendar-check me-2"></i>Schedule Appointment
            </button>
        </form>
    </div>

    <!-- Today's Appointments -->
    <div class="mb-4">
        <h5 class="brand-font mb-3"><i class="fas fa-calendar-day me-2"></i>Today's Appointments</h5>
        <?php if ($today_appointments->num_rows > 0): ?>
            <div class="row">
                <?php while ($appointment = $today_appointments->fetch_assoc()): ?>
                <div class="col-md-6">
                    <div class="appointment-card <?php echo $appointment['status']; ?>">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h6 class="brand-font mb-1"><?php echo htmlspecialchars($appointment['title']); ?></h6>
                                <p class="text-muted small mb-0">With: <?php echo htmlspecialchars($appointment['resident_name']); ?></p>
                            </div>
                            <span class="badge badge-<?php echo $appointment['status']; ?>">
                                <?php echo ucfirst($appointment['status']); ?>
                            </span>
                        </div>
                        
                        <p class="mb-3"><?php echo htmlspecialchars($appointment['description']); ?></p>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="appointment-date">
                                    <i class="fas fa-calendar me-1"></i><?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?>
                                </span>
                                <span class="appointment-time ms-3">
                                    <i class="fas fa-clock me-1"></i><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?>
                                </span>
                            </div>
                            <div class="action-buttons">
                                <?php if ($appointment['status'] == 'scheduled'): ?>
                                    <a href="?update_status=<?php echo $appointment['appointment_id']; ?>&status=completed" 
                                       class="btn btn-sm btn-success">
                                        <i class="fas fa-check"></i>
                                    </a>
                                    <a href="?update_status=<?php echo $appointment['appointment_id']; ?>&status=cancelled" 
                                       class="btn btn-sm btn-danger">
                                        <i class="fas fa-times"></i>
                                    </a>
                                <?php endif; ?>
                                <a href="?delete_appointment=<?php echo $appointment['appointment_id']; ?>" 
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Are you sure you want to delete this appointment?');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                        <?php if ($appointment['location']): ?>
                            <div class="mt-3">
                                <small class="text-muted">
                                    <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($appointment['location']); ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-5 bg-white rounded-3">
                <i class="fas fa-calendar-check text-muted fa-3x mb-3"></i>
                <p class="text-muted">No appointments scheduled for today.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- All Appointments -->
    <div>
        <h5 class="brand-font mb-3"><i class="fas fa-list me-2"></i>All Appointments</h5>
        <div class="table-responsive bg-white rounded-3 shadow-sm">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Resident</th>
                        <th>Title</th>
                        <th>Date & Time</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($appointment = $appointments->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                    <i class="fas fa-user text-muted"></i>
                                </div>
                                <div>
                                    <strong><?php echo htmlspecialchars($appointment['resident_name']); ?></strong>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($appointment['title']); ?></td>
                        <td>
                            <div>
                                <small class="text-muted"><?php echo date('M j, Y', strtotime($appointment['appointment_date'])); ?></small>
                                <div><?php echo date('g:i A', strtotime($appointment['appointment_time'])); ?></div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($appointment['location']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $appointment['status']; ?>">
                                <?php echo ucfirst($appointment['status']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <?php if ($appointment['status'] == 'scheduled'): ?>
                                    <a href="?update_status=<?php echo $appointment['appointment_id']; ?>&status=completed" 
                                       class="btn btn-sm btn-success" title="Mark as Completed">
                                        <i class="fas fa-check"></i>
                                    </a>
                                    <a href="?update_status=<?php echo $appointment['appointment_id']; ?>&status=cancelled" 
                                       class="btn btn-sm btn-danger" title="Cancel Appointment">
                                        <i class="fas fa-times"></i>
                                    </a>
                                <?php endif; ?>
                                <a href="?delete_appointment=<?php echo $appointment['appointment_id']; ?>" 
                                   class="btn btn-sm btn-outline-danger"
                                   title="Delete Appointment"
                                   onclick="return confirm('Are you sure you want to delete this appointment?');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
    // Initialize date picker
    flatpickr("input[type='date']", {
        minDate: "today",
        dateFormat: "Y-m-d",
    });

    // Initialize time picker
    flatpickr("input[type='time']", {
        enableTime: true,
        noCalendar: true,
        dateFormat: "H:i",
        time_24hr: false
    });

    // Form validation
    document.getElementById('appointmentForm').addEventListener('submit', function(e) {
        const dateInput = this.querySelector('input[name="appointment_date"]');
        const timeInput = this.querySelector('input[name="appointment_time"]');
        const today = new Date();
        const selectedDate = new Date(dateInput.value);
        
        if (selectedDate < today.setHours(0,0,0,0)) {
            e.preventDefault();
            alert('Please select a future date for the appointment.');
            return;
        }
        
        if (!timeInput.value) {
            e.preventDefault();
            alert('Please select a time for the appointment.');
            return;
        }
    });

    // Auto-refresh after status update
    if (window.location.search.includes('update_status') || window.location.search.includes('delete_appointment')) {
        setTimeout(() => {
            window.location.href = window.location.pathname;
        }, 2000);
    }
</script>
</body>
</html>