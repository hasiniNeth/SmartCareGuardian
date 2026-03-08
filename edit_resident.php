<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "Invalid resident ID.";
    exit();
}

$resident_id = $_GET['id'];

$stmt = $conn->prepare("SELECT * FROM users WHERE user_id=? AND role='resident'");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$residentResult = $stmt->get_result();

if ($residentResult->num_rows === 0) {
    echo "Resident not found.";
    exit();
}

$resident = $residentResult->fetch_assoc();
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $email     = trim($_POST['email']);
    $status    = trim($_POST['status']);

    $check = $conn->prepare("SELECT * FROM users WHERE email=? AND user_id != ?");
    $check->bind_param("si", $email, $resident_id);
    $check->execute();
    $resultCheck = $check->get_result();

    if ($resultCheck->num_rows > 0) {
        $message = "<div class='alert alert-danger'><i class='fas fa-exclamation-circle me-2'></i>This email is already used by another user.</div>";
    } else {
        $update = $conn->prepare("UPDATE users SET full_name=?, email=?, status=? WHERE user_id=?");
        $update->bind_param("sssi", $full_name, $email, $status, $resident_id);

        if ($update->execute()) {
            $message = "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Resident updated successfully!</div>";
            $resident['full_name'] = $full_name;
            $resident['email']     = $email;
            $resident['status']    = $status;
        } else {
            $message = "<div class='alert alert-danger'><i class='fas fa-exclamation-circle me-2'></i>Update failed. Try again.</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Resident - SmartCare Guardian</title>
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
            --green-bg:  #DDEFD8;
            --green-text:#3A6830;
            --amber-bg:  #FAECC8;
            --amber-text:#7A5010;
            --red-bg:    #F5DADA;
            --red-text:  #6A2020;
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
        .edit-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .edit-container {
            background: #ffffff;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lift);
            overflow: hidden;
            max-width: 520px;
            width: 100%;
            margin: 20px 0;
            border: 1px solid var(--s100);
        }

        /* ── Header ── */
        .edit-header {
            background: linear-gradient(135deg, var(--s800) 0%, var(--s700) 45%, var(--s500) 100%);
            padding: 34px 30px 28px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .edit-header::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(ellipse 100% 70% at 100% 50%, rgba(157,192,126,.15) 0%, transparent 60%);
            pointer-events: none;
        }

        .brand-icon-wrap {
            width: 54px;
            height: 54px;
            background: linear-gradient(135deg, var(--s300), var(--s500));
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            box-shadow: 0 4px 16px rgba(0,0,0,.25);
            margin-bottom: 13px;
            position: relative;
        }

        .edit-header h3 {
            color: white;
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 5px;
            position: relative;
        }

        .edit-header p {
            color: rgba(255,255,255,.6);
            font-size: 13px;
            margin: 0;
            position: relative;
        }

        /* ── Body ── */
        .edit-body { padding: 34px 36px 32px; }

        /* ── Resident ID badge ── */
        .resident-id-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--s50);
            border: 1px solid var(--s200);
            color: var(--s700);
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        /* ── Alerts ── */
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

        .alert-success { background: var(--green-bg); color: var(--green-text); }
        .alert-danger  { background: var(--red-bg);   color: var(--red-text);   }

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

        .input-group-icon .form-control { padding-left: 42px; }

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

        .form-control,
        .form-select {
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

        .form-control:focus,
        .form-select:focus {
            border-color: var(--s400);
            box-shadow: 0 0 0 3px rgba(122,166,88,.15);
            outline: none;
            background: #ffffff;
        }

        .form-control::placeholder { color: var(--st300); }

        /* ── Divider ── */
        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--s100), transparent);
            margin: 26px 0 22px;
        }

        /* ── Buttons ── */
        .btn-save {
            background: linear-gradient(135deg, var(--s400), var(--s700));
            border: none;
            padding: 11px 26px;
            border-radius: var(--radius-sm);
            font-weight: 700;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            color: white;
            cursor: pointer;
            transition: opacity .2s, transform .2s, box-shadow .2s;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .btn-save:hover {
            opacity: .92;
            transform: translateY(-1px);
            box-shadow: var(--shadow-card);
            color: white;
        }

        .btn-back {
            background: transparent;
            border: 2px solid var(--s200);
            border-radius: var(--radius-sm);
            padding: 11px 22px;
            font-weight: 700;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            color: var(--st500);
            text-decoration: none;
            transition: background .2s, border-color .2s, color .2s;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .btn-back:hover {
            background: var(--s50);
            border-color: var(--s300);
            color: var(--s700);
        }

        /* ── Responsive ── */
        @media (max-height: 700px) {
            .edit-wrapper { align-items: flex-start; padding-top: 40px; }
        }

        @media (max-width: 480px) {
            .edit-body   { padding: 26px 20px; }
            .edit-header { padding: 26px 20px 22px; }
            .btn-row     { flex-direction: column; }
            .btn-row .btn-save,
            .btn-row .btn-back { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="edit-wrapper">
        <div class="edit-container">

            <div class="edit-header">
                <div class="brand-icon-wrap">
                    <i class="fas fa-user-pen"></i>
                </div>
                <h3>Edit Resident Profile</h3>
                <p>Update resident information and status</p>
            </div>

            <div class="edit-body">

                <div class="text-center">
                    <span class="resident-id-badge">
                        <i class="fas fa-id-card"></i>Resident ID: #<?php echo $resident_id; ?>
                    </span>
                </div>

                <?php echo $message; ?>

                <form method="POST" id="editForm">

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-user"></i>Full Name
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-user"></i>
                            <input type="text" name="full_name" class="form-control"
                                   value="<?php echo htmlspecialchars($resident['full_name']); ?>" required
                                   placeholder="Enter resident's full name">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-envelope"></i>Email Address
                        </label>
                        <div class="input-group-icon">
                            <i class="fas fa-envelope"></i>
                            <input type="email" name="email" class="form-control"
                                   value="<?php echo htmlspecialchars($resident['email']); ?>" required
                                   placeholder="Enter email address">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="fas fa-circle-dot"></i>Account Status
                        </label>
                        <select name="status" class="form-select" required>
                            <option value="active"           <?php if ($resident['status'] == 'active')           echo 'selected'; ?>>🟢 Active</option>
                            <option value="pending_password" <?php if ($resident['status'] == 'pending_password') echo 'selected'; ?>>🟡 Pending Password</option>
                            <option value="inactive"         <?php if ($resident['status'] == 'inactive')         echo 'selected'; ?>>🔴 Inactive</option>
                        </select>
                    </div>

                    <div class="divider"></div>

                    <div class="d-flex justify-content-between gap-3 btn-row">
                        <a href="manage_residents.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i>Back to Residents
                        </a>
                        <button type="submit" class="btn-save">
                            <i class="fas fa-check"></i><span>Save Changes</span>
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('editForm').addEventListener('submit', function(e) {
            const fullName = document.querySelector('input[name="full_name"]').value;
            const email    = document.querySelector('input[name="email"]').value;

            if (fullName.trim().length < 2) {
                e.preventDefault(); alert('Please enter a valid full name.'); return;
            }
            if (!email.includes('@') || !email.includes('.')) {
                e.preventDefault(); alert('Please enter a valid email address.'); return;
            }

            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><span>Saving Changes…</span>';
            submitBtn.disabled = true;
        });

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('input[name="full_name"]').focus();
        });
    </script>
</body>
</html>