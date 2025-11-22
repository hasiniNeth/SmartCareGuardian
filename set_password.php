<?php
session_start();
include 'db_connection.php';

$message = "";

// Get email from URL
$email = isset($_GET['email']) ? trim($_GET['email']) : "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $newPassword = trim($_POST['password']);

    if (empty($email) || empty($newPassword)) {
        $message = "<div class='alert alert-danger'>All fields are required.</div>";
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        // Update the user password + activate account
        $stmt = $conn->prepare("UPDATE users SET password=?, status='active' WHERE email=?");
        $stmt->bind_param("ss", $hashedPassword, $email);

        if ($stmt->execute()) {
            echo "<script>
                    alert('Password set successfully! You may now login.');
                    window.location='login.php';
                  </script>";
            exit();
        } else {
            $message = "<div class='alert alert-danger'>Failed to update password.</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Set Password</title>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'>
</head>
<body class='p-5'>

<h3>Set Your Password</h3>
<?php echo $message; ?>

<form method="POST" style="max-width:400px;">

    <label>Email</label>
    <input type="email" name="email" class="form-control mb-3" value="<?php echo $email; ?>" readonly required>

    <label>New Password</label>
    <input type="password" name="password" class="form-control mb-3" required>

    <button class="btn btn-primary w-100">Set Password</button>
</form>

</body>
</html>
