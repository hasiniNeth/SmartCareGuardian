<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

include 'db_connection.php';

// Fetch dashboard counts
$residentCount = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role='resident'")->fetch_assoc()['total'];
$caregiverCount = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role='caregiver'")->fetch_assoc()['total'];
$alertCount = $conn->query("SELECT COUNT(*) AS total FROM alerts")->fetch_assoc()['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - SmartCare Guardian</title>
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
            overflow-x: hidden;
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

        /* Custom scrollbar for sidebar */
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

        /* Cards */
        .stats-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
            text-align: center;
            height: 100%;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.15);
        }
        
        .stats-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--deep-emerald);
            margin: 10px 0;
        }
        
        .stats-label {
            color: var(--dusty-teal);
            font-weight: 600;
            font-size: 1.1rem;
        }

        /* Preview Section */
        .preview-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-top: 30px;
        }
        
        .preview-icon {
            font-size: 2rem;
            color: var(--dusty-teal);
            margin-bottom: 15px;
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
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-header">
        <h4 class="brand-font mb-2"><i class="fa-solid fa-shield-heart me-2"></i>SmartCare Guardian</h4>
        <small class="opacity-75">Administrator Panel</small>
    </div>
    
    <div class="sidebar-nav">
        <a href="#" class="active"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="manage_users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i> Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i> Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i> Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i> Manage Services</a>
        <a href="view_alerts.php"><i class="fa-solid fa-bell"></i> Alerts</a>
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
            <h4 class="brand-font mb-1">Welcome, <?php echo $_SESSION['full_name']; ?></h4>
            <p class="text-muted mb-0">SmartCare Guardian Administration Dashboard</p>
        </div>
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-sign-out-alt me-2"></i>Logout
            </button>
        </form>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-icon">
                    <i class="fa-solid fa-user-friends"></i>
                </div>
                <div class="stats-number"><?php echo $residentCount; ?></div>
                <div class="stats-label">Total Residents</div>
                <p class="text-muted mt-2 mb-0">Elderly residents under care</p>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-icon">
                    <i class="fa-solid fa-user-nurse"></i>
                </div>
                <div class="stats-number"><?php echo $caregiverCount; ?></div>
                <div class="stats-label">Total Caregivers</div>
                <p class="text-muted mt-2 mb-0">Professional care staff</p>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stats-card">
                <div class="stats-icon">
                    <i class="fa-solid fa-heart-pulse"></i>
                </div>
                <div class="stats-number"><?php echo $alertCount; ?></div>
                <div class="stats-label">Active Health Alerts</div>
                <p class="text-muted mt-2 mb-0">Requiring attention</p>
            </div>
        </div>
    </div>

    <div class="preview-section">
        <div class="text-center">
            <div class="preview-icon">
                <i class="fa-solid fa-chart-line"></i>
            </div>
            <h4 class="brand-font mb-3">Recent Activity Preview</h4>
            <p class="text-muted mb-4">In the next version, this section will show comprehensive logs of new registrations, caregiver assignments, and real-time health updates with interactive charts and analytics.</p>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-center">
                        <i class="fas fa-user-plus fa-lg text-success mb-2"></i>
                        <p class="small text-muted mb-0">User Registrations</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center">
                        <i class="fas fa-tasks fa-lg text-primary mb-2"></i>
                        <p class="small text-muted mb-0">Care Assignments</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center">
                        <i class="fas fa-chart-bar fa-lg text-warning mb-2"></i>
                        <p class="small text-muted mb-0">Health Analytics</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>