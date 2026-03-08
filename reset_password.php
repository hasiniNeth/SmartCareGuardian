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
            $message = "<div class='alert alert-warning'><i class='fas fa-exclamation-triangle me-2'></i>Passwords do not match. Please try again.</div>";
        }
    } else {
        $message = "<div class='alert alert-danger'><i class='fas fa-exclamation-circle me-2'></i>Invalid or expired OTP. Please request again.</div>";
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
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --s50:  #F2F6EF;
            --s100: #E3EDDB;
            --s200: #C4D9B4;
            --s300: #9DC07E;
            --s400: #7AA658;
            --s500: #5E8A40;
            --s600: #4A6E30;
            --s700: #365220;
            --s800: #243816;
            --w50:  #FDFAF5;
            --w100: #F7F1E5;
            --st300: #B8B0A4;
            --st500: #7A7268;
            --st700: #4A4540;
            --amber-bg:   #FAECC8;
            --amber-text: #7A5010;
            --red-bg:     #F5DADA;
            --red-text:   #6A2020;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 20px;
            --shadow-card: 0 4px 24px rgba(36,56,22,.09), 0 1px 4px rgba(36,56,22,.06);
            --shadow-lift: 0 8px 32px rgba(36,56,22,.13), 0 2px 8px rgba(36,56,22,.07);
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            line-height: 1.6;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            background-color: var(--w50);
            background-image:
                radial-gradient(ellipse 80% 60% at 10% 10%, rgba(157,192,126,.12) 0%, transparent 55%),
                radial-gradient(ellipse 60% 50% at 90% 90%, rgba(94,138,64,.08) 0%, transparent 50%);
            color: var(--st700);
            overflow-x: hidden;
        }

        h1, h2, h3, h4, h5 {
            font-family: 'Cormorant Garamond', serif;
            color: var(--s800);
            margin: 0;
        }

        /* ── Layout ── */
        .reset-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .reset-container {
            background: #ffffff;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lift);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
            margin: 20px 0;
            border: 1px solid var(--s100);
        }

        /* ── Header ── */
        .reset-header {
            background: linear-gradient(135deg, var(--s800) 0%, var(--s700) 45%, var(--s500) 100%);
            color: white;
            padding: 38px 30px 32px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .reset-header::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(ellipse 100% 70% at 100% 50%, rgba(157,192,126,.15) 0%, transparent 60%);
            pointer-events: none;
        }

        .brand-icon-wrap {
            width: 58px;
            height: 58px;
            background: linear-gradient(135deg, var(--s300), var(--s500));
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
            box-shadow: 0 4px 16px rgba(0,0,0,.25);
            margin-bottom: 14px;
            position: relative;
        }

        .reset-header h2 {
            color: white;
            font-size: 26px;
            font-weight: 600;
            margin-bottom: 6px;
            position: relative;
        }

        .reset-header p {
            color: rgba(255,255,255,.6);
            font-size: 13px;
            margin: 0;
            position: relative;
        }

        /* ── Body ── */
        .reset-body {
            padding: 36px 32px 32px;
        }

        /* ── Alerts ── */
        .alert {
            border-radius: var(--radius-md);
            border: none;
            padding: 13px 16px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-warning {
            background: var(--amber-bg);
            color: var(--amber-text);
        }

        .alert-danger {
            background: var(--red-bg);
            color: var(--red-text);
        }

        /* ── Section label ── */
        .form-label {
            color: var(--s800);
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-label i { color: var(--s400); }

        /* ── OTP boxes ── */
        .otp-input-group {
            display: flex;
            justify-content: center;
            gap: 9px;
            margin: 16px 0 8px;
        }

        .otp-input {
            width: 50px;
            height: 58px;
            border: 2px solid var(--s100);
            border-radius: var(--radius-md);
            text-align: center;
            font-size: 22px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            color: var(--s800);
            background: var(--w50);
            transition: border-color .2s, box-shadow .2s;
            outline: none;
        }

        .otp-input:focus {
            border-color: var(--s400);
            box-shadow: 0 0 0 3px rgba(122,166,88,.15);
            background: #ffffff;
        }

        .otp-hint {
            text-align: center;
            font-size: 12px;
            color: var(--st300);
            margin-bottom: 4px;
        }

        /* ── Text inputs ── */
        .input-group-icon { position: relative; }

        .input-group-icon .form-control {
            padding-left: 42px;
        }

        .input-group-icon > i:first-of-type {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--s400);
            font-size: 13px;
            z-index: 3;
            pointer-events: none;
        }

        .form-control {
            border: 2px solid var(--s100);
            border-radius: var(--radius-md);
            padding: 11px 14px;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            background: var(--w50);
            color: var(--st700);
            transition: border-color .2s, box-shadow .2s;
            width: 100%;
        }

        .form-control:focus {
            border-color: var(--s400);
            box-shadow: 0 0 0 3px rgba(122,166,88,.15);
            outline: none;
            background: #ffffff;
        }

        .form-control::placeholder { color: var(--st300); }

        /* ── Password toggle ── */
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--st300);
            cursor: pointer;
            z-index: 3;
            padding: 4px;
            font-size: 13px;
            transition: color .2s;
        }

        .password-toggle:hover { color: var(--s500); }

        /* ── Submit button ── */
        .btn-primary {
            background: linear-gradient(135deg, var(--s400), var(--s700));
            border: none;
            padding: 12px 28px;
            border-radius: var(--radius-sm);
            font-weight: 700;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            color: white;
            width: 100%;
            margin-top: 8px;
            cursor: pointer;
            transition: opacity .2s, transform .2s, box-shadow .2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary:hover {
            opacity: .92;
            transform: translateY(-1px);
            box-shadow: var(--shadow-card);
        }

        .btn-primary:active { transform: translateY(0); }

        /* ── Back link ── */
        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--s100), transparent);
            margin: 22px 0;
        }

        .back-link {
            text-align: center;
        }

        .back-link a {
            color: var(--s500);
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            transition: color .2s;
        }

        .back-link a:hover {
            color: var(--s800);
            text-decoration: underline;
        }

        /* ── Responsive ── */
        @media (max-height: 700px) {
            .reset-wrapper { align-items: flex-start; padding-top: 40px; }
        }

        @media (max-width: 480px) {
            .reset-body   { padding: 28px 20px; }
            .reset-header { padding: 30px 20px 26px; }
            .otp-input    { width: 44px; height: 52px; font-size: 18px; }
            .otp-input-group { gap: 6px; }
        }
    </style>
</head>
<body>
    <div class="reset-wrapper">
        <div class="reset-container">

            <div class="reset-header">
                <div class="brand-icon-wrap">🔑</div>
                <h2>Reset Password</h2>
                <p>Create your new secure password</p>
            </div>

            <div class="reset-body">

                <?php echo $message; ?>

                <form method="POST" id="resetForm">

                    <!-- OTP -->
                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-shield-halved"></i>OTP Verification Code
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
                        <p class="otp-hint">Enter the 6-digit code sent to your email</p>
                    </div>

                    <!-- New password -->
                    <div class="mb-4">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock"></i>New Password
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

                    <!-- Confirm password -->
                    <div class="mb-4">
                        <label for="confirm_password" class="form-label">
                            <i class="fas fa-lock"></i>Confirm New Password
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
                        <i class="fas fa-redo"></i><span>Reset Password</span>
                    </button>
                </form>

                <div class="divider"></div>

                <div class="back-link">
                    <a href="login.php">
                        <i class="fas fa-arrow-left me-1"></i>Back to Login
                    </a>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function moveToNext(input, nextIndex) {
            if (input.value.length >= parseInt(input.getAttribute('maxlength'))) {
                const next = input.parentElement.querySelector(`input:nth-child(${nextIndex + 1})`);
                if (next) next.focus();
            }
            updateFullOtp();
        }

        function updateFullOtp() {
            let fullOtp = '';
            document.querySelectorAll('.otp-input').forEach(i => fullOtp += i.value);
            document.getElementById('fullOtp').value = fullOtp;
        }

        document.querySelectorAll('.otp-input').forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
            input.addEventListener('keydown', function(e) {
                if ((e.key === 'Backspace' || e.key === 'Delete') && this.value === '') {
                    const prev = this.previousElementSibling;
                    if (prev && prev.classList.contains('otp-input')) prev.focus();
                }
            });
        });

        function togglePassword(fieldId) {
            const input = document.getElementById(fieldId);
            const icon = input.parentElement.querySelector('.password-toggle i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const fullOtp = document.getElementById('fullOtp').value;
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (fullOtp.length !== 6) {
                e.preventDefault(); alert('Please enter the complete 6-digit OTP code.'); return;
            }
            if (password.length < 6) {
                e.preventDefault(); alert('Password must be at least 6 characters long.'); return;
            }
            if (password !== confirmPassword) {
                e.preventDefault(); alert('Passwords do not match. Please check and try again.'); return;
            }

            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Resetting Password…</span>';
            submitBtn.disabled = true;
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('.otp-input').focus();
        });
    </script>
</body>
</html>