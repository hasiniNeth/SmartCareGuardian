<?php
// send_reminders.php — run via Windows Task Scheduler every 5 minutes
// Absolute paths used so Task Scheduler never breaks __DIR__ resolution

require 'C:/wamp64/www/SmartCareGuardian/vendor/autoload.php';
include 'C:/wamp64/www/SmartCareGuardian/db_connection.php';

use PHPMailer\PHPMailer\PHPMailer;
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
      <table width="600" cellpadding="0" cellspacing="0" border="0"
             style="max-width:600px;width:100%;border-radius:20px;overflow:hidden;
                    box-shadow:0 8px 40px rgba(36,56,22,0.15);">
        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#243816 0%,#365220 50%,#5E8A40 100%);
                     padding:34px 40px 28px;text-align:center;">
            <table cellpadding="0" cellspacing="0" style="margin:0 auto 10px;">
              <tr>
                <td style="background:linear-gradient(135deg,#9DC07E,#5E8A40);
                           border-radius:12px;width:46px;height:46px;
                           text-align:center;vertical-align:middle;
                           font-size:22px;line-height:46px;">🌿</td>
                <td style="padding-left:12px;text-align:left;vertical-align:middle;">
                  <div style="font-size:21px;font-weight:700;color:#ffffff;line-height:1.1;">SmartCare</div>
                  <div style="font-size:21px;font-weight:700;color:#C8E6A0;line-height:1.1;">Guardian</div>
                </td>
              </tr>
            </table>
            <div style="font-size:11px;color:rgba(255,255,255,0.4);letter-spacing:0.12em;text-transform:uppercase;">Resident Care Portal</div>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="background:#ffffff;padding:40px 40px 32px;">
            $bodyContent
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#F2F6EF;border-top:1px solid #C4D9B4;padding:22px 40px;text-align:center;">
            <p style="margin:0 0 4px;font-size:13px;color:#7A7268;font-weight:600;">SmartCare Guardian &nbsp;·&nbsp; Resident Care Portal</p>
            <p style="margin:0 0 6px;font-size:12px;color:#B8B0A4;">This is an automated message. Please do not reply directly to this email.</p>
            <p style="margin:0;font-size:12px;color:#B8B0A4;">&copy; $year SmartCare Guardian. All rights reserved.</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

$now = date('Y-m-d H:i:s');

$stmt = $conn->prepare("
    SELECT r.*,
           cg.email AS caregiver_email, cg.full_name AS caregiver_name,
           u.email  AS resident_email,  u.full_name  AS resident_name
    FROM routines r
    JOIN users cg ON r.caregiver_id = cg.user_id
    JOIN users u  ON r.resident_id  = u.user_id
    WHERE r.send_reminder  = 1
      AND r.next_reminder IS NOT NULL
      AND r.next_reminder <= ?
");
$stmt->bind_param("s", $now);
$stmt->execute();
$res = $stmt->get_result();

while ($r = $res->fetch_assoc()) {

    $routineType    = ucfirst(str_replace('_', ' ', $r['routine_type']));
    $scheduleTime   = date('h:i A', strtotime($r['schedule_time']));
    $subject        = "Reminder: {$routineType} for {$r['resident_name']} at {$scheduleTime}";

    $routineTypeSafe  = htmlspecialchars($routineType);
    $residentNameSafe = htmlspecialchars($r['resident_name']);
    $cgNameSafe       = htmlspecialchars($r['caregiver_name']);
    $descSafe         = htmlspecialchars($r['description']);

    $bodyContent = <<<BODY
              <!-- Icon -->
              <div style="text-align:center;margin-bottom:26px;">
                <div style="display:inline-block;background:#F2F6EF;border-radius:50%;
                            width:62px;height:62px;line-height:62px;font-size:26px;
                            border:2px solid #C4D9B4;">🔔</div>
              </div>

              <!-- Heading -->
              <h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#243816;text-align:center;">
                Care Routine Reminder
              </h1>
              <p style="margin:0 0 26px;font-size:15px;color:#7A7268;text-align:center;">
                This is an automated reminder for an upcoming care task.
              </p>

              <!-- Divider -->
              <div style="height:1px;background:linear-gradient(90deg,transparent,#C4D9B4,transparent);margin-bottom:26px;"></div>

              <p style="margin:0 0 20px;font-size:15px;color:#4A4540;line-height:1.7;">
                Hello <strong style="color:#243816;">$cgNameSafe</strong>,<br>
                This is a reminder for the following care routine scheduled soon:
              </p>

              <!-- Details table -->
              <table width="100%" cellpadding="0" cellspacing="0"
                     style="border-radius:12px;overflow:hidden;margin-bottom:24px;border:1px solid #C4D9B4;">
                <tr>
                  <td style="background:#F2F6EF;padding:11px 16px;font-weight:700;font-size:13px;
                             color:#365220;width:35%;border-bottom:1px solid #C4D9B4;">🏷 Type</td>
                  <td style="background:#ffffff;padding:11px 16px;font-size:14px;color:#4A4540;
                             border-bottom:1px solid #C4D9B4;">$routineTypeSafe</td>
                </tr>
                <tr>
                  <td style="background:#F2F6EF;padding:11px 16px;font-weight:700;font-size:13px;
                             color:#365220;border-bottom:1px solid #C4D9B4;">👤 Resident</td>
                  <td style="background:#ffffff;padding:11px 16px;font-size:14px;color:#4A4540;
                             border-bottom:1px solid #C4D9B4;">$residentNameSafe</td>
                </tr>
                <tr>
                  <td style="background:#F2F6EF;padding:11px 16px;font-weight:700;font-size:13px;
                             color:#365220;border-bottom:1px solid #C4D9B4;">⏰ Scheduled</td>
                  <td style="background:#ffffff;padding:11px 16px;font-size:14px;color:#4A4540;
                             border-bottom:1px solid #C4D9B4;">$scheduleTime</td>
                </tr>
                <tr>
                  <td style="background:#F2F6EF;padding:11px 16px;font-weight:700;font-size:13px;
                             color:#365220;">📝 Notes</td>
                  <td style="background:#ffffff;padding:11px 16px;font-size:14px;color:#4A4540;">$descSafe</td>
                </tr>
              </table>

              <!-- Action note -->
              <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                <tr>
                  <td style="background:#DDEFD8;border-radius:10px;padding:14px 18px;
                             border-left:4px solid #7AA658;">
                    <p style="margin:0;font-size:14px;color:#3A6830;font-weight:600;">
                      ✅ &nbsp;Please mark the routine as completed in the portal once finished.
                    </p>
                  </td>
                </tr>
              </table>

              <p style="margin:0;font-size:15px;color:#4A4540;line-height:1.7;">
                Warm regards,<br>
                <strong style="color:#243816;">SmartCare Guardian Team</strong>
              </p>
BODY;

    try {
        $mail = new PHPMailer(true);
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
            'allow_self_signed' => true
        ]];

        $mail->setFrom('smartcareguardian@gmail.com', 'SmartCare Guardian');
        if (!empty($r['caregiver_email'])) $mail->addAddress($r['caregiver_email'], $r['caregiver_name']);
        if (!empty($r['resident_email']))  $mail->addAddress($r['resident_email'],  $r['resident_name']);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = emailWrapper($bodyContent);
        $mail->AltBody = "Hello {$r['caregiver_name']},\n\nReminder for: {$routineType} for {$r['resident_name']} at {$scheduleTime}.\n\nNotes: {$r['description']}\n\nPlease mark it completed after finishing.\n\nSmartCare Guardian";
        $mail->send();

        error_log(date('Y-m-d H:i:s') . " — Reminder sent: routine id={$r['id']} to {$r['caregiver_email']} and {$r['resident_email']}\n", 3, 'C:/wamp64/www/SmartCareGuardian/caregiver/reminder_log.txt');

        // ── Compute next_reminder ─────────────────────────────
        $next = null;

        if ((int)$r['repeat_hours'] > 0) {
            $next = (new DateTime($r['next_reminder']))
                ->modify('+' . (int)$r['repeat_hours'] . ' hours')
                ->modify('+10 minutes')
                ->format('Y-m-d H:i:s');

        } elseif ($r['days_of_week']) {
            $hourMin = explode(':', $r['schedule_time']);
            $hour    = (int)$hourMin[0];
            $min     = (int)($hourMin[1] ?? 0);
            $days    = array_map('trim', explode(',', $r['days_of_week']));
            $candidate = null;
            for ($i = 1; $i <= 14; $i++) {
                $c = (new DateTime($r['next_reminder']))->modify("+{$i} day");
                if (in_array($c->format('l'), $days)) {
                    $c->setTime($hour, $min, 0);
                    $candidate = $c;
                    break;
                }
            }
            if ($candidate) {
                $candidate->modify('-10 minutes');
                $next = $candidate->format('Y-m-d H:i:s');
            }

        } else {
            $next = null; // one-time routine — clear reminder
        }

        $upd = $conn->prepare("UPDATE routines SET next_reminder=?, updated_at=NOW() WHERE id=?");
        $upd->bind_param("si", $next, $r['id']);
        $upd->execute();
        $upd->close();

    } catch (Exception $e) {
        error_log(date('Y-m-d H:i:s') . " — FAILED routine id={$r['id']}: " . $e->getMessage() . "\n", 3, 'C:/wamp64/www/SmartCareGuardian/caregiver/reminder_log.txt');
    }
}

$stmt->close();
$conn->close();