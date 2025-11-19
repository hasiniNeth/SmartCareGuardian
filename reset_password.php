<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['reset_email']) || !isset($_SESSION['reset_otp'])) {
    header("Location: forgot_password.php");
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered_otp = $_POST['otp'];
    $new_password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    $email = $_SESSION['reset_email'];
    $otp = $_SESSION['reset_otp'];
    $otp_expiry = $_SESSION['reset_otp_expiry'];

    if ($entered_otp == $otp && strtotime($otp_expiry) > time()) {
        if ($new_password === $confirm_password) {
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
            $update = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
            $update->bind_param("ss", $hashed_password, $email);
            $update->execute();

            unset($_SESSION['reset_email'], $_SESSION['reset_otp'], $_SESSION['reset_otp_expiry']);
            echo "<script>alert('Password has been reset successfully! You can now log in.'); window.location='login.php';</script>";
        } else {
            $message = "<div class='alert alert-warning'>Passwords do not match. Please try again.</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'>Invalid or expired OTP. Please request again.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - SmartCare Guardian</title>
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
            max-width: 500px;
            width: 100%;
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin: 20px 0;
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
        
        .alert-warning {
            background: linear-gradient(135deg, #ffd93d, #ff9a3d);
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
        
        .password-toggle {
            position: absolute;
            right: 35px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--dusty-teal);
            cursor: pointer;
            z-index: 3;
        }
        
        .otp-input-group {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 20px 0;
        }
        
        .otp-input {
            width: 50px;
            height: 60px;
            border: 2px solid var(--forest-mist);
            border-radius: 12px;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            color: var(--deep-emerald);
            background: rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
        }
        
        .otp-input:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
            transform: translateY(-2px);
            outline: none;
        }
        
        .back-link {
            text-align: center;
            margin-top: 25px;
        }
        
        .back-link a {
            color: var(--sage-green);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .back-link a:hover {
            color: var(--deep-emerald);
            text-decoration: underline;
        }

        /* Responsive adjustments */
        @media (max-height: 700px) {
            .reset-wrapper {
                align-items: flex-start;
                padding: 40px 0;
            }
            
            .reset-container {
                margin: 20px;
            }
        }

        @media (max-width: 480px) {
            .reset-body {
                padding: 30px 20px;
            }
            
            .reset-header {
                padding: 30px 20px;
            }
            
            .otp-input {
                width: 45px;
                height: 55px;
                font-size: 18px;
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
                <h2 class="brand-font mb-3">Reset Password</h2>
                <p class="mb-0">Create your new secure password</p>
            </div>
            
            <div class="reset-body">
                <?php echo $message; ?>
                
                <form method="POST" id="resetForm">
                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-shield-check me-2"></i>OTP Verification Code
                        </label>
                        <div class="otp-input-group">
                            <input type="text" class="otp-input" name="otp[]" maxlength="1" required oninput="moveToNext(this, 1)">
                            <input type="text" class="otp-input" name="otp[]" maxlength="1" required oninput="moveToNext(this, 2)">
                            <input type="text" class="otp-input" name="otp[]" maxlength="1" required oninput="moveToNext(this, 3)">
                            <input type="text" class="otp-input" name="otp[]" maxlength="1" required oninput="moveToNext(this, 4)">
                            <input type="text" class="otp-input" name="otp[]" maxlength="1" required oninput="moveToNext(this, 5)">
                            <input type="text" class="otp-input" name="otp[]" maxlength="1" required oninput="moveToNext(this, 6)">
                        </div>
                        <input type="hidden" name="otp" id="fullOtp">
                        <div class="text-center mt-2">
                            <small class="text-muted">Enter the 6-digit code sent to your email</small>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock me-2"></i>New Password
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" class="form-control" id="password" name="password" 
                                   placeholder="Enter your new password" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="confirm_password" class="form-label">
                            <i class="fas fa-lock me-2"></i>Confirm New Password
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                   placeholder="Re-enter your new password" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-redo me-2"></i><span>Reset Password</span>
                    </button>
                </form>
                
                <div class="back-link">
                    <a href="login.php">
                        <i class="fas fa-arrow-left me-2"></i>Back to Login
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // OTP input handling
        function moveToNext(input, nextIndex) {
            const maxLength = parseInt(input.getAttribute('maxlength'));
            const currentLength = input.value.length;
            
            if (currentLength >= maxLength) {
                const nextInput = input.parentElement.querySelector(`input:nth-child(${nextIndex + 1})`);
                if (nextInput) {
                    nextInput.focus();
                }
            }
            
            // Combine all OTP inputs into one hidden field
            updateFullOtp();
        }
        
        function updateFullOtp() {
            const inputs = document.querySelectorAll('.otp-input');
            let fullOtp = '';
            inputs.forEach(input => {
                fullOtp += input.value;
            });
            document.getElementById('fullOtp').value = fullOtp;
        }
        
        // Allow only numbers in OTP inputs
        document.querySelectorAll('.otp-input').forEach(input => {
            input.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
            
            input.addEventListener('keydown', function(e) {
                // Allow backspace and delete
                if (e.key === 'Backspace' || e.key === 'Delete') {
                    if (this.value === '') {
                        const prevInput = this.previousElementSibling;
                        if (prevInput && prevInput.classList.contains('otp-input')) {
                            prevInput.focus();
                        }
                    }
                }
            });
        });
        
        // Password toggle visibility
        function togglePassword(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const icon = passwordInput.parentElement.querySelector('.password-toggle i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Form validation
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const fullOtp = document.getElementById('fullOtp').value;
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            // OTP validation
            if (fullOtp.length !== 6) {
                e.preventDefault();
                alert('Please enter the complete 6-digit OTP code.');
                return;
            }
            
            // Password validation
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long.');
                return;
            }
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match. Please check and try again.');
                return;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><span>Resetting Password...</span>';
            submitBtn.disabled = true;
        });
        
        // Auto-focus first OTP input on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('.otp-input').focus();
        });
    </script>
</body>
</html>