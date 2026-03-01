<?php
session_start();
include 'db_connection.php';

// --- AUTH CHECK ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// --- AJAX endpoints (return JSON) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_residents' && isset($_GET['caregiver_id'])) {
    $caregiver_id = intval($_GET['caregiver_id']);
    $stmt = $conn->prepare("
        SELECT u.user_id, u.full_name, u.email, u.status
        FROM caregiver_assignments a
        JOIN users u ON a.resident_id = u.user_id
        WHERE a.caregiver_id = ?
        ORDER BY u.full_name
    ");
    $stmt->bind_param("i", $caregiver_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = [];
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'residents' => $data]);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'chart_data') {
    // Return caregiver names and counts (include zeros)
    $sql = "
        SELECT c.user_id, c.full_name,
            IFNULL(COUNT(a.resident_id),0) AS assigned_count
        FROM users c
        LEFT JOIN caregiver_assignments a ON a.caregiver_id = c.user_id
        WHERE c.role = 'caregiver'
        GROUP BY c.user_id, c.full_name
        ORDER BY c.full_name
    ";
    $res = $conn->query($sql);
    $labels = [];
    $counts = [];
    while ($row = $res->fetch_assoc()) {
        $labels[] = $row['full_name'];
        $counts[] = intval($row['assigned_count']);
    }
    header('Content-Type: application/json');
    echo json_encode(['labels' => $labels, 'counts' => $counts]);
    exit();
}

// --- Handle form submission (assign caregiver) ---
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_submit'])) {
    $resident_id = intval($_POST['resident_id']);
    $caregiver_id = intval($_POST['caregiver_id']);

    // Check resident exists and is active
    $c = $conn->prepare("SELECT user_id FROM users WHERE user_id=? AND role='resident'");
    $c->bind_param("i", $resident_id);
    $c->execute();
    $cres = $c->get_result();
    if ($cres->num_rows === 0) {
        $message = "<div class='alert alert-danger'>Selected resident does not exist.</div>";
    } else {
        // Check if resident already assigned
        $check = $conn->prepare("SELECT * FROM caregiver_assignments WHERE resident_id = ?");
        $check->bind_param("i", $resident_id);
        $check->execute();
        $chkres = $check->get_result();

        if ($chkres->num_rows > 0) {
            $message = "<div class='alert alert-warning'>This resident is already assigned to a caregiver. Remove the previous assignment first to reassign.</div>";
        } else {
            $stmt = $conn->prepare("INSERT INTO caregiver_assignments (caregiver_id, resident_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $caregiver_id, $resident_id);
            if ($stmt->execute()) {
                $message = "<div class='alert alert-success'>Caregiver assigned successfully!</div>";
            } else {
                $message = "<div class='alert alert-danger'>Failed to assign caregiver. Please try again.</div>";
            }
        }
    }
}

// --- Handle remove assignment (GET param) ---
if (isset($_GET['remove_id'])) {
    $remove_id = intval($_GET['remove_id']);
    $del = $conn->prepare("DELETE FROM caregiver_assignments WHERE id = ?");
    $del->bind_param("i", $remove_id);
    if ($del->execute()) {
        header("Location: assign_caregiver.php?removed=1");
        exit();
    } else {
        $message = "<div class='alert alert-danger'>Failed to remove assignment.</div>";
    }
}

// --- Fetch caregivers and residents for form ---
$caregivers = $conn->query("SELECT user_id, full_name, email FROM users WHERE role='caregiver' AND status='active' ORDER BY full_name");
$residents = $conn->query("SELECT user_id, full_name, email FROM users WHERE role='resident' AND status='active' ORDER BY full_name");

// --- Fetch assignments for table display (with id) ---
$assignments = $conn->query("
    SELECT a.id, a.caregiver_id, a.resident_id, c.full_name AS caregiver_name, r.full_name AS resident_name
    FROM caregiver_assignments a
    JOIN users c ON a.caregiver_id = c.user_id
    JOIN users r ON a.resident_id = r.user_id
    ORDER BY c.full_name, r.full_name
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Assign Caregiver - Admin | SmartCare Guardian</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Quicksand:wght@300;400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>

    <style>
        :root {
            --sage-green: #87A96B;
            --mint-cream: #F0FFF0;
            --seafoam: #9FE2BF;
            --forest-mist: #B8E0D2;
            --dusty-teal: #6D9B8E;
            --deep-emerald: #4A766E;
        }
        
        body {
            font-family: 'Quicksand', sans-serif;
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
            min-height: 100vh;
            margin: 0;
            padding: 0;
            position: relative;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: float 20s infinite linear;
            z-index: 0;
        }
        
        @keyframes float {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(-50px, -50px) rotate(360deg); }
        }
        
        h1, h2, h3, h4, h5 {
            font-family: 'Playfair Display', serif;
            color: var(--deep-emerald);
        }
        
        .brand-font {
            font-family: 'Jost', sans-serif;
            font-weight: 600;
        }

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
        
        .content {
            margin-left: 280px;
            padding: 40px;
            position: relative;
            z-index: 1;
            min-height: 100vh;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="rgba(255,255,255,0.1)"><circle cx="20" cy="20" r="2"/><circle cx="80" cy="40" r="2"/><circle cx="40" cy="80" r="2"/><circle cx="70" cy="20" r="2"/></svg>');
        }
        
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .form-control, .form-select {
            border: 2px solid var(--forest-mist);
            border-radius: 12px;
            padding: 15px 20px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
            transform: translateY(-2px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            padding: 12px 25px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
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
        
        .btn-success {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            padding: 15px 30px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(141, 182, 154, 0.4);
        }
        
        .btn-outline-success, .btn-outline-primary {
            border: 2px solid var(--sage-green);
            color: var(--sage-green);
            padding: 12px 25px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-success:hover {
            background: var(--sage-green);
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-outline-primary {
            border-color: var(--dusty-teal);
            color: var(--dusty-teal);
        }
        
        .btn-outline-primary:hover {
            background: var(--dusty-teal);
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            border: none;
            padding: 8px 15px;
            border-radius: 25px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-danger:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
        }
        
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
        
        .alert-warning {
            background: linear-gradient(135deg, #ffd93d, #ff9a3d);
            color: white;
        }
        
        .table thead {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
        }
        
        .table th {
            border: none;
            padding: 15px 12px;
            font-weight: 600;
        }
        
        .table td {
            border-color: var(--forest-mist);
            padding: 15px 12px;
            vertical-align: middle;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:hover {
            background: rgba(135, 169, 107, 0.05);
            transform: translateY(-1px);
        }
        
        .feature-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .floating {
            animation: floating 3s ease-in-out infinite;
        }
        
        @keyframes floating {
            0% { transform: translate(0, 0px); }
            50% { transform: translate(0, -10px); }
            100% { transform: translate(0, 0px); }
        }
        
        .chart-container {
            padding: 20px;
            border-radius: 15px;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .info-note {
            font-size: 0.9rem;
            color: var(--dusty-teal);
            margin-top: 8px;
        }
        
        .view-residents-link {
            color: var(--deep-emerald);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .view-residents-link:hover {
            color: var(--sage-green);
            text-decoration: underline;
        }

        /* Responsive adjustments */
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
        <a href="admin_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="manage_users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i> Manage Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i> Manage Residents</a>
        <a href="#" class="active"><i class="fa-solid fa-link"></i> Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i> Manage Services</a>
        <a href="messages.php"><i class="fa-solid fa-envelope"></i> Messages</a>
        <a href="admin_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="admin_reports.php"><i class="fa-solid fa-chart-line"></i> Reports & Analytics</a>
    </div>
    
    <div class="sidebar-footer">
        <a href="logout.php" class="logout-sidebar">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>
</div>

    <!-- Main Content -->
    <div class="content">
        <div class="page-header">
            <div class="feature-icon floating">
                <i class="fas fa-link"></i>
            </div>
            <h2 class="brand-font mb-2">Assign Caregivers</h2>
            <p class="mb-0">Manage caregiver assignments and monitor workloads</p>
        </div>

        <?php if ($message): ?>
            <div class="mb-4"><?php echo $message; ?></div>
        <?php elseif (isset($_GET['removed'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>Assignment removed successfully.
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="brand-font mb-1">Care Management</h4>
                <p class="info-note mb-0">Assign caregivers to residents and monitor assignment distribution</p>
            </div>
            <div class="d-flex gap-2">
                <a href="add_caregiver.php" class="btn btn-outline-success">
                    <i class="fas fa-user-plus me-2"></i> Add Caregiver
                </a>
                <a href="add_resident.php" class="btn btn-outline-primary">
                    <i class="fas fa-user-plus me-2"></i> Add Resident
                </a>
            </div>
        </div>

        <div class="row g-4">
            <!-- Assignment Form -->
            <div class="col-12 col-xl-6">
                <div class="card p-4">
                    <h5 class="brand-font mb-3">
                        <i class="fas fa-user-plus me-2"></i>New Assignment
                    </h5>
                    <form method="POST" id="assignForm">
                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-user me-2"></i>Select Resident
                            </label>
                            <select name="resident_id" class="form-select" required>
                                <option value="">-- Choose Resident --</option>
                                <?php while ($r = $residents->fetch_assoc()): ?>
                                    <option value="<?php echo $r['user_id']; ?>">
                                        <?php echo htmlspecialchars($r['full_name'] . " — " . $r['email']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <div class="info-note">
                                <i class="fas fa-info-circle me-1"></i>
                                Each resident can have one caregiver. Reassign by removing previous assignment first.
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-hand-holding-heart me-2"></i>Select Caregiver
                            </label>
                            <select name="caregiver_id" class="form-select" required>
                                <option value="">-- Choose Caregiver --</option>
                                <?php
                                    $careq = $conn->query("SELECT user_id, full_name, email FROM users WHERE role='caregiver' AND status='active' ORDER BY full_name");
                                    while ($c = $careq->fetch_assoc()):
                                ?>
                                    <option value="<?php echo $c['user_id']; ?>">
                                        <?php echo htmlspecialchars($c['full_name'] . " — " . $c['email']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <div class="info-note">
                                <i class="fas fa-info-circle me-1"></i>
                                A caregiver may be assigned to multiple residents.
                            </div>
                        </div>

                        <button name="assign_submit" class="btn btn-primary">
                            <i class="fas fa-check me-2"></i><span>Assign Caregiver</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Workload Chart -->
            <div class="col-12 col-xl-6">
                <div class="card p-4">
                    <h5 class="brand-font mb-3">
                        <i class="fas fa-chart-bar me-2"></i>Caregiver Workload
                    </h5>
                    <div class="chart-container">
                        <canvas id="workloadChart" height="200"></canvas>
                    </div>
                    <div class="info-note mt-3">
                        <i class="fas fa-chart-line me-1"></i>
                        Chart displays number of residents assigned to each caregiver (includes zero assignments)
                    </div>
                </div>
            </div>

            <!-- Assignments Table -->
            <div class="col-12">
                <div class="card p-4">
                    <h5 class="brand-font mb-3">
                        <i class="fas fa-list-check me-2"></i>Current Assignments
                    </h5>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-user-nurse me-2"></i>Caregiver</th>
                                    <th><i class="fas fa-user me-2"></i>Resident</th>
                                    <th style="width: 140px;"><i class="fas fa-gears me-2"></i>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($assignments->num_rows > 0): ?>
                                <?php while ($row = $assignments->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <a href="#" class="view-residents-link" data-caregiver-id="<?php echo $row['caregiver_id']; ?>">
                                                <i class="fas fa-user-nurse me-2"></i>
                                                <?php echo htmlspecialchars($row['caregiver_name']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <i class="fas fa-user me-2 text-muted"></i>
                                            <?php echo htmlspecialchars($row['resident_name']); ?>
                                        </td>
                                        <td>
                                            <a href="remove_assignment.php?id=<?php echo $row['id']; ?>"
                                               class="btn btn-danger btn-sm"
                                               onclick="return confirm('Are you sure you want to remove this assignment?');">
                                                <i class="fas fa-trash me-1"></i> Remove
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-link fa-3x mb-3 d-block"></i>
                                            <h5>No Assignments Found</h5>
                                            <p>Start by assigning a caregiver to a resident using the form above.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Assigned Residents -->
    <div class="modal fade" id="residentsModal" tabindex="-1" aria-labelledby="residentsModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title brand-font">
                <i class="fas fa-user-group me-2"></i>Assigned Residents
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="residentsList">
                <div class="text-center py-4">
                    <i class="fas fa-spinner fa-spin me-2"></i>Loading residents...
                </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function(){

        // --- Chart: load data from endpoint ---
        fetch('assign_caregiver.php?action=chart_data')
            .then(res => res.json())
            .then(data => {
                const ctx = document.getElementById('workloadChart');
                const chart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Assigned Residents',
                            data: data.counts,
                            backgroundColor: data.counts.map(c => c === 0 ? 'rgba(200,200,200,0.6)' : 'rgba(135,169,107,0.8)'),
                            borderColor: 'rgba(60,90,60,0.9)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: ctx => `${ctx.parsed.y} residents` } }
                        }
                    }
                });
            });

        // --- View Residents modal (click caregiver names) ---
        document.querySelectorAll('.view-residents-link').forEach(link => {
            link.addEventListener('click', function(e){
                e.preventDefault();
                const caregiverId = this.getAttribute('data-caregiver-id');
                const modal = new bootstrap.Modal(document.getElementById('residentsModal'));
                const container = document.getElementById('residentsList');
                container.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin me-2"></i>Loading...</div>';

                fetch(`assign_caregiver.php?action=get_residents&caregiver_id=${encodeURIComponent(caregiverId)}`)
                    .then(res => res.json())
                    .then(json => {
                        if (json.success) {
                            if (json.residents.length === 0) {
                                container.innerHTML = '<div class="text-center text-muted py-4">No residents assigned to this caregiver.</div>';
                            } else {
                                let html = '<div class="list-group">';
                                json.residents.forEach(r => {
                                    html += `<div class="list-group-item d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong>${escapeHtml(r.full_name)}</strong><br>
                                                    <small class="text-muted">${escapeHtml(r.email)}</small>
                                                </div>
                                                <div>
                                                    <span class="badge bg-${r.status === 'active' ? 'success' : 'secondary'}">${r.status}</span>
                                                </div>
                                             </div>`;
                                });
                                html += '</div>';
                                container.innerHTML = html;
                            }
                        } else {
                            container.innerHTML = '<div class="text-danger">Failed to load residents.</div>';
                        }
                    })
                    .catch(() => {
                        container.innerHTML = '<div class="text-danger">Network error. Try again.</div>';
                    });

                modal.show();
            });
        });

        // Utility: escape HTML
        function escapeHtml(unsafe) {
            return unsafe
                 .replaceAll('&', '&amp;')
                 .replaceAll('<', '&lt;')
                 .replaceAll('>', '&gt;')
                 .replaceAll('"', '&quot;')
                 .replaceAll("'", '&#039;');
        }

    });
    </script>
</body>
</html>