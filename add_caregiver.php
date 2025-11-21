<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
include 'db_connection.php';

// Only admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Email function
function sendWelcomeEmail($email, $full_name) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'smartcareguardian@gmail.com';
        $mail->Password = 'yvryblsvyfsspjjn';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom('smartcareguardian@gmail.com', 'SmartCare Guardian');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = "Welcome to SmartCare Guardian";
        $mail->Body = "
            <h2>Welcome, $full_name!</h2>
            <p>You have been added as a <b>Caregiver</b>.</p>
            <p>Please set your password using the link below:</p>
            <p><a href='http://localhost/SmartCareGuardian/set_password.php?email=$email'>
                Click here to set your password
            </a></p>
            <p>Thank you,<br>SmartCare Guardian Team</p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);

    // Check if email exists
    $check = $conn->prepare("SELECT * FROM users WHERE email=?");
    $check->bind_param("s", $email);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $message = "<div class='alert alert-danger'>Email already exists!</div>";
    } else {
        // Insert caregiver (NO PASSWORD YET)
        $stmt = $conn->prepare("INSERT INTO users (full_name, email, role, status) 
                                VALUES (?, ?, 'caregiver', 'pending_password')");
        $stmt->bind_param("ss", $full_name, $email);
        $stmt->execute();

        // Send welcome email
        sendWelcomeEmail($email, $full_name);

        $message = "<div class='alert alert-success'>Caregiver added! Email sent.</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Caregiver - SmartCare Guardian</title>
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

        .add-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }

        .add-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin: 20px 0;
        }
        
        .add-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .add-header::before {
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
        
        .add-body {
            padding: 40px 30px;
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
            padding: 15px 30px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
            margin-top: 10px;
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
        
        .feature-icon {
            font-size: 3rem;
            margin-bottom: 20px;
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
        
        .info-note {
            background: var(--forest-mist);
            color: var(--deep-emerald);
            padding: 15px;
            border-radius: 12px;
            margin: 20px 0;
            font-size: 14px;
            text-align: center;
        }

        /* Responsive adjustments */
        @media (max-height: 700px) {
            .add-wrapper {
                align-items: flex-start;
                padding: 40px 0;
            }
            
            .add-container {
                margin: 20px;
            }
        }

        @media (max-width: 480px) {
            .add-body {
                padding: 30px 20px;
            }
            
            .add-header {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="add-wrapper">
        <div class="add-container">
            <div class="add-header">
                <div class="feature-icon floating">
                    <i class="fas fa-user-plus"></i>
                </div>
                <h3 class="brand-font mb-3">Add New Caregiver</h3>
                <p class="mb-0">Invite caregiver to join your team</p>
            </div>
            
            <div class="add-body">
                <?php echo $message; ?>
                
                <div class="info-note">
                    <i class="fas fa-envelope me-2"></i>
                    Caregiver will receive a welcome email to set their password
                </div>
                
                <form method="POST" id="caregiverForm">
                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-user me-2"></i>Full Name
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-user"></i>
                            <input type="text" name="full_name" class="form-control" required 
                                   placeholder="Enter caregiver's full name">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-envelope me-2"></i>Email Address
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" name="email" class="form-control" required 
                                   placeholder="Enter caregiver's email address">
                        </div>
                    </div>

                    <button type="submit" class="btn-success">
                        <i class="fas fa-user-plus me-2"></i><span>Add Caregiver</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation and loading state
        document.getElementById('caregiverForm').addEventListener('submit', function(e) {
            const fullName = document.querySelector('input[name="full_name"]').value;
            const email = document.querySelector('input[name="email"]').value;
            
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
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><span>Adding Caregiver...</span>';
            submitBtn.disabled = true;
        });
        
        // Add floating animation to form elements on focus
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.parentElement.classList.add('floating');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.parentElement.classList.remove('floating');
            });
        });
        
        // Auto-focus first field on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('input[name="full_name"]').focus();
        });
    </script>
</body>
</html>