<?php
// send_reminders.php - run from CLI (cron) every 5 minutes
// Example crontab entry (on Linux):
// */5 * * * * /usr/bin/php /path/to/your/project/send_reminders.php >> /path/to/logs/reminders.log 2>&1

require __DIR__ . '/../vendor/autoload.php';
include __DIR__ . '/../db_connection.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$now = date('Y-m-d H:i:s');

$stmt = $conn->prepare("
    SELECT r.*, 
           cg.email AS caregiver_email, cg.full_name AS caregiver_name,
           u.email AS resident_email, u.full_name AS resident_name
    FROM routines r
    JOIN users cg ON r.caregiver_id = cg.user_id
    JOIN users u  ON r.resident_id = u.user_id
    WHERE r.send_reminder = 1
      AND r.next_reminder IS NOT NULL
      AND r.next_reminder <= ?
");
$stmt->bind_param("s", $now);
$stmt->execute();
$res = $stmt->get_result();

while ($r = $res->fetch_assoc()) {
    // Build email contents
    $subject = "Reminder: " . ucfirst(str_replace('_',' ', $r['routine_type'])) . " for " . $r['resident_name'] . " at " . date('h:i A', strtotime($r['schedule_time']));
    $body = "Hello {$r['caregiver_name']},\n\nThis is a reminder for the routine:\n\n" .
            "Type: " . ucfirst(str_replace('_',' ', $r['routine_type'])) . "\n" .
            "Resident: " . $r['resident_name'] . "\n" .
            "Time: " . date('h:i A', strtotime($r['schedule_time'])) . "\n" .
            "Notes: " . $r['description'] . "\n\n" .
            "Please mark it completed after finishing the task.\n\nSmartCare Guardian";

    // Send email using PHPMailer
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';             // change as needed
        $mail->SMTPAuth = true;
        $mail->Username = 'smartcareguardian@gmail.com'; // replace
        $mail->Password = 'yvryblsvyfsspjjn';           // replace
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom('smartcareguardian@gmail.com', 'SmartCare Guardian');
        // send to caregiver and resident
        if (!empty($r['caregiver_email'])) $mail->addAddress($r['caregiver_email'], $r['caregiver_name']);
        if (!empty($r['resident_email']))  $mail->addAddress($r['resident_email'], $r['resident_name']);

        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();

        // LOG: success
        error_log("Reminder sent for routine id={$r['id']} to {$r['caregiver_email']} and {$r['resident_email']}");

        // AFTER sending: compute next_reminder
        $next = null;
        if ((int)$r['repeat_hours'] > 0) {
            // add repeat_hours to the current next_reminder (which was the datetime we just used) then subtract 10 minutes
            $current = new DateTime($r['next_reminder']);
            $next = $current->modify('+' . (int)$r['repeat_hours'] . ' hours')->modify('+10 minutes')->format('Y-m-d H:i:s');
            // Note: we stored next_reminder as 10 min BEFORE event; here to compute next we:
            // - take current next_reminder (which was 10 min prior), add repeat_hours, then add 10 minutes to set new '10 min prior' for next event.
            // Simpler alternative: compute based on current time + repeat_hours - 10 minutes:
            // $next = (new DateTime())->modify('+' . (int)$r['repeat_hours'] . ' hours')->modify('-10 minutes')->format('Y-m-d H:i:s');
        } elseif ($r['days_of_week']) {
            // compute next matching weekday (find next weekday > current occurrence)
            $hourMin = explode(':', $r['schedule_time']);
            $hour = intval($hourMin[0]);
            $min = intval($hourMin[1] ?? 0);
            $days = array_map('trim', explode(',', $r['days_of_week']));
            $candidate = null;
            // start search from tomorrow
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
            } else {
                $next = null;
            }
        } else {
            // one-time -> clear next_reminder
            $next = null;
        }

        $upd = $conn->prepare("UPDATE routines SET next_reminder=?, updated_at=NOW() WHERE id=?");
        $upd->bind_param("si", $next, $r['id']);
        $upd->execute();
        $upd->close();
    } catch (Exception $e) {
        error_log("Reminder send failed for routine id={$r['id']}: " . $e->getMessage());
        // do not update next_reminder on failure — it will try again next cron run
    }
}

$stmt->close();
$conn->close();
