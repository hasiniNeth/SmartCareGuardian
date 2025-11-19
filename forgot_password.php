<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
include 'db_connection.php';

function sendResetOTP($email, $otp) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'smartcareguardian@gmail.com'; // your email
        $mail->Password = 'yvry blsv yfss pjjn'; // Gmail app password
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
        $mail->Subject = 'Password Reset - SmartCare Guardian';
        $mail->Body = "
            <div style='font-family:Arial,sans-serif;padding:20px;background:#f9f9f9;border-radius:10px;'>
                <h2 style='color:#4A766E;'>Password Reset Request</h2>
                <p>Your verification code is:</p>
                <h1 style='letter-spacing:4px;color:#87A96B;'>$otp</h1>
                <p>This code will expire in 10 minutes.</p>
                <hr>
                <p>If you didn't request a password reset, please ignore this email.</p>
            </div>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'];

    // Check if email exists
    $check = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $otp = rand(100000, 999999);
        $otp_expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

        $_SESSION['reset_email'] = $email;
        $_SESSION['reset_otp'] = $otp;
        $_SESSION['reset_otp_expiry'] = $otp_expiry;

        if (sendResetOTP($email, $otp)) {
            header("Location: reset_password.php");
            exit();
        } else {
            $message = "<div class='alert alert-danger'>Failed to send OTP. Please try again later.</div>";
        }
    } else {
        $message = "<div class='alert alert-warning'>No account found with that email.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - SmartCare Guardian</title>
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

        .reset-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }

        .reset-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 450px;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .reset-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .reset-header::before {
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
        
        .reset-body {
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
        
        .btn-primary {
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
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            color: white;
        }
        
        .alert-warning {
            background: linear-gradient(135deg, #ffd166, #f4a261);
            color: #8B4513;
        }
        
        .feature-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .login-link {
            text-align: center;
            margin-top: 25px;
            color: var(--dusty-teal);
        }
        
        .login-link a {
            color: var(--sage-green);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .login-link a:hover {
            color: var(--deep-emerald);
            text-decoration: underline;
        }
        
        .floating {
            animation: floating 3s ease-in-out infinite;
        }
        
        @keyframes floating {
            0% { transform: translate(0, 0px); }
            50% { transform: translate(0, -10px); }
            100% { transform: translate(0, 0px); }
        }
        
        .instructions {
            text-align: center;
            color: var(--dusty-teal);
            margin-bottom: 30px;
            font-size: 14px;
            line-height: 1.5;
        }

        /* Responsive adjustments */
        @media (max-height: 700px) {
            .reset-wrapper {
                align-items: flex-start;
                padding: 40px 0;
            }
        }

        @media (max-width: 480px) {
            .reset-body {
                padding: 30px 20px;
            }
            
            .reset-header {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="reset-wrapper">
        <div class="reset-container">
            <div class="reset-header">
                <div class="feature-icon floating">
                    <i class="fas fa-key"></i>
                </div>
                <h2 class="brand-font mb-3">Reset Your Password</h2>
                <p class="mb-0">We'll help you get back into your account</p>
            </div>
            
            <div class="reset-body">
                <?php echo $message; ?>
                
                <div class="instructions">
                    <p>Enter your registered email address and we'll send you a verification code to reset your password.</p>
                </div>
                
                <form method="POST" id="resetForm">
                    <div class="mb-4">
                        <label for="email" class="form-label">
                            <i class="fas fa-envelope me-2"></i>Registered Email Address
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" class="form-control" id="email" name="email" 
                                   placeholder="Enter your email address" required
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i><span>Send Verification Code</span>
                    </button>
                </form>
                
                <div class="login-link">
                    <p>Remember your password? 
                        <a href="login.php">
                            <i class="fas fa-sign-in-alt me-1"></i>Back to Login
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation and loading state
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            
            // Basic validation
            if (!email.includes('@') || !email.includes('.')) {
                e.preventDefault();
                alert('Please enter a valid email address.');
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><span>Sending Code...</span>';
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
        
        // Auto-focus email field on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('email').focus();
        });
    </script>
</body>
</html>