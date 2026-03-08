<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: ../login.php"); exit();
}
date_default_timezone_set('Asia/Colombo');
include '../db_connection.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once '../vendor/autoload.php';

// ══════════════════════════════════════════════════════════════
//  SMARTCARE GUARDIAN — SHARED EMAIL WRAPPER
// ══════════════════════════════════════════════════════════════
function emailWrapper(string $bodyContent): string {
    $year = date('Y');
    return "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1.0\"></head>"
         . "<body style=\"margin:0;padding:0;background-color:#F7F1E5;font-family:Arial,Helvetica,sans-serif;\">"
         . "<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color:#F7F1E5;padding:40px 16px;\"><tr><td align=\"center\">"
         . "<table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"max-width:600px;width:100%;border-radius:20px;overflow:hidden;box-shadow:0 8px 40px rgba(36,56,22,0.15);\">"
         . "<tr><td style=\"background:linear-gradient(135deg,#243816 0%,#365220 50%,#5E8A40 100%);padding:34px 40px 28px;text-align:center;\">"
         . "<table cellpadding=\"0\" cellspacing=\"0\" style=\"margin:0 auto 10px;\"><tr>"
         . "<td style=\"background:linear-gradient(135deg,#9DC07E,#5E8A40);border-radius:12px;width:46px;height:46px;text-align:center;vertical-align:middle;font-size:22px;line-height:46px;\">🌿</td>"
         . "<td style=\"padding-left:12px;text-align:left;vertical-align:middle;\"><div style=\"font-size:21px;font-weight:700;color:#ffffff;line-height:1.1;\">SmartCare</div><div style=\"font-size:21px;font-weight:700;color:#C8E6A0;line-height:1.1;\">Guardian</div></td>"
         . "</tr></table><div style=\"font-size:11px;color:rgba(255,255,255,0.4);letter-spacing:0.12em;text-transform:uppercase;\">Resident Care Portal</div></td></tr>"
         . "<tr><td style=\"background:#ffffff;padding:40px 40px 32px;\">{$bodyContent}</td></tr>"
         . "<tr><td style=\"background:#F2F6EF;border-top:1px solid #C4D9B4;padding:22px 40px;text-align:center;\">"
         . "<p style=\"margin:0 0 4px;font-size:13px;color:#7A7268;font-weight:600;\">SmartCare Guardian &nbsp;·&nbsp; Resident Care Portal</p>"
         . "<p style=\"margin:0 0 6px;font-size:12px;color:#B8B0A4;\">This is an automated message. Please do not reply directly to this email.</p>"
         . "<p style=\"margin:0;font-size:12px;color:#B8B0A4;\">&copy; {$year} SmartCare Guardian. All rights reserved.</p>"
         . "</td></tr></table></td></tr></table></body></html>";
}

$caregiver_user_id = $_SESSION['user_id'];
$cg_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$cg_stmt->bind_param("i", $caregiver_user_id); $cg_stmt->execute();
$caregiver = $cg_stmt->get_result()->fetch_assoc(); $cg_stmt->close();
$pk_stmt = $conn->prepare("SELECT caregiver_id FROM caregivers WHERE user_id = ?");
$pk_stmt->bind_param("i", $caregiver_user_id); $pk_stmt->execute();
$pk_row = $pk_stmt->get_result()->fetch_assoc(); $pk_stmt->close();
$caregiver_pk = $pk_row['caregiver_id'] ?? 0;
$unread_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND is_read = 0");
$unread_stmt->bind_param("i", $caregiver_user_id); $unread_stmt->execute();
$unread_messages = $unread_stmt->get_result()->fetch_assoc()['cnt']; $unread_stmt->close();
$success = '';
if (!empty($_SESSION['appt_success'])) { $success = $_SESSION['appt_success']; unset($_SESSION['appt_success']); }
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_appointment'])) {
    $resident_id      = (int)$_POST['resident_id'];
    $title            = trim($_POST['title']);
    $description      = trim($_POST['description']);
    $appointment_date = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $location         = trim($_POST['location']);
    $auth = $conn->prepare("SELECT id FROM caregiver_assignments WHERE caregiver_id = ? AND resident_id = ?");
    $auth->bind_param("ii", $caregiver_user_id, $resident_id); $auth->execute();
    $authorized = $auth->get_result()->num_rows > 0; $auth->close();
    if (!$authorized) {
        $error = "You are not authorized to create appointments for this resident.";
    } elseif (empty($title) || empty($appointment_date) || empty($appointment_time) || empty($location)) {
        $error = "Please fill in all required fields.";
    } elseif ($appointment_date < date('Y-m-d')) {
        $error = "Appointment date cannot be in the past.";
    } else {
        $dup = $conn->prepare("SELECT appointment_id FROM appointments WHERE resident_id = ? AND caregiver_id = ? AND appointment_date = ? AND appointment_time = ? AND status = 'scheduled' LIMIT 1");
        $dup->bind_param("iiss", $resident_id, $caregiver_user_id, $appointment_date, $appointment_time);
        $dup->execute(); $is_dup = $dup->get_result()->num_rows > 0; $dup->close();
        if ($is_dup) {
            $error = "A scheduled appointment for this resident already exists at that date and time. Please choose a different time.";
        } else {
            $ins = $conn->prepare("INSERT INTO appointments (resident_id, caregiver_id, title, description, appointment_date, appointment_time, location, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')");
            $ins->bind_param("iisssss", $resident_id, $caregiver_user_id, $title, $description, $appointment_date, $appointment_time, $location);
            if ($ins->execute()) {
                $emailStmt = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ? AND role = 'resident'");
                $emailStmt->bind_param("i", $resident_id); $emailStmt->execute();
                $resident_email_data = $emailStmt->get_result()->fetch_assoc(); $emailStmt->close();
                if ($resident_email_data && !empty($resident_email_data['email'])) {
                    try {
                        $mail = new PHPMailer(true);
                        $mail->isSMTP(); $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true;
                        $mail->Username = 'smartcareguardian@gmail.com'; $mail->Password = 'yvryblsvyfsspjjn';
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = 587;
                        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
                        $mail->setFrom('smartcareguardian@gmail.com', 'SmartCare Guardian');
                        $mail->addAddress($resident_email_data['email'], $resident_email_data['full_name']);
                        $mail->isHTML(true);
                        $mail->Subject = 'New Appointment Scheduled — SmartCare Guardian';

                        // ── Branded appointment email ─────────────────────
                        $resName   = htmlspecialchars($resident_email_data['full_name']);
                        $apptTitle = htmlspecialchars($title);
                        $apptDate  = date('F j, Y', strtotime($appointment_date));
                        $apptTime  = date('g:i A',  strtotime($appointment_time));
                        $apptLoc   = htmlspecialchars($location);

                        $bodyContent = "
              <div style='text-align:center;margin-bottom:26px;'>
                <div style='display:inline-block;background:#F2F6EF;border-radius:50%;width:62px;height:62px;line-height:62px;font-size:26px;border:2px solid #C4D9B4;'>📅</div>
              </div>
              <h1 style='margin:0 0 8px;font-size:24px;font-weight:700;color:#243816;text-align:center;'>New Appointment Scheduled</h1>
              <p style='margin:0 0 26px;font-size:15px;color:#7A7268;text-align:center;'>Your caregiver has booked a new appointment for you.</p>
              <div style='height:1px;background:linear-gradient(90deg,transparent,#C4D9B4,transparent);margin-bottom:26px;'></div>
              <p style='margin:0 0 20px;font-size:15px;color:#4A4540;line-height:1.7;'>Dear <strong style='color:#243816;'>{$resName}</strong>,</p>
              <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse;margin-bottom:24px;border-radius:12px;overflow:hidden;'>
                <tr>
                  <td style='background:#F2F6EF;padding:11px 16px;font-size:12px;font-weight:700;color:#5E8A40;text-transform:uppercase;letter-spacing:0.06em;width:30%;'>Title</td>
                  <td style='background:#ffffff;padding:11px 16px;font-size:14px;color:#4A4540;border-bottom:1px solid #E3EDDB;'><strong>{$apptTitle}</strong></td>
                </tr>
                <tr>
                  <td style='background:#F2F6EF;padding:11px 16px;font-size:12px;font-weight:700;color:#5E8A40;text-transform:uppercase;letter-spacing:0.06em;'>Date</td>
                  <td style='background:#ffffff;padding:11px 16px;font-size:14px;color:#4A4540;border-bottom:1px solid #E3EDDB;'><strong style='color:#243816;'>{$apptDate}</strong></td>
                </tr>
                <tr>
                  <td style='background:#F2F6EF;padding:11px 16px;font-size:12px;font-weight:700;color:#5E8A40;text-transform:uppercase;letter-spacing:0.06em;'>Time</td>
                  <td style='background:#ffffff;padding:11px 16px;font-size:14px;color:#4A4540;border-bottom:1px solid #E3EDDB;'><strong style='color:#243816;'>{$apptTime}</strong></td>
                </tr>
                <tr>
                  <td style='background:#F2F6EF;padding:11px 16px;font-size:12px;font-weight:700;color:#5E8A40;text-transform:uppercase;letter-spacing:0.06em;'>Location</td>
                  <td style='background:#ffffff;padding:11px 16px;font-size:14px;color:#7A7268;'>{$apptLoc}</td>
                </tr>
              </table>
              <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom:16px;'>
                <tr><td style='background:#FAECC8;border-radius:10px;padding:14px 18px;border-left:4px solid #D4A853;'>
                  <p style='margin:0;font-size:14px;color:#7A5010;font-weight:600;'>⏰ &nbsp;Please be ready on time for your appointment.</p>
                </td></tr>
              </table>
              <p style='margin:0;font-size:15px;color:#4A4540;line-height:1.7;'>Warm regards,<br><strong style='color:#243816;'>SmartCare Guardian Team</strong></p>";

                        $mail->Body    = emailWrapper($bodyContent);
                        $mail->AltBody = "Dear {$resident_email_data['full_name']},\n\nNew appointment:\nTitle: {$title}\nDate: {$apptDate}\nTime: {$apptTime}\nLocation: {$location}\n\nPlease be ready on time.\n\nSmartCare Guardian";
                        $mail->send();
                    } catch (Exception $e) {
                        error_log("Appointment email failed: " . $mail->ErrorInfo);
                    }
                }
                $ins->close();
                $_SESSION['appt_success'] = "Appointment scheduled successfully!";
                header("Location: caregiver_appointments.php");
                exit();
            } else {
                $error = "Error creating appointment. Please try again.";
                $ins->close();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $appt_id    = (int)$_POST['appointment_id'];
    $new_status = in_array($_POST['new_status'], ['completed','cancelled']) ? $_POST['new_status'] : '';
    if ($new_status) {
        $upd = $conn->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ? AND caregiver_id = ?");
        $upd->bind_param("sii", $new_status, $appt_id, $caregiver_user_id);
        $upd->execute(); $upd->close();
        $success = "Appointment marked as " . $new_status . ".";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_appointment'])) {
    $appt_id = (int)$_POST['appointment_id'];
    $del = $conn->prepare("DELETE FROM appointments WHERE appointment_id = ? AND caregiver_id = ?");
    $del->bind_param("ii", $appt_id, $caregiver_user_id);
    $del->execute(); $del->close();
    $success = "Appointment deleted.";
}

$res_stmt = $conn->prepare("SELECT u.user_id, u.full_name FROM users u JOIN caregiver_assignments ca ON u.user_id = ca.resident_id WHERE ca.caregiver_id = ? AND u.role = 'resident' AND u.status = 'active' ORDER BY u.full_name ASC");
$res_stmt->bind_param("i", $caregiver_user_id); $res_stmt->execute();
$assigned_residents = $res_stmt->get_result(); $res_stmt->close();

$filter_status   = $_GET['filter_status']   ?? 'all';
$filter_resident = (int)($_GET['filter_resident'] ?? 0);
$filter_from     = $_GET['filter_from']     ?? '';
$filter_to       = $_GET['filter_to']       ?? '';
$today = date('Y-m-d');

$today_stmt = $conn->prepare("SELECT a.*, u.full_name AS resident_name FROM appointments a JOIN users u ON a.resident_id = u.user_id WHERE a.caregiver_id = ? AND a.appointment_date = ? ORDER BY a.appointment_time ASC");
$today_stmt->bind_param("is", $caregiver_user_id, $today); $today_stmt->execute();
$today_appointments = $today_stmt->get_result(); $today_stmt->close();
$today_count = $today_appointments->num_rows;

$where  = ["a.caregiver_id = ?"]; $params = [$caregiver_user_id]; $types = "i";
if ($filter_status !== 'all') { $where[] = "a.status = ?"; $params[] = $filter_status; $types .= "s"; }
if ($filter_resident > 0)     { $where[] = "a.resident_id = ?"; $params[] = $filter_resident; $types .= "i"; }
if ($filter_from)             { $where[] = "a.appointment_date >= ?"; $params[] = $filter_from; $types .= "s"; }
if ($filter_to)               { $where[] = "a.appointment_date <= ?"; $params[] = $filter_to; $types .= "s"; }

$sql = "SELECT a.*, u.full_name AS resident_name FROM appointments a JOIN users u ON a.resident_id = u.user_id WHERE " . implode(" AND ", $where) . " ORDER BY a.appointment_date DESC, a.appointment_time DESC";
$all_stmt = $conn->prepare($sql); $all_stmt->bind_param($types, ...$params);
$all_stmt->execute(); $appointments = $all_stmt->get_result(); $all_stmt->close();

$counts_stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(status = 'scheduled') AS scheduled, SUM(status = 'completed') AS completed, SUM(status = 'cancelled') AS cancelled FROM appointments WHERE caregiver_id = ?");
$counts_stmt->bind_param("i", $caregiver_user_id); $counts_stmt->execute();
$counts = $counts_stmt->get_result()->fetch_assoc(); $counts_stmt->close();

$res2_stmt = $conn->prepare("SELECT DISTINCT u.user_id, u.full_name FROM appointments a JOIN users u ON a.resident_id = u.user_id WHERE a.caregiver_id = ? ORDER BY u.full_name ASC");
$res2_stmt->bind_param("i", $caregiver_user_id); $res2_stmt->execute();
$filter_residents = $res2_stmt->get_result(); $res2_stmt->close();

$med_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM medications m JOIN caregiver_assignments ca ON ca.resident_id = m.resident_id WHERE ca.caregiver_id = ? AND m.taken = 0 AND m.medication_date = CURDATE()");
$med_stmt->bind_param("i", $caregiver_user_id); $med_stmt->execute();
$pending_meds = $med_stmt->get_result()->fetch_assoc()['cnt'] ?? 0; $med_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    :root{--s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;--s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;--s600:#4A6E30;--s700:#365220;--s800:#243816;--w50:#FDFAF5;--w100:#F7F1E5;--st300:#B8B0A4;--st500:#7A7268;--st700:#4A4540;--green-bg:#DDEFD8;--green-text:#3A6830;--amber-bg:#FAECC8;--amber-text:#7A5010;--red-bg:#F5DADA;--red-text:#6A2020;--blue-bg:#DBEEFF;--blue-text:#1A4870;--radius-sm:8px;--radius-md:12px;--radius-lg:20px;--shadow-soft:0 2px 12px rgba(36,56,22,.07),0 1px 3px rgba(36,56,22,.05);--shadow-card:0 4px 24px rgba(36,56,22,.09),0 1px 4px rgba(36,56,22,.06);--shadow-lift:0 8px 32px rgba(36,56,22,.13),0 2px 8px rgba(36,56,22,.07);}
    *,*::before,*::after{box-sizing:border-box;}
    body{font-family:'Outfit',sans-serif;font-size:15px;line-height:1.6;background:var(--w50);background-image:radial-gradient(ellipse 70% 50% at 90% 0%,rgba(157,192,126,.09) 0%,transparent 55%),radial-gradient(ellipse 50% 40% at 0% 100%,rgba(122,166,88,.06) 0%,transparent 50%);color:var(--st700);min-height:100vh;margin:0;padding:0;}
    h1,h2,h3,h4,h5,h6{font-family:'Cormorant Garamond',serif;color:var(--s800);margin:0;}
    .sidebar{width:240px;height:100vh;position:fixed;background:var(--s800);display:flex;flex-direction:column;z-index:1000;overflow:hidden;}
    .sidebar::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:radial-gradient(ellipse 120% 60% at 50% -10%,rgba(157,192,126,.18) 0%,transparent 60%),radial-gradient(ellipse 80% 80% at 110% 110%,rgba(94,138,64,.15) 0%,transparent 55%);}
    .sidebar-header{padding:22px 18px 16px;border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0;position:relative;}
    .brand-mark{display:flex;align-items:center;gap:9px;margin-bottom:4px;}
    .brand-icon{width:32px;height:32px;background:linear-gradient(135deg,var(--s300),var(--s500));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;color:white;box-shadow:0 3px 10px rgba(0,0,0,.25);flex-shrink:0;}
    .sidebar-header h4{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:white;line-height:1.15;}
    .sidebar-header small{font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.08em;text-transform:uppercase;font-weight:500;display:block;margin-left:41px;margin-top:1px;}
    .sidebar-nav{flex:1;overflow-y:auto;padding:8px 0;position:relative;}
    .sidebar-nav::-webkit-scrollbar{width:4px;}.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:3px;}
    .sidebar a{display:flex;align-items:center;gap:9px;padding:10px 10px 10px 20px;color:rgba(255,255,255,.6);text-decoration:none;font-size:13.5px;font-weight:500;transition:all .2s;margin:1px 8px;border-radius:var(--radius-sm);position:relative;min-height:42px;}
    .sidebar a:hover{background:rgba(255,255,255,.10);color:white;}
    .sidebar a.active{background:rgba(157,192,126,.2);color:#C8E6A0;}
    .sidebar a.active::before{content:'';position:absolute;left:-8px;top:20%;bottom:20%;width:3px;background:var(--s300);border-radius:0 3px 3px 0;}
    .sidebar i{width:18px;text-align:center;font-size:13px;opacity:.85;flex-shrink:0;}
    .sb-badge{margin-left:auto;background:#8B3A3A;color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;}
    .msg-badge{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:#8B3A3A;border-radius:50%;min-width:18px;height:18px;font-size:.65rem;display:flex;align-items:center;justify-content:center;padding:0 3px;color:white;font-weight:700;}
    .sidebar-footer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 8px;position:relative;}
    .sidebar-footer a{margin:0;color:rgba(255,255,255,.5)!important;font-size:13.5px;}
    .sidebar-footer a:hover{color:rgba(255,255,255,.8)!important;}
    .content{margin-left:240px;padding:24px;min-height:100vh;}
    .topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
    .topbar h4{font-size:22px;font-weight:500;color:var(--s800);margin-bottom:2px;}
    .topbar p{font-size:13px;color:var(--st300);margin:0;}
    .logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;}
    .logout-btn:hover{opacity:.9;transform:translateY(-1px);}
    .alert{border-radius:var(--radius-md);border:none;padding:13px 18px;margin-bottom:18px;font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px;}
    .alert-success{background:var(--green-bg);color:var(--green-text);}
    .alert-danger{background:var(--red-bg);color:var(--red-text);}
    .summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:20px;}
    .summary-card{background:white;border-radius:var(--radius-lg);padding:18px 20px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);transition:all .2s;text-decoration:none;display:block;color:inherit;}
    .summary-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lift);color:inherit;}
    .s-icon{width:46px;height:46px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;font-size:1.15rem;margin-bottom:11px;color:white;}
    .s-total{background:linear-gradient(135deg,var(--s200),var(--s400));}
    .s-scheduled{background:linear-gradient(135deg,var(--s300),var(--s600));}
    .s-completed{background:linear-gradient(135deg,#7BB3D9,#1A4870);}
    .s-cancelled{background:linear-gradient(135deg,#C87A7A,#8B3A3A);}
    .s-number{font-size:1.8rem;font-weight:800;color:var(--s800);line-height:1;}
    .s-label{font-size:12px;font-weight:600;color:var(--st500);margin-top:4px;}
    .form-card{background:white;border-radius:var(--radius-lg);padding:26px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;}
    .form-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:12px;border-bottom:2px solid var(--s100);}
    .form-card-header h5{margin:0;font-family:'Outfit',sans-serif;font-weight:700;color:var(--s800);font-size:15px;display:flex;align-items:center;gap:7px;}
    .collapse-btn{background:var(--s50);border:1px solid var(--s200);border-radius:var(--radius-sm);padding:6px 13px;color:var(--s700);font-weight:700;font-size:12px;cursor:pointer;transition:all .2s;font-family:'Outfit',sans-serif;}
    .collapse-btn:hover{background:var(--s100);}
    .form-control,.form-select{border:2px solid var(--s100);border-radius:var(--radius-md);padding:10px 13px;font-family:'Outfit',sans-serif;font-size:14px;color:var(--st700);background:var(--w50);transition:all .2s;}
    .form-control:focus,.form-select:focus{border-color:var(--s400);box-shadow:0 0 0 3px rgba(122,166,88,.15);outline:none;}
    .form-control::placeholder{color:var(--st300);}
    .form-label{color:var(--s800);font-weight:700;margin-bottom:6px;font-size:13px;}
    .required::after{content:" *";color:var(--red-text);}
    .section-head{background:linear-gradient(135deg,var(--s600),var(--s800));border-radius:var(--radius-lg) var(--radius-lg) 0 0;padding:15px 22px;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;}
    .section-head::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
    .section-head h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;position:relative;display:flex;align-items:center;gap:7px;}
    .section-head span{color:rgba(255,255,255,.7);font-size:12px;position:relative;}
    .appt-card{background:white;border-radius:var(--radius-md);padding:18px;box-shadow:var(--shadow-card);border-left:4px solid;transition:all .2s;height:100%;}
    .appt-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lift);}
    .appt-card.scheduled{border-left-color:var(--s400);}
    .appt-card.completed{border-left-color:var(--blue-text);}
    .appt-card.cancelled{border-left-color:var(--red-text);}
    .appt-time{background:var(--s50);border-radius:var(--radius-sm);padding:4px 11px;font-weight:700;font-size:12px;color:var(--s800);display:inline-block;}
    .appt-resident{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
    .res-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--s300),var(--s600));display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:.9rem;flex-shrink:0;}
    .badge{padding:4px 10px;border-radius:20px;font-weight:700;font-size:11px;}
    .badge-scheduled{background:var(--green-bg);color:var(--green-text);}
    .badge-completed{background:var(--blue-bg);color:var(--blue-text);}
    .badge-cancelled{background:var(--red-bg);color:var(--red-text);}
    .badge-today{background:var(--amber-bg);color:var(--amber-text);font-size:10px;margin-left:4px;}
    .table-card{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);overflow:hidden;}
    .table{margin:0;font-size:13px;}
    .table thead{background:var(--s50);}
    .table thead th{border:none;padding:11px 14px;font-weight:700;color:var(--s700);font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
    .table tbody tr{border-bottom:1px solid var(--s50);transition:background .15s;}
    .table tbody tr:hover{background:var(--s50);}
    .table tbody td{padding:11px 14px;vertical-align:middle;border:none;color:var(--st700);}
    .filter-bar{background:var(--s50);padding:16px 20px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;border-bottom:1px solid var(--s100);}
    .filter-bar .form-control,.filter-bar .form-select{border-radius:var(--radius-sm);padding:8px 11px;font-size:13px;min-width:130px;flex:1;}
    .filter-group{display:flex;flex-direction:column;flex:1;min-width:120px;}
    .filter-group label{font-size:11px;font-weight:700;color:var(--s700);margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;}
    .act-btns{display:flex;gap:6px;flex-wrap:wrap;}
    .btn-icon{width:30px;height:30px;border-radius:var(--radius-sm);border:none;display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;transition:all .2s;}
    .btn-icon:hover{transform:translateY(-1px);}
    .btn-complete{background:var(--blue-bg);color:var(--blue-text);}
    .btn-cancel-ap{background:var(--amber-bg);color:var(--amber-text);}
    .btn-delete{background:var(--red-bg);color:var(--red-text);}
    .btn-submit{background:linear-gradient(135deg,var(--s400),var(--s700));border:none;border-radius:var(--radius-md);color:white;padding:11px 28px;font-weight:700;font-size:14px;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:7px;}
    .btn-submit:hover{opacity:.9;transform:translateY(-1px);box-shadow:var(--shadow-card);}
    .btn-filter{background:linear-gradient(135deg,var(--s400),var(--s600));border:none;border-radius:var(--radius-sm);color:white;padding:8px 16px;font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;align-self:flex-end;display:inline-flex;align-items:center;gap:5px;}
    .btn-filter:hover{opacity:.9;transform:translateY(-1px);}
    .btn-clear{background:white;border:2px solid var(--s200);border-radius:var(--radius-sm);color:var(--st500);padding:7px 14px;font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;align-self:flex-end;text-decoration:none;display:inline-flex;align-items:center;gap:5px;}
    .btn-clear:hover{border-color:var(--s400);color:var(--s600);}
    .empty-st{text-align:center;padding:40px 20px;color:var(--st300);}
    .empty-st i{font-size:2.4rem;display:block;margin-bottom:10px;opacity:.25;}
    .empty-st p{margin:0;font-size:13px;}
    @media(max-width:768px){.sidebar{width:100%;height:auto;position:relative;}.content{margin-left:0;padding:14px;}}
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <div class="brand-mark">
            <div class="brand-icon"><i class="fas fa-leaf"></i></div>
            <h4>SmartCare<br>Guardian</h4>
        </div>
        <small>Caregiver Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="caregiver_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="caregiver_profile.php"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="caregiver_residents.php"><i class="fa-solid fa-user-group"></i> My Residents</a>
        <a href="log_health.php"><i class="fa-solid fa-heart-pulse"></i> Log Health Data</a>
        <a href="manage_routines.php"><i class="fa-solid fa-calendar-check"></i> Manage Routines</a>
        <a href="manage_medications.php">
            <i class="fa-solid fa-pills"></i> Medications
            <?php if ($pending_meds > 0): ?><span class="sb-badge"><?= $pending_meds ?></span><?php endif; ?>
        </a>
        <a href="caregiver_appointments.php" class="active"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="caregiver_messages.php">
            <i class="fa-solid fa-comments"></i> Messages
            <?php if ($unread_messages > 0): ?><span class="msg-badge"><?= $unread_messages ?></span><?php endif; ?>
        </a>
        <a href="caregiver_ai_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="ai_suggestions.php"><i class="fa-solid fa-wand-magic-sparkles"></i> Routine Suggestions</a>
        <a href="caregiver_reports.php"><i class="fa-solid fa-chart-line"></i> Health Reports</a>
    </div>
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<div class="content">
    <div class="topbar">
        <div>
            <h4><i class="fas fa-calendar-days me-2" style="font-size:18px;color:var(--s500);"></i>Appointment Management</h4>
            <p>Schedule and manage resident appointments</p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt"></i>Logout</button>
        </form>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card"><div class="s-icon s-total"><i class="fas fa-calendar-alt"></i></div><div class="s-number"><?= $counts['total'] ?? 0 ?></div><div class="s-label">Total</div></div>
        <div class="summary-card"><div class="s-icon s-scheduled"><i class="fas fa-calendar-check"></i></div><div class="s-number"><?= $counts['scheduled'] ?? 0 ?></div><div class="s-label">Scheduled</div></div>
        <div class="summary-card"><div class="s-icon s-completed"><i class="fas fa-check-double"></i></div><div class="s-number"><?= $counts['completed'] ?? 0 ?></div><div class="s-label">Completed</div></div>
        <div class="summary-card"><div class="s-icon s-cancelled"><i class="fas fa-calendar-xmark"></i></div><div class="s-number"><?= $counts['cancelled'] ?? 0 ?></div><div class="s-label">Cancelled</div></div>
        <div class="summary-card"><div class="s-icon s-scheduled"><i class="fas fa-calendar-day"></i></div><div class="s-number"><?= $today_count ?></div><div class="s-label">Today</div></div>
    </div>

    <div class="form-card">
        <div class="form-card-header">
            <h5><i class="fas fa-calendar-plus" style="color:var(--s400);"></i>Schedule New Appointment</h5>
            <button class="collapse-btn" type="button" data-bs-toggle="collapse" data-bs-target="#createForm">
                <i class="fas fa-chevron-up" id="collapseIcon"></i> Toggle
            </button>
        </div>
        <div class="collapse show" id="createForm">
            <form method="POST" id="appointmentForm">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required">Resident</label>
                        <select name="resident_id" class="form-select" required>
                            <option value="">Choose a resident…</option>
                            <?php while ($res = $assigned_residents->fetch_assoc()): ?>
                                <option value="<?= $res['user_id'] ?>" <?= (isset($_POST['resident_id']) && $_POST['resident_id'] == $res['user_id']) ? 'selected' : '' ?>><?= htmlspecialchars($res['full_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required">Appointment Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Doctor Visit, Physiotherapy" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Additional notes about this appointment"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required">Date</label>
                        <input type="date" name="appointment_date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($_POST['appointment_date'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required">Time</label>
                        <input type="time" name="appointment_time" class="form-control" value="<?= htmlspecialchars($_POST['appointment_time'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required">Location</label>
                        <input type="text" name="location" class="form-control" placeholder="e.g. Room 3, General Hospital" value="<?= htmlspecialchars($_POST['location'] ?? '') ?>" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="create_appointment" class="btn-submit"><i class="fas fa-calendar-check"></i>Schedule Appointment</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="table-card mb-4">
        <div class="section-head">
            <h5><i class="fas fa-calendar-day"></i>Today's Appointments</h5>
            <span><?= date('l, F j') ?></span>
        </div>
        <div class="p-3">
            <?php if ($today_count > 0): ?>
                <div class="row g-3 p-2">
                    <?php while ($ap = $today_appointments->fetch_assoc()): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="appt-card <?= $ap['status'] ?>">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="appt-time"><i class="fas fa-clock me-1"></i><?= date('g:i A', strtotime($ap['appointment_time'])) ?></span>
                                <span class="badge badge-<?= $ap['status'] ?>"><?= ucfirst($ap['status']) ?></span>
                            </div>
                            <h6 style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--s800);font-size:14px;margin-bottom:10px;"><?= htmlspecialchars($ap['title']) ?></h6>
                            <div class="appt-resident">
                                <div class="res-avatar"><?= strtoupper(substr($ap['resident_name'],0,1)) ?></div>
                                <span style="font-size:13px;color:var(--st500);font-weight:600;"><?= htmlspecialchars($ap['resident_name']) ?></span>
                            </div>
                            <?php if ($ap['description']): ?><p style="font-size:12px;color:var(--st300);margin-bottom:8px;"><?= htmlspecialchars($ap['description']) ?></p><?php endif; ?>
                            <?php if ($ap['location']): ?><p style="font-size:12px;color:var(--st300);margin-bottom:12px;"><i class="fas fa-location-dot me-1"></i><?= htmlspecialchars($ap['location']) ?></p><?php endif; ?>
                            <?php if ($ap['status'] === 'scheduled'): ?>
                            <div class="act-btns mt-auto">
                                <form method="POST" style="display:inline;"><input type="hidden" name="appointment_id" value="<?= $ap['appointment_id'] ?>"><input type="hidden" name="new_status" value="completed"><button type="submit" name="update_status" class="btn-icon btn-complete" title="Mark as Completed"><i class="fas fa-check"></i></button></form>
                                <form method="POST" style="display:inline;"><input type="hidden" name="appointment_id" value="<?= $ap['appointment_id'] ?>"><input type="hidden" name="new_status" value="cancelled"><button type="submit" name="update_status" class="btn-icon btn-cancel-ap" title="Cancel Appointment"><i class="fas fa-ban"></i></button></form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this appointment?');"><input type="hidden" name="appointment_id" value="<?= $ap['appointment_id'] ?>"><button type="submit" name="delete_appointment" class="btn-icon btn-delete" title="Delete"><i class="fas fa-trash"></i></button></form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-st" style="padding:28px 16px;">
                    <i class="fas fa-calendar-check" style="color:var(--s400);opacity:.5;"></i>
                    <p style="color:var(--s600);font-weight:700;">No appointments today — enjoy your day!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-card">
        <div class="section-head">
            <h5><i class="fas fa-list"></i>All Appointments</h5>
            <span><?= $appointments->num_rows ?> record(s)</span>
        </div>
        <div class="filter-bar">
            <form method="GET" style="display:contents;">
                <div class="filter-group"><label>Status</label><select name="filter_status" class="form-select"><option value="all" <?= $filter_status==='all'?'selected':'' ?>>All Statuses</option><option value="scheduled" <?= $filter_status==='scheduled'?'selected':'' ?>>Scheduled</option><option value="completed" <?= $filter_status==='completed'?'selected':'' ?>>Completed</option><option value="cancelled" <?= $filter_status==='cancelled'?'selected':'' ?>>Cancelled</option></select></div>
                <div class="filter-group"><label>Resident</label><select name="filter_resident" class="form-select"><option value="0">All Residents</option><?php while ($fr = $filter_residents->fetch_assoc()): ?><option value="<?= $fr['user_id'] ?>" <?= $filter_resident===(int)$fr['user_id']?'selected':'' ?>><?= htmlspecialchars($fr['full_name']) ?></option><?php endwhile; ?></select></div>
                <div class="filter-group"><label>From</label><input type="date" name="filter_from" class="form-control" value="<?= htmlspecialchars($filter_from) ?>"></div>
                <div class="filter-group"><label>To</label><input type="date" name="filter_to" class="form-control" value="<?= htmlspecialchars($filter_to) ?>"></div>
                <button type="submit" class="btn-filter"><i class="fas fa-filter"></i>Filter</button>
                <a href="caregiver_appointments.php" class="btn-clear"><i class="fas fa-times"></i>Clear</a>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Resident</th><th>Title</th><th>Date</th><th>Time</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if ($appointments->num_rows > 0): ?>
                        <?php while ($ap = $appointments->fetch_assoc()): ?>
                        <tr>
                            <td><div style="display:flex;align-items:center;gap:8px;"><div class="res-avatar" style="width:30px;height:30px;font-size:.8rem;"><?= strtoupper(substr($ap['resident_name'],0,1)) ?></div><strong style="color:var(--s800);"><?= htmlspecialchars($ap['resident_name']) ?></strong></div></td>
                            <td><?= htmlspecialchars($ap['title']) ?><?php if ($ap['description']): ?><div style="font-size:11px;color:var(--st300);margin-top:1px;"><?= mb_strimwidth(htmlspecialchars($ap['description']),0,50,'…') ?></div><?php endif; ?></td>
                            <td><strong style="color:var(--s800);"><?= date('M j, Y', strtotime($ap['appointment_date'])) ?></strong><?php if ($ap['appointment_date'] === $today): ?><span class="badge badge-today">Today</span><?php endif; ?></td>
                            <td><span style="background:var(--s50);border-radius:6px;padding:3px 8px;font-weight:700;font-size:12px;color:var(--s800);"><?= date('g:i A', strtotime($ap['appointment_time'])) ?></span></td>
                            <td><small style="color:var(--st300);"><?= htmlspecialchars($ap['location']) ?></small></td>
                            <td><span class="badge badge-<?= $ap['status'] ?>"><?= ucfirst($ap['status']) ?></span></td>
                            <td>
                                <div class="act-btns">
                                    <?php if ($ap['status'] === 'scheduled'): ?>
                                        <form method="POST" style="display:inline;"><input type="hidden" name="appointment_id" value="<?= $ap['appointment_id'] ?>"><input type="hidden" name="new_status" value="completed"><button type="submit" name="update_status" class="btn-icon btn-complete" title="Mark Completed"><i class="fas fa-check"></i></button></form>
                                        <form method="POST" style="display:inline;"><input type="hidden" name="appointment_id" value="<?= $ap['appointment_id'] ?>"><input type="hidden" name="new_status" value="cancelled"><button type="submit" name="update_status" class="btn-icon btn-cancel-ap" title="Cancel"><i class="fas fa-ban"></i></button></form>
                                    <?php endif; ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this appointment?');"><input type="hidden" name="appointment_id" value="<?= $ap['appointment_id'] ?>"><button type="submit" name="delete_appointment" class="btn-icon btn-delete" title="Delete"><i class="fas fa-trash"></i></button></form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7"><div class="empty-st"><i class="fas fa-calendar-xmark"></i><p>No appointments found<?= $filter_status !== 'all' || $filter_resident || $filter_from ? ' matching the selected filters.' : '.' ?></p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('createForm')?.addEventListener('show.bs.collapse', () => { document.getElementById('collapseIcon').className = 'fas fa-chevron-up'; });
document.getElementById('createForm')?.addEventListener('hide.bs.collapse', () => { document.getElementById('collapseIcon').className = 'fas fa-chevron-down'; });
document.getElementById('appointmentForm')?.addEventListener('submit', function(e) {
    const dateVal = this.querySelector('[name="appointment_date"]').value;
    const timeVal = this.querySelector('[name="appointment_time"]').value;
    const today = new Date(); today.setHours(0,0,0,0);
    const chosen = new Date(dateVal);
    if (!dateVal || chosen < today) { e.preventDefault(); alert('Please select today or a future date for the appointment.'); return; }
    if (!timeVal) { e.preventDefault(); alert('Please select a time for the appointment.'); return; }
});
document.querySelectorAll('.alert').forEach(el => { setTimeout(() => { el.style.transition='opacity .5s'; el.style.opacity='0'; setTimeout(()=>el.remove(),500); }, 4000); });
</script>
</body>
</html>