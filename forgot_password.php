<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';
include 'db_connection.php';

function sendResetOTP($email, $otp) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP(); $mail->Host='smtp.gmail.com'; $mail->SMTPAuth=true;
        $mail->Username='smartcareguardian@gmail.com'; $mail->Password='yvry blsv yfss pjjn';
        $mail->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS; $mail->Port=587;
        $mail->SMTPOptions=['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
        $mail->setFrom('smartcareguardian@gmail.com','SmartCare Guardian');
        $mail->addAddress($email); $mail->isHTML(true);
        $mail->Subject='Password Reset - SmartCare Guardian';
        $year=date('Y'); $otpSafe=htmlspecialchars($otp);
        $mail->Body="<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;padding:0;background:#F7F1E5;font-family:Arial,sans-serif;'><table width='100%' cellpadding='0' cellspacing='0' style='background:#F7F1E5;padding:40px 16px;'><tr><td align='center'><table width='600' cellpadding='0' cellspacing='0' style='max-width:600px;border-radius:20px;overflow:hidden;box-shadow:0 8px 40px rgba(36,56,22,.15);'><tr><td style='background:linear-gradient(135deg,#243816,#365220,#5E8A40);padding:34px 40px 28px;text-align:center;'><table cellpadding='0' cellspacing='0' style='margin:0 auto 10px;'><tr><td style='background:linear-gradient(135deg,#9DC07E,#5E8A40);border-radius:12px;width:46px;height:46px;text-align:center;vertical-align:middle;font-size:22px;line-height:46px;'>🌿</td><td style='padding-left:12px;text-align:left;vertical-align:middle;'><div style='font-size:21px;font-weight:700;color:#fff;line-height:1.1;'>SmartCare</div><div style='font-size:21px;font-weight:700;color:#C8E6A0;line-height:1.1;'>Guardian</div></td></tr></table><div style='font-size:11px;color:rgba(255,255,255,.4);letter-spacing:.12em;text-transform:uppercase;'>Resident Care Portal</div></td></tr><tr><td style='background:#fff;padding:40px;'><div style='text-align:center;margin-bottom:24px;'><div style='display:inline-block;background:#F2F6EF;border-radius:50%;width:62px;height:62px;line-height:62px;font-size:26px;border:2px solid #C4D9B4;'>🔑</div></div><h1 style='margin:0 0 8px;font-size:24px;font-weight:700;color:#243816;text-align:center;'>Password Reset Request</h1><p style='margin:0 0 24px;font-size:15px;color:#7A7268;text-align:center;'>Use the code below to reset your SmartCare Guardian password.</p><div style='height:1px;background:linear-gradient(90deg,transparent,#C4D9B4,transparent);margin-bottom:24px;'></div><table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:20px;'><tr><td align='center'><div style='display:inline-block;background:linear-gradient(135deg,#243816,#5E8A40);border-radius:16px;padding:26px 52px;box-shadow:0 6px 24px rgba(36,56,22,.25);'><div style='font-size:10px;font-weight:700;color:rgba(255,255,255,.5);letter-spacing:.14em;text-transform:uppercase;margin-bottom:10px;'>Your Reset Code</div><div style='font-size:46px;font-weight:800;color:#fff;letter-spacing:.18em;font-family:\"Courier New\",monospace;line-height:1;'>$otpSafe</div></div></td></tr></table><table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:16px;'><tr><td style='background:#FAECC8;border-radius:10px;padding:14px 18px;border-left:4px solid #D4A853;'><p style='margin:0;font-size:14px;color:#7A5010;font-weight:600;'>⏱ This code will expire in <strong>10 minutes</strong>.</p></td></tr></table><table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:24px;'><tr><td style='background:#F2F6EF;border-radius:10px;padding:14px 18px;'><p style='margin:0;font-size:13px;color:#7A7268;'>🛡 If you did not request a password reset, please ignore this email. Your account remains secure.</p></td></tr></table><p style='margin:0;font-size:15px;color:#4A4540;'>Warm regards,<br><strong style='color:#243816;'>SmartCare Guardian Team</strong></p></td></tr><tr><td style='background:#F2F6EF;border-top:1px solid #C4D9B4;padding:22px 40px;text-align:center;'><p style='margin:0 0 4px;font-size:13px;color:#7A7268;font-weight:600;'>SmartCare Guardian · Resident Care Portal</p><p style='margin:0;font-size:12px;color:#B8B0A4;'>&copy; $year SmartCare Guardian. All rights reserved.</p></td></tr></table></td></tr></table></body></html>";
        $mail->AltBody="Your reset code: $otp — expires in 10 minutes.";
        $mail->send(); return true;
    } catch (Exception $e) { error_log("Mailer Error: {$mail->ErrorInfo}"); return false; }
}

$message='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $email=$_POST['email'];
    $check=$conn->prepare("SELECT * FROM users WHERE email=?"); $check->bind_param("s",$email); $check->execute();
    if ($check->get_result()->num_rows>0) {
        $otp=rand(100000,999999); $otp_expiry=date("Y-m-d H:i:s",strtotime("+10 minutes"));
        $_SESSION['reset_email']=$email; $_SESSION['reset_otp']=$otp; $_SESSION['reset_otp_expiry']=$otp_expiry;
        if (sendResetOTP($email,$otp)) { header("Location: reset_password.php"); exit(); }
        else $message="<div class='alert alert-danger'><i class='fas fa-exclamation-circle'></i>Failed to send OTP. Please try again later.</div>";
    } else $message="<div class='alert alert-warning'><i class='fas fa-exclamation-triangle'></i>No account found with that email address.</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Forgot Password – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════
   SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
   Auth / Standalone pages
═══════════════════════════════════════════════════════════ */
:root {
    --s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;
    --s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;
    --s600:#4A6E30;--s700:#365220;--s800:#243816;
    --w50:#FDFAF5;--w100:#F7F1E5;
    --st300:#B8B0A4;--st500:#7A7268;--st700:#4A4540;
    --green-bg:#DDEFD8;--green-text:#3A6830;
    --amber-bg:#FAECC8;--amber-text:#7A5010;
    --red-bg:#F5DADA;--red-text:#6A2020;
    --radius-sm:8px;--radius-md:12px;--radius-lg:20px;
    --shadow-card:0 4px 24px rgba(36,56,22,.09),0 1px 4px rgba(36,56,22,.06);
    --shadow-lift:0 8px 32px rgba(36,56,22,.13),0 2px 8px rgba(36,56,22,.07);
}
*,*::before,*::after{box-sizing:border-box;}
body {
    font-family:'Outfit',sans-serif;font-size:15px;line-height:1.6;
    background:var(--w50);
    background-image:
        radial-gradient(ellipse 70% 50% at 90% 0%,rgba(157,192,126,.09) 0%,transparent 55%),
        radial-gradient(ellipse 50% 40% at 0% 100%,rgba(122,166,88,.06) 0%,transparent 50%);
    color:var(--st700);min-height:100vh;margin:0;padding:30px 16px;
    display:flex;align-items:center;justify-content:center;
}
h1,h2,h3,h4,h5,h6{font-family:'Cormorant Garamond',serif;color:var(--s800);margin:0;}

.auth-card {
    background:white;border-radius:var(--radius-lg);
    box-shadow:var(--shadow-lift);overflow:hidden;width:100%;
    border:1px solid rgba(196,217,180,.25);
}
.auth-header {
    background:linear-gradient(135deg,var(--s800) 0%,var(--s700) 50%,var(--s500) 100%);
    padding:34px 36px 28px;text-align:center;position:relative;overflow:hidden;
}
.auth-header::before {
    content:'';position:absolute;inset:0;pointer-events:none;
    background-image:
        radial-gradient(ellipse 120% 60% at 50% -10%,rgba(157,192,126,.18) 0%,transparent 60%),
        radial-gradient(ellipse 80% 80% at 110% 110%,rgba(94,138,64,.15) 0%,transparent 55%);
}
.auth-brand {
    display:inline-flex;align-items:center;gap:10px;margin-bottom:20px;position:relative;
}
.auth-brand-icon {
    width:36px;height:36px;background:linear-gradient(135deg,var(--s300),var(--s500));
    border-radius:9px;display:flex;align-items:center;justify-content:center;
    font-size:15px;color:white;box-shadow:0 3px 10px rgba(0,0,0,.3);flex-shrink:0;
}
.auth-brand-text {
    text-align:left;font-family:'Cormorant Garamond',serif;
    font-size:18px;font-weight:600;color:white;line-height:1.1;
}
.auth-brand-text span{color:#C8E6A0;display:block;}
.auth-header-icon {
    width:58px;height:58px;background:rgba(255,255,255,.12);border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:1.4rem;color:white;margin:0 auto 14px;position:relative;
    border:2px solid rgba(255,255,255,.2);
}
.auth-header h2 {
    font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:600;
    color:white;margin:0 0 6px;position:relative;
}
.auth-header p{font-size:13px;color:rgba(255,255,255,.65);margin:0;position:relative;}
.auth-body{padding:34px 36px;}

.form-label {
    color:var(--s800);font-weight:700;margin-bottom:6px;
    font-size:13px;display:flex;align-items:center;gap:6px;
}
.form-control,.form-select {
    border:2px solid var(--s100);border-radius:var(--radius-md);
    padding:11px 14px;font-family:'Outfit',sans-serif;
    font-size:14px;color:var(--st700);background:var(--w50);
    transition:all .2s;width:100%;display:block;
}
.form-control:focus,.form-select:focus {
    border-color:var(--s400);box-shadow:0 0 0 3px rgba(122,166,88,.15);outline:none;
}
.form-control::placeholder{color:var(--st300);}
.input-icon-wrap{position:relative;}
.input-icon-wrap .form-control{padding-left:42px;}
.input-icon-wrap i {
    position:absolute;left:14px;top:50%;transform:translateY(-50%);
    color:var(--s400);font-size:13px;z-index:2;pointer-events:none;
}
.btn-save {
    background:linear-gradient(135deg,var(--s400),var(--s700));
    border:none;border-radius:var(--radius-md);color:white;
    padding:12px 28px;font-weight:700;font-size:14px;
    font-family:'Outfit',sans-serif;transition:all .2s;
    cursor:pointer;display:inline-flex;align-items:center;
    justify-content:center;gap:7px;width:100%;
}
.btn-save:hover{opacity:.9;transform:translateY(-1px);box-shadow:var(--shadow-card);}
.btn-outline {
    background:transparent;border:2px solid var(--s200);
    border-radius:var(--radius-md);color:var(--st500);
    padding:11px 20px;font-weight:700;font-size:14px;
    font-family:'Outfit',sans-serif;transition:all .2s;
    text-decoration:none;display:inline-flex;align-items:center;gap:7px;
    cursor:pointer;
}
.btn-outline:hover{background:var(--s50);border-color:var(--s300);color:var(--s700);}
.alert {
    border-radius:var(--radius-md);border:none;padding:13px 16px;
    margin-bottom:20px;font-size:14px;font-weight:600;
    display:flex;align-items:flex-start;gap:8px;
}
.alert-danger {background:var(--red-bg);color:var(--red-text);}
.alert-warning{background:var(--amber-bg);color:var(--amber-text);}
.alert-success{background:var(--green-bg);color:var(--green-text);}
.info-note {
    background:var(--s50);border:1px solid var(--s200);border-radius:var(--radius-md);
    padding:13px 16px;margin-bottom:22px;font-size:13px;color:var(--s700);
    display:flex;align-items:center;gap:8px;
}
.auth-footer-link{text-align:center;margin-top:22px;font-size:13.5px;color:var(--st500);}
.auth-footer-link a{color:var(--s500);text-decoration:none;font-weight:700;transition:color .2s;}
.auth-footer-link a:hover{color:var(--s800);text-decoration:underline;}
.pw-hint{font-size:12px;color:var(--st300);margin-top:5px;display:flex;align-items:center;gap:5px;}
.otp-row{display:flex;justify-content:center;gap:10px;margin:22px 0;}
.otp-digit {
    width:52px;height:62px;text-align:center;
    border:2px solid var(--s100);border-radius:var(--radius-md);
    font-size:22px;font-weight:800;color:var(--s800);
    background:var(--w50);font-family:'Outfit',sans-serif;
    transition:all .2s;outline:none;
}
.otp-digit:focus{border-color:var(--s400);box-shadow:0 0 0 3px rgba(122,166,88,.15);}
@media(max-width:480px){
    .auth-body{padding:24px 20px;}
    .auth-header{padding:26px 20px;}
    .otp-digit{width:42px;height:54px;font-size:18px;}
}
        .auth-card{max-width:460px;}
    </style>
</head>
<body>
<div class="auth-card">
    <div class="auth-header">
        <div class="auth-brand">
            <div class="auth-brand-icon"><i class="fas fa-leaf"></i></div>
            <div class="auth-brand-text">SmartCare<span>Guardian</span></div>
        </div>
        <div class="auth-header-icon"><i class="fas fa-key"></i></div>
        <h2>Reset Your Password</h2>
        <p>Enter your email and we'll send you a verification code</p>
    </div>
    <div class="auth-body">
        <?php echo $message; ?>
        <form method="POST" id="resetForm">
            <div class="mb-4">
                <label class="form-label"><i class="fas fa-envelope" style="color:var(--s400);"></i>Registered Email Address</label>
                <div class="input-icon-wrap">
                    <i class="fas fa-envelope"></i>
                    <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email address" required
                           value="<?php echo isset($_POST['email'])?htmlspecialchars($_POST['email']):''; ?>">
                </div>
            </div>
            <button type="submit" class="btn-save"><i class="fas fa-paper-plane"></i>Send Verification Code</button>
        </form>
        <div class="auth-footer-link">
            Remember your password? <a href="login.php"><i class="fas fa-sign-in-alt me-1"></i>Back to Login</a>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('resetForm').addEventListener('submit',function(e){
    const email=document.getElementById('email').value;
    if(!email.includes('@')||!email.includes('.')){e.preventDefault();alert('Please enter a valid email address.');return;}
    const btn=this.querySelector('button[type="submit"]');
    btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>Sending Code...';btn.disabled=true;
});
document.addEventListener('DOMContentLoaded',()=>document.getElementById('email').focus());
</script>
</body>
</html>