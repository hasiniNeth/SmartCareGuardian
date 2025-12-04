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

// Get assigned residents count
$residents_stmt = $conn->prepare("SELECT COUNT(*) as resident_count FROM caregiver_assignments WHERE caregiver_id = ?");
$residents_stmt->bind_param("i", $caregiver_id);
$residents_stmt->execute();
$residents_result = $residents_stmt->get_result();
$residents_count = $residents_result->fetch_assoc()['resident_count'];

// Get today's appointments count (using services as placeholder)
$today = date('Y-m-d');
$appointments_stmt = $conn->prepare("SELECT COUNT(*) as appointments_count FROM services WHERE DATE(created_at) = ?");
$appointments_stmt->bind_param("s", $today);
$appointments_stmt->execute();
$appointments_result = $appointments_stmt->get_result();
$appointments_count = $appointments_result->fetch_assoc()['appointments_count'];

// Get pending alerts count
$alerts_stmt = $conn->prepare("SELECT COUNT(*) as alerts_count FROM alerts WHERE resolved = 0");
$alerts_stmt->execute();
$alerts_result = $alerts_stmt->get_result();
$alerts_count = $alerts_result->fetch_assoc()['alerts_count'];

// Get assigned residents
$assigned_residents_stmt = $conn->prepare("
    SELECT u.user_id, u.full_name, u.email, u.status 
    FROM users u 
    JOIN caregiver_assignments ca ON u.user_id = ca.resident_id 
    WHERE ca.caregiver_id = ? AND u.role = 'resident'
");
$assigned_residents_stmt->bind_param("i", $caregiver_id);
$assigned_residents_stmt->execute();
$assigned_residents = $assigned_residents_stmt->get_result();

// Get recent alerts
$recent_alerts_stmt = $conn->prepare("
    SELECT a.*, u.full_name as resident_name 
    FROM alerts a 
    JOIN users u ON a.resident_id = u.user_id 
    WHERE a.resolved = 0 
    ORDER BY a.created_at DESC 
    LIMIT 5
");
$recent_alerts_stmt->execute();
$recent_alerts = $recent_alerts_stmt->get_result();

// Get caregiver profile details
$caregiver_profile_stmt = $conn->prepare("
    SELECT c.phone, c.address, c.gender, c.experience_years, c.skills 
    FROM caregivers c 
    WHERE c.user_id = ?
");
$caregiver_profile_stmt->bind_param("i", $caregiver_id);
$caregiver_profile_stmt->execute();
$caregiver_profile = $caregiver_profile_stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caregiver Dashboard - SmartCare Guardian</title>
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }
        
        .icon-residents { background: linear-gradient(135deg, var(--seafoam), var(--sage-green)); color: white; }
        .icon-appointments { background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal)); color: white; }
        .icon-alerts { background: linear-gradient(135deg, #ff6b6b, #ee5a52); color: white; }
        .icon-experience { background: linear-gradient(135deg, #ffd93d, #ff9a3d); color: white; }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--deep-emerald);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--dusty-teal);
            font-weight: 600;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .action-btn {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 2px solid var(--forest-mist);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: var(--deep-emerald);
            transition: all 0.3s ease;
            font-weight: 600;
        }
        
        .action-btn:hover {
            background: var(--sage-green);
            color: white;
            transform: translateY(-3px);
            border-color: var(--sage-green);
            box-shadow: 0 8px 25px rgba(141, 182, 154, 0.4);
        }
        
        .action-btn i {
            font-size: 2rem;
            margin-bottom: 10px;
            display: block;
        }

        /* Sections */
        .section-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }
        
        .section-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 20px 30px;
        }
        
        .section-body {
            padding: 25px 30px;
        }

        /* Tables */
        .table {
            margin: 0;
            background: transparent;
        }
        
        .table thead {
            background: var(--light-sage);
        }
        
        .table thead th {
            border: none;
            padding: 15px;
            font-weight: 600;
            color: var(--deep-emerald);
            font-family: 'Jost', sans-serif;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .table tbody tr:hover {
            background: var(--light-sage);
        }
        
        .table tbody td {
            padding: 15px;
            vertical-align: middle;
            border: none;
            color: var(--deep-emerald);
        }

        /* Badges */
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.75rem;
        }
        
        .bg-success { background: linear-gradient(135deg, var(--seafoam), var(--sage-green)) !important; }
        .bg-warning { background: linear-gradient(135deg, #ffd93d, #ff9a3d) !important; }
        .bg-danger { background: linear-gradient(135deg, #ff6b6b, #ee5a52) !important; }

        /* Resident Cards */
        .resident-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .resident-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            border-left: 4px solid var(--sage-green);
        }
        
        .resident-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .resident-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }

        /* Profile Info */
        .profile-info {
            background: var(--light-sage);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .profile-item {
            display: flex;
            justify-content: between;
            margin-bottom: 10px;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        
        .profile-label {
            font-weight: 600;
            color: var(--deep-emerald);
            min-width: 120px;
        }
        
        .profile-value {
            color: var(--dusty-teal);
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
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
        <a href="#" class="active"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
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
            <h4 class="brand-font mb-1">Caregiver Dashboard</h4>
            <p class="text-muted mb-0">Welcome back, <?php echo $caregiver['full_name']; ?>! 👋</p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-sign-out-alt me-2"></i>Logout
            </button>
        </form>
    </div>

    <!-- Caregiver Profile Info -->
    <?php if ($caregiver_profile): ?>
    <div class="section-card">
        <div class="section-header">
            <h5 class="brand-font mb-0"><i class="fas fa-user me-2"></i>My Profile</h5>
        </div>
        <div class="section-body">
            <div class="profile-info">
                <div class="profile-item">
                    <span class="profile-label">Experience:</span>
                    <span class="profile-value"><?php echo $caregiver_profile['experience_years'] ?? '0'; ?> years</span>
                </div>
                <div class="profile-item">
                    <span class="profile-label">Phone:</span>
                    <span class="profile-value"><?php echo $caregiver_profile['phone'] ?? 'Not set'; ?></span>
                </div>
                <div class="profile-item">
                    <span class="profile-label">Skills:</span>
                    <span class="profile-value"><?php echo $caregiver_profile['skills'] ?? 'Not specified'; ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats Overview -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon icon-residents">
                <i class="fas fa-user-group"></i>
            </div>
            <div class="stat-number"><?php echo $residents_count; ?></div>
            <div class="stat-label">Assigned Residents</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon icon-appointments">
                <i class="fas fa-calendar-check"></i>
            </div>
            <div class="stat-number"><?php echo $appointments_count; ?></div>
            <div class="stat-label">Today's Activities</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon icon-alerts">
                <i class="fas fa-bell"></i>
            </div>
            <div class="stat-number"><?php echo $alerts_count; ?></div>
            <div class="stat-label">Pending Alerts</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon icon-experience">
                <i class="fas fa-award"></i>
            </div>
            <div class="stat-number"><?php echo $caregiver_profile['experience_years'] ?? '0'; ?></div>
            <div class="stat-label">Years Experience</div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <a href="log_health.php" class="action-btn">
            <i class="fas fa-heart-pulse"></i>
            Log Health Data
        </a>
        <a href="manage_routines.php" class="action-btn">
            <i class="fas fa-calendar-check"></i>
            Manage Routines
        </a>
        <a href="caregiver_residents.php" class="action-btn">
            <i class="fas fa-user-group"></i>
            View Residents
        </a>
        <a href="caregiver_appointments.php" class="action-btn">
            <i class="fas fa-calendar-days"></i>
            Appointments
        </a>
    </div>

    <div class="row">
        <!-- Assigned Residents -->
        <div class="col-lg-6">
            <div class="section-card">
                <div class="section-header">
                    <h5 class="brand-font mb-0"><i class="fas fa-user-group me-2"></i>My Assigned Residents</h5>
                </div>
                <div class="section-body">
                    <?php if ($assigned_residents->num_rows > 0): ?>
                        <div class="resident-grid">
                            <?php while ($resident = $assigned_residents->fetch_assoc()): ?>
                            <div class="resident-card">
                                <div class="resident-avatar">
                                    <?php echo strtoupper(substr($resident['full_name'], 0, 1)); ?>
                                </div>
                                <h6 class="brand-font"><?php echo htmlspecialchars($resident['full_name']); ?></h6>
                                <p class="text-muted small mb-2"><?php echo htmlspecialchars($resident['email']); ?></p>
                                <div class="d-flex justify-content-between">
                                    <span class="badge bg-<?php echo $resident['status'] == 'active' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($resident['status']); ?>
                                    </span>
                                    <a href="resident_profile.php?id=<?php echo $resident['user_id']; ?>" class="btn btn-sm btn-outline-secondary">View Profile</a>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-users text-muted fa-3x mb-3"></i>
                            <p class="text-muted">No residents assigned yet.</p>
                            <a href="caregiver_residents.php" class="btn" style="background: var(--sage-green); color: white;">
                                <i class="fas fa-user-plus me-2"></i>Request Assignments
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Alerts -->
        <div class="col-lg-6">
            <div class="section-card">
                <div class="section-header">
                    <h5 class="brand-font mb-0"><i class="fas fa-bell me-2"></i>Recent Health Alerts</h5>
                </div>
                <div class="section-body">
                    <?php if ($recent_alerts->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Resident</th>
                                        <th>Alert Type</th>
                                        <th>Message</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($alert = $recent_alerts->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($alert['resident_name']); ?></td>
                                        <td>
                                            <span class="badge bg-danger">
                                                <?php echo htmlspecialchars($alert['alert_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($alert['alert_message']); ?></td>
                                        <td><?php echo date('M j, g:i A', strtotime($alert['created_at'])); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <a href="caregiver_alerts.php" class="btn w-100 mt-3" style="background: var(--sage-green); color: white;">
                            <i class="fas fa-list me-2"></i>View All Alerts
                        </a>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                            <p class="text-muted">No pending alerts. Great job! 🎉</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Today's Schedule -->
    <div class="section-card">
        <div class="section-header">
            <h5 class="brand-font mb-0"><i class="fas fa-calendar-day me-2"></i>Today's Schedule</h5>
        </div>
        <div class="section-body">
            <div class="text-center py-4">
                <i class="fas fa-calendar-plus text-muted fa-3x mb-3"></i>
                <p class="text-muted">No routines scheduled for today.</p>
                <a href="manage_routines.php" class="btn" style="background: var(--sage-green); color: white;">
                    <i class="fas fa-plus me-2"></i>Create Routine
                </a>
            </div>
        </div>
    </div>
</div>

</body>
</html>