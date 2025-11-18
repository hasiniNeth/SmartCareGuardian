<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; 

function sendOTP($email, $otp) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; 
        $mail->SMTPAuth = true;
        $mail->Username = 'smartcareguardian@gmail.com';
        $mail->Password = 'yvryblsvyfsspjjn'; // use App Password, not your Gmail password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('smartcareguardian@gmail.com', 'SmartCare Guardian');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'SmartCare Guardian - OTP Verification';
        $mail->Body = "Your verification code is <b>$otp</b>. It will expire in 10 minutes.";

        $mail->send();
    } catch (Exception $e) {
        error_log("Email could not be sent. Error: {$mail->ErrorInfo}");
    }
}
?>
