<?php
session_start();
include 'db_connection.php';

// Check admin login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Handle delete request
if (isset($_GET['delete'])) {
    $user_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    header("Location: manage_users.php");
    exit();
}

// Get search and filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';

// Build query with filters
$query = "SELECT user_id, full_name, email, role, status FROM users WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (full_name LIKE ? OR email LIKE ?)";
    $search_term = "%" . $search . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
}

if (!empty($role_filter) && $role_filter !== 'all') {
    $query .= " AND role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

$query .= " ORDER BY role ASC, full_name ASC";

// Prepare and execute query
$stmt = $conn->prepare($query);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Get count of users by role for filter display
$role_counts = [];
$count_query = $conn->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
while ($row = $count_query->fetch_assoc()) {
    $role_counts[$row['role']] = $row['count'];
}
$total_users = array_sum($role_counts);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - SmartCare Guardian</title>
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

        /* Filter Section */
        .filter-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-box {
            flex: 1;
            min-width: 250px;
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
        
        .filter-select {
            padding: 12px 15px;
            border: 2px solid var(--forest-mist);
            border-radius: 10px;
            font-family: 'Quicksand', sans-serif;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.9);
            color: var(--deep-emerald);
            min-width: 200px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
            outline: none;
        }
        
        .filter-badge {
            background: var(--light-sage);
            color: var(--deep-emerald);
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .reset-btn {
            background: transparent;
            border: 2px solid var(--dusty-teal);
            color: var(--dusty-teal);
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .reset-btn:hover {
            background: var(--dusty-teal);
            color: white;
        }

        /* Table Styling */
        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            overflow: hidden;
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

        /* Badges */
        .badge {
            padding: 8px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.75rem;
        }
        
        .bg-success {
            background: linear-gradient(135deg, var(--seafoam), var(--sage-green)) !important;
        }
        
        .bg-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52) !important;
        }
        
        .bg-secondary {
            background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal)) !important;
        }

        /* Action Buttons */
        .btn-edit {
            background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal));
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            transition: all 0.3s ease;
            margin: 0 3px;
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            transition: all 0.3s ease;
            margin: 0 3px;
        }
        
        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(141, 182, 154, 0.4);
        }
        
        .btn-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 107, 107, 0.4);
        }

        /* Results Info */
        .results-info {
            color: var(--dusty-teal);
            font-weight: 600;
            margin-bottom: 15px;
            padding: 10px 0;
            border-bottom: 2px solid var(--forest-mist);
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
            
            .filter-row {
                flex-direction: column;
            }
            
            .search-box, .filter-select {
                width: 100%;
                min-width: unset;
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
        <a href="#" class="active"><i class="fa-solid fa-users"></i> Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i> Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i> Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i> Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i> Manage Services</a>
        <a href="messages.php"><i class="fa-solid fa-envelope"></i> Messages</a>
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
            <h4 class="brand-font mb-1">Manage Users</h4>
            <p class="text-muted mb-0">View and manage all system users</p>
        </div>
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-sign-out-alt me-2"></i>Logout
            </button>
        </form>
    </div>

    <!-- Filter Section -->
    <div class="filter-container">
        <form method="GET" action="" id="filterForm">
            <div class="filter-row">
                <div class="search-box">
                    <input type="text" 
                           name="search" 
                           class="search-input" 
                           placeholder="Search by name or email..."
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="search-btn">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                
                <select name="role" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?php echo $role_filter === 'all' || empty($role_filter) ? 'selected' : ''; ?>>All Roles</option>
                    <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="caregiver" <?php echo $role_filter === 'caregiver' ? 'selected' : ''; ?>>Caregiver</option>
                    <option value="resident" <?php echo $role_filter === 'resident' ? 'selected' : ''; ?>>Resident</option>
                </select>
                
                <div class="filter-badge">
                    <i class="fas fa-users"></i>
                    <?php 
                    $display_count = $result->num_rows;
                    echo "Showing {$display_count} of {$total_users} users";
                    ?>
                </div>
                
                <?php if (!empty($search) || (!empty($role_filter) && $role_filter !== 'all')): ?>
                <button type="button" class="reset-btn" onclick="resetFilters()">
                    <i class="fas fa-times me-2"></i>Reset Filters
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Results Info -->
    <div class="results-info">
        <?php if (!empty($search)): ?>
            <i class="fas fa-search me-2"></i>Search results for: "<strong><?php echo htmlspecialchars($search); ?></strong>"
        <?php endif; ?>
        <?php if (!empty($role_filter) && $role_filter !== 'all'): ?>
            <?php if (!empty($search)): ?> • <?php endif; ?>
            <i class="fas fa-filter me-2"></i>Filter: <strong><?php echo ucfirst($role_filter); ?></strong>
        <?php endif; ?>
    </div>

    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#<?php echo $row['user_id']; ?></strong></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                            <i class="fas fa-user text-muted"></i>
                                        </div>
                                        <div>
                                            <strong><?php echo htmlspecialchars($row['full_name']); ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td>
                                    <span class="badge bg-secondary text-uppercase">
                                        <i class="fas fa-<?php echo $row['role'] === 'admin' ? 'crown' : ($row['role'] === 'caregiver' ? 'hands-helping' : 'user'); ?> me-1"></i>
                                        <?php echo $row['role']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'active'): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i>Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">
                                            <i class="fas fa-times-circle me-1"></i>Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-center">
                                        <a href="edit_user.php?id=<?php echo $row['user_id']; ?>" class="btn-edit" title="Edit User">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </a>
                                        <a href="manage_users.php?delete=<?php echo $row['user_id']; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>" 
                                           onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.');" 
                                           class="btn-delete" title="Delete User">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-4">
                                <i class="fas fa-search fa-2x text-muted mb-3"></i>
                                <h5 class="brand-font">No users found</h5>
                                <p class="text-muted">
                                    <?php if (!empty($search) || (!empty($role_filter) && $role_filter !== 'all')): ?>
                                        Try adjusting your search or filter criteria
                                    <?php else: ?>
                                        No users are currently registered in the system
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($search) || (!empty($role_filter) && $role_filter !== 'all')): ?>
                                    <button class="btn" style="background: var(--sage-green); color: white;" onclick="resetFilters()">
                                        <i class="fas fa-redo me-2"></i>Reset Filters
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function resetFilters() {
    window.location.href = 'manage_users.php';
}

// Auto-submit search when typing stops (with delay)
let searchTimeout;
const searchInput = document.querySelector('.search-input');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            document.getElementById('filterForm').submit();
        }, 500); // 0.5 second delay
    });
}

// Preserve filter state when deleting
document.querySelectorAll('.btn-delete').forEach(link => {
    link.addEventListener('click', function(e) {
        const url = new URL(this.href);
        const search = '<?php echo urlencode($search); ?>';
        const role = '<?php echo urlencode($role_filter); ?>';
        
        if (search) url.searchParams.set('search', decodeURIComponent(search));
        if (role && role !== 'all') url.searchParams.set('role', decodeURIComponent(role));
        
        this.href = url.toString();
    });
});
</script>

</body>
</html>