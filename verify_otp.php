<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
include 'db_connection.php';

// --- Function to resend OTP via email ---
function sendOTP($email, $otp) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'smartcareguardian@gmail.com'; 
        $mail->Password = 'yvry blsv yfss pjjn'; // Gmail App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('smartcareguardian@gmail.com', 'SmartCare Guardian');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'SmartCare Guardian - OTP Verification';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; text-align:center; padding:20px;'>
                <h2 style='color:#4A766E;'>SmartCare Guardian</h2>
                <p>Your verification code is:</p>
                <h1 style='color:#87A96B;'>$otp</h1>
                <p>This code will expire in 10 minutes.</p>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// --- Redirect if no temporary user session ---
if (!isset($_SESSION['temp_user'])) {
    header("Location: register.php");
    exit();
}

$user = $_SESSION['temp_user'];
$email = $user['email'];
$message = '';
$message_type = '';

// --- Verify OTP ---
if (isset($_POST['verify'])) {
    $entered_otp = trim($_POST['otp']); // Hidden field holds the full OTP value

    // Check OTP from database
    $stmt = $conn->prepare("SELECT * FROM otp_verification WHERE email = ? AND otp = ? AND verified = 0 ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("ss", $email, $entered_otp);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $otp_data = $result->fetch_assoc();

        if (strtotime($otp_data['otp_expiry']) > time()) {
            // Mark OTP as verified
            $update = $conn->prepare("UPDATE otp_verification SET verified = 1 WHERE id = ?");
            $update->bind_param("i", $otp_data['id']);
            $update->execute();

            // Add user to main table
            $insert = $conn->prepare("INSERT INTO users (full_name, email, password, role, status) VALUES (?, ?, ?, 'resident', 'active')");
            $insert->bind_param("sss", $user['full_name'], $user['email'], $user['password']);
            $insert->execute();

            unset($_SESSION['temp_user']);
            echo "<script>alert('Account verified successfully! You can now login.'); window.location='login.php';</script>";
            exit();
        } else {
            $message = "OTP has expired. Please request a new one.";
            $message_type = "danger";
        }
    } else {
        $message = "Invalid OTP. Please try again.";
        $message_type = "danger";
    }
}

// --- Resend OTP ---
if (isset($_POST['resend'])) {
    $otp = rand(100000, 999999);
    $otp_expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

    $stmt = $conn->prepare("INSERT INTO otp_verification (email, otp, otp_expiry) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $email, $otp, $otp_expiry);
    $stmt->execute();

    if (sendOTP($email, $otp)) {
        $message = "A new OTP has been sent to your email.";
        $message_type = "success";
    } else {
        $message = "Failed to resend OTP. Please try again later.";
        $message_type = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --sage-green: #87A96B;
            --dusty-teal: #6D9B8E;
            --forest-mist: #B8E0D2;
            --deep-emerald: #4A766E;
        }
        body {
            font-family: 'Quicksand', sans-serif;
            background: linear-gradient(135deg, #F0FFF0 0%, var(--forest-mist) 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .otp-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            width: 400px;
        }
        .otp-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            text-align: center;
            padding: 30px;
        }
        .otp-input-group {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 30px 0;
        }
        .otp-input {
            width: 50px;
            height: 60px;
            text-align: center;
            border: 2px solid var(--forest-mist);
            border-radius: 10px;
            font-size: 20px;
            font-weight: bold;
            color: var(--deep-emerald);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            width: 100%;
            border-radius: 50px;
            padding: 12px;
            font-weight: bold;
        }
        .btn-secondary {
            border: 2px solid var(--dusty-teal);
            color: var(--dusty-teal);
            width: 100%;
            border-radius: 50px;
            padding: 12px;
            background: none;
        }
    </style>
</head>
<body>
    <div class="otp-container">
        <div class="otp-header">
            <h2>OTP Verification</h2>
            <p>We’ve sent a code to <?php echo htmlspecialchars($email); ?></p>
        </div>
        <div class="p-4">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
            <?php endif; ?>

            <form method="POST" id="otpForm">
                <div class="otp-input-group">
                    <input type="text" class="otp-input" maxlength="1" required>
                    <input type="text" class="otp-input" maxlength="1" required>
                    <input type="text" class="otp-input" maxlength="1" required>
                    <input type="text" class="otp-input" maxlength="1" required>
                    <input type="text" class="otp-input" maxlength="1" required>
                    <input type="text" class="otp-input" maxlength="1" required>
                </div>
                <input type="hidden" name="otp" id="fullOtp">
                <button type="submit" name="verify" class="btn btn-primary mb-3">Verify OTP</button>
            </form>
            <form method="POST">
                <button type="submit" name="resend" class="btn btn-secondary">Resend OTP</button>
            </form>
        </div>
    </div>

<script>
    // Combine OTP digits
    const inputs = document.querySelectorAll('.otp-input');
    const hiddenField = document.getElementById('fullOtp');

    inputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            if (input.value.length === 1 && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }
            hiddenField.value = Array.from(inputs).map(i => i.value).join('');
        });
        input.addEventListener('keydown', e => {
            if (e.key === "Backspace" && input.value === '' && index > 0) {
                inputs[index - 1].focus();
            }
        });
    });
</script>
</body>
</html>
