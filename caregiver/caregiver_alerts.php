<?php
session_start();
include '../db_connection.php';

// Check if user is logged in and is a caregiver
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php");
    exit();
}

// Fetch caregiver's assigned residents
$caregiver_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT resident_id FROM caregiver_assignments WHERE caregiver_id = ?");
$stmt->bind_param("i", $caregiver_id);
$stmt->execute();
$result = $stmt->get_result();
$assigned_residents = [];
while ($row = $result->fetch_assoc()) {
    $assigned_residents[] = $row['resident_id'];
}

// Get alerts for assigned residents
if (!empty($assigned_residents)) {
    $placeholders = str_repeat('?,', count($assigned_residents) - 1) . '?';
    $stmt = $conn->prepare("
        SELECT a.*, r.full_name as resident_name, 
               DATE_FORMAT(a.created_at, '%Y-%m-%d %h:%i %p') as formatted_time
        FROM alerts a
        JOIN users r ON a.resident_id = r.user_id
        WHERE a.resident_id IN ($placeholders) 
        ORDER BY a.created_at DESC
    ");
    $stmt->bind_param(str_repeat('i', count($assigned_residents)), ...$assigned_residents);
    $stmt->execute();
    $alerts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $alerts = [];
}

// Handle alert resolution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_alert'])) {
    $alert_id = $_POST['alert_id'];
    $update = $conn->prepare("UPDATE alerts SET resolved = 1 WHERE alert_id = ?");
    $update->bind_param("i", $alert_id);
    $update->execute();
    
    // Refresh page to show updated status
    header("Location: caregiver_alerts.php");
    exit();
}

// Handle marking all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (!empty($assigned_residents)) {
        $placeholders = str_repeat('?,', count($assigned_residents) - 1) . '?';
        $update = $conn->prepare("UPDATE alerts SET resolved = 1 WHERE resident_id IN ($placeholders)");
        $update->bind_param(str_repeat('i', count($assigned_residents)), ...$assigned_residents);
        $update->execute();
        
        header("Location: caregiver_alerts.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alerts - SmartCare Guardian</title>
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
            --warning-red: #FF6B6B;
            --warning-orange: #FFA726;
            --info-blue: #42A5F5;
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

        /* Sidebar - Matches Dashboard */
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

        /* Stats Cards - Matches Dashboard */
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
        
        .icon-alerts { background: linear-gradient(135deg, #ff6b6b, #ee5a52); color: white; }
        .icon-pending { background: linear-gradient(135deg, var(--warning-orange), #f57c00); color: white; }
        .icon-resolved { background: linear-gradient(135deg, var(--seafoam), var(--sage-green)); color: white; }
        .icon-residents { background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal)); color: white; }
        
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

        /* Alerts Section */
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-body {
            padding: 25px 30px;
        }

        /* Alert Cards */
        .alert-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            border-left: 5px solid var(--warning-red);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .alert-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .alert-card.resolved {
            opacity: 0.8;
            border-left-color: var(--forest-mist);
        }
        
        .alert-type {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .alert-critical {
            background: linear-gradient(135deg, var(--warning-red), #ee5a52);
            color: white;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, var(--warning-orange), #f57c00);
            color: white;
        }
        
        .alert-info {
            background: linear-gradient(135deg, var(--info-blue), #1976D2);
            color: white;
        }
        
        .alert-health {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
        }
        
        .resident-badge {
            background: var(--forest-mist);
            color: var(--deep-emerald);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            margin-left: 10px;
        }
        
        .btn-resolve {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            border-radius: 25px;
            padding: 8px 20px;
            font-size: 14px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
        }
        
        .btn-resolve:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(135, 169, 107, 0.3);
        }
        
        .btn-mark-all {
            background: linear-gradient(135deg, var(--dusty-teal), var(--deep-emerald));
            border: none;
            border-radius: 25px;
            padding: 10px 25px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
        }
        
        .btn-mark-all:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(77, 126, 108, 0.3);
        }
        
        .alert-icon {
            font-size: 1.5rem;
            color: var(--warning-red);
            margin-right: 15px;
        }
        
        .alert-icon.resolved {
            color: var(--forest-mist);
        }
        
        .time-badge {
            background: var(--mint-cream);
            color: var(--dusty-teal);
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 11px;
            display: inline-block;
            margin-top: 10px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--dusty-teal);
        }
        
        .empty-icon {
            font-size: 4rem;
            color: var(--forest-mist);
            margin-bottom: 20px;
        }

        /* Alert Legend */
        .legend-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            padding: 10px;
            background: var(--light-sage);
            border-radius: 10px;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            margin-right: 10px;
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
        
        /* Animations */
        .floating {
            animation: floating 3s ease-in-out infinite;
        }
        
        @keyframes floating {
            0% { transform: translate(0, 0px); }
            50% { transform: translate(0, -10px); }
            100% { transform: translate(0, 0px); }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.6; }
            100% { opacity: 1; }
        }
    </style>
</head>
<body>

<!-- Sidebar - Same as Dashboard -->
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
        <a href="caregiver_appointments.php"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="caregiver_messages.php"><i class="fa-solid fa-comments"></i> Messages</a>
        <a href="caregiver_alerts.php" class="active"><i class="fa-solid fa-bell"></i> Health Alerts</a>
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
            <h4 class="brand-font mb-1">Health Alerts & Notifications</h4>
            <p class="text-muted mb-0">Monitor and manage health alerts for your assigned residents</p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-sign-out-alt me-2"></i>Logout
            </button>
        </form>
    </div>

    <?php
    $unresolved_count = 0;
    foreach ($alerts as $alert) {
        if (!$alert['resolved']) $unresolved_count++;
    }
    ?>

    <!-- Stats Overview -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon icon-alerts">
                <i class="fas fa-bell"></i>
            </div>
            <div class="stat-number"><?php echo count($alerts); ?></div>
            <div class="stat-label">Total Alerts</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon icon-pending">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-number"><?php echo $unresolved_count; ?></div>
            <div class="stat-label">Pending Alerts</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon icon-resolved">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-number"><?php echo count($alerts) - $unresolved_count; ?></div>
            <div class="stat-label">Resolved Alerts</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon icon-residents">
                <i class="fas fa-user-group"></i>
            </div>
            <div class="stat-number"><?php echo count($assigned_residents); ?></div>
            <div class="stat-label">Assigned Residents</div>
        </div>
    </div>

    <!-- Mark All as Resolved -->
    <?php if ($unresolved_count > 0): ?>
    <div class="section-card mb-4">
        <div class="section-body text-center">
            <form method="POST">
                <button type="submit" name="mark_all_read" class="btn btn-mark-all">
                    <i class="fas fa-check-double me-2"></i>Mark All Alerts as Resolved
                </button>
                <p class="text-muted small mt-2 mb-0">
                    <i class="fas fa-info-circle me-1"></i>This will mark all <?php echo $unresolved_count; ?> pending alerts as resolved.
                </p>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Alerts List -->
    <div class="section-card">
        <div class="section-header">
            <h5 class="brand-font mb-0"><i class="fas fa-bell me-2"></i>Alerts</h5>
            <div class="text-white">
                <?php if ($unresolved_count > 0): ?>
                    <span class="pulse">
                        <i class="fas fa-circle me-1"></i>
                        <?php echo $unresolved_count; ?> attention required
                    </span>
                <?php else: ?>
                    <span>
                        <i class="fas fa-check-circle me-1"></i>
                        All clear
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="section-body">
            <?php if (empty($alerts)): ?>
                <div class="empty-state">
                    <div class="empty-icon floating">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h4 class="brand-font mb-3">No Alerts Found</h4>
                    <p class="text-muted mb-4">Great job! All your assigned residents are doing well.</p>
                    <p class="small text-muted">You will be notified immediately if any health concerns arise.</p>
                </div>
            <?php else: ?>
                <?php foreach ($alerts as $alert): ?>
                <div class="alert-card <?php echo $alert['resolved'] ? 'resolved' : ''; ?>">
                    <div class="row align-items-center">
                        <div class="col-lg-8">
                            <div class="d-flex align-items-start mb-2">
                                <div class="alert-icon <?php echo $alert['resolved'] ? 'resolved' : ''; ?>">
                                    <?php if (!$alert['resolved']): ?>
                                        <i class="fas fa-exclamation-circle pulse"></i>
                                    <?php else: ?>
                                        <i class="fas fa-check-circle"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="d-flex align-items-center mb-2">
                                        <span class="alert-type alert-<?php echo strtolower($alert['alert_type']); ?>">
                                            <?php echo strtoupper($alert['alert_type']); ?>
                                        </span>
                                        <span class="resident-badge">
                                            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($alert['resident_name']); ?>
                                        </span>
                                    </div>
                                    <h6 class="brand-font mb-2"><?php echo htmlspecialchars($alert['alert_message']); ?></h6>
                                    <div class="time-badge">
                                        <i class="far fa-clock me-1"></i><?php echo $alert['formatted_time']; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 text-lg-end">
                            <?php if (!$alert['resolved']): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="alert_id" value="<?php echo $alert['alert_id']; ?>">
                                    <button type="submit" name="resolve_alert" class="btn btn-resolve">
                                        <i class="fas fa-check me-1"></i>Mark as Resolved
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="badge" style="background: linear-gradient(135deg, var(--seafoam), var(--sage-green)); padding: 8px 15px;">
                                    <i class="fas fa-check me-1"></i>Resolved
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert Type Legend -->
    <div class="section-card">
        <div class="section-header">
            <h5 class="brand-font mb-0"><i class="fas fa-info-circle me-2"></i>Alert Types Guide</h5>
        </div>
        <div class="section-body">
            <div class="legend-grid">
                <div class="legend-item">
                    <div class="legend-color" style="background: linear-gradient(135deg, var(--warning-red), #ee5a52);"></div>
                    <span><strong>Critical</strong> - Requires immediate attention</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: linear-gradient(135deg, var(--warning-orange), #f57c00);"></div>
                    <span><strong>Warning</strong> - Needs monitoring</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: linear-gradient(135deg, var(--info-blue), #1976D2);"></div>
                    <span><strong>Info</strong> - Routine updates</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));"></div>
                    <span><strong>Health</strong> - Vital sign alerts</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Auto-refresh page every 60 seconds for new alerts
    setInterval(function() {
        location.reload();
    }, 60000);
    
    // Smooth scroll to top when clicking resolved alerts
    document.querySelectorAll('.btn-resolve').forEach(button => {
        button.addEventListener('click', function() {
            setTimeout(() => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }, 500);
        });
    });
    
    // Show notification when new alert comes
    const unresolvedCount = <?php echo $unresolved_count; ?>;
    if (unresolvedCount > 0) {
        // Play subtle notification sound if browser allows
        try {
            const audio = new Audio('https://assets.mixkit.co/sfx/preview/mixkit-alarm-digital-clock-beep-989.mp3');
            audio.volume = 0.3;
            audio.play().catch(e => console.log("Audio play failed:", e));
        } catch (e) {}
        
        // Change page title for attention
        let originalTitle = document.title;
        let alertBlink = false;
        setInterval(() => {
            if (alertBlink) {
                document.title = "(" + unresolvedCount + ") New Alerts - SmartCare";
            } else {
                document.title = originalTitle;
            }
            alertBlink = !alertBlink;
        }, 1000);
    }
</script>
</body>
</html>