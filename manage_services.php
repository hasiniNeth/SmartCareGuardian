<?php
session_start();
include 'db_connection.php';

// Only admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$services = $conn->query("
    SELECT s.*, c.category_name 
    FROM services s
    JOIN service_categories c ON s.category_id = c.category_id
    ORDER BY s.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Services - SmartCare Guardian</title>
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
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .table-container {
            border-radius: 15px;
            overflow: hidden;
        }
        
        .table thead {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
        }
        
        .table th {
            border: none;
            padding: 15px 12px;
            font-weight: 600;
            font-family: 'Jost', sans-serif;
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
        
        .btn-success {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            padding: 12px 25px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(141, 182, 154, 0.4);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ffd93d, #ff9a3d);
            border: none;
            padding: 8px 15px;
            border-radius: 25px;
            font-weight: 500;
            transition: all 0.3s ease;
            color: white;
        }
        
        .btn-warning:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(255, 217, 61, 0.4);
            color: white;
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
        
        .service-image {
            width: 80px;
            height: 60px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid var(--forest-mist);
            transition: all 0.3s ease;
        }
        
        .service-image:hover {
            transform: scale(1.1);
            border-color: var(--sage-green);
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
            
            .sidebar-footer {
                position: relative;
                margin-top: 20px;
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
        
        <nav class="sidebar-nav">
            <a href="admin_dashboard.php"><i class="fas fa-gauge-high"></i> Dashboard</a>
            <a href="manage_users.php"><i class="fas fa-users"></i> Manage Users</a>
            <a href="manage_caregivers.php"><i class="fas fa-hand-holding-heart"></i> Caregivers</a>
            <a href="manage_residents.php"><i class="fas fa-user-group"></i> Residents</a>
            <a href="assign_caregiver.php"><i class="fas fa-link"></i> Assign Caregivers</a>
            <a href="#" class="active"><i class="fas fa-spa"></i> Manage Services</a>
            <a href="messages.php"><i class="fa-solid fa-envelope"></i> Messages</a>
            <a href="view_alerts.php"><i class="fas fa-bell"></i> Alerts</a>
        </nav>
        
        <div class="sidebar-footer">
            <a href="logout.php" class="logout-sidebar">
                <i class="fas fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="content">
        <div class="page-header">
            <div class="feature-icon floating">
                <i class="fas fa-spa"></i>
            </div>
            <h2 class="brand-font mb-2">Manage Services</h2>
            <p class="mb-0">View and manage all healthcare services offered</p>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="brand-font mb-1">Service Catalog</h4>
                <p class="text-muted mb-0">Manage your healthcare service offerings and categories</p>
            </div>
            <a href="add_service.php" class="btn btn-primary">
                <i class="fas fa-plus-circle me-2"></i><span>Add New Service</span>
            </a>
        </div>

        <div class="card">
            <div class="card-header bg-transparent border-0 py-4">
                <h4 class="brand-font mb-0 text-center">
                    <i class="fas fa-list me-2"></i>Service List
                </h4>
            </div>
            <div class="card-body p-0">
                <div class="table-container">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-image me-2"></i>Image</th>
                                    <th><i class="fas fa-heading me-2"></i>Title</th>
                                    <th><i class="fas fa-tags me-2"></i>Category</th>
                                    <th><i class="fas fa-align-left me-2"></i>Description</th>
                                    <th style="width: 200px;"><i class="fas fa-gears me-2"></i>Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                            <?php if ($services->num_rows > 0): ?>
                                <?php while($row = $services->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <img src="<?= $row['image_path'] ?>" 
                                                 class="service-image" 
                                                 alt="<?= htmlspecialchars($row['title']) ?>"
                                                 onerror="this.src='https://via.placeholder.com/80x60/87A96B/ffffff?text=Service'">
                                        </td>
                                        <td class="fw-semibold"><?= htmlspecialchars($row['title']) ?></td>
                                        <td>
                                            <span class="badge rounded-pill" style="background: var(--forest-mist); color: var(--deep-emerald);">
                                                <?= htmlspecialchars($row['category_name']) ?>
                                            </span>
                                        </td>
                                        <td class="text-muted"><?= substr(htmlspecialchars($row['description']), 0, 60) ?>...</td>
                                        <td>
                                            <div class="d-flex gap-2">
                                                <a href="edit_service.php?id=<?= $row['service_id'] ?>" 
                                                   class="btn btn-warning btn-sm">
                                                    <i class="fas fa-edit me-1"></i>Edit
                                                </a>
                                                <a href="delete_service.php?id=<?= $row['service_id'] ?>"
                                                   class="btn btn-danger btn-sm"
                                                   onclick="return confirm('Are you sure you want to delete this service? This action cannot be undone.');">
                                                   <i class="fas fa-trash me-1"></i>Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-spa fa-3x mb-3 d-block"></i>
                                            <h5>No Services Found</h5>
                                            <p>Get started by adding your first healthcare service.</p>
                                            <a href="add_service.php" class="btn btn-success mt-2">
                                                <i class="fas fa-plus-circle me-2"></i>Add First Service
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>