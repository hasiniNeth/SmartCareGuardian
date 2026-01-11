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

// Get health logs with pagination
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Get total count
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM health_logs WHERE resident_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_result = $stmt->get_result()->fetch_assoc();
$total_logs = $total_result['total'];
$total_pages = ceil($total_logs / $limit);

// Get health logs for current page
$stmt = $conn->prepare("
    SELECT hl.*, 
           DATE_FORMAT(hl.logged_at, '%b %d, %Y') as log_date,
           DATE_FORMAT(hl.logged_at, '%h:%i %p') as log_time,
           u.full_name as caregiver_name
    FROM health_logs hl
    JOIN users u ON hl.caregiver_id = u.user_id
    WHERE hl.resident_id = ?
    ORDER BY hl.logged_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iii", $user_id, $limit, $offset);
$stmt->execute();
$health_logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get health statistics
$stmt = $conn->prepare("
    SELECT 
        AVG(blood_pressure_systolic) as avg_bp_systolic,
        AVG(blood_pressure_diastolic) as avg_bp_diastolic,
        AVG(blood_sugar) as avg_sugar,
        AVG(pulse) as avg_pulse,
        AVG(weight) as avg_weight,
        AVG(temperature) as avg_temp,
        AVG(oxygen_saturation) as avg_oxygen,
        MAX(weight) as max_weight,
        MIN(weight) as min_weight,
        MAX(blood_sugar) as max_sugar,
        MIN(blood_sugar) as min_sugar
    FROM health_logs 
    WHERE resident_id = ? 
    AND logged_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$health_stats = $stmt->get_result()->fetch_assoc();

// Get latest health log
$stmt = $conn->prepare("
    SELECT * FROM health_logs 
    WHERE resident_id = ? 
    ORDER BY logged_at DESC 
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$latest_health = $stmt->get_result()->fetch_assoc();

// Get health trends (last 7 days)
$stmt = $conn->prepare("
    SELECT 
        DATE(logged_at) as date,
        AVG(blood_pressure_systolic) as bp_sys,
        AVG(blood_pressure_diastolic) as bp_dia,
        AVG(blood_sugar) as sugar,
        AVG(pulse) as pulse,
        AVG(weight) as weight
    FROM health_logs 
    WHERE resident_id = ? 
    AND logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(logged_at)
    ORDER BY date
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$health_trends = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Health Data - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Quicksand:wght@300;400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --sage-green: #87A96B;
            --mint-cream: #F0FFF0;
            --seafoam: #9FE2BF;
            --forest-mist: #B8E0D2;
            --dusty-teal: #6D9B8E;
            --deep-emerald: #4A766E;
            --heart-red: #FF6B6B;
            --bp-blue: #4285F4;
            --sugar-purple: #9C27B0;
            --weight-orange: #FF9800;
        }
        
        body {
            font-family: 'Quicksand', sans-serif;
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
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
            padding: 25px 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .health-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
        }
        
        .health-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(0,0,0,0.12);
        }
        
        .metric-card {
            text-align: center;
            padding: 20px;
            border-radius: 15px;
            margin: 10px 0;
            transition: all 0.3s ease;
        }
        
        .metric-card:hover {
            transform: scale(1.05);
        }
        
        .metric-value {
            font-size: 2.2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .metric-label {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 10px;
        }
        
        .metric-trend {
            font-size: 12px;
            padding: 2px 10px;
            border-radius: 10px;
            display: inline-block;
        }
        
        .trend-up { background: #FFE5E5; color: #D32F2F; }
        .trend-down { background: #E8F5E8; color: #388E3C; }
        .trend-stable { background: #E3F2FD; color: #1976D2; }
        
        .health-log-item {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 5px solid var(--sage-green);
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .health-log-item:hover {
            transform: translateX(10px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .log-date {
            color: var(--dusty-teal);
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .log-caregiver {
            font-size: 12px;
            color: #888;
        }
        
        .vital-sign {
            display: inline-block;
            background: var(--mint-cream);
            padding: 8px 15px;
            border-radius: 25px;
            margin: 5px;
            font-size: 14px;
        }
        
        .vital-value {
            font-weight: bold;
            color: var(--deep-emerald);
        }
        
        .chart-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
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
        
        .nav-btn.active {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
        }
        
        .pagination {
            justify-content: center;
            margin-top: 30px;
        }
        
        .page-link {
            border: none;
            color: var(--deep-emerald);
            margin: 0 5px;
            border-radius: 10px;
        }
        
        .page-item.active .page-link {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
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
            animation: gentleFloat 4s ease-in-out infinite;
        }
        
        @keyframes gentleFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        
        .status-normal { background: #4CAF50; }
        .status-warning { background: #FF9800; }
        .status-critical { background: #F44336; }
        
        .filter-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .filter-btn {
            padding: 8px 15px;
            border: 2px solid var(--forest-mist);
            background: white;
            border-radius: 20px;
            color: var(--deep-emerald);
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .filter-btn:hover, .filter-btn.active {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            border-color: var(--sage-green);
        }
        
        .export-btn {
            background: linear-gradient(135deg, var(--dusty-teal), var(--deep-emerald));
            color: white;
            border: none;
            border-radius: 25px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .export-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(77, 126, 108, 0.3);
        }
        
        @media print {
            .no-print { display: none !important; }
            .health-card { box-shadow: none; border: 1px solid #ddd; }
        }
        
        @media (max-width: 768px) {
            .vital-sign {
                display: block;
                margin: 5px 0;
            }
            
            .metric-value {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <!-- Elder Header -->
    <div class="elder-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-5 fw-bold mb-2">My Health Data</h1>
                    <p class="mb-0">
                        <i class="fas fa-heartbeat me-2"></i>Track and monitor your health vitals
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="d-flex flex-wrap justify-content-md-end gap-2 no-print">
                        <button class="btn btn-light btn-sm" onclick="window.print()">
                            <i class="fas fa-print me-1"></i> Print Report
                        </button>
                        <a href="elder_dashboard.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container py-4">
        <div class="row">
            <!-- Left Navigation -->
            <div class="col-lg-3 mb-4 no-print">
                <div class="health-card">
                    <h4 class="brand-font mb-4">
                        <i class="fas fa-compass me-2"></i>Navigation
                    </h4>
                    
                    <button class="nav-btn active" onclick="location.href='elder_health.php'">
                        <i class="fas fa-heartbeat"></i> Health Data
                    </button>
                    
                    <button class="nav-btn" onclick="location.href='elder_dashboard.php'">
                        <i class="fas fa-home"></i> Dashboard
                    </button>
                    
                    <button class="nav-btn" onclick="location.href='elder_schedule.php'">
                        <i class="fas fa-calendar-alt"></i> Schedule
                    </button>
                    
                    <button class="nav-btn" onclick="location.href='elder_medications.php'">
                        <i class="fas fa-pills"></i> Medications
                    </button>
                    
                    <button class="nav-btn" onclick="location.href='elder_messages.php'">
                        <i class="fas fa-comments"></i> Messages
                    </button>
                    
                    <hr class="my-4">
                    
                    <h5 class="brand-font mb-3">
                        <i class="fas fa-chart-line me-2"></i>Health Summary
                    </h5>
                    <div class="small text-muted">
                        <p><i class="fas fa-check-circle text-success me-2"></i>Total Records: <?php echo $total_logs; ?></p>
                        <p><i class="far fa-calendar me-2"></i>Last 30 Days</p>
                        <p><i class="fas fa-user-nurse me-2"></i>Monitored by Caregivers</p>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="health-card">
                    <h5 class="brand-font mb-3">
                        <i class="fas fa-chart-pie me-2"></i>Quick Stats
                    </h5>
                    <?php if ($health_stats && $health_stats['avg_bp_systolic']): ?>
                        <div class="small">
                            <p><span class="status-indicator status-normal"></span> Avg BP: <?php echo round($health_stats['avg_bp_systolic']); ?>/<?php echo round($health_stats['avg_bp_diastolic']); ?></p>
                            <p><span class="status-indicator status-normal"></span> Avg Sugar: <?php echo round($health_stats['avg_sugar'], 1); ?> mg/dL</p>
                            <p><span class="status-indicator status-normal"></span> Avg Pulse: <?php echo round($health_stats['avg_pulse']); ?> bpm</p>
                            <p><span class="status-indicator status-normal"></span> Avg Weight: <?php echo round($health_stats['avg_weight'], 1); ?> kg</p>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small">No statistics available yet.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Main Health Content -->
            <div class="col-lg-9">
                <!-- Current Vitals Summary -->
                <div class="health-card">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h3 class="brand-font mb-0">
                            <i class="fas fa-heartbeat me-2"></i>Current Health Status
                        </h3>
                        <span class="badge bg-success">
                            <?php echo $latest_health ? 'Updated Today' : 'No Data Yet'; ?>
                        </span>
                    </div>
                    
                    <?php if ($latest_health): ?>
                        <div class="row">
                            <div class="col-md-3 col-6">
                                <div class="metric-card" style="background: linear-gradient(135deg, #E3F2FD, #BBDEFB);">
                                    <div class="metric-value"><?php echo htmlspecialchars($latest_health['blood_pressure_systolic'] ?? '--'); ?>/<?php echo htmlspecialchars($latest_health['blood_pressure_diastolic'] ?? '--'); ?></div>
                                    <div class="metric-label">Blood Pressure</div>
                                    <span class="metric-trend trend-stable">Normal</span>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="metric-card" style="background: linear-gradient(135deg, #F3E5F5, #E1BEE7);">
                                    <div class="metric-value"><?php echo htmlspecialchars($latest_health['blood_sugar'] ?? '--'); ?></div>
                                    <div class="metric-label">Blood Sugar (mg/dL)</div>
                                    <span class="metric-trend trend-stable">Stable</span>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="metric-card" style="background: linear-gradient(135deg, #FFEBEE, #FFCDD2);">
                                    <div class="metric-value"><?php echo htmlspecialchars($latest_health['pulse'] ?? '--'); ?></div>
                                    <div class="metric-label">Heart Rate (bpm)</div>
                                    <span class="metric-trend trend-stable">Normal</span>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="metric-card" style="background: linear-gradient(135deg, #FFF3E0, #FFE0B2);">
                                    <div class="metric-value"><?php echo htmlspecialchars($latest_health['weight'] ?? '--'); ?> kg</div>
                                    <div class="metric-label">Weight</div>
                                    <span class="metric-trend trend-stable">Stable</span>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($latest_health['temperature'] || $latest_health['oxygen_saturation']): ?>
                            <div class="row mt-3">
                                <?php if ($latest_health['temperature']): ?>
                                    <div class="col-md-4 col-6">
                                        <div class="metric-card" style="background: linear-gradient(135deg, #E8F5E9, #C8E6C9);">
                                            <div class="metric-value"><?php echo htmlspecialchars($latest_health['temperature']); ?>°C</div>
                                            <div class="metric-label">Temperature</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($latest_health['oxygen_saturation']): ?>
                                    <div class="col-md-4 col-6">
                                        <div class="metric-card" style="background: linear-gradient(135deg, #E0F7FA, #B2EBF2);">
                                            <div class="metric-value"><?php echo htmlspecialchars($latest_health['oxygen_saturation']); ?>%</div>
                                            <div class="metric-label">Oxygen Level</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($latest_health['notes']): ?>
                            <div class="mt-4 p-3 rounded" style="background: #E8F5E8; border-left: 4px solid #4CAF50;">
                                <h6 class="brand-font mb-2">
                                    <i class="fas fa-sticky-note me-2"></i>Caregiver Notes
                                </h6>
                                <p class="mb-0"><?php echo htmlspecialchars($latest_health['notes']); ?></p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <div class="empty-icon">
                                <i class="fas fa-heartbeat"></i>
                            </div>
                            <h5 class="brand-font mb-3">No Health Data Available</h5>
                            <p class="text-muted">Your caregiver will record your health vitals soon.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Health Trends Chart -->
                <?php if (!empty($health_trends)): ?>
                    <div class="health-card">
                        <h4 class="brand-font mb-4">
                            <i class="fas fa-chart-line me-2"></i>Health Trends (Last 7 Days)
                        </h4>
                        <div class="chart-container">
                            <canvas id="healthTrendsChart"></canvas>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Health History -->
                <div class="health-card">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h3 class="brand-font mb-0">
                            <i class="fas fa-history me-2"></i>Health History
                        </h3>
                        <div class="d-flex gap-2">
                            <div class="filter-buttons">
                                <button class="filter-btn active" onclick="filterLogs('all')">All</button>
                                <button class="filter-btn" onclick="filterLogs('week')">This Week</button>
                                <button class="filter-btn" onclick="filterLogs('month')">This Month</button>
                            </div>
                            <button class="export-btn no-print" onclick="exportHealthData()">
                                <i class="fas fa-download me-1"></i> Export
                            </button>
                        </div>
                    </div>
                    
                    <?php if (!empty($health_logs)): ?>
                        <?php foreach ($health_logs as $log): ?>
                            <div class="health-log-item" data-date="<?php echo $log['log_date']; ?>">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <div class="log-date">
                                            <i class="far fa-calendar me-1"></i><?php echo $log['log_date']; ?>
                                            <i class="far fa-clock ms-3 me-1"></i><?php echo $log['log_time']; ?>
                                        </div>
                                        <div class="log-caregiver">
                                            <i class="fas fa-user-nurse me-1"></i>Recorded by: <?php echo htmlspecialchars($log['caregiver_name']); ?>
                                        </div>
                                    </div>
                                    <?php if ($log['notes']): ?>
                                        <span class="badge bg-info">
                                            <i class="fas fa-sticky-note me-1"></i>Has Notes
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="vital-signs">
                                    <span class="vital-sign">
                                        <i class="fas fa-tint me-1" style="color: var(--bp-blue);"></i>
                                        BP: <span class="vital-value"><?php echo htmlspecialchars($log['blood_pressure_systolic']); ?>/<?php echo htmlspecialchars($log['blood_pressure_diastolic']); ?></span>
                                    </span>
                                    
                                    <span class="vital-sign">
                                        <i class="fas fa-syringe me-1" style="color: var(--sugar-purple);"></i>
                                        Sugar: <span class="vital-value"><?php echo htmlspecialchars($log['blood_sugar']); ?> mg/dL</span>
                                    </span>
                                    
                                    <span class="vital-sign">
                                        <i class="fas fa-heart me-1" style="color: var(--heart-red);"></i>
                                        Pulse: <span class="vital-value"><?php echo htmlspecialchars($log['pulse']); ?> bpm</span>
                                    </span>
                                    
                                    <span class="vital-sign">
                                        <i class="fas fa-weight me-1" style="color: var(--weight-orange);"></i>
                                        Weight: <span class="vital-value"><?php echo htmlspecialchars($log['weight']); ?> kg</span>
                                    </span>
                                    
                                    <?php if ($log['temperature']): ?>
                                        <span class="vital-sign">
                                            <i class="fas fa-thermometer-half me-1" style="color: #FF5722;"></i>
                                            Temp: <span class="vital-value"><?php echo htmlspecialchars($log['temperature']); ?>°C</span>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($log['oxygen_saturation']): ?>
                                        <span class="vital-sign">
                                            <i class="fas fa-wind me-1" style="color: #00BCD4;"></i>
                                            Oxygen: <span class="vital-value"><?php echo htmlspecialchars($log['oxygen_saturation']); ?>%</span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($log['notes']): ?>
                                    <div class="mt-3 p-3 rounded" style="background: #F8F9FA; border-left: 3px solid var(--dusty-teal);">
                                        <p class="mb-0 small"><strong>Notes:</strong> <?php echo htmlspecialchars($log['notes']); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="Health logs pagination">
                                <ul class="pagination">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?>">
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?>">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                            <h5 class="brand-font mb-3">No Health Records Yet</h5>
                            <p class="text-muted">Your health data will appear here once recorded by caregivers.</p>
                            <p class="small text-muted">Check back soon for updates on your health vitals.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Health Trends Chart
        <?php if (!empty($health_trends)): ?>
            const healthTrendsData = {
                labels: [<?php echo implode(',', array_map(function($trend) { return "'" . date('M d', strtotime($trend['date'])) . "'"; }, $health_trends)); ?>],
                datasets: [
                    {
                        label: 'Blood Sugar (mg/dL)',
                        data: [<?php echo implode(',', array_column($health_trends, 'sugar')); ?>],
                        borderColor: '#9C27B0',
                        backgroundColor: 'rgba(156, 39, 176, 0.1)',
                        borderWidth: 2,
                        tension: 0.4
                    },
                    {
                        label: 'Heart Rate (bpm)',
                        data: [<?php echo implode(',', array_column($health_trends, 'pulse')); ?>],
                        borderColor: '#FF6B6B',
                        backgroundColor: 'rgba(255, 107, 107, 0.1)',
                        borderWidth: 2,
                        tension: 0.4
                    },
                    {
                        label: 'Weight (kg)',
                        data: [<?php echo implode(',', array_column($health_trends, 'weight')); ?>],
                        borderColor: '#FF9800',
                        backgroundColor: 'rgba(255, 152, 0, 0.1)',
                        borderWidth: 2,
                        tension: 0.4
                    }
                ]
            };
            
            const healthTrendsConfig = {
                type: 'line',
                data: healthTrendsData,
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: true,
                            text: 'Health Trends Over Time'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false
                        }
                    }
                },
            };
            
            document.addEventListener('DOMContentLoaded', function() {
                const ctx = document.getElementById('healthTrendsChart').getContext('2d');
                new Chart(ctx, healthTrendsConfig);
            });
        <?php endif; ?>
        
        // Filter logs by time period
        function filterLogs(period) {
            const filterBtns = document.querySelectorAll('.filter-btn');
            filterBtns.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            const logs = document.querySelectorAll('.health-log-item');
            const now = new Date();
            
            logs.forEach(log => {
                const logDate = new Date(log.dataset.date);
                let showLog = true;
                
                switch(period) {
                    case 'week':
                        const oneWeekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
                        showLog = logDate >= oneWeekAgo;
                        break;
                    case 'month':
                        const oneMonthAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
                        showLog = logDate >= oneMonthAgo;
                        break;
                }
                
                log.style.display = showLog ? 'block' : 'none';
            });
            
            // Show message if no logs match filter
            const visibleLogs = Array.from(logs).filter(log => log.style.display !== 'none');
            if (visibleLogs.length === 0 && period !== 'all') {
                showToast('No records found for selected period');
            }
        }
        
        // Export health data
        function exportHealthData() {
            let csvContent = "Date,Time,Blood Pressure,Blood Sugar,Heart Rate,Weight,Temperature,Oxygen,Notes\n";

            <?php foreach ($health_logs as $log): ?>
                csvContent += "<?php
                    echo addslashes(
                        $log['log_date'] . "," .
                        $log['log_time'] . "," .
                        $log['blood_pressure_systolic'] . "/" . $log['blood_pressure_diastolic'] . "," .
                        $log['blood_sugar'] . "," .
                        $log['pulse'] . "," .
                        $log['weight'] . "," .
                        ($log['temperature'] ?? '') . "," .
                        ($log['oxygen_saturation'] ?? '') . "," .
                        '"' . str_replace('"', '""', $log['notes'] ?? '') . '"'
                    );
                ?>\n";
            <?php endforeach; ?>

            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);

            const link = document.createElement("a");
            link.href = url;
            link.download = "health-data-<?php echo date('Y-m-d'); ?>.csv";
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            showToast("Health data exported successfully");
        }
        
        // Show toast notification
        function showToast(message) {
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
            
            setTimeout(() => {
                toast.remove();
            }, 3000);
        }
        
        // Print functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Add print button functionality
            document.querySelector('[onclick="window.print()"]').addEventListener('click', function() {
                // Show loading message
                showToast('Preparing print view...');
            });
        });
        
        // Auto-refresh health data every 5 minutes
        setInterval(() => {
            const visibleLogs = document.querySelectorAll('.health-log-item');
            if (visibleLogs.length > 0) {
                // Reload page if there are logs
                location.reload();
            }
        }, 300000); // 5 minutes
    </script>
</body>
</html>