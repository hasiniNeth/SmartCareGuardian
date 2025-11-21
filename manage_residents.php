<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

include 'db_connection.php';

// Search function
$search = "";
if (isset($_GET['search'])) {
    $search = $_GET['search'];
}

$query = "SELECT * FROM users WHERE role='resident' AND (full_name LIKE ? OR email LIKE ?) ORDER BY user_id DESC";
$stmt = $conn->prepare($query);
$searchParam = "%" . $search . "%";
$stmt->bind_param("ss", $searchParam, $searchParam);
$stmt->execute();
$residents = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Residents - SmartCare Guardian</title>
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

        /* Mobile First Sidebar */
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
            padding: 20px;
            position: relative;
            z-index: 1;
            min-height: 100vh;
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 25px 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
            text-align: center;
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
        
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .table-container {
            border-radius: 10px;
            overflow: hidden;
        }
        
        .table thead {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
        }
        
        .table th {
            border: none;
            padding: 12px 8px;
            font-weight: 600;
            font-family: 'Jost', sans-serif;
            font-size: 14px;
        }
        
        .table td {
            border-color: var(--forest-mist);
            padding: 12px 8px;
            vertical-align: middle;
            font-size: 14px;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:hover {
            background: rgba(135, 169, 107, 0.05);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(141, 182, 154, 0.4);
        }
        
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
        
        .search-box {
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid var(--forest-mist);
            border-radius: 25px;
            padding: 10px 15px;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .search-box:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
        }
        
        .badge {
            padding: 6px 10px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 12px;
        }

        .bg-success {
            background: linear-gradient(135deg, var(--seafoam), var(--sage-green)) !important;
        }
        
        .feature-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .floating {
            animation: floating 3s ease-in-out infinite;
        }
        
        @keyframes floating {
            0% { transform: translate(0, 0px); }
            50% { transform: translate(0, -5px); }
            100% { transform: translate(0, 0px); }
        }

        /* Mobile-specific styles */
        .mobile-hidden {
            display: none;
        }
        
        .mobile-card-view {
            display: none;
        }
        
        .resident-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid var(--sage-green);
        }

        /* Desktop styles */
        @media (min-width: 992px) {
            .sidebar {
                width: 280px;
                height: 100vh;
                position: fixed;
                padding-top: 30px;
                box-shadow: 5px 0 25px rgba(0,0,0,0.1);
            }
            
            .sidebar-nav {
                display: block;
                padding: 0;
            }
            
            .sidebar a {
                display: block;
                padding: 15px 25px;
                border-radius: 8px;
                margin: 5px 15px;
                border-left: 4px solid transparent;
                font-size: 16px;
                white-space: normal;
            }
            
            .sidebar a:hover {
                border-left: 4px solid white;
                transform: translateX(5px);
            }
            
            .sidebar a.active {
                border-left: 4px solid white;
            }
            
            .sidebar i {
                width: 25px;
                font-size: 18px;
            }
            
            .content {
                margin-left: 280px;
                padding: 30px;
            }
            
            .page-header {
                padding: 25px 30px;
                text-align: left;
            }
            
            .feature-icon {
                font-size: 2.5rem;
            }
            
            .mobile-hidden {
                display: table-cell;
            }
            
            .table th, .table td {
                padding: 15px 12px;
                font-size: 16px;
            }
            
            .btn-edit, .btn-delete {
                padding: 8px 15px;
                font-size: 14px;
            }
        }

        /* Tablet styles */
        @media (max-width: 991px) and (min-width: 768px) {
            .sidebar-nav {
                justify-content: flex-start;
            }
            
            .content {
                padding: 25px;
            }
        }

        /* Mobile table to cards conversion */
        @media (max-width: 767px) {
            .table-desktop {
                display: none;
            }
            
            .mobile-card-view {
                display: block;
            }
            
            .resident-card .row {
                margin-bottom: 8px;
            }
            
            .resident-card .row:last-child {
                margin-bottom: 0;
            }
            
            .btn-group-mobile {
                display: flex;
                gap: 8px;
                justify-content: center;
                margin-top: 10px;
            }
            
            .btn-edit, .btn-delete {
                flex: 1;
                padding: 8px 12px;
                font-size: 13px;
            }
        }

        /* Small mobile devices */
        @media (max-width: 576px) {
            .content {
                padding: 15px;
            }
            
            .page-header {
                padding: 20px 15px;
                margin-bottom: 20px;
            }
            
            .search-box {
                font-size: 16px; /* Prevents zoom on iOS */
            }
            
            .sidebar a {
                padding: 10px 12px;
                font-size: 13px;
            }
            
            .sidebar i {
                margin-right: 5px;
                font-size: 14px;
            }
        }

        /* Very small devices */
        @media (max-width: 380px) {
            .sidebar-nav {
                gap: 2px;
            }
            
            .sidebar a {
                padding: 8px 10px;
                font-size: 12px;
            }
            
            .sidebar a span {
                display: none;
            }
            
            .sidebar a i {
                margin-right: 0;
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
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i> Caregivers</a>
        <a href="#" class="active"><i class="fa-solid fa-user-group"></i> Residents</a>
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

<!-- Content -->
<div class="content">
    <div class="page-header">
        <div class="feature-icon floating">
            <i class="fas fa-user-group"></i>
        </div>
        <h2 class="brand-font mb-2">Manage Residents</h2>
        <p class="mb-0">View and manage all elderly residents in the system</p>
    </div>

    <!-- Search and Add Section -->
    <div class="card p-3 mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-md-8">
                <form method="GET">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-0">
                            <i class="fas fa-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control search-box" placeholder="Search residents by name or email..." name="search" value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
            </div>
            <div class="col-md-4 text-md-end text-center">
                <a href="add_resident.php" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i> <span>Add Resident</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Desktop Table View -->
    <div class="card table-desktop">
        <div class="card-header bg-transparent border-0 py-3">
            <h4 class="brand-font mb-0 text-center">
                <i class="fas fa-list me-2"></i>Resident List
            </h4>
        </div>
        <div class="card-body p-0">
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th><i class="fas fa-id-card me-2"></i>ID</th>
                                <th><i class="fas fa-user me-2"></i>Full Name</th>
                                <th class="mobile-hidden"><i class="fas fa-envelope me-2"></i>Email</th>
                                <th><i class="fas fa-circle me-2"></i>Status</th>
                                <th style="width: 200px;"><i class="fas fa-gears me-2"></i>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($residents->num_rows > 0): ?>
                                <?php while ($row = $residents->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold text-muted">#<?php echo $row['user_id']; ?></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($row['full_name']); ?></td>
                                        <td class="mobile-hidden"><?php echo htmlspecialchars($row['email']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $row['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                <i class="fas fa-<?php echo $row['status'] === 'active' ? 'check' : 'pause'; ?>-circle me-1"></i>
                                                <?php echo ucfirst($row['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-2 justify-content-center">
                                                <a href="edit_resident.php?id=<?php echo $row['user_id']; ?>" class="btn btn-edit btn-sm">
                                                    <i class="fas fa-pen-to-square me-1"></i> Edit
                                                </a>
                                                <a href="delete_resident.php?id=<?php echo $row['user_id']; ?>"
                                                   class="btn btn-delete btn-sm"
                                                   onclick="return confirm('Are you sure you want to delete this resident? This action cannot be undone.');">
                                                    <i class="fas fa-trash me-1"></i> Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-users fa-3x mb-3 d-block"></i>
                                            <h5>No Residents Found</h5>
                                            <p>No residents match your search criteria.</p>
                                            <a href="manage_residents.php" class="btn btn-success mt-2">
                                                <i class="fas fa-refresh me-2"></i>View All Residents
                                            </a>
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

    <!-- Mobile Card View -->
    <div class="mobile-card-view">
        <?php if ($residents->num_rows > 0): ?>
            <?php 
            // Reset pointer and loop again for mobile view
            $residents->data_seek(0);
            while ($row = $residents->fetch_assoc()): ?>
                <div class="resident-card">
                    <div class="row">
                        <div class="col-6">
                            <strong>ID:</strong> #<?php echo $row['user_id']; ?>
                        </div>
                        <div class="col-6 text-end">
                            <span class="badge bg-<?php echo $row['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                <?php echo ucfirst($row['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <strong>Name:</strong> <?php echo htmlspecialchars($row['full_name']); ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <strong>Email:</strong> <?php echo htmlspecialchars($row['email']); ?>
                        </div>
                    </div>
                    <div class="btn-group-mobile">
                        <a href="edit_resident.php?id=<?php echo $row['user_id']; ?>" class="btn btn-edit">
                            <i class="fas fa-pen-to-square me-1"></i> Edit
                        </a>
                        <a href="delete_resident.php?id=<?php echo $row['user_id']; ?>"
                           class="btn btn-delete"
                           onclick="return confirm('Are you sure you want to delete this resident? This action cannot be undone.');">
                            <i class="fas fa-trash me-1"></i> Delete
                        </a>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="card text-center py-5">
                <div class="text-muted">
                    <i class="fas fa-users fa-3x mb-3 d-block"></i>
                    <h5>No Residents Found</h5>
                    <p>No residents match your search criteria.</p>
                    <a href="manage_residents.php" class="btn btn-success mt-2">
                        <i class="fas fa-refresh me-2"></i>View All Residents
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Auto-focus search input
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.focus();
        }
        
        // Handle window resize for better mobile experience
        function handleResize() {
            const sidebar = document.querySelector('.sidebar');
            if (window.innerWidth < 992) {
                document.body.style.paddingTop = sidebar.offsetHeight + 'px';
            } else {
                document.body.style.paddingTop = '0';
            }
        }
        
        // Initial call
        handleResize();
        
        // Listen for resize events
        window.addEventListener('resize', handleResize);
    });
</script>
</body>
</html>