<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';

// ══════════════════════════════════════════════════════════════
//  SMARTCARE GUARDIAN — SHARED EMAIL WRAPPER
//  (same function used in reply_message.php)
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

function sendOTP(string $email, string $otp): void {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'smartcareguardian@gmail.com';
        $mail->Password   = 'yvryblsvyfsspjjn';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('smartcareguardian@gmail.com', 'SmartCare Guardian');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'SmartCare Guardian – OTP Verification';

        // ── OTP email body ────────────────────────────────────
        $otpSafe = htmlspecialchars($otp);
        $bodyContent = <<<BODY
              <!-- Icon -->
              <div style="text-align:center;margin-bottom:26px;">
                <div style="display:inline-block;background:#F2F6EF;border-radius:50%;
                            width:62px;height:62px;line-height:62px;font-size:26px;
                            border:2px solid #C4D9B4;">🔐</div>
              </div>

              <!-- Heading -->
              <h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#243816;
                         text-align:center;">Verify Your Identity</h1>
              <p style="margin:0 0 26px;font-size:15px;color:#7A7268;text-align:center;">
                Use the one-time code below to complete your verification.
              </p>

              <!-- Divider -->
              <div style="height:1px;background:linear-gradient(90deg,transparent,#C4D9B4,transparent);
                          margin-bottom:26px;"></div>

              <p style="margin:0 0 22px;font-size:15px;color:#7A7268;line-height:1.7;text-align:center;">
                Enter this code to proceed. For your security,<br>do not share it with anyone.
              </p>

              <!-- OTP box -->
              <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                <tr>
                  <td align="center">
                    <div style="display:inline-block;
                                background:linear-gradient(135deg,#243816,#5E8A40);
                                border-radius:16px;padding:26px 52px;
                                box-shadow:0 6px 24px rgba(36,56,22,0.25);">
                      <div style="font-size:10px;font-weight:700;
                                  color:rgba(255,255,255,0.5);letter-spacing:0.14em;
                                  text-transform:uppercase;margin-bottom:10px;">
                        Your Verification Code
                      </div>
                      <div style="font-size:46px;font-weight:800;color:#ffffff;
                                  letter-spacing:0.18em;
                                  font-family:'Courier New',Courier,monospace;
                                  line-height:1;">$otpSafe</div>
                    </div>
                  </td>
                </tr>
              </table>

              <!-- Expiry warning -->
              <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px;">
                <tr>
                  <td style="background:#FAECC8;border-radius:10px;padding:14px 18px;
                             border-left:4px solid #D4A853;">
                    <p style="margin:0;font-size:14px;color:#7A5010;font-weight:600;">
                      ⏱ &nbsp;This code will expire in <strong>10 minutes</strong>.
                    </p>
                  </td>
                </tr>
              </table>

              <!-- Security note -->
              <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:26px;">
                <tr>
                  <td style="background:#F2F6EF;border-radius:10px;padding:14px 18px;">
                    <p style="margin:0;font-size:13px;color:#7A7268;line-height:1.6;">
                      🛡 &nbsp;If you did not request this code, you can safely ignore this email.
                      Your account has not been affected.
                    </p>
                  </td>
                </tr>
              </table>

              <p style="margin:0;font-size:15px;color:#4A4540;line-height:1.7;">
                Warm regards,<br>
                <strong style="color:#243816;">SmartCare Guardian Team</strong>
              </p>
BODY;

        $mail->Body    = emailWrapper($bodyContent);
        $mail->AltBody = "Your SmartCare Guardian verification code is: $otp\n\nIt will expire in 10 minutes.\n\nIf you did not request this, please ignore this email.";

        $mail->send();
    } catch (Exception $e) {
        error_log("Email could not be sent. Error: {$mail->ErrorInfo}");
    }
}
?>