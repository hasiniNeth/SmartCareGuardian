<?php
session_start();
include 'db_connection.php';
$message = "";

$email = isset($_GET['email']) ? trim($_GET['email']) : "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $newPassword = trim($_POST['password']);
    if (empty($email) || empty($newPassword)) {
        $message = "<div class='alert alert-danger'><i class='fas fa-exclamation-circle me-2'></i>All fields are required.</div>";
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password=?, status='active' WHERE email=?");
        $stmt->bind_param("ss", $hashedPassword, $email);
        if ($stmt->execute()) {
            echo "<script>
                    alert('Password set successfully! You may now login.');
                    window.location='login.php';
                  </script>";
            exit();
        } else {
            $message = "<div class='alert alert-danger'><i class='fas fa-exclamation-circle me-2'></i>Failed to update password.</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Password - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --s50:  #F2F6EF;
            --s100: #E3EDDB;
            --s200: #C4D9B4;
            --s300: #9DC07E;
            --s400: #7AA658;
            --s500: #5E8A40;
            --s600: #4A6E30;
            --s700: #365220;
            --s800: #243816;
            --w50:  #FDFAF5;
            --w100: #F7F1E5;
            --st300: #B8B0A4;
            --st500: #7A7268;
            --st700: #4A4540;
            --red-bg:   #F5DADA;
            --red-text: #6A2020;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 20px;
            --shadow-card: 0 4px 24px rgba(36,56,22,.09), 0 1px 4px rgba(36,56,22,.06);
            --shadow-lift: 0 8px 32px rgba(36,56,22,.13), 0 2px 8px rgba(36,56,22,.07);
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            line-height: 1.6;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            background-color: var(--w50);
            background-image:
                radial-gradient(ellipse 80% 60% at 10% 10%, rgba(157,192,126,.12) 0%, transparent 55%),
                radial-gradient(ellipse 60% 50% at 90% 90%, rgba(94,138,64,.08) 0%, transparent 50%);
            color: var(--st700);
            overflow-x: hidden;
        }

        h1, h2, h3, h4, h5 {
            font-family: 'Cormorant Garamond', serif;
            color: var(--s800);
            margin: 0;
        }

        /* ── Layout ── */
        .page-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .set-container {
            background: #ffffff;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lift);
            overflow: hidden;
            max-width: 450px;
            width: 100%;
            margin: 20px 0;
            border: 1px solid var(--s100);
        }

        /* ── Header ── */
        .set-header {
            background: linear-gradient(135deg, var(--s800) 0%, var(--s700) 45%, var(--s500) 100%);
            padding: 38px 30px 32px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .set-header::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(ellipse 100% 70% at 100% 50%, rgba(157,192,126,.15) 0%, transparent 60%);
            pointer-events: none;
        }

        .brand-icon-wrap {
            width: 58px;
            height: 58px;
            background: linear-gradient(135deg, var(--s300), var(--s500));
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: 0 4px 16px rgba(0,0,0,.25);
            margin-bottom: 14px;
            position: relative;
        }

        .set-header h2 {
            color: white;
            font-size: 26px;
            font-weight: 600;
            margin-bottom: 6px;
            position: relative;
        }

        .set-header p {
            color: rgba(255,255,255,.6);
            font-size: 13px;
            margin: 0;
            position: relative;
        }

        /* ── Body ── */
        .set-body {
            padding: 36px 32px 32px;
        }

        /* ── Alert ── */
        .alert {
            border-radius: var(--radius-md);
            border: none;
            padding: 13px 16px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-danger {
            background: var(--red-bg);
            color: var(--red-text);
        }

        /* ── Info note ── */
        .info-note {
            background: var(--s50);
            border: 1px solid var(--s100);
            border-radius: var(--radius-md);
            padding: 13px 16px;
            margin-bottom: 24px;
            font-size: 13px;
            color: var(--st500);
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .info-note i { color: var(--s400); margin-top: 2px; flex-shrink: 0; }

        /* ── Form labels ── */
        .form-label {
            color: var(--s800);
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-label i { color: var(--s400); }

        /* ── Inputs ── */
        .input-group-icon { position: relative; }

        .input-group-icon .form-control {
            padding-left: 42px;
        }

        .input-group-icon > i:first-of-type {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--s400);
            font-size: 13px;
            z-index: 3;
            pointer-events: none;
        }

        .form-control {
            border: 2px solid var(--s100);
            border-radius: var(--radius-md);
            padding: 11px 14px;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            background: var(--w50);
            color: var(--st700);
            transition: border-color .2s, box-shadow .2s;
            width: 100%;
        }

        .form-control:focus {
            border-color: var(--s400);
            box-shadow: 0 0 0 3px rgba(122,166,88,.15);
            outline: none;
            background: #ffffff;
        }

        .form-control::placeholder { color: var(--st300); }

        .form-control[readonly] {
            background: var(--s50);
            color: var(--st500);
            cursor: not-allowed;
        }

        /* ── Password toggle ── */
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--st300);
            cursor: pointer;
            z-index: 3;
            padding: 4px;
            font-size: 13px;
            transition: color .2s;
        }

        .password-toggle:hover { color: var(--s500); }

        /* ── Password hint ── */
        .pw-hint {
            font-size: 12px;
            color: var(--st300);
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* ── Submit button ── */
        .btn-primary {
            background: linear-gradient(135deg, var(--s400), var(--s700));
            border: none;
            padding: 12px 28px;
            border-radius: var(--radius-sm);
            font-weight: 700;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            color: white;
            width: 100%;
            margin-top: 8px;
            cursor: pointer;
            transition: opacity .2s, transform .2s, box-shadow .2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary:hover {
            opacity: .92;
            transform: translateY(-1px);
            box-shadow: var(--shadow-card);
        }

        .btn-primary:active { transform: translateY(0); }

        /* ── Footer link ── */
        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--s100), transparent);
            margin: 22px 0;
        }

        .back-link {
            text-align: center;
        }

        .back-link a {
            color: var(--s500);
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            transition: color .2s;
        }

        .back-link a:hover {
            color: var(--s800);
            text-decoration: underline;
        }

        /* ── Responsive ── */
        @media (max-height: 700px) {
            .page-wrapper { align-items: flex-start; padding-top: 40px; }
        }

        @media (max-width: 480px) {
            .set-body   { padding: 28px 20px; }
            .set-header { padding: 30px 20px 26px; }
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <div class="set-container">

            <div class="set-header">
                <div class="brand-icon-wrap">🌿</div>
                <h2>Set Your Password</h2>
                <p>Create a secure password for your account</p>
            </div>

            <div class="set-body">

                <?php echo $message; ?>

                <div class="info-note">
                    <i class="fas fa-envelope"></i>
                    <span>Your email address has been pre-filled from the invitation link. Just set a password to activate your account.</span>
                </div>

                <form method="POST" id="setPasswordForm">

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-envelope"></i>Email Address
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" name="email" class="form-control"
                                   value="<?php echo htmlspecialchars($email); ?>" readonly required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock"></i>New Password
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="password" id="password" class="form-control"
                                   placeholder="Create a secure password" required>
                            <button type="button" class="password-toggle" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <p class="pw-hint">
                            <i class="fas fa-info-circle"></i>
                            Password must be at least 6 characters long
                        </p>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check"></i><span>Set Password &amp; Activate</span>
                    </button>

                </form>

                <div class="divider"></div>

                <div class="back-link">
                    <a href="login.php">
                        <i class="fas fa-arrow-left me-1"></i>Back to Login
                    </a>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('togglePassword').addEventListener('click', function() {
            const input = document.getElementById('password');
            const icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });

        document.getElementById('setPasswordForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long.');
                return;
            }
            const btn = this.querySelector('button[type="submit"]');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Activating…</span>';
            btn.disabled = true;
        });
    </script>
</body>
</html>