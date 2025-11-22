<?php
$conn = mysqli_connect("localhost", "root", "", "smartcare_guardian");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullname = mysqli_real_escape_string($conn, $_POST['fullname']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $message = mysqli_real_escape_string($conn, $_POST['message']);

    $sql = "INSERT INTO contact_messages (fullname, email, phone, message)
            VALUES ('$fullname', '$email', '$phone', '$message')";

    if (mysqli_query($conn, $sql)) {
        echo "<script>
                alert('Message sent successfully! We will contact you soon.');
                window.location='contact.php';
              </script>";
    } else {
        echo "<script>
                alert('Error sending message.');
                window.location='contact.php';
              </script>";
    }
}

mysqli_close($conn);
?>
