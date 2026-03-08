<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}
include 'db_connection.php';
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ══════════════════════════════════════════════════════════════
//  SMARTCARE GUARDIAN — SHARED EMAIL WRAPPER
// ══════════════════════════════════════════════════════════════
function emailWrapper(string $bodyContent): string {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#F7F1E5;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" border="0"
         style="background-color:#F7F1E5;padding:40px 16px;">
    <tr><td align="center">

      <!-- Card -->
      <table width="600" cellpadding="0" cellspacing="0" border="0"
             style="max-width:600px;width:100%;border-radius:20px;overflow:hidden;
                    box-shadow:0 8px 40px rgba(36,56,22,0.15);">

        <!-- ── Header ───────────────────────────────────────── -->
        <tr>
          <td style="background:linear-gradient(135deg,#243816 0%,#365220 50%,#5E8A40 100%);
                     padding:34px 40px 28px;text-align:center;">
            <table cellpadding="0" cellspacing="0" style="margin:0 auto 10px;">
              <tr>
                <td style="background:linear-gradient(135deg,#9DC07E,#5E8A40);
                           border-radius:12px;width:46px;height:46px;
                           text-align:center;vertical-align:middle;
                           font-size:22px;line-height:46px;">
                  🌿
                </td>
                <td style="padding-left:12px;text-align:left;vertical-align:middle;">
                  <div style="font-size:21px;font-weight:700;color:#ffffff;line-height:1.1;">SmartCare</div>
                  <div style="font-size:21px;font-weight:700;color:#C8E6A0;line-height:1.1;">Guardian</div>
                </td>
              </tr>
            </table>
            <div style="font-size:11px;color:rgba(255,255,255,0.4);letter-spacing:0.12em;
                        text-transform:uppercase;">Resident Care Portal</div>
          </td>
        </tr>

        <!-- ── Body ─────────────────────────────────────────── -->
        <tr>
          <td style="background:#ffffff;padding:40px 40px 32px;">
            $bodyContent
          </td>
        </tr>

        <!-- ── Footer ───────────────────────────────────────── -->
        <tr>
          <td style="background:#F2F6EF;border-top:1px solid #C4D9B4;
                     padding:22px 40px;text-align:center;">
            <p style="margin:0 0 4px;font-size:13px;color:#7A7268;font-weight:600;">
              SmartCare Guardian &nbsp;·&nbsp; Resident Care Portal
            </p>
            <p style="margin:0 0 6px;font-size:12px;color:#B8B0A4;">
              This is an automated message. Please do not reply directly to this email.
            </p>
            <p style="margin:0;font-size:12px;color:#B8B0A4;">
              &copy; $year SmartCare Guardian. All rights reserved.
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $message_id = mysqli_real_escape_string($conn, $_POST['message_id']);
    $reply      = mysqli_real_escape_string($conn, $_POST['reply']);
    $replied_by = $_SESSION['name'];

    $sql = "UPDATE contact_messages SET
                reply_message = '$reply',
                replied_by    = '$replied_by',
                replied_at    = NOW()
            WHERE id = $message_id";

    if (mysqli_query($conn, $sql)) {
        $email_result = mysqli_query($conn, "SELECT email FROM contact_messages WHERE id = $message_id");
        $user_email   = mysqli_fetch_assoc($email_result)['email'];

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'smartcareguardian@gmail.com';
            $mail->Password   = 'yvryblsvyfsspjjn';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->SMTPOptions = ['ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]];

            $mail->setFrom('smartcareguardian@gmail.com', 'SmartCare Guardian');
            $mail->addAddress($user_email);
            $mail->isHTML(true);
            $mail->Subject = 'Reply from SmartCare Guardian';

            // ── Reply email body ──────────────────────────────
            $replyHtml = htmlspecialchars($reply);
            $bodyContent = <<<BODY
              <!-- Icon -->
              <div style="text-align:center;margin-bottom:26px;">
                <div style="display:inline-block;background:#F2F6EF;border-radius:50%;
                            width:62px;height:62px;line-height:62px;font-size:26px;
                            border:2px solid #C4D9B4;">💬</div>
              </div>

              <!-- Heading -->
              <h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#243816;
                         text-align:center;">We've Replied to Your Message</h1>
              <p style="margin:0 0 26px;font-size:15px;color:#7A7268;text-align:center;">
                Our support team has responded to your enquiry.
              </p>

              <!-- Divider -->
              <div style="height:1px;background:linear-gradient(90deg,transparent,#C4D9B4,transparent);
                          margin-bottom:26px;"></div>

              <p style="margin:0 0 14px;font-size:15px;color:#4A4540;line-height:1.7;">
                Dear Customer,
              </p>
              <p style="margin:0 0 20px;font-size:15px;color:#7A7268;line-height:1.7;">
                Thank you for reaching out to us. Here is our response to your message:
              </p>

              <!-- Reply box -->
              <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                <tr>
                  <td style="background:#F2F6EF;border-left:4px solid #7AA658;
                             border-radius:0 12px 12px 0;padding:20px 24px;">
                    <p style="margin:0 0 6px;font-size:11px;font-weight:700;color:#5E8A40;
                               text-transform:uppercase;letter-spacing:0.08em;">Our Response</p>
                    <p style="margin:0;font-size:15px;color:#4A4540;line-height:1.75;
                               white-space:pre-line;">$replyHtml</p>
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 14px;font-size:15px;color:#7A7268;line-height:1.7;">
                If you have any further questions, please contact us again.
              </p>
              <p style="margin:0;font-size:15px;color:#4A4540;line-height:1.7;">
                Warm regards,<br>
                <strong style="color:#243816;">SmartCare Guardian Support Team</strong>
              </p>
BODY;

            $mail->Body    = emailWrapper($bodyContent);
            $mail->AltBody = "Dear Customer,\n\nHere is our reply:\n\n$reply\n\nBest regards,\nSmartCare Guardian";

            $mail->send();
            echo "<script>alert('Reply sent successfully!'); window.history.back();</script>";
        } catch (Exception $e) {
            echo "<script>alert('Error sending email: {$mail->ErrorInfo}'); window.history.back();</script>";
        }
    } else {
        echo "<script>alert('Error: Unable to send reply.'); window.history.back();</script>";
    }
}
mysqli_close($conn);
?>