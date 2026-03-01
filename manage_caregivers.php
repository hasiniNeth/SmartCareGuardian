<?php
session_start();
include 'db_connection.php';

// Only Admin Access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Get search parameter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Handle delete request
if (isset($_GET['delete'])) {
    $user_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role = 'caregiver'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    // Refresh the page to show updated list
    header("Location: manage_caregivers.php" . ($search ? "?search=" . urlencode($search) : ""));
    exit();
}

// Build query with search (joining users and caregivers tables)
$query = "SELECT 
            u.user_id, 
            u.full_name, 
            u.email, 
            u.status,
            c.caregiver_id,
            c.phone,
            c.address,
            c.gender,
            c.dob,
            c.experience_years,
            c.skills,
            c.created_at
          FROM users u
          LEFT JOIN caregivers c ON u.user_id = c.user_id
          WHERE u.role = 'caregiver'";
$params = [];

if (!empty($search)) {
    $query .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR c.phone LIKE ?)";
    $search_term = "%" . $search . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$query .= " ORDER BY u.full_name ASC";

// Prepare and execute query
if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Caregivers - SmartCare Guardian</title>
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
            z-index: 1030;
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
            padding: 40px;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }

        .header-section {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Search Section */
        .search-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .search-form {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .search-input-group {
            flex: 1;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid var(--forest-mist);
            border-radius: 10px;
            font-family: 'Quicksand', sans-serif;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }

        .search-input:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
            outline: none;
        }

        .search-btn {
            position: absolute;
            right: 5px;
            top: 50%;
            transform: translateY(-50%);
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            border-radius: 8px;
            color: white;
            padding: 8px 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .search-btn:hover {
            transform: translateY(-50%) scale(1.05);
        }

        .clear-search {
            background: transparent;
            border: 2px solid var(--dusty-teal);
            color: var(--dusty-teal);
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .clear-search:hover {
            background: var(--dusty-teal);
            color: white;
        }

        /* Buttons */
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

        /* Table Styles */
        .table {
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 0;
        }

        .table thead {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
        }

        .table th {
            border: none;
            padding: 18px 15px;
            font-weight: 600;
            font-size: 15px;
        }

        .table td {
            border-color: var(--forest-mist);
            padding: 16px 15px;
            vertical-align: middle;
        }

        .table tbody tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background-color: var(--light-sage);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        /* Action Buttons */
        .btn-action {
            padding: 8px 15px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-edit {
            background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal));
            color: white;
        }

        .btn-view {
            background: linear-gradient(135deg, var(--seafoam), var(--sage-green));
            color: white;
        }

        .btn-delete {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
        }

        .btn-edit:hover, .btn-view:hover, .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        /* Badges */
        .badge {
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
        }

        .bg-success {
            background: linear-gradient(135deg, var(--seafoam), var(--sage-green)) !important;
        }

        .bg-secondary {
            background: linear-gradient(135deg, #9E9E9E, #757575) !important;
        }

        /* Search Results Info */
        .search-results-info {
            color: var(--dusty-teal);
            font-weight: 600;
            margin-bottom: 15px;
            padding: 10px 0;
            border-bottom: 2px solid var(--forest-mist);
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

        /* Experience Badge */
        .experience-badge {
            background: linear-gradient(135deg, #ffd93d, #ff9a3d);
            color: #333;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* ===== FIXED MODAL STYLES ===== */
        .modal {
            z-index: 1060 !important;
            overflow-y: auto !important;
        }

        .modal-backdrop {
            z-index: 1055 !important;
            background-color: rgba(0, 0, 0, 0.5) !important;
        }

        .modal-dialog {
            margin: 1.75rem auto !important;
            max-width: 800px !important;
            z-index: 1065 !important;
        }

        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-height: 85vh !important;
            overflow-y: auto !important;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            border-radius: 15px 15px 0 0;
            border: none;
            padding: 20px 30px;
            position: sticky;
            top: 0;
            z-index: 1070;
        }

        .modal-header .btn-close {
            background: none;
            color: white;
            opacity: 1;
            filter: brightness(0) invert(1);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23fff'%3e%3cpath d='M.293.293a1 1 0 0 1 1.414 0L8 6.586 14.293.293a1 1 0 1 1 1.414 1.414L9.414 8l6.293 6.293a1 1 0 0 1-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 0 1-1.414-1.414L6.586 8 .293 1.707a1 1 0 0 1 0-1.414z'/%3e%3c/svg%3e");
        }

        .modal-body {
            padding: 30px;
            overflow-y: visible !important;
        }

        .caregiver-detail-row {
            display: flex;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--forest-mist);
        }

        .detail-icon {
            width: 40px;
            height: 40px;
            background: var(--forest-mist);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--deep-emerald);
            margin-right: 15px;
            flex-shrink: 0;
        }

        .detail-content {
            flex: 1;
        }

        .detail-label {
            font-weight: 600;
            color: var(--deep-emerald);
            margin-bottom: 5px;
            font-size: 14px;
        }

        .detail-value {
            color: #333;
        }

        .skills-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 5px;
        }

        .skill-tag {
            background: var(--light-sage);
            color: var(--deep-emerald);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .modal-footer {
            border-top: 1px solid var(--forest-mist);
            padding: 20px 30px;
            background: var(--light-sage);
            border-radius: 0 0 15px 15px;
            position: sticky;
            bottom: 0;
            z-index: 1070;
        }

        /* Fix for body scrolling when modal is open */
        body.modal-open {
            overflow: hidden !important;
            padding-right: 0 !important;
        }

        body.modal-open .content {
            filter: blur(2px);
            pointer-events: none;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
                z-index: 1030;
            }
            
            .content {
                margin-left: 0;
                padding: 20px;
            }
            
            .table-container {
                padding: 20px;
                overflow-x: auto;
            }
            
            .search-form {
                flex-direction: column;
            }
            
            .search-input-group {
                width: 100%;
            }
            
            .clear-search {
                width: 100%;
                text-align: center;
            }
            
            .modal-dialog {
                margin: 0.5rem !important;
                max-width: 95% !important;
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
        <a href="#" class="active"><i class="fa-solid fa-hand-holding-heart"></i> Manage Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i> Manage Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i> Assign Caregivers</a>
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
    <div class="header-section">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="mb-2">
                    <i class="fas fa-user-nurse me-2"></i>Manage Caregivers
                </h3>
                <p class="text-muted mb-0">Manage and oversee all caregiver accounts and assignments</p>
            </div>
            <a href="add_caregiver.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i><span>Add Caregiver</span>
            </a>
        </div>
    </div>

    <!-- Search Section -->
    <div class="search-section">
        <form method="GET" action="" class="search-form">
            <div class="search-input-group">
                <input type="text" 
                       name="search" 
                       class="search-input" 
                       placeholder="Search caregivers by name, email or phone..."
                       value="<?php echo htmlspecialchars($search); ?>"
                       autocomplete="off">
                <button type="submit" class="search-btn">
                    <i class="fas fa-search"></i>
                </button>
            </div>
            
            <?php if (!empty($search)): ?>
                <a href="manage_caregivers.php" class="clear-search">
                    <i class="fas fa-times me-2"></i>Clear Search
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Search Results Info -->
    <?php if (!empty($search)): ?>
    <div class="search-results-info">
        <i class="fas fa-search me-2"></i>
        Search results for: "<strong><?php echo htmlspecialchars($search); ?></strong>"
        <?php 
        $total_count = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'caregiver'")->fetch_assoc()['count'];
        $result_count = $result->num_rows;
        echo " (Showing {$result_count} of {$total_count} caregivers)";
        ?>
    </div>
    <?php endif; ?>

    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th><i class="fas fa-id-card me-2"></i>ID</th>
                        <th><i class="fas fa-user me-2"></i>Full Name</th>
                        <th><i class="fas fa-phone me-2"></i>Contact</th>
                        <th><i class="fas fa-clock me-2"></i>Experience</th>
                        <th><i class="fas fa-circle me-2"></i>Status</th>
                        <th width="220"><i class="fas fa-bolt me-2"></i>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()) : ?>
                            <tr>
                                <td class="fw-bold text-muted">#<?= $row['user_id']; ?></td>
                                <td class="fw-semibold"><?= htmlspecialchars($row['full_name']); ?></td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span><?= htmlspecialchars($row['email']); ?></span>
                                        <?php if (!empty($row['phone'])): ?>
                                            <small class="text-muted"><?= htmlspecialchars($row['phone']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($row['experience_years'] > 0): ?>
                                        <span class="experience-badge">
                                            <i class="fas fa-medal"></i>
                                            <?= $row['experience_years']; ?> years
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $row['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <i class="fas fa-circle me-1" style="font-size: 6px;"></i>
                                        <?= ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button 
                                            class="btn-action btn-view view-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#caregiverModal"
                                            data-id="<?= $row['user_id']; ?>"
                                            data-name="<?= htmlspecialchars($row['full_name']); ?>"
                                            data-email="<?= htmlspecialchars($row['email']); ?>"
                                            data-phone="<?= htmlspecialchars($row['phone']); ?>"
                                            data-gender="<?= htmlspecialchars($row['gender']); ?>"
                                            data-dob="<?= htmlspecialchars($row['dob']); ?>"
                                            data-experience="<?= $row['experience_years']; ?>"
                                            data-address="<?= htmlspecialchars($row['address']); ?>"
                                            data-skills="<?= htmlspecialchars($row['skills']); ?>"
                                            data-created="<?= htmlspecialchars($row['created_at']); ?>"
                                            data-status="<?= htmlspecialchars($row['status']); ?>"
                                        >
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <a href="edit_caregiver.php?id=<?= $row['user_id']; ?>" class="btn-action btn-edit">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="manage_caregivers.php?delete=<?= $row['user_id']; ?>&search=<?= urlencode($search); ?>" 
                                           class="btn-action btn-delete"
                                           onclick="return confirm('Are you sure you want to delete this caregiver? This action cannot be undone.');">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <?php if (!empty($search)): ?>
                                        <i class="fas fa-search"></i>
                                        <h5 class="text-muted">No Caregivers Found</h5>
                                        <p class="mb-3">No caregivers match your search "<strong><?php echo htmlspecialchars($search); ?></strong>"</p>
                                        <a href="manage_caregivers.php" class="btn" style="background: var(--sage-green); color: white;">
                                            <i class="fas fa-redo me-2"></i>Clear Search
                                        </a>
                                    <?php else: ?>
                                        <i class="fas fa-user-nurse"></i>
                                        <h5 class="text-muted">No Caregivers Found</h5>
                                        <p class="mb-3">Get started by adding your first caregiver to the system.</p>
                                        <a href="add_caregiver.php" class="btn btn-primary">
                                            <i class="fas fa-plus me-2"></i><span>Add First Caregiver</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Reusable Caregiver Modal -->
<div class="modal fade" id="caregiverModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title brand-font">
                    <i class="fas fa-user-nurse me-2"></i>
                    <span id="modalName"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Email:</strong> <span id="modalEmail"></span></p>
                        <p><strong>Phone:</strong> <span id="modalPhone"></span></p>
                        <p><strong>Gender:</strong> <span id="modalGender"></span></p>
                        <p><strong>Date of Birth:</strong> <span id="modalDob"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Experience:</strong> <span id="modalExperience"></span></p>
                        <p><strong>Address:</strong> <span id="modalAddress"></span></p>
                        <p><strong>Status:</strong> <span id="modalStatus"></span></p>
                        <p><strong>Joined:</strong> <span id="modalCreated"></span></p>
                    </div>

                    <div class="col-12 mt-3">
                        <strong>Skills:</strong>
                        <div id="modalSkills" class="mt-2"></div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <a id="modalEditBtn" href="#" class="btn-action btn-edit">
                    <i class="fas fa-edit me-2"></i>Edit
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Auto-submit search when typing stops (with delay)
let searchTimeout;
const searchInput = document.querySelector('.search-input');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            this.form.submit();
        }, 500);
    });
}

// Focus search input on page load if there's a search
document.addEventListener('DOMContentLoaded', function() {
    if (searchInput && searchInput.value) {
        searchInput.focus();
        searchInput.select();
    }
});

// Preserve search state when deleting
document.querySelectorAll('.btn-delete').forEach(link => {
    link.addEventListener('click', function(e) {
        const url = new URL(this.href);
        const search = '<?php echo urlencode($search); ?>';
        
        if (search) {
            url.searchParams.set('search', decodeURIComponent(search));
        }
        
        this.href = url.toString();
    });
});

// Populate Modal Dynamically
document.querySelectorAll('.view-btn').forEach(button => {
    button.addEventListener('click', function () {

        document.getElementById('modalName').textContent = this.dataset.name;
        document.getElementById('modalEmail').textContent = this.dataset.email || 'N/A';
        document.getElementById('modalPhone').textContent = this.dataset.phone || 'N/A';
        document.getElementById('modalGender').textContent = this.dataset.gender || 'N/A';
        document.getElementById('modalDob').textContent = this.dataset.dob || 'N/A';
        document.getElementById('modalExperience').textContent = 
            this.dataset.experience > 0 ? this.dataset.experience + ' years' : 'N/A';
        document.getElementById('modalAddress').textContent = this.dataset.address || 'N/A';
        document.getElementById('modalStatus').textContent = this.dataset.status;
        document.getElementById('modalCreated').textContent = this.dataset.created || 'N/A';

        // Skills
        const skillsContainer = document.getElementById('modalSkills');
        skillsContainer.innerHTML = '';

        if (this.dataset.skills) {
            this.dataset.skills.split(',').forEach(skill => {
                const span = document.createElement('span');
                span.className = 'skill-tag';
                span.textContent = skill.trim();
                skillsContainer.appendChild(span);
            });
        } else {
            skillsContainer.textContent = 'No skills listed';
        }

        // Edit button link
        document.getElementById('modalEditBtn').href =
            "edit_caregiver.php?id=" + this.dataset.id;
    });
});
</script>
</body>
</html>