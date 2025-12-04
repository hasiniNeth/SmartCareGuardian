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

// Get assigned residents with their details
$assigned_residents_stmt = $conn->prepare("
    SELECT 
        u.user_id, 
        u.full_name, 
        u.email, 
        u.status,
        r.phone,
        r.gender,
        r.dob,
        r.emergency_contact,
        r.medical_conditions,
        r.blood_type,
        r.primary_physician
    FROM users u 
    LEFT JOIN residents r ON u.user_id = r.user_id
    JOIN caregiver_assignments ca ON u.user_id = ca.resident_id 
    WHERE ca.caregiver_id = ? AND u.role = 'resident'
    ORDER BY u.full_name ASC
");
$assigned_residents_stmt->bind_param("i", $caregiver_id);
$assigned_residents_stmt->execute();
$assigned_residents = $assigned_residents_stmt->get_result();

// Get health alerts for residents
$health_alerts_stmt = $conn->prepare("
    SELECT resident_id, COUNT(*) as alert_count 
    FROM alerts 
    WHERE resolved = 0 
    GROUP BY resident_id
");
$health_alerts_stmt->execute();
$alerts_result = $health_alerts_stmt->get_result();
$alerts_count = [];
while ($alert = $alerts_result->fetch_assoc()) {
    $alerts_count[$alert['resident_id']] = $alert['alert_count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Residents - SmartCare Guardian</title>
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

        /* Residents Grid */
        .residents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .resident-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .resident-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }
        
        .resident-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 25px;
            position: relative;
        }
        
        .resident-avatar {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 15px;
            border: 3px solid rgba(255, 255, 255, 0.3);
        }
        
        .alert-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            border-radius: 20px;
            padding: 5px 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .resident-body {
            padding: 25px;
        }
        
        .resident-info {
            margin-bottom: 20px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .info-label {
            font-weight: 600;
            color: var(--deep-emerald);
        }
        
        .info-value {
            color: var(--dusty-teal);
        }
        
        .medical-conditions {
            background: var(--light-sage);
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
            border-left: 4px solid var(--sage-green);
        }
        
        .conditions-title {
            font-weight: 600;
            color: var(--deep-emerald);
            margin-bottom: 8px;
        }
        
        .conditions-list {
            color: var(--dusty-teal);
            font-size: 0.9rem;
        }
        
        .action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            border-radius: 10px;
            color: white;
            padding: 10px 15px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(141, 182, 154, 0.4);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--forest-mist);
            border-radius: 10px;
            color: var(--deep-emerald);
            padding: 10px 15px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
        }
        
        .btn-outline:hover {
            background: var(--forest-mist);
            transform: translateY(-2px);
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.75rem;
        }
        
        .bg-success { 
            background: linear-gradient(135deg, var(--seafoam), var(--sage-green)) !important; 
            color: white;
        }
        
        .bg-warning { 
            background: linear-gradient(135deg, #ffd93d, #ff9a3d) !important; 
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 30px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .empty-icon {
            font-size: 4rem;
            color: var(--forest-mist);
            margin-bottom: 20px;
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
            
            .residents-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
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
    </style>
</head>
<body>

<!-- Sidebar (same as before) -->
<div class="sidebar">
    <div class="sidebar-header">
        <h4 class="brand-font mb-2"><i class="fa-solid fa-shield-heart me-2"></i>SmartCare Guardian</h4>
        <small class="opacity-75">Caregiver Panel</small>
    </div>
    
    <div class="sidebar-nav">
        <a href="caregiver_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="#" class="active"><i class="fa-solid fa-user-group"></i> My Residents</a>
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
            <h4 class="brand-font mb-1">My Residents</h4>
            <p class="text-muted mb-0">Manage and view all your assigned residents</p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-sign-out-alt me-2"></i>Logout
            </button>
        </form>
    </div>

    <?php if ($assigned_residents->num_rows > 0): ?>
        <div class="residents-grid">
            <?php while ($resident = $assigned_residents->fetch_assoc()): 
                $alert_count = $alerts_count[$resident['user_id']] ?? 0;
                $age = $resident['dob'] ? floor((time() - strtotime($resident['dob'])) / 31556926) : 'Not set';
            ?>
            <div class="resident-card">
                <div class="resident-header">
                    <?php if ($alert_count > 0): ?>
                        <div class="alert-badge">
                            <i class="fas fa-exclamation-triangle me-1"></i><?php echo $alert_count; ?> Alert<?php echo $alert_count > 1 ? 's' : ''; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="resident-avatar">
                        <?php echo strtoupper(substr($resident['full_name'], 0, 1)); ?>
                    </div>
                    
                    <h5 class="brand-font mb-2"><?php echo htmlspecialchars($resident['full_name']); ?></h5>
                    
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="status-badge bg-<?php echo $resident['status'] == 'active' ? 'success' : 'warning'; ?>">
                            <i class="fas fa-<?php echo $resident['status'] == 'active' ? 'check-circle' : 'clock'; ?> me-1"></i>
                            <?php echo ucfirst($resident['status']); ?>
                        </span>
                        <span class="text-white-50">
                            <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($resident['email']); ?>
                        </span>
                    </div>
                </div>
                
                <div class="resident-body">
                    <div class="resident-info">
                        <div class="info-item">
                            <span class="info-label">Age:</span>
                            <span class="info-value"><?php echo $age; ?> years</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Gender:</span>
                            <span class="info-value"><?php echo $resident['gender'] ?? 'Not specified'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Phone:</span>
                            <span class="info-value"><?php echo $resident['phone'] ?? 'Not set'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Blood Type:</span>
                            <span class="info-value"><?php echo $resident['blood_type'] ?? 'Not set'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Emergency Contact:</span>
                            <span class="info-value"><?php echo $resident['emergency_contact'] ?? 'Not set'; ?></span>
                        </div>
                        <?php if (!empty($resident['primary_physician'])): ?>
                        <div class="info-item">
                            <span class="info-label">Primary Physician:</span>
                            <span class="info-value"><?php echo htmlspecialchars($resident['primary_physician']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($resident['medical_conditions'])): ?>
                    <div class="medical-conditions">
                        <div class="conditions-title">
                            <i class="fas fa-file-medical me-2"></i>Medical Conditions
                        </div>
                        <div class="conditions-list">
                            <?php echo htmlspecialchars($resident['medical_conditions']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="action-buttons">
                        <a href="log_health.php?resident_id=<?php echo $resident['user_id']; ?>" class="btn-primary">
                            <i class="fas fa-heart-pulse me-2"></i>Log Health
                        </a>
                        <a href="resident_profile.php?id=<?php echo $resident['user_id']; ?>" class="btn-outline">
                            <i class="fas fa-user me-2"></i>View Profile
                        </a>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fas fa-users"></i>
            </div>
            <h4 class="brand-font mb-3">No Residents Assigned</h4>
            <p class="text-muted mb-4">You don't have any residents assigned to you yet.</p>
            <div class="d-flex justify-content-center gap-3">
                <a href="caregiver_dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
                <button class="btn btn-outline-secondary">
                    <i class="fas fa-question-circle me-2"></i>Contact Admin
                </button>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Add smooth animations to cards
    document.addEventListener('DOMContentLoaded', function() {
        const cards = document.querySelectorAll('.resident-card');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100);
        });
    });
</script>

</body>
</html>