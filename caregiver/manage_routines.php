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
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
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
    }

    body {
        font-family: 'Quicksand', sans-serif;
        background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
        min-height: 100vh;
        padding: 20px;
        color: #333;
    }

    h1, h2, h3, h4, h5 {
        font-family: 'Playfair Display', serif;
        color: var(--deep-emerald);
    }

    .brand-font {
        font-family: 'Jost', sans-serif;
        font-weight: 600;
    }

    /* Header */
    .header-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 20px;
        padding: 25px 30px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        margin-bottom: 30px;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    /* Cards */
    .card-custom {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 20px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        overflow: hidden;
        margin-bottom: 25px;
    }

    .card-header-custom {
        background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
        color: white;
        padding: 20px 30px;
        border: none;
        border-radius: 20px 20px 0 0 !important;
    }

    .card-header-custom h5 {
        color: white;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header-custom i {
        font-size: 1.2rem;
    }

    .card-body-custom {
        padding: 30px;
    }

    /* Today's Tasks */
    .task-item {
        background: white;
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        border-left: 5px solid var(--sage-green);
        transition: all 0.3s ease;
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

    .resident-name {
        font-weight: 600;
        color: var(--deep-emerald);
        font-size: 1.1rem;
    }

    .routine-type {
        background: var(--forest-mist);
        color: var(--deep-emerald);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        display: inline-block;
        margin-right: 10px;
    }

    .task-time {
        color: var(--dusty-teal);
        font-weight: 600;
        font-size: 0.9rem;
    }

    .days-badge {
        background: linear-gradient(135deg, var(--seafoam), var(--sage-green));
        color: white;
        padding: 4px 10px;
        border-radius: 15px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    /* Buttons */
    .btn-primary-custom {
        background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
        border: none;
        border-radius: 50px;
        color: white;
        padding: 10px 25px;
        font-weight: 600;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .btn-primary-custom::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, var(--dusty-teal), var(--sage-green));
        transition: left 0.4s ease;
    }

    .btn-primary-custom:hover::before {
        left: 0;
    }

    .btn-primary-custom span {
        position: relative;
        z-index: 2;
    }

    .btn-primary-custom:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 20px rgba(141, 182, 154, 0.4);
    }

    .btn-success {
        background: linear-gradient(135deg, var(--success-green), #1e7e34);
        border: none;
        border-radius: 10px;
        color: white;
        padding: 8px 16px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
    }

    .btn-info {
        background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal));
        border: none;
        border-radius: 10px;
        color: white;
        padding: 8px 16px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-info:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(141, 182, 154, 0.4);
    }

    .btn-danger {
        background: linear-gradient(135deg, var(--danger-red), #c82333);
        border: none;
        border-radius: 10px;
        color: white;
        padding: 8px 16px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-danger:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
    }

    .btn-outline-secondary {
        border: 2px solid var(--dusty-teal);
        color: var(--dusty-teal);
        border-radius: 50px;
        padding: 8px 20px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-outline-secondary:hover {
        background: var(--dusty-teal);
        color: white;
        transform: translateY(-2px);
    }

    /* Form Styles */
    .form-control-custom {
        border: 2px solid var(--forest-mist);
        border-radius: 12px;
        padding: 12px 15px;
        font-family: 'Quicksand', sans-serif;
        transition: all 0.3s ease;
        background: rgba(255, 255, 255, 0.9);
    }

    .form-control-custom:focus {
        border-color: var(--sage-green);
        box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
        transform: translateY(-1px);
    }

    .form-label {
        color: var(--deep-emerald);
        font-weight: 600;
        margin-bottom: 8px;
    }

    .form-check-input:checked {
        background-color: var(--sage-green);
        border-color: var(--sage-green);
    }

    /* Table Styles */
    .table-custom {
        background: transparent;
        border-collapse: separate;
        border-spacing: 0;
    }

    .table-custom thead {
        background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
        color: white;
    }

    .table-custom thead th {
        border: none;
        padding: 18px 15px;
        font-weight: 600;
        font-family: 'Jost', sans-serif;
        position: relative;
    }

    .table-custom tbody tr {
        background: white;
        transition: all 0.3s ease;
        border-bottom: 1px solid var(--forest-mist);
    }

    .table-custom tbody tr:hover {
        background: var(--light-sage);
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }

    .table-custom tbody td {
        padding: 16px 15px;
        vertical-align: middle;
        color: var(--deep-emerald);
        border: none;
    }

    /* Badges */
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

    /* Pagination */
    .pagination-custom .page-item.active .page-link {
        background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
        border-color: var(--sage-green);
        color: white;
    }

    .pagination-custom .page-link {
        color: var(--deep-emerald);
        border: 1px solid var(--forest-mist);
        margin: 0 3px;
        border-radius: 10px;
        transition: all 0.3s ease;
    }

    .pagination-custom .page-link:hover {
        background: var(--forest-mist);
        border-color: var(--dusty-teal);
    }

    /* Alerts */
    .alert-custom {
        background: linear-gradient(135deg, var(--seafoam), var(--forest-mist));
        border: none;
        border-radius: 15px;
        color: var(--deep-emerald);
        padding: 15px 20px;
        margin-bottom: 20px;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: var(--dusty-teal);
    }

    .empty-state i {
        font-size: 3rem;
        margin-bottom: 15px;
        opacity: 0.5;
    }

    /* Checkboxes for days */
    .days-checkboxes {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 8px;
        margin-top: 10px;
    }

    .day-checkbox {
        background: var(--light-sage);
        border: 2px solid var(--forest-mist);
        border-radius: 10px;
        padding: 8px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .day-checkbox input {
        display: none;
    }

    .day-checkbox span {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--deep-emerald);
    }

    .day-checkbox input:checked + span {
        color: white;
    }

    .day-checkbox input:checked ~ .day-checkbox {
        background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
        border-color: var(--sage-green);
    }

    .day-checkbox.checked {
        background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
        border-color: var(--sage-green);
        color: white;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .days-checkboxes {
            grid-template-columns: repeat(4, 1fr);
        }
        
        .header-container {
            padding: 20px;
        }
        
        .card-body-custom {
            padding: 20px;
        }
    }
  </style>
</head>
<body>
<div class="container-fluid">
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

  <!-- Header -->
  <div class="header-container">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h2 class="brand-font mb-2"><i class="fas fa-calendar-check me-2"></i>Manage Routines</h2>
        <p class="text-muted mb-0">Schedule and manage daily care routines for your residents</p>
      </div>
    </div>
  </div>

  <!-- Success Message -->
  <?php if (isset($_GET['msg'])): ?>
    <div class="alert-custom">
      <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_GET['msg']) ?>
    </div>
  <?php endif; ?>

  <div class="row">
    <!-- Today's Tasks -->
    <div class="col-lg-6">
      <div class="card-custom">
        <div class="card-header-custom">
          <h5><i class="fas fa-tasks me-2"></i>Today's Tasks — <?= date('l, M j, Y') ?></h5>
        </div>
        <div class="card-body-custom">
          <?php if ($todayTasks->num_rows === 0): ?>
            <div class="empty-state">
              <i class="fas fa-calendar-plus"></i>
              <h5>No Tasks Today</h5>
              <p class="text-muted">No routines scheduled for today. Add a new routine below!</p>
            </div>
          <?php else: ?>
            <?php while($r = $todayTasks->fetch_assoc()): ?>
              <div class="task-item <?= $r['status'] ?>">
                <div class="d-flex justify-content-between align-items-start">
                  <div class="flex-grow-1">
                    <div class="d-flex align-items-center mb-2">
                      <span class="resident-name"><?= htmlspecialchars($r['resident_name']) ?></span>
                      <span class="routine-type"><?= ucfirst(str_replace('_',' ', $r['routine_type'])) ?></span>
                      <span class="task-time">
                        <i class="far fa-clock me-1"></i><?= htmlspecialchars(date('h:i A', strtotime($r['schedule_time']))) ?>
                      </span>
                    </div>
                    <div class="mb-2 text-muted">
                      <i class="far fa-file-alt me-2"></i><?= htmlspecialchars($r['description']) ?>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                      <?php if ($r['days_of_week']): ?>
                        <span class="days-badge">
                          <i class="fas fa-calendar-alt me-1"></i><?= htmlspecialchars($r['days_of_week']) ?>
                        </span>
                      <?php endif; ?>
                      <?php if ($r['next_reminder']): ?>
                        <span class="text-muted small">
                          <i class="fas fa-bell me-1"></i>Reminder: <?= date('M j, g:i A', strtotime($r['next_reminder'])) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="d-flex flex-column gap-2 ms-3">
                    <?php if ($r['status'] !== 'completed'): ?>
                      <a href="complete_routine.php?id=<?= $r['id'] ?>" class="btn btn-success">
                        <i class="fas fa-check me-1"></i>Complete
                      </a>
                    <?php else: ?>
                      <span class="status-badge badge-completed">Completed</span>
                    <?php endif; ?>
                    <div class="d-flex gap-1">
                      <a class="btn btn-info" href="manage_routines.php?edit=<?= $r['id'] ?>">
                        <i class="fas fa-edit"></i>
                      </a>
                      <form action="routines_action.php" method="POST" class="d-inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="routine_id" value="<?= $r['id'] ?>">
                        <button class="btn btn-danger" onclick="return confirm('Delete this routine?')">
                          <i class="fas fa-trash"></i>
                        </button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            <?php endwhile; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Add/Edit Form -->
    <div class="col-lg-6">
      <div class="card-custom">
        <div class="card-header-custom">
          <h5><i class="fas fa-<?= $editing ? 'edit' : 'plus-circle' ?> me-2"></i><?= $editing ? 'Edit Routine' : 'Add New Routine' ?></h5>
        </div>
        <div class="card-body-custom">
          <form method="POST" action="routines_action.php" id="routineForm">
            <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
            <?php if ($editing): ?>
              <input type="hidden" name="routine_id" value="<?= (int)$editing['id'] ?>">
            <?php endif; ?>

            <div class="mb-3">
              <label class="form-label">Resident</label>
              <select name="resident_id" class="form-select form-control-custom" required>
                <option value="">-- Select Resident --</option>
                <?php $residents->data_seek(0); while($res=$residents->fetch_assoc()): ?>
                  <option value="<?= $res['user_id'] ?>" <?= $editing && $editing['resident_id']==$res['user_id'] ? 'selected':'' ?>>
                    <i class="fas fa-user me-2"></i><?= htmlspecialchars($res['full_name']) ?>
                  </option>
                <?php endwhile; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">Type</label>
              <select name="routine_type" class="form-select form-control-custom" required>
                <?php $types = ['medication'=>'Medication','meal'=>'Meal','exercise'=>'Exercise','personal_care'=>'Personal Care','other'=>'Other']; ?>
                <option value="">-- Select Type --</option>
                <?php foreach($types as $k=>$v): ?>
                  <option value="<?= $k ?>" <?= $editing && $editing['routine_type']==$k ? 'selected':'' ?>>
                    <i class="fas fa-<?= $k === 'medication' ? 'pills' : ($k === 'meal' ? 'utensils' : ($k === 'exercise' ? 'dumbbell' : ($k === 'personal_care' ? 'hands-wash' : 'calendar'))) ?> me-2"></i><?= $v ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control form-control-custom" rows="3" required placeholder="Enter routine description..."><?= $editing ? htmlspecialchars($editing['description']):'' ?></textarea>
            </div>

            <div class="mb-3">
              <label class="form-label">Schedule Time</label>
              <input type="time" name="schedule_time" class="form-control form-control-custom" value="<?= $editing ? $editing['schedule_time']:'' ?>" required>
            </div>

            <div class="mb-3">
              <label class="form-label">Repeat Days (check to repeat on selected days)</label>
              <div class="days-checkboxes">
                <?php
                  $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
                  $selected = $editing && $editing['days_of_week'] ? explode(',', $editing['days_of_week']) : [];
                ?>
                <?php foreach($days as $d): ?>
                  <label class="day-checkbox <?= in_array($d,$selected) ? 'checked' : '' ?>">
                    <input type="checkbox" name="days_of_week[]" value="<?= $d ?>"
                           <?= in_array($d,$selected) ? 'checked':'' ?>>
                    <span><?= substr($d, 0, 3) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="form-text text-muted mt-2">
                <i class="fas fa-info-circle me-1"></i>If no days are selected, the routine will be treated as one-time.
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Repeat Every (hours)</label>
              <input type="number" name="repeat_hours" class="form-control form-control-custom" min="0" max="168" value="<?= $editing ? (int)$editing['repeat_hours'] : 0 ?>" placeholder="Enter hours...">
              <div class="form-text text-muted">
                <i class="fas fa-info-circle me-1"></i>Set to 0 for no hourly repeat. If set, the routine will repeat every N hours after completion.
              </div>
            </div>

            <div class="mb-4 form-check">
              <input type="checkbox" class="form-check-input" id="send_reminder" name="send_reminder" value="1" <?= $editing && $editing['send_reminder'] ? 'checked':'' ?>>
              <label class="form-check-label" for="send_reminder">
                <i class="fas fa-bell me-2"></i>Send email reminder to caregiver and resident
              </label>
            </div>

            <div class="d-flex gap-3">
              <button class="btn btn-primary-custom">
                <span><i class="fas fa-<?= $editing ? 'save' : 'plus' ?> me-2"></i><?= $editing ? 'Update Routine' : 'Add Routine' ?></span>
              </button>
              <?php if ($editing): ?>
                <a href="manage_routines.php" class="btn btn-outline-secondary">
                  <i class="fas fa-times me-2"></i>Cancel
                </a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>
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
  <div class="card-custom mt-4">
    <div class="card-header-custom">
      <h5><i class="fas fa-list-alt me-2"></i>All Routines</h5>
    </div>
    <div class="card-body-custom">

      <!-- FILTER BAR -->
      <div class="filter-section">
        <form class="row g-3">
          <input type="hidden" name="p" value="1">

          <div class="col-lg-3 col-md-6">
            <div class="input-group">
              <span class="input-group-text" style="background: var(--forest-mist); border-color: var(--forest-mist); color: var(--deep-emerald);">
                <i class="fas fa-search"></i>
              </span>
              <input type="text" name="search" class="form-control form-control-custom"
                     placeholder="Search description / type / resident"
                     value="<?= htmlspecialchars($search) ?>">
            </div>
          </div>

          <div class="col-lg-2 col-md-6">
            <select name="f_resident" class="form-select form-control-custom">
              <option value="">All Residents</option>
              <?php $residents->data_seek(0); while($r=$residents->fetch_assoc()): ?>
                <option value="<?= $r['user_id'] ?>" <?= $f_resident==$r['user_id']?'selected':'' ?>>
                  <?= htmlspecialchars($r['full_name']) ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>

          <div class="col-lg-2 col-md-6">
            <select name="f_type" class="form-select form-control-custom">
              <option value="">All Types</option>
              <?php foreach($types as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $f_type==$k?'selected':'' ?>><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-lg-2 col-md-6">
            <select name="f_status" class="form-select form-control-custom">
              <option value="">All Status</option>
              <option value="pending"   <?= $f_status=='pending'?'selected':'' ?>>Pending</option>
              <option value="completed" <?= $f_status=='completed'?'selected':'' ?>>Completed</option>
              <option value="cancelled" <?= $f_status=='cancelled'?'selected':'' ?>>Cancelled</option>
            </select>
          </div>

          <div class="col-lg-2 col-md-6">
            <select name="f_day" class="form-select form-control-custom">
              <option value="">Any Day</option>
              <?php foreach($days as $d): ?>
                <option value="<?= $d ?>" <?= $f_day==$d?'selected':'' ?>><?= $d ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-lg-1 col-md-6">
            <button class="btn btn-primary-custom w-100">
              <span><i class="fas fa-filter me-2"></i>Filter</span>
            </button>
          </div>
        </form>
      </div>

      <div class="table-responsive">
        <table class="table table-custom">
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
                <td colspan="9" class="text-center py-4">
                  <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <h5>No Routines Found</h5>
                    <p class="text-muted">Try adjusting your filters or add new routines.</p>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php while($r = $list->fetch_assoc()): ?>
              <tr class="<?= $r['status']=='completed'?'table-success':'' ?>">
                <td>
                  <div class="task-time">
                    <i class="far fa-clock me-1"></i><?= date('h:i A', strtotime($r['schedule_time'])) ?>
                  </div>
                </td>
                <td>
                  <span class="routine-type"><?= ucfirst(str_replace('_',' ', $r['routine_type'])) ?></span>
                </td>
                <td class="resident-name"><?= htmlspecialchars($r['resident_name']) ?></td>
                <td>
                  <div class="text-muted small"><?= htmlspecialchars($r['description']) ?></div>
                </td>
                <td>
                  <?php if ($r['days_of_week']): ?>
                    <span class="days-badge"><?= htmlspecialchars($r['days_of_week']) ?></span>
                  <?php else: ?>
                    <em class="text-muted">One-time</em>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ((int)$r['repeat_hours'] > 0): ?>
                    <span class="text-muted">Every <?= (int)$r['repeat_hours'] ?>h</span>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['send_reminder']): ?>
                    <i class="fas fa-check text-success"></i> Yes
                    <?php if ($r['next_reminder']): ?>
                      <br><small class="text-muted"><?= date('M j, g:i A', strtotime($r['next_reminder'])) ?></small>
                    <?php endif; ?>
                  <?php else: ?>
                    <i class="fas fa-times text-danger"></i> No
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
                      <a href="complete_routine.php?id=<?= $r['id'] ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-check me-1"></i>Complete
                      </a>
                    <?php endif; ?>
                    <a href="manage_routines.php?edit=<?= $r['id'] ?>" class="btn btn-info btn-sm">
                      <i class="fas fa-edit"></i>
                    </a>
                    <form action="routines_action.php" method="POST" class="d-inline">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="routine_id" value="<?= $r['id'] ?>">
                      <button class="btn btn-danger btn-sm" onclick="return confirm('Delete this routine?')">
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

      <!-- PAGINATION -->
      <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-4">
          <ul class="pagination pagination-custom justify-content-center">
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
document.getElementById('routineForm')?.addEventListener('submit', function(e){
  const res = this.querySelector('[name="resident_id"]').value;
  if (!res) { 
    e.preventDefault(); 
    alert('Please select a resident.'); 
    return false; 
  }
});

// Day checkboxes styling
document.querySelectorAll('.day-checkbox').forEach(checkbox => {
  checkbox.addEventListener('click', function() {
    this.classList.toggle('checked');
    const input = this.querySelector('input');
    input.checked = !input.checked;
  });
});

// Filter form submit on enter
document.querySelectorAll('.form-control-custom').forEach(input => {
  input.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      this.closest('form').submit();
    }
  });
});
</script>
</body>
</html>