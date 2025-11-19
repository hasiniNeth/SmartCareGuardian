<?php
session_start();
include 'db_connection.php';

// Check admin login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: manage_users.php");
    exit();
}

$user_id = intval($_GET['id']);

// Fetch user data
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    echo "<script>alert('User not found.'); window.location='manage_users.php';</script>";
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $role = $_POST['role'];
    $status = $_POST['status'];

    $update = $conn->prepare("UPDATE users SET full_name=?, email=?, role=?, status=? WHERE user_id=?");
    $update->bind_param("ssssi", $full_name, $email, $role, $status, $user_id);

    if ($update->execute()) {
        echo "<script>alert('User updated successfully!'); window.location='manage_users.php';</script>";
    } else {
        echo "<script>alert('Error updating user.');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - SmartCare Guardian</title>
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
            padding: 20px;
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

        .edit-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }

        .edit-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 600px;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin: 20px 0;
        }
        
        .edit-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .edit-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" fill="rgba(255,255,255,0.1)"><circle cx="20" cy="20" r="2"/><circle cx="80" cy="40" r="2"/><circle cx="40" cy="80" r="2"/><circle cx="70" cy="20" r="2"/></svg>');
            animation: subtleMove 10s infinite linear;
        }
        
        @keyframes subtleMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(10px, 10px); }
        }
        
        .edit-body {
            padding: 40px;
        }
        
        .form-control {
            border: 2px solid var(--forest-mist);
            border-radius: 12px;
            padding: 15px 20px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .form-control:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
            transform: translateY(-2px);
        }
        
        .form-select {
            border: 2px solid var(--forest-mist);
            border-radius: 12px;
            padding: 15px 20px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .form-select:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
            transform: translateY(-2px);
        }
        
        .form-label {
            color: var(--deep-emerald);
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .input-group-icon {
            position: relative;
        }
        
        .input-group-icon .form-control {
            padding-left: 45px;
        }
        
        .input-group-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dusty-teal);
            z-index: 3;
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .btn-success::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--dusty-teal), var(--sage-green));
            transition: left 0.4s ease;
        }
        
        .btn-success:hover::before {
            left: 0;
        }
        
        .btn-success span {
            position: relative;
            z-index: 2;
        }
        
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(141, 182, 154, 0.4);
        }
        
        .btn-secondary {
            background: transparent;
            border: 2px solid var(--dusty-teal);
            color: var(--dusty-teal);
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .btn-secondary:hover {
            background: var(--dusty-teal);
            color: white;
            transform: translateY(-3px);
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
        
        .user-info-badge {
            background: var(--forest-mist);
            color: var(--deep-emerald);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
            text-align: center;
        }

        /* Responsive adjustments */
        @media (max-height: 700px) {
            .edit-wrapper {
                align-items: flex-start;
                padding: 40px 0;
            }
            
            .edit-container {
                margin: 20px;
            }
        }

        @media (max-width: 480px) {
            .edit-body {
                padding: 30px 20px;
            }
            
            .edit-header {
                padding: 25px 20px;
            }
            
            .btn-group {
                flex-direction: column;
                gap: 10px;
            }
            
            .btn-group .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="edit-wrapper">
        <div class="edit-container">
            <div class="edit-header">
                <div class="feature-icon floating">
                    <i class="fas fa-user-pen"></i>
                </div>
                <h3 class="brand-font mb-2">Edit User Profile</h3>
                <p class="mb-0">Update user information and permissions</p>
            </div>
            
            <div class="edit-body">
                <div class="user-info-badge">
                    <i class="fas fa-id-card me-2"></i>User ID: <?php echo $user_id; ?>
                </div>
                
                <form method="POST" id="editForm">
                    <div class="mb-4">
                        <label for="full_name" class="form-label">
                            <i class="fas fa-user me-2"></i>Full Name
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-user"></i>
                            <input type="text" name="full_name" id="full_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['full_name']); ?>" required
                                   placeholder="Enter full name">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="email" class="form-label">
                            <i class="fas fa-envelope me-2"></i>Email Address
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" name="email" id="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required
                                   placeholder="Enter email address">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="status" class="form-label">
                            <i class="fas fa-circle me-2"></i>Account Status
                        </label>
                        <select name="status" id="status" class="form-select" required>
                            <option value="active" <?php if($user['status'] == 'active') echo 'selected'; ?>>🟢 Active</option>
                            <option value="inactive" <?php if($user['status'] == 'inactive') echo 'selected'; ?>>🔴 Inactive</option>
                        </select>
                    </div>

                    <div class="d-flex justify-content-between gap-3 mt-4 btn-group">
                        <a href="manage_users.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Users
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check me-2"></i><span>Save Changes</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation and loading state
        document.getElementById('editForm').addEventListener('submit', function(e) {
            const fullName = document.getElementById('full_name').value;
            const email = document.getElementById('email').value;
            
            // Basic validation
            if (fullName.trim().length < 2) {
                e.preventDefault();
                alert('Please enter a valid full name.');
                return;
            }
            
            if (!email.includes('@') || !email.includes('.')) {
                e.preventDefault();
                alert('Please enter a valid email address.');
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><span>Saving Changes...</span>';
            submitBtn.disabled = true;
        });
        
        // Add floating animation to form elements on focus
        document.querySelectorAll('.form-control, .form-select').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('floating');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('floating');
            });
        });
        
        // Auto-focus first field on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('full_name').focus();
        });
    </script>
</body>
</html>