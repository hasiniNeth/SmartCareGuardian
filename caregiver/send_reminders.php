<?php
// send_reminders.php — run via Windows Task Scheduler every 5 minutes
// Absolute paths used so Task Scheduler never breaks __DIR__ resolution

require 'C:/wamp64/www/SmartCareGuardian/vendor/autoload.php';
include 'C:/wamp64/www/SmartCareGuardian/db_connection.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

    $subject = "Reminder: " . ucfirst(str_replace('_', ' ', $r['routine_type']))
             . " for " . $r['resident_name']
             . " at "  . date('h:i A', strtotime($r['schedule_time']));

    $body = "Hello {$r['caregiver_name']},\n\n"
          . "This is a reminder for the routine:\n\n"
          . "Type: "     . ucfirst(str_replace('_', ' ', $r['routine_type'])) . "\n"
          . "Resident: " . $r['resident_name']  . "\n"
          . "Time: "     . date('h:i A', strtotime($r['schedule_time'])) . "\n"
          . "Notes: "    . $r['description']    . "\n\n"
          . "Please mark it completed after finishing the task.\n\nSmartCare Guardian";

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

        $mail->Subject = $subject;
        $mail->Body    = $body;
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
        // next_reminder NOT updated on failure — will retry next run
    }
}

$stmt->close();
$conn->close();