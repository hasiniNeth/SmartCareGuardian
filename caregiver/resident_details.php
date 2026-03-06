<?php
session_start();
require_once '../db_connection.php';
require_once '../includes/ai_service.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header('Location: ../login.php');
    exit();
}

$resident_id = $_GET['id'] ?? null;
if (!$resident_id) {
    header('Location: dashboard.php');
    exit();
}

$ai = new AIService();

// Get resident info
$query = "SELECT * FROM residents WHERE resident_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $resident_id);
$stmt->execute();
$resident = $stmt->get_result()->fetch_assoc();

if (!$resident) {
    header('Location: dashboard.php');
    exit();
}

// Get latest health log (for AI prediction)
$health_query = "SELECT * FROM health_logs 
                 WHERE resident_id = ? 
                 ORDER BY logged_at DESC 
                 LIMIT 1";
$stmt = $conn->prepare($health_query);
$stmt->bind_param('i', $resident_id);
$stmt->execute();
$latest_health = $stmt->get_result()->fetch_assoc();

// Get AI prediction if we have health data
$ai_prediction = null;
if ($latest_health) {
    $vitals = [
        'blood_pressure_systolic' => $latest_health['blood_pressure_systolic'],
        'blood_pressure_diastolic' => $latest_health['blood_pressure_diastolic'],
        'blood_sugar' => $latest_health['blood_sugar'],
        'pulse' => $latest_health['pulse'],
        'weight' => $latest_health['weight'],
        'temperature' => $latest_health['temperature'],
        'oxygen_saturation' => $latest_health['oxygen_saturation'],
        'logged_at' => $latest_health['logged_at']
    ];
    
    $prediction_result = $ai->getPrediction($resident_id, $vitals);
    if ($prediction_result['success']) {
        $ai_prediction = $prediction_result['data']['data'];
    }
}

// Get health history (last 7 days)
$history_query = "SELECT * FROM health_logs 
                  WHERE resident_id = ? 
                  ORDER BY logged_at DESC 
                  LIMIT 7";
$stmt = $conn->prepare($history_query);
$stmt->bind_param('i', $resident_id);
$stmt->execute();
$health_history = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?> - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .resident-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
        .stat-card {
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .risk-indicator {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .risk-high { background-color: #dc3545; }
        .risk-medium { background-color: #ffc107; }
        .risk-low { background-color: #28a745; }
    </style>
</head>
<body class="bg-light">
    <!-- Navigation (your existing nav) -->
    
    <div class="container mt-4">
        <!-- Resident Header -->
        <div class="resident-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2>
                        <i class="fas fa-user-circle"></i>
                        <?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?>
                    </h2>
                    <p class="mb-0">
                        <i class="fas fa-door-open"></i> Room <?php echo $resident['room_number']; ?> |
                        <i class="fas fa-birthday-cake"></i> Age <?php echo $resident['age']; ?> |
                        <i class="fas fa-venus-mars"></i> <?php echo ucfirst($resident['gender']); ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="log_health.php?resident=<?php echo $resident_id; ?>" class="btn btn-light btn-lg">
                        <i class="fas fa-plus"></i> Log Health Data
                    </a>
                </div>
            </div>
        </div>
        
        <!-- ⭐ AI HEALTH RISK ASSESSMENT -->
        <?php if ($ai_prediction): ?>
            <?php 
            $prediction = $ai_prediction['prediction'];
            $alerts = $ai_prediction['alerts'];
            $risk_info = $ai->formatRiskLevel($prediction['risk_level']);
            ?>
            
            <div class="card mb-4 border-<?php echo $risk_info['class']; ?>">
                <div class="card-header bg-<?php echo $risk_info['class']; ?> text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-brain"></i> AI Health Risk Assessment
                        <span class="float-end badge bg-light text-<?php echo $risk_info['class']; ?>">
                            Latest: <?php echo date('M d, Y H:i', strtotime($latest_health['logged_at'])); ?>
                        </span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <!-- Risk Level -->
                        <div class="col-md-4 text-center border-end">
                            <h3 class="mb-3">
                                <span class="badge bg-<?php echo $risk_info['class']; ?> p-3">
                                    <i class="fas fa-<?php echo $risk_info['icon']; ?>"></i>
                                    <?php echo $risk_info['text']; ?>
                                </span>
                            </h3>
                            <div class="mb-2">
                                <strong>Risk Probability</strong>
                                <h4 class="text-<?php echo $risk_info['class']; ?>">
                                    <?php echo $prediction['risk_percentage']; ?>%
                                </h4>
                            </div>
                            <div class="progress" style="height: 25px;">
                                <div class="progress-bar bg-<?php echo $risk_info['class']; ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo $prediction['risk_percentage']; ?>%">
                                    <?php echo $prediction['risk_percentage']; ?>%
                                </div>
                            </div>
                        </div>
                        
                        <!-- Latest Vitals -->
                        <div class="col-md-4 border-end">
                            <h6><i class="fas fa-heartbeat"></i> Latest Vital Signs</h6>
                            <ul class="list-unstyled mb-0">
                                <li><strong>BP:</strong> <?php echo $latest_health['blood_pressure_systolic']; ?>/<?php echo $latest_health['blood_pressure_diastolic']; ?> mmHg</li>
                                <li><strong>Blood Sugar:</strong> <?php echo $latest_health['blood_sugar']; ?> mg/dL</li>
                                <li><strong>Pulse:</strong> <?php echo $latest_health['pulse']; ?> bpm</li>
                                <li><strong>Temperature:</strong> <?php echo $latest_health['temperature']; ?> °C</li>
                                <li><strong>Oxygen:</strong> <?php echo $latest_health['oxygen_saturation']; ?>%</li>
                                <li><strong>Weight:</strong> <?php echo $latest_health['weight']; ?> kg</li>
                            </ul>
                        </div>
                        
                        <!-- Alerts -->
                        <div class="col-md-4">
                            <h6>
                                <i class="fas fa-bell"></i> Health Alerts
                                <span class="badge bg-<?php echo $alerts['total_alerts'] > 0 ? 'danger' : 'success'; ?>">
                                    <?php echo $alerts['total_alerts']; ?>
                                </span>
                            </h6>
                            <?php if ($alerts['total_alerts'] > 0): ?>
                                <div class="alert alert-warning p-2 mb-2" style="font-size: 0.9rem;">
                                    <?php foreach ($alerts['alerts'] as $alert): ?>
                                        <div class="mb-1">
                                            <strong><?php echo $alert['vital_sign']; ?>:</strong>
                                            <?php echo $alert['value']; ?> <?php echo $alert['unit']; ?>
                                            <span class="badge bg-<?php echo $ai->formatVitalStatus($alert['status'])['class']; ?>">
                                                <?php echo $alert['status']; ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted">
                                    <i class="fas fa-lightbulb"></i>
                                    <?php echo $alerts['alerts'][0]['recommendation'] ?? 'Monitor closely'; ?>
                                </small>
                            <?php else: ?>
                                <div class="alert alert-success p-2 mb-0">
                                    <i class="fas fa-check-circle"></i> All vitals within normal range
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($prediction['risk_level'] === 'high'): ?>
                        <div class="alert alert-danger mt-3 mb-0">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Action Required:</strong> High risk detected. Consider immediate medical consultation and increase monitoring frequency.
                        </div>
                    <?php elseif ($prediction['risk_level'] === 'medium'): ?>
                        <div class="alert alert-warning mt-3 mb-0">
                            <i class="fas fa-info-circle"></i>
                            <strong>Attention:</strong> Medium risk level. Monitor resident closely over the next 24-48 hours.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                No health data available yet. Log the first health reading to get AI risk assessment.
            </div>
        <?php endif; ?>
        
        <!-- Health History -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-history"></i> Recent Health History
                </h5>
            </div>
            <div class="card-body">
                <?php if ($health_history->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>BP</th>
                                    <th>Blood Sugar</th>
                                    <th>Pulse</th>
                                    <th>Temp</th>
                                    <th>O2</th>
                                    <th>Weight</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($log = $health_history->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y H:i', strtotime($log['logged_at'])); ?></td>
                                        <td><?php echo $log['blood_pressure_systolic']; ?>/<?php echo $log['blood_pressure_diastolic']; ?></td>
                                        <td><?php echo $log['blood_sugar']; ?></td>
                                        <td><?php echo $log['pulse']; ?></td>
                                        <td><?php echo $log['temperature']; ?>°C</td>
                                        <td><?php echo $log['oxygen_saturation']; ?>%</td>
                                        <td><?php echo $log['weight']; ?> kg</td>
                                        <td><?php echo htmlspecialchars($log['notes'] ?? '-'); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center py-4">No health history available</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>