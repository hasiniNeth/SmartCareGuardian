<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; 
include 'db_connection.php'; 

function sendOTP($email, $otp) {
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
        $mail->Subject = 'SmartCare Guardian - OTP Verification';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 10px;'>
                <div style='text-align: center; background: linear-gradient(135deg, #87A96B, #6D9B8E); padding: 20px; border-radius: 10px 10px 0 0; color: white;'>
                    <h2>SmartCare Guardian</h2>
                    <p>OTP Verification</p>
                </div>
                <div style='padding: 30px; text-align: center;'>
                    <h3 style='color: #4A766E;'>Your Verification Code</h3>
                    <div style='font-size: 32px; font-weight: bold; color: #87A96B; letter-spacing: 5px; margin: 20px 0;'>
                        $otp
                    </div>
                    <p style='color: #666;'>This code will expire in 10 minutes.</p>
                    <p style='color: #999; font-size: 12px; margin-top: 30px;'>
                        If you didn't request this code, please ignore this email.
                    </p>
                </div>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT);

    // Check if user already exists
    $check = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $error = "Email already registered. Please login.";
    } else {
        $otp = rand(100000, 999999);
        $otp_expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

        // Store temporary session data
        $_SESSION['temp_user'] = [
            'full_name' => $name,
            'email' => $email,
            'password' => $password
        ];

        // Save OTP in the database
        $stmt = $conn->prepare("INSERT INTO otp_verification (email, otp, otp_expiry) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $email, $otp, $otp_expiry);
        
        if ($stmt->execute()) {
            // Send OTP email
            if (sendOTP($email, $otp)) {
                header("Location: verify_otp.php");
                exit();
            } else {
                $error = "Failed to send OTP. Please try again.";
            }
        } else {
            $error = "Database error. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Registration - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Quicksand:wght@300;400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --sage-green: #87A96B;
            --mint-cream: #F0FFF0;
            --seafoam: #9FE2BF;
            --forest-mist: #B8E0D2;
            --willow: #B5C8A4;
            --dusty-teal: #6D9B8E;
            --deep-emerald: #4A766E;
            --soft-olive: #8A9A5B;
        }
        
        body {
            font-family: 'Quicksand', sans-serif;
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        h1, h2, h3, h4, h5 {
            font-family: 'Playfair Display', serif;
            color: var(--deep-emerald);
        }
        
        .brand-font {
            font-family: 'Jost', sans-serif;
            font-weight: 600;
        }

        .registration-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
        }
        
        .registration-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .registration-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: float 15s infinite linear;
        }
        
        @keyframes float {
            0% { transform: translate(0, 0) rotate(0deg); }
            100% { transform: translate(-50px, -50px) rotate(360deg); }
        }
        
        .registration-body {
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
        
        .feature-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .password-requirements {
            font-size: 12px;
            color: var(--dusty-teal);
            margin-top: 5px;
        }
        
        .floating {
            animation: floating 3s ease-in-out infinite;
        }
        
        @keyframes floating {
            0% { transform: translate(0, 0px); }
            50% { transform: translate(0, -10px); }
            100% { transform: translate(0, 0px); }
        }
    </style>
</head>
<body>
    <div class="registration-container">
        <div class="registration-header">
            <div class="feature-icon floating">
                <i class="fas fa-user-plus"></i>
            </div>
            <h2 class="brand-font mb-3">Join SmartCare Guardian</h2>
            <p class="mb-0">Begin your journey to personalized elderly care</p>
        </div>
        
        <div class="registration-body">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="registrationForm">
                <div class="mb-4">
                    <label for="full_name" class="form-label">
                        <i class="fas fa-user me-2"></i>Full Name
                    </label>
                    <input type="text" class="form-control" id="full_name" name="full_name" 
                           placeholder="Enter your full name" required 
                           value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>">
                </div>
                
                <div class="mb-4">
                    <label for="email" class="form-label">
                        <i class="fas fa-envelope me-2"></i>Email Address
                    </label>
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="Enter your email address" required
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label">
                        <i class="fas fa-lock me-2"></i>Password
                    </label>
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="Create a secure password" required
                           minlength="6">
                    <div class="password-requirements">
                        <i class="fas fa-info-circle me-1"></i>
                        Password must be at least 6 characters long
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i>Create Account
                </button>
            </form>
            
            <div class="login-link">
                <p>Already have an account? 
                    <a href="login.php">
                        <i class="fas fa-sign-in-alt me-1"></i>Sign In
                    </a>
                </p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const email = document.getElementById('email').value;
            
            // Basic password validation
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long.');
                return;
            }
            
            // Basic email validation
            if (!email.includes('@') || !email.includes('.')) {
                e.preventDefault();
                alert('Please enter a valid email address.');
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Creating Account...';
            submitBtn.disabled = true;
        });
        
        // Add floating animation to form elements on focus
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('floating');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('floating');
            });
        });
    </script>
</body>
</html>