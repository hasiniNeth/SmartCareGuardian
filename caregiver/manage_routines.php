<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php");
    exit();
}
include '../db_connection.php';

$caregiver_id = (int)$_SESSION['user_id'];
$today_date = date('Y-m-d');
$today_weekday = date('l'); // "Monday"...

/* DAILY AUTO-RESET:
   For routines that repeat on weekdays (days_of_week contains today),
   if last_completed_date != today then ensure status is 'pending' (so it reappears).
*/
$reset = $conn->prepare("
    UPDATE routines
    SET status = 'pending'
    WHERE caregiver_id = ? 
      AND days_of_week IS NOT NULL
      AND FIND_IN_SET(?, days_of_week)
      AND (last_completed_date IS NULL OR last_completed_date <> ?)
");
$reset->bind_param("iss", $caregiver_id, $today_weekday, $today_date);
$reset->execute();
$reset->close();

/* Fetch assigned residents (for dropdowns) */
$resStmt = $conn->prepare("
    SELECT u.user_id, u.full_name
    FROM users u
    JOIN caregiver_assignments ca ON u.user_id = ca.resident_id
    WHERE ca.caregiver_id = ?
    ORDER BY u.full_name
");
$resStmt->bind_param("i", $caregiver_id);
$resStmt->execute();
$residents = $resStmt->get_result();
$resStmt->close();

/* Today's tasks:
   Show routines that:
   - belong to this caregiver, AND
   - either days_of_week contains today OR days_of_week IS NULL (we treat as one-time/all-day),
   AND either:
     - status = 'pending'
     - OR status='completed' but repeat_hours>0 and last_completed_date != today (should have been reset earlier)
*/
$todayStmt = $conn->prepare("
    SELECT r.*, u.full_name as resident_name
    FROM routines r
    JOIN users u ON r.resident_id = u.user_id
    WHERE r.caregiver_id = ?
      AND (
            (r.days_of_week IS NOT NULL AND FIND_IN_SET(?, r.days_of_week))
            OR (r.days_of_week IS NULL)
          )
    ORDER BY r.schedule_time ASC
");
$todayStmt->bind_param("is", $caregiver_id, $today_weekday);
$todayStmt->execute();
$todayTasks = $todayStmt->get_result();
$todayStmt->close();

/* All routines (management table) */
$allStmt = $conn->prepare("
    SELECT r.*, u.full_name as resident_name
    FROM routines r
    JOIN users u ON r.resident_id = u.user_id
    WHERE r.caregiver_id = ?
    ORDER BY r.schedule_time ASC, r.id DESC
");
$allStmt->bind_param("i", $caregiver_id);
$allStmt->execute();
$allRoutines = $allStmt->get_result();
$allStmt->close();

/* If editing */
$editing = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $e = $conn->prepare("SELECT * FROM routines WHERE id=? AND caregiver_id=?");
    $e->bind_param("ii", $eid, $caregiver_id);
    $e->execute();
    $editing = $e->get_result()->fetch_assoc();
    $e->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Routines - SmartCare Guardian</title>
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
            --success-green: #28a745;
            --warning-orange: #ffc107;
            --danger-red: #dc3545;
            --info-blue: #17a2b8;
        }

        body {
            font-family: 'Quicksand', sans-serif;
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
            min-height: 100vh;
            margin: 0;
            padding: 0;
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

        .header-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Dashboard Cards */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            text-align: center;
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
            margin: 0 auto 15px;
        }

        .icon-today { background: linear-gradient(135deg, var(--seafoam), var(--sage-green)); color: white; }
        .icon-pending { background: linear-gradient(135deg, var(--warning-orange), #e0a800); color: white; }
        .icon-completed { background: linear-gradient(135deg, var(--success-green), #1e7e34); color: white; }
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
            font-size: 0.9rem;
        }

        /* Main Cards */
        .main-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 20px 30px;
            border: none;
            border-radius: 0;
        }

        .card-header h5 {
            color: white;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 30px;
        }

        /* Task Items */
        .task-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .task-item {
            background: white;
            border-radius: 15px;
            padding: 20px;
            border-left: 5px solid var(--sage-green);
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .task-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .task-item.completed {
            border-left-color: var(--success-green);
            background: var(--light-sage);
        }

        .task-item.cancelled {
            border-left-color: var(--danger-red);
            opacity: 0.8;
        }

        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .resident-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .resident-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .resident-name {
            font-weight: 600;
            color: var(--deep-emerald);
            font-size: 1.1rem;
        }

        .routine-type-badge {
            background: var(--forest-mist);
            color: var(--deep-emerald);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .task-time {
            color: var(--dusty-teal);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .task-description {
            color: #666;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .task-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .task-tags {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .tag {
            background: var(--light-sage);
            color: var(--deep-emerald);
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .task-actions {
            display: flex;
            gap: 8px;
        }

        /* Buttons */
        .btn-action {
            padding: 8px 15px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            border: none;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-green), #1e7e34);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, var(--info-blue), #138496);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-orange), #e0a800);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-red), #c82333);
            color: white;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(141, 182, 154, 0.4);
        }

        .btn-outline {
            border: 2px solid var(--dusty-teal);
            color: var(--dusty-teal);
            background: transparent;
            padding: 8px 20px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-outline:hover {
            background: var(--dusty-teal);
            color: white;
        }

        .btn-action:hover, .btn-success:hover, .btn-info:hover, .btn-warning:hover, .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        /* Form Styles */
        .form-control {
            border: 2px solid var(--forest-mist);
            border-radius: 12px;
            padding: 12px 15px;
            font-family: 'Quicksand', sans-serif;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }

        .form-control:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
            transform: translateY(-1px);
        }

        .form-label {
            color: var(--deep-emerald);
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-select {
            border: 2px solid var(--forest-mist);
            border-radius: 12px;
            padding: 12px 15px;
            font-family: 'Quicksand', sans-serif;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }

        .form-select:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
        }

        /* Days Checkboxes */
        .days-checkboxes {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
            margin-top: 10px;
        }

        .day-checkbox {
            background: var(--light-sage);
            border: 2px solid var(--forest-mist);
            border-radius: 10px;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .day-checkbox input {
            display: none;
        }

        .day-checkbox span {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--deep-emerald);
        }

        .day-checkbox.checked {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border-color: var(--sage-green);
            color: white;
        }

        .day-checkbox.checked span {
            color: white;
        }

        /* Filter Section */
        .filter-section {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Table Styles */
        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .table {
            margin: 0;
            background: transparent;
        }

        .table thead {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
        }

        .table thead th {
            border: none;
            padding: 20px 15px;
            font-weight: 600;
            font-family: 'Jost', sans-serif;
        }

        .table tbody tr {
            transition: all 0.3s ease;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }

        .table tbody tr:hover {
            background: var(--light-sage);
            transform: translateY(-1px);
        }

        .table tbody td {
            padding: 18px 15px;
            vertical-align: middle;
            border: none;
            color: var(--deep-emerald);
        }

        /* Status Badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-block;
        }

        .badge-pending {
            background: linear-gradient(135deg, var(--warning-orange), #e0a800);
            color: white;
        }

        .badge-completed {
            background: linear-gradient(135deg, var(--success-green), #1e7e34);
            color: white;
        }

        .badge-cancelled {
            background: linear-gradient(135deg, var(--danger-red), #c82333);
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--dusty-teal);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Pagination */
        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border-color: var(--sage-green);
            color: white;
        }

        .pagination .page-link {
            color: var(--deep-emerald);
            border: 1px solid var(--forest-mist);
            margin: 0 3px;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .pagination .page-link:hover {
            background: var(--forest-mist);
            border-color: var(--dusty-teal);
        }

        /* Success Message */
        .alert-success {
            background: linear-gradient(135deg, var(--seafoam), var(--forest-mist));
            border: none;
            border-radius: 15px;
            color: var(--deep-emerald);
            padding: 15px 20px;
            margin-bottom: 20px;
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
            
            .dashboard-stats {
                grid-template-columns: 1fr;
            }
            
            .days-checkboxes {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .task-header, .task-footer {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .task-actions {
                width: 100%;
                justify-content: flex-start;
            }
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
        <a href="#" class="active"><i class="fa-solid fa-calendar-check"></i> Manage Routines</a>
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
    <!-- Header -->
    <div class="header-section">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="brand-font mb-2">
                    <i class="fas fa-calendar-check me-2"></i>Manage Routines
                </h2>
                <p class="text-muted mb-0">Schedule and manage daily care routines for your residents</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted">
                    <i class="fas fa-calendar-day me-2"></i><?= date('l, F j, Y') ?>
                </span>
            </div>
        </div>
    </div>

    <?php
    // Calculate statistics
    $pending_count = 0;
    $completed_count = 0;
    $today_count = $todayTasks->num_rows;
    $residents_count = $residents->num_rows;
    
    $allRoutines->data_seek(0);
    while($r = $allRoutines->fetch_assoc()) {
        if ($r['status'] == 'pending') $pending_count++;
        if ($r['status'] == 'completed') $completed_count++;
    }
    ?>

    <!-- Success Message -->
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert-success mb-4">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_GET['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Add/Edit Form -->
    <div class="main-card mb-4">
        <div class="card-header">
            <h5>
                <i class="fas fa-<?= $editing ? 'edit' : 'plus-circle' ?> me-2"></i>
                <?= $editing ? 'Edit Routine' : 'Add New Routine' ?>
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" action="routines_action.php" id="routineForm">
                <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
                <?php if ($editing): ?>
                    <input type="hidden" name="routine_id" value="<?= (int)$editing['id'] ?>">
                <?php endif; ?>

                <div class="row">
                    <!-- Left Column -->
                    <div class="col-md-6">
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="fas fa-user me-2"></i>Resident
                            </label>
                            <select name="resident_id" class="form-select" required>
                                <option value="">-- Select Resident --</option>
                                <?php $residents->data_seek(0); while($res=$residents->fetch_assoc()): ?>
                                    <option value="<?= $res['user_id'] ?>" <?= $editing && $editing['resident_id']==$res['user_id'] ? 'selected':'' ?>>
                                        <?= htmlspecialchars($res['full_name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">
                                <i class="fas fa-tag me-2"></i>Type
                            </label>
                            <select name="routine_type" class="form-select" required>
                                <?php $types = ['medication'=>'Medication','meal'=>'Meal','exercise'=>'Exercise','personal_care'=>'Personal Care','other'=>'Other']; ?>
                                <option value="">-- Select Type --</option>
                                <?php foreach($types as $k=>$v): ?>
                                    <option value="<?= $k ?>" <?= $editing && $editing['routine_type']==$k ? 'selected':'' ?>>
                                        <i class="fas fa-<?= $k === 'medication' ? 'pills' : ($k === 'meal' ? 'utensils' : ($k === 'exercise' ? 'dumbbell' : ($k === 'personal_care' ? 'hands-wash' : 'calendar'))) ?> me-2"></i>
                                        <?= $v ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">
                                <i class="fas fa-clock me-2"></i>Schedule Time
                            </label>
                            <input type="time" name="schedule_time" class="form-control" 
                                   value="<?= $editing ? $editing['schedule_time']:'' ?>" required>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="col-md-6">
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="fas fa-redo me-2"></i>Repeat Every (hours)
                            </label>
                            <input type="number" name="repeat_hours" class="form-control" min="0" max="168" 
                                   value="<?= $editing ? (int)$editing['repeat_hours'] : 0 ?>" 
                                   placeholder="Enter hours...">
                            <div class="form-text text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Set to 0 for no hourly repeat. If set, the routine will repeat every N hours after completion.
                            </div>
                        </div>

                        <div class="mb-4 form-check">
                            <input type="checkbox" class="form-check-input" id="send_reminder" 
                                   name="send_reminder" value="1" 
                                   <?= $editing && $editing['send_reminder'] ? 'checked':'' ?>>
                            <label class="form-check-label" for="send_reminder">
                                <i class="fas fa-bell me-2"></i>Send email reminder to caregiver and resident
                            </label>
                        </div>

                        <div class="d-flex gap-3 mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-<?= $editing ? 'save' : 'plus' ?> me-2"></i>
                                <?= $editing ? 'Update Routine' : 'Add Routine' ?>
                            </button>
                            <?php if ($editing): ?>
                                <a href="manage_routines.php" class="btn btn-outline">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Full Width Sections -->
                <div class="row">
                    <div class="col-12">
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="fas fa-file-alt me-2"></i>Description
                            </label>
                            <textarea name="description" class="form-control" rows="2" required 
                                      placeholder="Enter routine description..."><?= $editing ? htmlspecialchars($editing['description']):'' ?></textarea>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="mb-4">
                            <label class="form-label">
                                <i class="fas fa-calendar-alt me-2"></i>Repeat Days
                            </label>
                            <div class="days-checkboxes">
                                <?php
                                $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
                                $selected = $editing && $editing['days_of_week'] ? explode(',', $editing['days_of_week']) : [];
                                ?>
                                <?php foreach($days as $d): ?>
                                    <label class="day-checkbox <?= in_array($d,$selected) ? 'checked' : '' ?>">
                                        <input type="checkbox" name="days_of_week[]" value="<?= $d ?>"
                                               <?= in_array($d,$selected) ? 'checked':'' ?>>
                                        <span><?= $d ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text text-muted mt-2">
                                <i class="fas fa-info-circle me-1"></i>
                                If no days are selected, the routine will be treated as one-time.
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Today's Tasks -->
    <div class="main-card mb-4">
        <div class="card-header">
            <h5><i class="fas fa-tasks me-2"></i>Today's Tasks — <?= date('l, M j, Y') ?></h5>
        </div>
        <div class="card-body">
            <?php if ($todayTasks->num_rows === 0): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-plus"></i>
                    <h5>No Tasks Today</h5>
                    <p class="text-muted">No routines scheduled for today. Add a new routine above!</p>
                </div>
            <?php else: ?>
                <div class="task-list">
                    <?php while($r = $todayTasks->fetch_assoc()): ?>
                        <div class="task-item <?= $r['status'] ?>">
                            <div class="task-header">
                                <div class="resident-info">
                                    <div class="resident-avatar">
                                        <?= strtoupper(substr($r['resident_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="resident-name"><?= htmlspecialchars($r['resident_name']) ?></div>
                                        <div class="d-flex align-items-center gap-2 mt-1">
                                            <span class="routine-type-badge">
                                                <?= ucfirst(str_replace('_',' ', $r['routine_type'])) ?>
                                            </span>
                                            <span class="task-time">
                                                <i class="far fa-clock"></i> <?= htmlspecialchars(date('h:i A', strtotime($r['schedule_time']))) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="task-actions">
                                    <?php if ($r['status'] !== 'completed'): ?>
                                        <a href="complete_routine.php?id=<?= $r['id'] ?>" class="btn-action btn-success">
                                            <i class="fas fa-check"></i> Complete
                                        </a>
                                    <?php else: ?>
                                        <span class="status-badge badge-completed">Completed</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="task-description">
                                <i class="fas fa-file-alt me-2 text-muted"></i>
                                <?= htmlspecialchars($r['description']) ?>
                            </div>
                            
                            <div class="task-footer">
                                <div class="task-tags">
                                    <?php if ($r['days_of_week']): ?>
                                        <span class="tag">
                                            <i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($r['days_of_week']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ((int)$r['repeat_hours'] > 0): ?>
                                        <span class="tag">
                                            <i class="fas fa-redo"></i> Every <?= (int)$r['repeat_hours'] ?>h
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($r['send_reminder']): ?>
                                        <span class="tag">
                                            <i class="fas fa-bell"></i> Reminders On
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="task-actions">
                                    <a class="btn-action btn-info" href="manage_routines.php?edit=<?= $r['id'] ?>" title="Edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <form action="routines_action.php" method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="routine_id" value="<?= $r['id'] ?>">
                                        <button class="btn-action btn-danger" onclick="return confirm('Delete this routine?')" title="Delete">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    /* ----------- FILTERING + SEARCH + PAGINATION LOGIC ----------- */

    // Pagination
    $limit = 10;
    $page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
    $offset = ($page - 1) * $limit;

    // Filters
    $f_resident = $_GET['f_resident'] ?? '';
    $f_type     = $_GET['f_type'] ?? '';
    $f_status   = $_GET['f_status'] ?? '';
    $f_day      = $_GET['f_day'] ?? '';
    $search     = trim($_GET['search'] ?? '');

    // Build WHERE conditions
    $where = ["r.caregiver_id = $caregiver_id"];

    if ($f_resident !== '') $where[] = "r.resident_id = " . (int)$f_resident;
    if ($f_type !== '')     $where[] = "r.routine_type = '" . $conn->real_escape_string($f_type) . "'";
    if ($f_status !== '')   $where[] = "r.status = '" . $conn->real_escape_string($f_status) . "'";
    if ($f_day !== '')      $where[] = "FIND_IN_SET('" . $conn->real_escape_string($f_day) . "', r.days_of_week)";
    if ($search !== '') {
        $safe = $conn->real_escape_string($search);
        $where[] = "(r.description LIKE '%$safe%' OR u.full_name LIKE '%$safe%' OR r.routine_type LIKE '%$safe%')";
    }

    $whereSql = implode(" AND ", $where);

    // Count total for pagination
    $countSql = "
        SELECT COUNT(*) AS total
        FROM routines r 
        JOIN users u ON r.resident_id=u.user_id
        WHERE $whereSql
    ";
    $total = $conn->query($countSql)->fetch_assoc()['total'];
    $totalPages = max(1, ceil($total / $limit));

    /* Fetch filtered routines */
    $listSql = "
        SELECT r.*, u.full_name AS resident_name
        FROM routines r
        JOIN users u ON r.resident_id = u.user_id
        WHERE $whereSql
        ORDER BY r.schedule_time ASC, r.id DESC
        LIMIT $limit OFFSET $offset
    ";
    $list = $conn->query($listSql);
    ?>

    <!-- All Routines -->
    <div class="main-card mt-4">
        <div class="card-header">
            <h5><i class="fas fa-list-alt me-2"></i>All Routines</h5>
        </div>
        <div class="card-body">
            <!-- FILTER BAR -->
            <div class="filter-section">
                <form class="row g-3">
                    <input type="hidden" name="p" value="1">

                    <div class="col-lg-2 col-md-6">
                        <select name="f_resident" class="form-select">
                            <option value="">All Residents</option>
                            <?php $residents->data_seek(0); while($r=$residents->fetch_assoc()): ?>
                                <option value="<?= $r['user_id'] ?>" <?= $f_resident==$r['user_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($r['full_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <select name="f_type" class="form-select">
                            <option value="">All Types</option>
                            <?php foreach($types as $k=>$v): ?>
                                <option value="<?= $k ?>" <?= $f_type==$k?'selected':'' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <select name="f_status" class="form-select">
                            <option value="">All Status</option>
                            <option value="pending"   <?= $f_status=='pending'?'selected':'' ?>>Pending</option>
                            <option value="completed" <?= $f_status=='completed'?'selected':'' ?>>Completed</option>
                            <option value="cancelled" <?= $f_status=='cancelled'?'selected':'' ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <select name="f_day" class="form-select">
                            <option value="">Any Day</option>
                            <?php foreach($days as $d): ?>
                                <option value="<?= $d ?>" <?= $f_day==$d?'selected':'' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-4 col-md-6">
                        <button class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i>Filter
                        </button>
                    </div>
                </form>
            </div>

            <div class="table-container">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><i class="far fa-clock me-2"></i>Time</th>
                                <th><i class="fas fa-tag me-2"></i>Type</th>
                                <th><i class="fas fa-user me-2"></i>Resident</th>
                                <th><i class="far fa-file-alt me-2"></i>Description</th>
                                <th><i class="far fa-calendar-alt me-2"></i>Days</th>
                                <th><i class="fas fa-redo me-2"></i>Repeat</th>
                                <th><i class="fas fa-bell me-2"></i>Reminder</th>
                                <th><i class="fas fa-circle me-2"></i>Status</th>
                                <th style="width:200px"><i class="fas fa-bolt me-2"></i>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($list->num_rows == 0): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <div class="empty-state">
                                            <i class="fas fa-search"></i>
                                            <h5>No Routines Found</h5>
                                            <p class="text-muted">Try adjusting your filters or add new routines.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php while($r = $list->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="task-time">
                                            <i class="far fa-clock"></i> <?= date('h:i A', strtotime($r['schedule_time'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="routine-type-badge"><?= ucfirst(str_replace('_',' ', $r['routine_type'])) ?></span>
                                    </td>
                                    <td>
                                        <div class="resident-name"><?= htmlspecialchars($r['resident_name']) ?></div>
                                    </td>
                                    <td>
                                        <div class="text-muted small"><?= htmlspecialchars($r['description']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($r['days_of_week']): ?>
                                            <span class="tag">
                                                <i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($r['days_of_week']) ?>
                                            </span>
                                        <?php else: ?>
                                            <em class="text-muted">One-time</em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$r['repeat_hours'] > 0): ?>
                                            <span class="tag">
                                                <i class="fas fa-redo"></i> <?= (int)$r['repeat_hours'] ?>h
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($r['send_reminder']): ?>
                                            <span class="tag">
                                                <i class="fas fa-check"></i> Yes
                                            </span>
                                            <?php if ($r['next_reminder']): ?>
                                                <br><small class="text-muted"><?= date('M j, g:i A', strtotime($r['next_reminder'])) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge badge-<?= $r['status'] ?>">
                                            <?= ucfirst($r['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <?php if ($r['status'] !== 'completed'): ?>
                                                <a href="complete_routine.php?id=<?= $r['id'] ?>" class="btn-action btn-success" title="Complete">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="manage_routines.php?edit=<?= $r['id'] ?>" class="btn-action btn-info" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form action="routines_action.php" method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="routine_id" value="<?= $r['id'] ?>">
                                                <button class="btn-action btn-danger" onclick="return confirm('Delete this routine?')" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PAGINATION -->
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php for ($i=1; $i<=$totalPages; $i++): ?>
                            <li class="page-item <?= $i==$page ? 'active' : '' ?>">
                                <a class="page-link"
                                   href="?<?= http_build_query(array_merge($_GET, ['p'=>$i])) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form validation
    const routineForm = document.getElementById('routineForm');
    if (routineForm) {
        routineForm.addEventListener('submit', function(e) {
            const residentSelect = this.querySelector('[name="resident_id"]');
            if (!residentSelect.value) {
                e.preventDefault();
                alert('Please select a resident.');
                residentSelect.focus();
                return false;
            }
        });
    }

    // Day checkboxes styling
    document.querySelectorAll('.day-checkbox').forEach(box => {
        const input = box.querySelector('input');

        // When the label is clicked, allow checkbox to toggle normally
        box.addEventListener('click', function (e) {
            // Let the checkbox toggle by default — DO NOTHING HERE
        });

        // After checkbox changes, update styling
        input.addEventListener('change', function () {
            if (this.checked) {
                box.classList.add('checked');
            } else {
                box.classList.remove('checked');
            }
        });
    });

    // Auto-submit filter form on filter change (except search)
    document.querySelectorAll('select[name^="f_"]').forEach(select => {
        select.addEventListener('change', function() {
            this.form.submit();
        });
    });

    // Auto-submit search with delay
    let searchTimeout;
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.form.submit();
            }, 500);
        });
    }
});
</script>
</body>
</html>