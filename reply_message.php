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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $message_id = mysqli_real_escape_string($conn, $_POST['message_id']);
    $reply = mysqli_real_escape_string($conn, $_POST['reply']);
    $replied_by = $_SESSION['name'];

    // Update the message with the reply
    $sql = "UPDATE contact_messages SET 
            reply_message = '$reply',
            replied_by = '$replied_by',
            replied_at = NOW()
            WHERE id = $message_id";

    if (mysqli_query($conn, $sql)) {
        // Get user email
        $email_result = mysqli_query($conn, "SELECT email FROM contact_messages WHERE id = $message_id");
        $user_email = mysqli_fetch_assoc($email_result)['email'];

        // Send email using PHPMailer
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com'; 
            $mail->SMTPAuth = true;
            $mail->Username = 'smartcareguardian@gmail.com'; 
            $mail->Password = 'yvryblsvyfsspjjn'; 
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
            $mail->Port = 587; 
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            // Recipients
            $mail->setFrom('smartcareguardian@gmail.com', 'SmartCare Guardian');
            $mail->addAddress($user_email);

            // Content
            $mail->isHTML(false); 
            $mail->Subject = 'Reply from SmartCare Guardian';
            $mail->Body = "Dear Customer,\n\nWe received your message and here's our reply:\n\n$reply\n\nBest regards,\nSmartCare Guardian";

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