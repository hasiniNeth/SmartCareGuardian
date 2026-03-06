<?php
session_start();
include '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$user_stmt = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_data = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

function fetch_profile($conn, $user_id) {
    $s = $conn->prepare("SELECT * FROM residents WHERE user_id = ?");
    $s->bind_param("i", $user_id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: [
        'resident_id' => null, 'phone' => null, 'address' => null,
        'gender' => null, 'dob' => null, 'emergency_contact' => null,
        'medical_conditions' => null, 'blood_type' => null,
        'primary_physician' => null, 'allergies' => null,
        'dietary_restrictions' => null,
    ];
}

$flash_message = '';
$flash_type    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone               = trim($_POST['phone']               ?? '');
    $address             = trim($_POST['address']             ?? '');
    $gender              = $_POST['gender']                   ?? '';
    $dob                 = $_POST['dob']                      ?? '';
    $emergency_contact   = trim($_POST['emergency_contact']   ?? '');
    $medical_conditions  = trim($_POST['medical_conditions']  ?? '');
    $blood_type          = $_POST['blood_type']               ?? '';
    $primary_physician   = trim($_POST['primary_physician']   ?? '');
    $allergies           = trim($_POST['allergies']           ?? '');
    $dietary_restrictions= trim($_POST['dietary_restrictions']?? '');

    if (empty($phone) || empty($emergency_contact) || empty($dob)) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Please fill in all required fields (phone, date of birth, emergency contact).'];
    } else {
        $chk = $conn->prepare("SELECT resident_id FROM residents WHERE user_id = ?");
        $chk->bind_param("i", $user_id);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($exists) {
            $upd = $conn->prepare("
                UPDATE residents
                SET phone=?, address=?, gender=?, dob=?,
                    emergency_contact=?, medical_conditions=?,
                    blood_type=?, primary_physician=?, allergies=?,
                    dietary_restrictions=?, updated_at=NOW()
                WHERE user_id=?
            ");
            $upd->bind_param("ssssssssssi",
                $phone, $address, $gender, $dob, $emergency_contact,
                $medical_conditions, $blood_type, $primary_physician,
                $allergies, $dietary_restrictions, $user_id
            );
            $ok = $upd->execute();
            $upd->close();
            $_SESSION['flash'] = $ok
                ? ['type'=>'success','msg'=>'Profile updated successfully!']
                : ['type'=>'error',  'msg'=>'Error updating profile. Please try again.'];
        } else {
            $ins = $conn->prepare("
                INSERT INTO residents
                (user_id, phone, address, gender, dob, emergency_contact,
                 medical_conditions, blood_type, primary_physician, allergies,
                 dietary_restrictions, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
            ");
            $ins->bind_param("issssssssss",
                $user_id, $phone, $address, $gender, $dob, $emergency_contact,
                $medical_conditions, $blood_type, $primary_physician,
                $allergies, $dietary_restrictions
            );
            $ok = $ins->execute();
            $ins->close();
            $_SESSION['flash'] = $ok
                ? ['type'=>'success','msg'=>'Profile created successfully!']
                : ['type'=>'error',  'msg'=>'Error creating profile. Please try again.'];
        }
    }
    $redirect = 'elder_profile.php';
    if (isset($_SESSION['flash']) && $_SESSION['flash']['type'] === 'error') {
        $redirect .= '?edit=1';
    }
    header("Location: $redirect");
    exit();
}

if (isset($_SESSION['flash'])) {
    $flash_message = $_SESSION['flash']['msg'];
    $flash_type    = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

$profile_data = fetch_profile($conn, $user_id);
$has_profile  = !empty($profile_data['resident_id']);

$caregiver_info = null;
$cg = $conn->prepare("
    SELECT u.full_name, u.email,
           c.phone          AS cg_phone,
           c.experience_years,
           c.skills
    FROM caregiver_assignments ca
    JOIN  users u     ON u.user_id   = ca.caregiver_id
    LEFT JOIN caregivers c ON c.user_id = u.user_id
    WHERE ca.resident_id = ?
    ORDER BY ca.assigned_at DESC
    LIMIT 1
");
$cg->bind_param("i", $user_id);
$cg->execute();
$caregiver_info = $cg->get_result()->fetch_assoc();
$cg->close();

$um = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id=? AND is_read=0");
$um->bind_param("i", $user_id);
$um->execute();
$unread_messages = $um->get_result()->fetch_assoc()['cnt'] ?? 0;
$um->close();

$ua = $conn->prepare("SELECT COUNT(*) AS cnt FROM alerts WHERE resident_id=? AND resolved=0");
$ua->bind_param("i", $user_id);
$ua->execute();
$unresolved_alerts = $ua->get_result()->fetch_assoc()['cnt'] ?? 0;
$ua->close();

$age = null;
if (!empty($profile_data['dob'])) {
    $age = (new DateTime())->diff(new DateTime($profile_data['dob']))->y;
}

$completion_fields = ['phone','emergency_contact','dob','blood_type','address','gender'];
$filled = 0;
foreach ($completion_fields as $f) { if (!empty($profile_data[$f])) $filled++; }
$completion = round(($filled / count($completion_fields)) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════════
           SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
           Elder Profile · Larger base font for readability
        ═══════════════════════════════════════════════════════════════ */
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
            --w200: #EDE0C8;

            --st300: #B8B0A4;
            --st500: #7A7268;
            --st700: #4A4540;

            --radius-sm: 8px;
            --radius-md: 14px;
            --radius-lg: 22px;

            --shadow-soft: 0 2px 12px rgba(36,56,22,.07), 0 1px 3px rgba(36,56,22,.05);
            --shadow-card: 0 4px 24px rgba(36,56,22,.09), 0 1px 4px rgba(36,56,22,.06);
            --shadow-lift: 0 12px 40px rgba(36,56,22,.12), 0 2px 8px rgba(36,56,22,.08);
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--w50);
            background-image:
                radial-gradient(ellipse 70% 50% at 90% 0%, rgba(157,192,126,.08) 0%, transparent 55%),
                radial-gradient(ellipse 50% 40% at 0% 100%, rgba(122,166,88,.05) 0%, transparent 50%);
            color: var(--st700);
            font-size: 17px; /* Larger for elders */
            line-height: 1.7;
            min-height: 100vh;
            margin: 0; padding: 0;
        }

        h1, h2, h3, h4, h5 {
            font-family: 'Cormorant Garamond', serif;
            color: var(--s800);
        }

        /* ── Sidebar ──────────────────────────────────────────── */
        .sidebar {
            width: 240px;
            height: 100vh;
            position: fixed;
            background: var(--s800);
            display: flex;
            flex-direction: column;
            z-index: 1000;
            overflow: hidden;
        }

        .sidebar::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(ellipse 120% 60% at 50% -10%, rgba(157,192,126,.18) 0%, transparent 60%),
                radial-gradient(ellipse 80% 80% at 110% 110%, rgba(94,138,64,.15) 0%, transparent 55%);
            pointer-events: none;
        }

        .sidebar-header {
            padding: 26px 20px 18px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            flex-shrink: 0;
            position: relative;
        }

        .brand-mark {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }

        .brand-icon {
            width: 34px; height: 34px;
            background: linear-gradient(135deg, var(--s300), var(--s500));
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; color: white;
            box-shadow: 0 3px 10px rgba(0,0,0,.25);
            flex-shrink: 0;
        }

        .sidebar-header h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 17px;
            font-weight: 600;
            color: white;
            line-height: 1.1;
            margin: 0;
        }

        .sidebar-header small {
            font-size: 10px;
            color: rgba(255,255,255,.4);
            letter-spacing: .08em;
            text-transform: uppercase;
            font-weight: 500;
            display: block;
            margin-left: 44px;
            margin-top: 2px;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 10px 0;
            position: relative;
        }

        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.15); border-radius: 3px; }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 12px 11px 22px;
            color: rgba(255,255,255,.6);
            text-decoration: none;
            font-size: 15px; /* Larger nav text for elders */
            font-weight: 500;
            transition: all .2s;
            margin: 2px 10px;
            border-radius: var(--radius-sm);
            position: relative;
            min-height: 46px;
        }

        .sidebar a:hover {
            background: rgba(255,255,255,.1);
            color: white;
        }

        .sidebar a.active {
            background: rgba(157,192,126,.2);
            color: #C8E6A0;
        }

        .sidebar a.active::before {
            content: '';
            position: absolute;
            left: -10px; top: 20%; bottom: 20%;
            width: 3px;
            background: var(--s300);
            border-radius: 0 3px 3px 0;
        }

        .sidebar i { width: 20px; text-align: center; font-size: 15px; opacity: .85; }

        .sb-badge {
            margin-left: auto;
            background: #8B3A3A;
            color: white;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 20px;
        }

        .sidebar-footer {
            flex-shrink: 0;
            border-top: 1px solid rgba(255,255,255,.08);
            padding: 14px 10px;
            position: relative;
        }

        .sidebar-footer a { margin: 0; color: rgba(255,255,255,.5) !important; font-size: 15px; }
        .sidebar-footer a:hover { color: rgba(255,255,255,.8) !important; }

        /* ── Layout ──────────────────────────────────────────── */
        .content { margin-left: 240px; padding: 28px; min-height: 100vh; }

        /* ── Topbar ──────────────────────────────────────────── */
        .topbar {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px 28px;
            box-shadow: var(--shadow-card);
            border: 1px solid rgba(196,217,180,.3);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .topbar h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 24px;
            font-weight: 500;
            color: var(--s800);
            margin: 0 0 3px 0;
        }

        .topbar p { font-size: 13px; color: var(--st300); margin: 0; }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-top: 4px;
            flex-wrap: wrap;
          flex-wrap: wrap; gap: 8px; align-items: center; }

        .date-chip {
            background: white;
            border: 1px solid var(--s100);
            border-radius: 20px;
            padding: 7px 14px;
            font-size: 1rem;
            font-weight: 600;
            color: var(--s600);
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: var(--shadow-soft);
        }

        .topbar-btn {
            background: white;
            border: 1px solid var(--s100);
            border-radius: var(--radius-sm);
            padding: 7px 12px;
            font-size: 1rem;
            font-weight: 400;
            color: var(--st500);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: var(--shadow-soft);
            transition: all .2s;
            font-family: 'Outfit', sans-serif;
        }

        .topbar-btn:hover { background: var(--s50); border-color: var(--s200); color: var(--s600); }

        .logout-btn {
            background: linear-gradient(135deg, #C87A7A, #8B3A3A);
            border: none;
            border-radius: var(--radius-sm);
            color: white;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all .25s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .logout-btn:hover { opacity: .9; box-shadow: var(--shadow-card); transform: translateY(-1px); color: white; }

        /* ── Completion bar ───────────────────────────────────── */
        .completion-status {
            background: white;
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-card);
            border: 1px solid rgba(196,217,180,.3);
            border-left: 4px solid var(--s400);
        }

        .completion-status h5 {
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--s800);
            margin: 0;
        }

        .completion-pct {
            font-size: 22px;
            font-weight: 800;
            color: var(--s600);
            font-family: 'Outfit', sans-serif;
        }

        .completion-progress {
            height: 10px;
            background: var(--s100);
            border-radius: 6px;
            overflow: hidden;
            margin: 10px 0;
        }

        .completion-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--s300), var(--s500));
            border-radius: 6px;
            transition: width .6s ease;
        }

        .completion-fields { display: flex; gap: 7px; flex-wrap: wrap; margin-top: 8px; }

        .cf-chip {
            padding: 4px 11px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .cf-done    { background: #DDEFD8; color: #3A6830; }
        .cf-missing { background: #F5DADA; color: #6A2020; }

        /* ── Profile container ────────────────────────────────── */
        .profile-container {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            border: 1px solid rgba(196,217,180,.3);
            overflow: hidden;
        }

        /* ── Profile header ───────────────────────────────────── */
        .profile-header {
            background: linear-gradient(105deg, var(--s700) 0%, var(--s500) 60%, var(--s400) 100%);
            padding: 36px 30px;
            text-align: center;
        }

        .profile-avatar {
            width: 90px; height: 90px;
            background: rgba(255,255,255,.2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white;
            font-size: 2.4rem;
            font-weight: 700;
            margin: 0 auto 16px;
            border: 3px solid rgba(255,255,255,.35);
            font-family: 'Cormorant Garamond', serif;
        }

        .profile-header h4 {
            font-family: 'Cormorant Garamond', serif;
            font-size: 24px;
            font-weight: 600;
            color: white;
            margin: 0 0 4px 0;
        }

        .profile-header p { color: rgba(255,255,255,.75); font-size: 14px; margin: 0 0 3px 0; }

        .blood-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(255,255,255,.18);
            border: 1px solid rgba(255,255,255,.25);
            color: white;
            border-radius: 20px;
            padding: 4px 14px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 8px;
        }

        /* ── Profile body ─────────────────────────────────────── */
        .profile-body { padding: 32px; }

        /* ── Info cards ───────────────────────────────────────── */
        .info-card {
            background: var(--s50);
            border-radius: var(--radius-md);
            padding: 22px;
            margin-bottom: 18px;
            border-left: 4px solid var(--s300);
        }

        .info-card h5 {
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--s800);
            margin: 0 0 16px 0;
        }

        .emergency-card {
            background: #FFF5F5;
            border-radius: var(--radius-md);
            padding: 22px;
            margin-bottom: 18px;
            border: 2px solid rgba(200,122,122,.35);
        }

        .emergency-card h5 {
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: #6A2020;
            margin: 0 0 10px 0;
        }

        .caregiver-card {
            background: linear-gradient(135deg, #EFF7FF, #DBEEFF);
            border-radius: var(--radius-md);
            padding: 22px;
            margin-bottom: 18px;
            border: 1px solid rgba(107,170,212,.3);
        }

        .caregiver-card h5 {
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: #1A4870;
            margin: 0 0 14px 0;
        }

        .info-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--st300);
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--s800);
            line-height: 1.4;
        }

        .info-item { margin-bottom: 16px; }
        .info-item:last-child { margin-bottom: 0; }

        /* ── Flash messages ───────────────────────────────────── */
        .flash-success {
            background: linear-gradient(135deg, #DDEFD8, #C4D9B4);
            color: #243816;
            border: 1px solid var(--s200);
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 22px;
            font-weight: 600;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .flash-error {
            background: #FFF5F5;
            color: #6A2020;
            border: 1px solid rgba(200,122,122,.35);
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 22px;
            font-weight: 600;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ── Form fields ──────────────────────────────────────── */
        .form-label {
            color: var(--s700);
            font-weight: 600;
            margin-bottom: 7px;
            font-size: 15px;
        }

        .required::after { content: " *"; color: #C87A7A; }

        .form-control, .form-select {
            border: 1.5px solid var(--s200);
            border-radius: var(--radius-sm);
            padding: 12px 15px;
            font-size: 16px; /* Larger for elders */
            font-family: 'Outfit', sans-serif;
            color: var(--st700);
            transition: all .2s;
            min-height: 50px;
            background: white;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--s400);
            box-shadow: 0 0 0 3px rgba(122,166,88,.15);
            outline: none;
        }

        .input-icon-wrap { position: relative; }
        .input-icon-wrap > i.field-icon {
            position: absolute;
            left: 14px; top: 50%; transform: translateY(-50%);
            color: var(--s400); z-index: 3; pointer-events: none;
            font-size: 14px;
        }
        .input-icon-wrap > .form-control { padding-left: 42px; }

        .age-hint { font-size: 13px; color: var(--s500); font-weight: 600; margin-top: 5px; }

        /* ── Section divider ──────────────────────────────────── */
        .section-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 26px 0 16px;
        }

        .section-divider h5 {
            margin: 0;
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--s700);
            white-space: nowrap;
        }

        .section-divider hr { flex: 1; border-color: var(--s100); margin: 0; }

        /* ── Buttons ──────────────────────────────────────────── */
        .btn-save {
            background: linear-gradient(135deg, var(--s400), var(--s600));
            border: none;
            border-radius: var(--radius-sm);
            color: white;
            padding: 14px 24px;
            font-size: 16px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            width: 100%;
            cursor: pointer;
            transition: all .25s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-save:hover { opacity: .9; transform: translateY(-2px); box-shadow: var(--shadow-card); color: white; }

        .btn-cancel {
            background: white;
            border: 1.5px solid var(--s300);
            border-radius: var(--radius-sm);
            color: var(--s600);
            padding: 14px 24px;
            font-size: 16px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            width: 100%;
            cursor: pointer;
            transition: all .25s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-cancel:hover { background: var(--s50); border-color: var(--s400); color: var(--s700); }

        .btn-edit {
            background: linear-gradient(135deg, var(--s400), var(--s600));
            border: none;
            border-radius: var(--radius-sm);
            color: white;
            padding: 14px 24px;
            font-size: 16px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            width: 100%;
            cursor: pointer;
            transition: all .25s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-edit:hover { opacity: .9; transform: translateY(-2px); box-shadow: var(--shadow-card); color: white; }

        .btn-back {
            background: white;
            border: 1.5px solid var(--s300);
            border-radius: var(--radius-sm);
            color: var(--s600);
            padding: 14px 24px;
            font-size: 16px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            width: 100%;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all .25s;
        }

        .btn-back:hover { background: var(--s50); border-color: var(--s400); color: var(--s700); }

        .btn-action-sm {
            background: linear-gradient(135deg, var(--s300), var(--s500));
            border: none;
            border-radius: var(--radius-sm);
            color: white;
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all .2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-action-sm:hover { opacity: .9; color: white; }

        .btn-outline-sm {
            background: white;
            border: 1.5px solid var(--s300);
            border-radius: var(--radius-sm);
            color: var(--s600);
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all .2s;
        }

        .btn-outline-sm:hover { background: var(--s50); }

        .btn-emergency-call {
            background: linear-gradient(135deg, #C87A7A, #8B3A3A);
            border: none;
            border-radius: var(--radius-sm);
            color: white;
            padding: 11px 20px;
            font-size: 15px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .btn-emergency-call:hover { opacity: .9; }

        /* ── Entry animation ─────────────────────────────────── */
        .profile-container { animation: fadeUp .4s ease both; }
        .completion-status { animation: fadeUp .4s .1s ease both; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Responsive ──────────────────────────────────────── */
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; }
            .content { margin-left: 0; padding: 16px; }
            .profile-body { padding: 18px; }
        }
    </style>
</head>
<body>

<!-- ══ SIDEBAR ════════════════════════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sidebar-header">
        <div class="brand-mark">
            <div class="brand-icon"><i class="fas fa-leaf"></i></div>
            <h4>SmartCare<br>Guardian</h4>
        </div>
        <small>Resident Portal</small>
    </div>
    <div class="sidebar-nav">
        <a href="resident_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="elder_profile.php" class="active"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="elder_health.php"><i class="fa-solid fa-heart-pulse"></i> My Health Data</a>
        <a href="elder_schedule.php"><i class="fa-solid fa-calendar-check"></i> Daily Schedule</a>
        <a href="elder_medications.php"><i class="fa-solid fa-pills"></i> My Medications</a>
        <a href="elder_appointments.php"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="elder_messages.php">
            <i class="fa-solid fa-comments"></i> Messages
            <?php if ($unread_messages > 0): ?><span class="sb-badge"><?= $unread_messages ?></span><?php endif; ?>
        </a>
        <a href="resident_alerts.php">
            <i class="fa-solid fa-bell"></i> Health Alerts
            <?php if ($unresolved_alerts > 0): ?><span class="sb-badge"><?= $unresolved_alerts ?></span><?php endif; ?>
        </a>
    </div>
    <div class="sidebar-footer">
        <a href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════════════ -->
<div class="content">

    <!-- Topbar -->
    <div class="topbar">
        <div>
            <h4><i class="fas fa-user-pen me-2" style="font-size:20px;color:var(--s500);"></i>My Profile</h4>
            <p>Manage your personal and medical information</p>
        </div>
        <div class="topbar-actions">
            <button class="topbar-btn" onclick="increaseFontSize()"><i class="fas fa-search-plus"></i>Larger</button>
            <button class="topbar-btn" onclick="decreaseFontSize()"><i class="fas fa-search-minus"></i>Smaller</button>
            <button class="topbar-btn" onclick="resetFontSize()"><i class="fas fa-redo"></i>Reset</button>
            <button class="topbar-btn" onclick="toggleHighContrast()"><i class="fas fa-adjust"></i>Contrast</button>
        </div>
        <a href="../logout.php" class="logout-btn">
            <i class="fa-solid fa-sign-out-alt"></i>Logout
        </a>
    </div>

    <!-- Profile Completion -->
    <div class="completion-status">
        <div class="d-flex justify-content-between align-items-center">
            <h5>Profile Completion</h5>
            <span class="completion-pct"><?= $completion ?>%</span>
        </div>
        <div class="completion-progress">
            <div class="completion-bar" style="width:<?= $completion ?>%"></div>
        </div>
        <div class="completion-fields">
            <?php
            $field_labels = [
                'phone'             => 'Phone',
                'emergency_contact' => 'Emergency Contact',
                'dob'               => 'Date of Birth',
                'blood_type'        => 'Blood Type',
                'address'           => 'Address',
                'gender'            => 'Gender',
            ];
            foreach ($field_labels as $f => $label): ?>
                <span class="cf-chip <?= !empty($profile_data[$f]) ? 'cf-done' : 'cf-missing' ?>">
                    <i class="fas fa-<?= !empty($profile_data[$f]) ? 'check' : 'times' ?> me-1"></i><?= $label ?>
                </span>
            <?php endforeach; ?>
        </div>
        <p class="mb-0 mt-2" style="font-size:14px;color:var(--st500);">
            <?php if ($completion == 100): ?>
                <i class="fas fa-check-circle me-1" style="color:#4A7C59;"></i>Your profile is complete — your care team has everything they need.
            <?php else: ?>
                <i class="fas fa-exclamation-circle me-1" style="color:#A06B2A;"></i>Complete your profile to help your care team provide the best care.
            <?php endif; ?>
        </p>
    </div>

    <!-- Profile Card -->
    <div class="profile-container">

        <!-- Header -->
        <div class="profile-header">
            <div class="profile-avatar"><?= strtoupper(substr($user_data['full_name'], 0, 1)) ?></div>
            <h4><?= htmlspecialchars($user_data['full_name']) ?></h4>
            <p><?= htmlspecialchars($user_data['email']) ?></p>
            <?php if ($age): ?>
                <p><i class="fas fa-birthday-cake me-1"></i><?= $age ?> years old</p>
            <?php endif; ?>
            <?php if ($profile_data['blood_type']): ?>
                <span class="blood-badge">
                    <i class="fas fa-tint"></i><?= htmlspecialchars($profile_data['blood_type']) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="profile-body">

            <!-- Flash message -->
            <?php if ($flash_message): ?>
                <div class="flash-<?= $flash_type ?>">
                    <i class="fas fa-<?= $flash_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($flash_message) ?>
                </div>
            <?php endif; ?>

            <!-- ══ VIEW MODE ══════════════════════════════════════════════ -->
            <div id="viewMode" <?= isset($_GET['edit']) ? 'style="display:none"' : '' ?>>

                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="info-card">
                            <h5><i class="fas fa-user me-2"></i>Personal Information</h5>
                            <div class="info-item">
                                <div class="info-label">Phone Number</div>
                                <div class="info-value"><?= $profile_data['phone'] ? htmlspecialchars($profile_data['phone']) : '<span style="color:var(--st300);font-weight:400;">Not provided</span>' ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Address</div>
                                <div class="info-value"><?= $profile_data['address'] ? nl2br(htmlspecialchars($profile_data['address'])) : '<span style="color:var(--st300);font-weight:400;">Not provided</span>' ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Gender</div>
                                <div class="info-value"><?= $profile_data['gender'] ? ucfirst(htmlspecialchars($profile_data['gender'])) : '<span style="color:var(--st300);font-weight:400;">Not specified</span>' ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Date of Birth</div>
                                <div class="info-value">
                                    <?php if ($profile_data['dob']): ?>
                                        <?= date('F d, Y', strtotime($profile_data['dob'])) ?> <span style="color:var(--st300);font-weight:400;">(<?= $age ?> years)</span>
                                    <?php else: ?>
                                        <span style="color:var(--st300);font-weight:400;">Not provided</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="info-card">
                            <h5><i class="fas fa-heartbeat me-2"></i>Medical Information</h5>
                            <div class="info-item">
                                <div class="info-label">Blood Type</div>
                                <div class="info-value"><?= $profile_data['blood_type'] ? htmlspecialchars($profile_data['blood_type']) : '<span style="color:var(--st300);font-weight:400;">Not recorded</span>' ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Primary Physician</div>
                                <div class="info-value"><?= $profile_data['primary_physician'] ? htmlspecialchars($profile_data['primary_physician']) : '<span style="color:var(--st300);font-weight:400;">Not assigned</span>' ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Allergies</div>
                                <div class="info-value"><?= $profile_data['allergies'] ? nl2br(htmlspecialchars($profile_data['allergies'])) : '<span style="color:var(--st300);font-weight:400;">No known allergies</span>' ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Dietary Restrictions</div>
                                <div class="info-value"><?= $profile_data['dietary_restrictions'] ? nl2br(htmlspecialchars($profile_data['dietary_restrictions'])) : '<span style="color:var(--st300);font-weight:400;">No restrictions</span>' ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Emergency Contact -->
                <div class="emergency-card">
                    <h5><i class="fas fa-phone-alt me-2"></i>Emergency Contact</h5>
                    <p style="font-size:18px;font-weight:700;color:#6A2020;margin-bottom:12px;">
                        <?= $profile_data['emergency_contact'] ? htmlspecialchars($profile_data['emergency_contact']) : '<span style="font-weight:400;color:var(--st300);">No emergency contact set</span>' ?>
                    </p>
                    <?php if ($profile_data['emergency_contact']): ?>
                        <button class="btn-emergency-call" onclick="callEmergency()">
                            <i class="fas fa-phone"></i>Call Emergency Contact
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Medical Conditions -->
                <div class="info-card">
                    <h5><i class="fas fa-file-medical me-2"></i>Medical Conditions</h5>
                    <?php if ($profile_data['medical_conditions']): ?>
                        <p style="font-size:16px;margin:0;line-height:1.7;"><?= nl2br(htmlspecialchars($profile_data['medical_conditions'])) ?></p>
                    <?php else: ?>
                        <p style="color:var(--st300);margin:0;">No medical conditions recorded.</p>
                    <?php endif; ?>
                </div>

                <!-- Assigned Caregiver -->
                <?php if ($caregiver_info): ?>
                <div class="caregiver-card">
                    <h5><i class="fas fa-user-nurse me-2"></i>Assigned Caregiver</h5>
                    <div class="row align-items-center g-3">
                        <div class="col-md-8">
                            <p style="font-size:18px;font-weight:700;color:#1A4870;margin-bottom:8px;"><?= htmlspecialchars($caregiver_info['full_name']) ?></p>
                            <p style="font-size:14px;color:var(--st500);margin-bottom:5px;"><i class="fas fa-envelope me-2"></i><?= htmlspecialchars($caregiver_info['email']) ?></p>
                            <?php if ($caregiver_info['cg_phone']): ?>
                                <p style="font-size:14px;color:var(--st500);margin-bottom:5px;"><i class="fas fa-phone me-2"></i><?= htmlspecialchars($caregiver_info['cg_phone']) ?></p>
                            <?php endif; ?>
                            <?php if ($caregiver_info['experience_years']): ?>
                                <p style="font-size:14px;color:var(--st500);margin-bottom:5px;"><i class="fas fa-briefcase me-2"></i><?= htmlspecialchars($caregiver_info['experience_years']) ?> years experience</p>
                            <?php endif; ?>
                            <?php if ($caregiver_info['skills']): ?>
                                <p style="font-size:14px;color:var(--st500);margin-bottom:0;"><i class="fas fa-tools me-2"></i><?= htmlspecialchars($caregiver_info['skills']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex flex-column gap-2">
                                <a href="elder_messages.php" class="btn-action-sm"><i class="fas fa-comment-medical"></i>Message</a>
                                <button class="btn-outline-sm" onclick="callCaregiver()"><i class="fas fa-phone-alt me-1"></i>Call</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="caregiver-card text-center py-3">
                    <i class="fas fa-user-nurse fa-2x mb-2 d-block" style="color:var(--st300);"></i>
                    <p style="color:var(--st300);margin:0;">No caregiver assigned yet. Please contact your administrator.</p>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="row g-3 mt-2">
                    <div class="col-md-6">
                        <button class="btn-edit" onclick="toggleEditMode()">
                            <i class="fas fa-edit"></i>Edit Profile
                        </button>
                    </div>
                    <div class="col-md-6">
                        <a href="resident_dashboard.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i>Back to Dashboard
                        </a>
                    </div>
                </div>
            </div><!-- /viewMode -->

            <!-- ══ EDIT MODE ══════════════════════════════════════════════ -->
            <div id="editMode" <?= !isset($_GET['edit']) ? 'style="display:none"' : '' ?>>
                <form method="POST" id="profileForm" novalidate>

                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="section-divider">
                                <h5><i class="fas fa-user me-2"></i>Personal Information</h5>
                                <hr>
                            </div>

                            <div class="mb-3">
                                <label for="phone" class="form-label required">Phone Number</label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-phone field-icon"></i>
                                    <input type="tel" class="form-control" id="phone" name="phone"
                                           value="<?= htmlspecialchars($profile_data['phone'] ?? '') ?>"
                                           placeholder="e.g. +94 77 123 4567" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="address" class="form-label">
                                    <i class="fas fa-home me-1"></i>Address
                                </label>
                                <textarea class="form-control" id="address" name="address"
                                          rows="3" placeholder="Enter your full address"><?= htmlspecialchars($profile_data['address'] ?? '') ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="gender" class="form-label"><i class="fas fa-venus-mars me-1"></i>Gender</label>
                                <select class="form-select" id="gender" name="gender">
                                    <option value="">Select Gender</option>
                                    <option value="male"   <?= ($profile_data['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>Male</option>
                                    <option value="female" <?= ($profile_data['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="dob" class="form-label required">
                                    <i class="fas fa-birthday-cake me-1"></i>Date of Birth
                                </label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-calendar field-icon"></i>
                                    <input type="date" class="form-control" id="dob" name="dob"
                                           value="<?= htmlspecialchars($profile_data['dob'] ?? '') ?>"
                                           max="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="age-hint" id="ageDisplay">
                                    <?php if ($age): ?><?= $age ?> years old<?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="section-divider">
                                <h5><i class="fas fa-heartbeat me-2"></i>Medical Information</h5>
                                <hr>
                            </div>

                            <div class="mb-3">
                                <label for="emergency_contact" class="form-label required">
                                    <i class="fas fa-phone-alt me-1"></i>Emergency Contact
                                </label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-phone-alt field-icon"></i>
                                    <input type="text" class="form-control" id="emergency_contact" name="emergency_contact"
                                           value="<?= htmlspecialchars($profile_data['emergency_contact'] ?? '') ?>"
                                           placeholder="Name and phone number" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="blood_type" class="form-label"><i class="fas fa-tint me-1"></i>Blood Type</label>
                                <select class="form-select" id="blood_type" name="blood_type">
                                    <option value="">Select Blood Type</option>
                                    <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bt): ?>
                                        <option value="<?= $bt ?>" <?= ($profile_data['blood_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="primary_physician" class="form-label">
                                    <i class="fas fa-user-md me-1"></i>Primary Physician
                                </label>
                                <div class="input-icon-wrap">
                                    <i class="fas fa-user-md field-icon"></i>
                                    <input type="text" class="form-control" id="primary_physician" name="primary_physician"
                                           value="<?= htmlspecialchars($profile_data['primary_physician'] ?? '') ?>"
                                           placeholder="Doctor's name">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6 mb-2">
                            <label for="allergies" class="form-label">
                                <i class="fas fa-allergies me-1"></i>Allergies
                            </label>
                            <textarea class="form-control" id="allergies" name="allergies"
                                      rows="3" placeholder="List any known allergies"><?= htmlspecialchars($profile_data['allergies'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label for="dietary_restrictions" class="form-label">
                                <i class="fas fa-utensils me-1"></i>Dietary Restrictions
                            </label>
                            <textarea class="form-control" id="dietary_restrictions" name="dietary_restrictions"
                                      rows="3" placeholder="List any dietary restrictions"><?= htmlspecialchars($profile_data['dietary_restrictions'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="medical_conditions" class="form-label">
                            <i class="fas fa-file-medical me-1"></i>Medical Conditions
                        </label>
                        <textarea class="form-control" id="medical_conditions" name="medical_conditions"
                                  rows="4" placeholder="Describe any medical conditions or special health needs"><?= htmlspecialchars($profile_data['medical_conditions'] ?? '') ?></textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <button type="submit" class="btn-save" id="submitBtn">
                                <i class="fas fa-save"></i><?= $has_profile ? 'Update Profile' : 'Create Profile' ?>
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="button" class="btn-cancel" onclick="toggleEditMode()">
                                <i class="fas fa-times"></i>Cancel
                            </button>
                        </div>
                    </div>

                </form>
            </div><!-- /editMode -->

        </div><!-- /profile-body -->
    </div><!-- /profile-container -->

</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleEditMode() {
        const vm = document.getElementById('viewMode');
        const em = document.getElementById('editMode');
        if (vm.style.display === 'none') {
            vm.style.display = 'block'; em.style.display = 'none';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            vm.style.display = 'none'; em.style.display = 'block';
            window.scrollTo({ top: em.offsetTop - 120, behavior: 'smooth' });
        }
    }
    (function() {
    var BASE = 12, MIN = 10, MAX = 18;
    var sz = parseInt(localStorage.getItem('elderFontSize')) || BASE;
    if (isNaN(sz) || sz < MIN || sz > MAX) sz = BASE;
    document.documentElement.style.fontSize = sz + 'px';

    function save(v) {
        sz = v;
        document.documentElement.style.fontSize = sz + 'px';
        localStorage.setItem('elderFontSize', String(sz));
    }
    function toast(msg) {
        var old = document.getElementById('_acc_t');
        if (old) old.remove();
        var d = document.createElement('div');
        d.id = '_acc_t';
        d.style.cssText = 'position:fixed;bottom:28px;right:22px;z-index:99999;pointer-events:none;';
        d.innerHTML = '<div style="background:rgba(36,56,22,.96);color:#fff;padding:12px 20px;'
            + 'border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.28);'
            + 'font-weight:700;font-family:Outfit,sans-serif;font-size:16px;">' + msg + '</div>';
        document.body.appendChild(d);
        setTimeout(function() { if (d && d.parentNode) d.remove(); }, 2500);
    }
    window.increaseFontSize = function() {
        if (sz < MAX) { save(sz + 2); toast('Text enlarged (' + sz + 'px)'); }
        else toast('Maximum size reached');
    };
    window.decreaseFontSize = function() {
        if (sz > MIN) { save(sz - 2); toast('Text reduced (' + sz + 'px)'); }
        else toast('Minimum size reached');
    };
    window.resetFontSize = function() {
        save(BASE); toast('Text size reset');
    };
    window.toggleHighContrast = function() {
        document.body.classList.toggle('high-contrast');
        var on = document.body.classList.contains('high-contrast');
        localStorage.setItem('elderHighContrast', on ? 'true' : 'false');
        toast(on ? 'High contrast on' : 'High contrast off');
    };

    document.getElementById('dob').addEventListener('change', function () {
        const dob   = new Date(this.value);
        const today = new Date();
        let age     = today.getFullYear() - dob.getFullYear();
        const md    = today.getMonth() - dob.getMonth();
        if (md < 0 || (md === 0 && today.getDate() < dob.getDate())) age--;
        const el = document.getElementById('ageDisplay');
        el.textContent = age >= 0 && age < 130 ? age + ' years old' : '';
    });

    document.getElementById('profileForm').addEventListener('submit', function (e) {
        const phone = document.getElementById('phone').value.trim();
        const ec    = document.getElementById('emergency_contact').value.trim();
        const dob   = document.getElementById('dob').value;

        if (!/^[0-9+\-\s()]{7,20}$/.test(phone)) {
            e.preventDefault();
            showMsg('Please enter a valid phone number.', 'error');
            document.getElementById('phone').focus();
            return;
        }
        if (ec.length < 5) {
            e.preventDefault();
            showMsg('Please enter a valid emergency contact (name and phone).', 'error');
            document.getElementById('emergency_contact').focus();
            return;
        }
        if (new Date(dob) > new Date()) {
            e.preventDefault();
            showMsg('Date of birth cannot be in the future.', 'error');
            document.getElementById('dob').focus();
            return;
        }
        const btn = document.getElementById('submitBtn');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
        btn.disabled = true;
    });

    function callEmergency() {
        if (confirm('Are you sure you want to call your emergency contact?')) {
            showMsg('Connecting to emergency contact...', 'info');
        }
    }

    function callCaregiver() { showMsg('Calling your assigned caregiver...', 'info'); }

    function showMsg(text, type) {
        const existing = document.getElementById('inline-msg');
        if (existing) existing.remove();
        const div = document.createElement('div');
        div.id = 'inline-msg';
        const colours = {
            success: 'background:#DDEFD8;color:#243816;border:1px solid #C4D9B4;',
            error:   'background:#FFF5F5;color:#6A2020;border:1px solid rgba(200,122,122,.35);',
            info:    'background:#EFF7FF;color:#1A4870;border:1px solid rgba(107,170,212,.3);'
        };
        div.style.cssText = `${colours[type]||colours.info}padding:14px 18px;border-radius:12px;margin-bottom:18px;font-weight:600;font-size:15px;display:flex;align-items:center;gap:9px;`;
        div.innerHTML = `<i class="fas fa-info-circle"></i>${text}`;
        const form = document.getElementById('profileForm');
        form.insertAdjacentElement('beforebegin', div);
        setTimeout(() => div.remove(), 4000);
    }

    document.querySelectorAll('textarea').forEach(t => {
        t.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        if (new URLSearchParams(window.location.search).has('edit')) {
            document.getElementById('viewMode').style.display = 'none';
            document.getElementById('editMode').style.display = 'block';
        }
    });
    document.addEventListener('DOMContentLoaded', function() {
        document.documentElement.style.fontSize = sz + 'px';
        if (localStorage.getItem('elderHighContrast') === 'true')
            document.body.classList.add('high-contrast');
    });
})();
</script>
</body>
</html>