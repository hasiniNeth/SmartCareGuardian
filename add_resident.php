<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';
include 'db_connection.php';

if (!isset($_SESSION['user_id'])||$_SESSION['role']!=='admin') { header("Location: login.php"); exit(); }

function sendWelcomeEmail($email,$full_name) {
    $mail=new PHPMailer(true);
    try {
        $mail->isSMTP(); $mail->Host='smtp.gmail.com'; $mail->SMTPAuth=true;
        $mail->Username='smartcareguardian@gmail.com'; $mail->Password='yvryblsvyfsspjjn';
        $mail->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS; $mail->Port=587;
        $mail->SMTPOptions=['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
        $mail->setFrom('smartcareguardian@gmail.com','SmartCare Guardian');
        $mail->addAddress($email); $mail->isHTML(true);
        $mail->Subject="Welcome to SmartCare Guardian";
        $nameSafe=htmlspecialchars($full_name); $emailEnc=urlencode($email); $year=date('Y');
        $mail->Body="<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;padding:0;background:#F7F1E5;font-family:Arial,sans-serif;'><table width='100%' cellpadding='0' cellspacing='0' style='background:#F7F1E5;padding:40px 16px;'><tr><td align='center'><table width='600' cellpadding='0' cellspacing='0' style='max-width:600px;border-radius:20px;overflow:hidden;box-shadow:0 8px 40px rgba(36,56,22,.15);'><tr><td style='background:linear-gradient(135deg,#243816,#365220,#5E8A40);padding:34px 40px 28px;text-align:center;'><table cellpadding='0' cellspacing='0' style='margin:0 auto 10px;'><tr><td style='background:linear-gradient(135deg,#9DC07E,#5E8A40);border-radius:12px;width:46px;height:46px;text-align:center;vertical-align:middle;font-size:22px;line-height:46px;'>🌿</td><td style='padding-left:12px;text-align:left;vertical-align:middle;'><div style='font-size:21px;font-weight:700;color:#fff;line-height:1.1;'>SmartCare</div><div style='font-size:21px;font-weight:700;color:#C8E6A0;line-height:1.1;'>Guardian</div></td></tr></table><div style='font-size:11px;color:rgba(255,255,255,.4);letter-spacing:.12em;text-transform:uppercase;'>Resident Care Portal</div></td></tr><tr><td style='background:#fff;padding:40px;'><div style='text-align:center;margin-bottom:24px;'><div style='display:inline-block;background:#F2F6EF;border-radius:50%;width:62px;height:62px;line-height:62px;font-size:26px;border:2px solid #C4D9B4;'>👋</div></div><h1 style='margin:0 0 8px;font-size:24px;font-weight:700;color:#243816;text-align:center;'>Welcome, $nameSafe!</h1><p style='margin:0 0 24px;font-size:15px;color:#7A7268;text-align:center;'>You have been added as a <strong>Resident</strong> on SmartCare Guardian.</p><div style='height:1px;background:linear-gradient(90deg,transparent,#C4D9B4,transparent);margin-bottom:24px;'></div><p style='margin:0 0 20px;font-size:15px;color:#4A4540;'>Please click the button below to set your password and activate your account.</p><table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:24px;'><tr><td align='center'><a href='http://localhost/SmartCareGuardian/set_password.php?email=$emailEnc' style='display:inline-block;background:linear-gradient(135deg,#243816,#5E8A40);color:#fff;font-size:15px;font-weight:700;padding:14px 36px;border-radius:10px;text-decoration:none;'>Set My Password</a></td></tr></table><p style='margin:0;font-size:15px;color:#4A4540;'>Warm regards,<br><strong style='color:#243816;'>SmartCare Guardian Team</strong></p></td></tr><tr><td style='background:#F2F6EF;border-top:1px solid #C4D9B4;padding:22px 40px;text-align:center;'><p style='margin:0 0 4px;font-size:13px;color:#7A7268;font-weight:600;'>SmartCare Guardian · Resident Care Portal</p><p style='margin:0;font-size:12px;color:#B8B0A4;'>&copy; $year SmartCare Guardian. All rights reserved.</p></td></tr></table></td></tr></table></body></html>";
        $mail->AltBody="Welcome, $full_name! Set your password at: http://localhost/SmartCareGuardian/set_password.php?email=$emailEnc";
        $mail->send(); return true;
    } catch (Exception $e) { return false; }
}

$message="";
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $full_name=trim($_POST['full_name']); $email=trim($_POST['email']);
    $check=$conn->prepare("SELECT * FROM users WHERE email=?"); $check->bind_param("s",$email); $check->execute();
    if ($check->get_result()->num_rows>0) {
        $message="<div class='alert alert-danger'><i class='fas fa-exclamation-circle'></i>This email is already registered.</div>";
    } else {
        $stmt=$conn->prepare("INSERT INTO users (full_name,email,role,status) VALUES (?,?,'resident','pending_password')");
        $stmt->bind_param("ss",$full_name,$email); $stmt->execute();
        sendWelcomeEmail($email,$full_name);
        $message="<div class='alert alert-success'><i class='fas fa-check-circle'></i>Resident added! Welcome email sent.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Add Resident – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══ AYURVEDIC DESIGN SYSTEM — with sidebar ═══ */
        :root{
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
        body{font-family:'Outfit',sans-serif;font-size:15px;line-height:1.6;background:var(--w50);
            background-image:radial-gradient(ellipse 70% 50% at 90% 0%,rgba(157,192,126,.09) 0%,transparent 55%),
            radial-gradient(ellipse 50% 40% at 0% 100%,rgba(122,166,88,.06) 0%,transparent 50%);
            color:var(--st700);min-height:100vh;margin:0;padding:0;}
        h1,h2,h3,h4,h5,h6{font-family:'Cormorant Garamond',serif;color:var(--s800);margin:0;}

        /* Sidebar */
        .sidebar{width:240px;height:100vh;position:fixed;background:var(--s800);display:flex;flex-direction:column;z-index:1000;overflow:hidden;}
        .sidebar::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:radial-gradient(ellipse 120% 60% at 50% -10%,rgba(157,192,126,.18) 0%,transparent 60%),radial-gradient(ellipse 80% 80% at 110% 110%,rgba(94,138,64,.15) 0%,transparent 55%);}
        .sidebar-header{padding:22px 18px 16px;border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0;position:relative;}
        .brand-mark{display:flex;align-items:center;gap:9px;margin-bottom:4px;}
        .brand-icon{width:32px;height:32px;background:linear-gradient(135deg,var(--s300),var(--s500));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;color:white;box-shadow:0 3px 10px rgba(0,0,0,.25);flex-shrink:0;}
        .sidebar-header h4{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:white;line-height:1.15;}
        .sidebar-header small{font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.08em;text-transform:uppercase;font-weight:500;display:block;margin-left:41px;margin-top:1px;}
        .sidebar-nav{flex:1;overflow-y:auto;padding:8px 0;position:relative;}
        .sidebar a{display:flex;align-items:center;gap:9px;padding:10px 10px 10px 20px;color:rgba(255,255,255,.6);text-decoration:none;font-size:13.5px;font-weight:500;transition:all .2s;margin:1px 8px;border-radius:var(--radius-sm);position:relative;min-height:42px;}
        .sidebar a:hover{background:rgba(255,255,255,.10);color:white;}
        .sidebar a.active{background:rgba(157,192,126,.2);color:#C8E6A0;}
        .sidebar a.active::before{content:'';position:absolute;left:-8px;top:20%;bottom:20%;width:3px;background:var(--s300);border-radius:0 3px 3px 0;}
        .sidebar i{width:18px;text-align:center;font-size:13px;opacity:.85;flex-shrink:0;}
        .sidebar-footer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 8px;position:relative;}
        .sidebar-footer a{margin:0;color:rgba(255,255,255,.5)!important;}
        .sidebar-footer a:hover{color:rgba(255,255,255,.8)!important;}

        /* Layout */
        .content{margin-left:240px;padding:24px;min-height:100vh;display:flex;align-items:center;justify-content:center;}

        /* Topbar */
        .topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
        .topbar h4{font-size:22px;font-weight:500;color:var(--s800);margin-bottom:2px;}
        .topbar p{font-size:13px;color:var(--st300);margin:0;}
        .logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;}
        .logout-btn:hover{opacity:.9;transform:translateY(-1px);}

        /* Form card */
        .form-card{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-lift);overflow:hidden;width:100%;max-width:520px;border:1px solid rgba(196,217,180,.25);}
        .form-card-header{background:linear-gradient(135deg,var(--s800),var(--s700),var(--s500));padding:28px 32px;text-align:center;position:relative;overflow:hidden;}
        .form-card-header::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:radial-gradient(ellipse 120% 60% at 50% -10%,rgba(157,192,126,.18) 0%,transparent 60%);}
        .form-card-header-icon{width:54px;height:54px;background:rgba(255,255,255,.12);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:white;margin:0 auto 12px;border:2px solid rgba(255,255,255,.2);position:relative;}
        .form-card-header h3{font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:600;color:white;margin:0 0 5px;position:relative;}
        .form-card-header p{font-size:13px;color:rgba(255,255,255,.65);margin:0;position:relative;}
        .form-card-body{padding:32px;}

        /* Form elements */
        .form-label{color:var(--s800);font-weight:700;margin-bottom:6px;font-size:13px;display:flex;align-items:center;gap:6px;}
        .form-control{border:2px solid var(--s100);border-radius:var(--radius-md);padding:11px 14px;font-family:'Outfit',sans-serif;font-size:14px;color:var(--st700);background:var(--w50);transition:all .2s;width:100%;display:block;}
        .form-control:focus{border-color:var(--s400);box-shadow:0 0 0 3px rgba(122,166,88,.15);outline:none;}
        .form-control::placeholder{color:var(--st300);}
        .input-icon-wrap{position:relative;}
        .input-icon-wrap .form-control{padding-left:42px;}
        .input-icon-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--s400);font-size:13px;z-index:2;pointer-events:none;}

        /* Buttons */
        .btn-save{background:linear-gradient(135deg,var(--s400),var(--s700));border:none;border-radius:var(--radius-md);color:white;padding:12px 28px;font-weight:700;font-size:14px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:7px;}
        .btn-save:hover{opacity:.9;transform:translateY(-1px);box-shadow:var(--shadow-card);}
        .btn-outline{background:transparent;border:2px solid var(--s200);border-radius:var(--radius-md);color:var(--st500);padding:11px 20px;font-weight:700;font-size:14px;font-family:'Outfit',sans-serif;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:7px;cursor:pointer;}
        .btn-outline:hover{background:var(--s50);border-color:var(--s300);color:var(--s700);}

        /* Alerts */
        .alert{border-radius:var(--radius-md);border:none;padding:13px 16px;margin-bottom:20px;font-size:14px;font-weight:600;display:flex;align-items:flex-start;gap:8px;}
        .alert-danger{background:var(--red-bg);color:var(--red-text);}
        .alert-success{background:var(--green-bg);color:var(--green-text);}

        /* Info note */
        .info-note{background:var(--s50);border:1px solid var(--s200);border-radius:var(--radius-md);padding:13px 16px;margin-bottom:22px;font-size:13px;color:var(--s700);display:flex;align-items:center;gap:8px;}

        @media(max-width:768px){.sidebar{width:100%;height:auto;position:relative;}.content{margin-left:0;padding:16px;}}
    </style>
</head>
<body>

<!-- ══ ADMIN SIDEBAR ════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sidebar-header">
        <div class="brand-mark">
            <div class="brand-icon"><i class="fas fa-leaf"></i></div>
            <h4>SmartCare<br>Guardian</h4>
        </div>
        <small>Admin Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="admin_dashboard.php"><i class="fas fa-gauge-high"></i> Dashboard</a>
        <a href="manage_residents.php"><i class="fas fa-user-group"></i> Manage Residents</a>
        <a href="manage_caregivers.php"><i class="fas fa-user-nurse"></i> Manage Caregivers</a>
        <a href="manage_appointments.php"><i class="fas fa-calendar-days"></i> Appointments</a>
        <a href="contact_messages.php"><i class="fas fa-envelope"></i> Messages</a>
        <a href="admin_reports.php"><i class="fas fa-chart-line"></i> Reports</a>
    </div>
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<div class="content">
  <div style="width:100%;max-width:520px;">
    <div class="form-card">
        <div class="form-card-header">
            <div class="form-card-header-icon"><i class="fas fa-user-plus"></i></div>
            <h3>Add New Resident</h3>
            <p>Invite a resident to join SmartCare Guardian</p>
        </div>
        <div class="form-card-body">
            <?php echo $message; ?>
            <div class="info-note">
                <i class="fas fa-envelope" style="color:var(--s400);flex-shrink:0;"></i>
                Resident will receive a welcome email with a link to set their password.
            </div>
            <form method="POST" id="residentForm">
                <div class="mb-4">
                    <label class="form-label"><i class="fas fa-user" style="color:var(--s400);"></i>Full Name</label>
                    <div class="input-icon-wrap">
                        <i class="fas fa-user"></i>
                        <input type="text" name="full_name" class="form-control" required placeholder="Enter resident's full name">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label"><i class="fas fa-envelope" style="color:var(--s400);"></i>Email Address</label>
                    <div class="input-icon-wrap">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" class="form-control" required placeholder="Enter resident's email address">
                    </div>
                </div>
                <div class="d-flex gap-3 mt-2">
                    <a href="manage_residents.php" class="btn-outline"><i class="fas fa-arrow-left"></i>Back</a>
                    <button type="submit" class="btn-save flex-grow-1"><i class="fas fa-user-plus"></i>Add Resident</button>
                </div>
            </form>
        </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('residentForm').addEventListener('submit',function(e){
    const name=document.querySelector('input[name="full_name"]').value;
    const email=document.querySelector('input[name="email"]').value;
    if(name.trim().length<2){e.preventDefault();alert('Please enter a valid full name.');return;}
    if(!email.includes('@')||!email.includes('.')){e.preventDefault();alert('Please enter a valid email address.');return;}
    const btn=this.querySelector('button[type="submit"]');
    btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>Adding Resident...';btn.disabled=true;
});
document.addEventListener('DOMContentLoaded',()=>document.querySelector('input[name="full_name"]').focus());
</script>
</body>
</html>