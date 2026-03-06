<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php");
    exit();
}

include '../db_connection.php';

$caregiver_id = $_SESSION['user_id'];
$chat_user_id = isset($_GET['chat']) ? (int)$_GET['chat'] : null;

$caregiver_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$caregiver_stmt->bind_param("i", $caregiver_id);
$caregiver_stmt->execute();
$caregiver = $caregiver_stmt->get_result()->fetch_assoc();
$caregiver_stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiver_id     = (int)$_POST['receiver_id'];
    $message_content = trim($_POST['message_content']);
    if (!empty($message_content) && $receiver_id > 0) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_content, sent_at, is_read) VALUES (?, ?, ?, NOW(), 0)");
        $stmt->bind_param("iis", $caregiver_id, $receiver_id, $message_content);
        if ($stmt->execute()) {
            $redirect = $chat_user_id ? "caregiver_messages.php?chat={$chat_user_id}&sent=1" : "caregiver_messages.php?sent=1";
            header("Location: $redirect"); exit();
        } else { $error = "Failed to send message. Please try again."; }
        $stmt->close();
    } else { $error = "Please enter a message and select a recipient."; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_message'])) {
    $message_id = (int)$_POST['message_id'];
    $del = $conn->prepare("DELETE FROM messages WHERE message_id = ? AND sender_id = ?");
    $del->bind_param("ii", $message_id, $caregiver_id);
    $del->execute(); $del->close();
    header("Location: caregiver_messages.php?deleted=1"); exit();
}

if ($chat_user_id) {
    $mark = $conn->prepare("UPDATE messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0");
    $mark->bind_param("ii", $caregiver_id, $chat_user_id);
    $mark->execute(); $mark->close();
}

$chatUser = null;
$conversationMessages = null;
if ($chat_user_id) {
    $u = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
    $u->bind_param("i", $chat_user_id); $u->execute();
    $chatUser = $u->get_result()->fetch_assoc(); $u->close();
    $c = $conn->prepare("SELECT m.*, s.full_name AS sender_name FROM messages m JOIN users s ON m.sender_id = s.user_id WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?) ORDER BY m.sent_at ASC");
    $c->bind_param("iiii", $caregiver_id, $chat_user_id, $chat_user_id, $caregiver_id);
    $c->execute(); $conversationMessages = $c->get_result(); $c->close();
}

$recipients_stmt = $conn->prepare("SELECT u.user_id, u.full_name, u.role FROM users u WHERE u.role = 'admin' OR u.user_id IN (SELECT ca.resident_id FROM caregiver_assignments ca WHERE ca.caregiver_id = ?) ORDER BY u.role DESC, u.full_name ASC");
$recipients_stmt->bind_param("i", $caregiver_id);
$recipients_stmt->execute();
$recipients_result = $recipients_stmt->get_result();
$recipients_arr = [];
while ($r = $recipients_result->fetch_assoc()) { $recipients_arr[] = $r; }
$recipients_stmt->close();

$received_stmt = $conn->prepare("SELECT m.*, u.full_name AS sender_name, m.is_read FROM messages m JOIN users u ON m.sender_id = u.user_id WHERE m.receiver_id = ? ORDER BY m.sent_at DESC");
$received_stmt->bind_param("i", $caregiver_id);
$received_stmt->execute();
$received_messages = $received_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$received_stmt->close();
$unread_count = count(array_filter($received_messages, fn($m) => !$m['is_read']));

$sent_stmt = $conn->prepare("SELECT m.*, u.full_name AS recipient_name FROM messages m JOIN users u ON m.receiver_id = u.user_id WHERE m.sender_id = ? ORDER BY m.sent_at DESC");
$sent_stmt->bind_param("i", $caregiver_id);
$sent_stmt->execute();
$sent_messages = $sent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sent_stmt->close();

$med_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM medications m JOIN caregiver_assignments ca ON ca.resident_id = m.resident_id WHERE ca.caregiver_id = ? AND m.taken = 0 AND m.medication_date = CURDATE()");
$med_stmt->bind_param("i", $caregiver_id);
$med_stmt->execute();
$pending_meds = $med_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$med_stmt->close();

$success = '';
$error   = $error ?? '';
if (isset($_GET['sent']))    $success = "Message sent successfully!";
if (isset($_GET['deleted'])) $success = "Message deleted.";

$search = trim($_GET['search'] ?? '');

$unread_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = ? AND is_read = 0");
$unread_stmt->bind_param("i", $caregiver_id);
$unread_stmt->execute();
$unread_messages = $unread_stmt->get_result()->fetch_assoc()['cnt'];
$unread_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* ═══════════════════════════════════════════════════════════
       SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
       Caregiver Messages · Professional scale
    ═══════════════════════════════════════════════════════════ */
    :root{
        --s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;
        --s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;
        --s600:#4A6E30;--s700:#365220;--s800:#243816;
        --w50:#FDFAF5;--w100:#F7F1E5;
        --st300:#B8B0A4;--st500:#7A7268;--st700:#4A4540;
        --green-bg:#DDEFD8;--green-text:#3A6830;
        --amber-bg:#FAECC8;--amber-text:#7A5010;
        --red-bg:#F5DADA;  --red-text:#6A2020;
        --blue-bg:#DBEEFF; --blue-text:#1A4870;
        --radius-sm:8px;--radius-md:12px;--radius-lg:20px;
        --shadow-soft:0 2px 12px rgba(36,56,22,.07),0 1px 3px rgba(36,56,22,.05);
        --shadow-card:0 4px 24px rgba(36,56,22,.09),0 1px 4px rgba(36,56,22,.06);
        --shadow-lift:0 8px 32px rgba(36,56,22,.13),0 2px 8px rgba(36,56,22,.07);
    }
    *,*::before,*::after{box-sizing:border-box;}
    body{
        font-family:'Outfit',sans-serif;font-size:15px;line-height:1.6;
        background:var(--w50);
        background-image:
            radial-gradient(ellipse 70% 50% at 90% 0%,rgba(157,192,126,.09) 0%,transparent 55%),
            radial-gradient(ellipse 50% 40% at 0% 100%,rgba(122,166,88,.06) 0%,transparent 50%);
        color:var(--st700);min-height:100vh;margin:0;padding:0;
    }
    h1,h2,h3,h4,h5,h6{font-family:'Cormorant Garamond',serif;color:var(--s800);margin:0;}

    /* ── Sidebar ── */
    .sidebar{width:240px;height:100vh;position:fixed;background:var(--s800);display:flex;flex-direction:column;z-index:1000;overflow:hidden;}
    .sidebar::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:radial-gradient(ellipse 120% 60% at 50% -10%,rgba(157,192,126,.18) 0%,transparent 60%),radial-gradient(ellipse 80% 80% at 110% 110%,rgba(94,138,64,.15) 0%,transparent 55%);}
    .sidebar-header{padding:22px 18px 16px;border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0;position:relative;}
    .brand-mark{display:flex;align-items:center;gap:9px;margin-bottom:4px;}
    .brand-icon{width:32px;height:32px;background:linear-gradient(135deg,var(--s300),var(--s500));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;color:white;box-shadow:0 3px 10px rgba(0,0,0,.25);flex-shrink:0;}
    .sidebar-header h4{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:600;color:white;line-height:1.15;}
    .sidebar-header small{font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.08em;text-transform:uppercase;font-weight:500;display:block;margin-left:41px;margin-top:1px;}
    .sidebar-nav{flex:1;overflow-y:auto;padding:8px 0;position:relative;}
    .sidebar-nav::-webkit-scrollbar{width:4px;}.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:3px;}
    .sidebar a{display:flex;align-items:center;gap:9px;padding:10px 10px 10px 20px;color:rgba(255,255,255,.6);text-decoration:none;font-size:13.5px;font-weight:500;transition:all .2s;margin:1px 8px;border-radius:var(--radius-sm);position:relative;min-height:42px;}
    .sidebar a:hover{background:rgba(255,255,255,.10);color:white;}
    .sidebar a.active{background:rgba(157,192,126,.2);color:#C8E6A0;}
    .sidebar a.active::before{content:'';position:absolute;left:-8px;top:20%;bottom:20%;width:3px;background:var(--s300);border-radius:0 3px 3px 0;}
    .sidebar i{width:18px;text-align:center;font-size:13px;opacity:.85;flex-shrink:0;}
    .sb-badge{margin-left:auto;background:#8B3A3A;color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;}
    .msg-badge{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:#8B3A3A;border-radius:50%;min-width:18px;height:18px;font-size:.65rem;display:flex;align-items:center;justify-content:center;padding:0 3px;color:white;font-weight:700;}
    .sidebar-footer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 8px;position:relative;}
    .sidebar-footer a{margin:0;color:rgba(255,255,255,.5)!important;font-size:13.5px;}
    .sidebar-footer a:hover{color:rgba(255,255,255,.8)!important;}

    /* ── Layout ── */
    .content{margin-left:240px;padding:24px;min-height:100vh;}

    /* ── Topbar ── */
    .topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
    .topbar h4{font-size:22px;font-weight:500;color:var(--s800);margin-bottom:2px;}
    .topbar p{font-size:13px;color:var(--st300);margin:0;}
    .logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;}
    .logout-btn:hover{opacity:.9;transform:translateY(-1px);}

    /* ── Flash alerts ── */
    .alert{border-radius:var(--radius-md);border:none;padding:13px 18px;margin-bottom:18px;font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px;}
    .alert-success-msg{background:var(--green-bg);color:var(--green-text);}
    .alert-error-msg  {background:var(--red-bg);  color:var(--red-text);}

    /* ── Card panel ── */
    .card-panel{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);overflow:hidden;margin-bottom:22px;}
    .section-header{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:16px 24px;position:relative;overflow:hidden;}
    .section-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
    .section-header h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;position:relative;display:flex;align-items:center;gap:7px;}
    .section-body{padding:22px 24px;}

    /* ── Compose form ── */
    .compose-form{background:var(--s50);border-radius:var(--radius-md);padding:18px;border:1px solid var(--s100);}
    .form-control,.form-select{border:2px solid var(--s100);border-radius:var(--radius-md);padding:10px 13px;font-family:'Outfit',sans-serif;font-size:14px;color:var(--st700);background:var(--w50);transition:all .2s;}
    .form-control:focus,.form-select:focus{border-color:var(--s400);box-shadow:0 0 0 3px rgba(122,166,88,.15);outline:none;}
    .form-control::placeholder{color:var(--st300);}
    .form-label{color:var(--s800);font-weight:700;font-size:13px;margin-bottom:5px;}
    .btn-primary{background:linear-gradient(135deg,var(--s400),var(--s700));border:none;padding:10px 22px;border-radius:var(--radius-md);font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;color:white;transition:all .2s;cursor:pointer;}
    .btn-primary:hover{opacity:.9;transform:translateY(-1px);color:white;}

    /* ── Tabs ── */
    .nav-tabs{border-bottom:2px solid var(--s100);margin-bottom:18px;}
    .nav-tabs .nav-link{border:none;color:var(--st500);font-weight:700;font-size:13px;padding:9px 18px;margin-right:6px;border-radius:var(--radius-sm) var(--radius-sm) 0 0;transition:all .2s;font-family:'Outfit',sans-serif;}
    .nav-tabs .nav-link:hover{background:var(--s50);color:var(--s700);}
    .nav-tabs .nav-link.active{background:linear-gradient(135deg,var(--s500),var(--s800));color:white;border:none;}

    /* ── Message list ── */
    .message-list{max-height:450px;overflow-y:auto;}
    .message-list::-webkit-scrollbar{width:4px;}.message-list::-webkit-scrollbar-thumb{background:var(--s200);border-radius:3px;}
    .message-item{border-bottom:1px solid var(--s50);padding:14px 0;transition:all .2s;position:relative;}
    .message-item:last-child{border-bottom:none;}
    .message-item:hover{background:var(--s50);padding-left:10px;padding-right:10px;margin:0 -10px;border-radius:var(--radius-sm);}
    .message-item.unread .message-preview{font-weight:700;color:var(--s800);}
    .message-item.unread .sender-name{font-weight:800;color:var(--s800);}
    .unread-dot{width:9px;height:9px;background:var(--s400);border-radius:50%;display:inline-block;margin-right:7px;flex-shrink:0;}
    .message-avatar{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;margin-right:12px;flex-shrink:0;}
    .avatar-received{background:linear-gradient(135deg,var(--s300),var(--s600));color:white;}
    .avatar-sent    {background:linear-gradient(135deg,var(--s200),var(--s400));color:white;}
    .message-preview{color:var(--st500);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:380px;}
    .message-time   {font-size:11px;color:var(--st300);white-space:nowrap;}
    .sender-name    {font-size:14px;color:var(--s800);}
    .message-actions{opacity:0;transition:opacity .2s;}
    .message-item:hover .message-actions{opacity:1;}
    .btn-delete{background:var(--red-bg);border:none;border-radius:var(--radius-sm);color:var(--red-text);padding:4px 10px;font-size:12px;font-weight:700;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;}
    .btn-delete:hover{background:var(--red-text);color:white;}
    .btn-outline-success{border:2px solid var(--s400);color:var(--s600);border-radius:var(--radius-sm);padding:4px 10px;font-size:12px;font-weight:700;background:transparent;font-family:'Outfit',sans-serif;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all .2s;}
    .btn-outline-success:hover{background:var(--green-bg);color:var(--green-text);}
    .btn-outline-primary{border:2px solid var(--blue-text);color:var(--blue-text);border-radius:var(--radius-sm);padding:4px 10px;font-size:12px;font-weight:700;background:transparent;font-family:'Outfit',sans-serif;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all .2s;}
    .btn-outline-primary:hover{background:var(--blue-bg);color:var(--blue-text);}

    /* ── Conversation view ── */
    .conversation-container{height:400px;overflow-y:auto;padding:18px;background:var(--s50);border-radius:var(--radius-md);margin-bottom:18px;display:flex;flex-direction:column;gap:14px;border:1px solid var(--s100);}
    .conversation-container::-webkit-scrollbar{width:4px;}.conversation-container::-webkit-scrollbar-thumb{background:var(--s200);border-radius:3px;}
    .chat-bubble-wrap{display:flex;align-items:flex-end;gap:8px;max-width:72%;}
    .chat-bubble-wrap.mine  {align-self:flex-end;flex-direction:row-reverse;}
    .chat-bubble-wrap.theirs{align-self:flex-start;}
    .chat-avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;flex-shrink:0;}
    .mine   .chat-avatar{background:linear-gradient(135deg,var(--s400),var(--s700));color:white;}
    .theirs .chat-avatar{background:linear-gradient(135deg,var(--s300),var(--s600));color:white;}
    .bubble{padding:11px 15px;border-radius:16px;font-size:13px;line-height:1.5;word-break:break-word;}
    .mine   .bubble{background:linear-gradient(135deg,var(--s500),var(--s700));color:white;border-bottom-right-radius:4px;}
    .theirs .bubble{background:white;color:var(--st700);border:1px solid var(--s100);border-bottom-left-radius:4px;}
    .bubble-time{font-size:11px;opacity:.7;margin-top:3px;text-align:right;}
    .mine   .bubble-time{color:rgba(255,255,255,.8);}
    .theirs .bubble-time{color:var(--st300);}
    .close-conv-btn{background:rgba(255,255,255,.15);border:none;border-radius:var(--radius-sm);color:white;padding:5px 12px;font-size:12px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:5px;}
    .close-conv-btn:hover{background:rgba(255,255,255,.25);color:white;}

    /* ── Badges ── */
    .badge-pill{padding:3px 9px;border-radius:50px;font-weight:700;font-size:11px;}
    .badge-unread{background:var(--s500);color:white;}
    .badge-count {background:rgba(255,255,255,.2);color:white;}
    .badge-delivered{background:var(--green-bg);color:var(--green-text);font-size:11px;padding:3px 9px;border-radius:20px;font-weight:700;}

    /* ── Search bar ── */
    .search-bar{position:relative;margin-bottom:14px;}
    .search-bar input{padding-left:36px;border-radius:var(--radius-md)!important;}
    .search-bar .fa-search{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--st300);font-size:13px;}

    /* ── Empty state ── */
    .empty-state{text-align:center;padding:50px 20px;color:var(--st300);}
    .empty-state i{font-size:3rem;margin-bottom:14px;opacity:.25;display:block;}
    .empty-state h5{color:var(--st500);font-family:'Outfit',sans-serif;font-weight:700;}

    @media(max-width:768px){
        .sidebar{width:100%;height:auto;position:relative;}
        .content{margin-left:0;padding:14px;}
        .message-preview{max-width:180px;}
    }
    </style>
</head>
<body>

<!-- ══ SIDEBAR ════════════════════════════════════════════════════ -->
<div class="sidebar">
    <div class="sidebar-header">
        <div class="brand-mark">
            <div class="brand-icon"><i class="fas fa-leaf"></i></div>
            <h4>SmartCare<br>Guardian</h4>
        </div>
        <small>Caregiver Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="caregiver_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="caregiver_profile.php"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="caregiver_residents.php"><i class="fa-solid fa-user-group"></i> My Residents</a>
        <a href="log_health.php"><i class="fa-solid fa-heart-pulse"></i> Log Health Data</a>
        <a href="manage_routines.php"><i class="fa-solid fa-calendar-check"></i> Manage Routines</a>
        <a href="manage_medications.php">
            <i class="fa-solid fa-pills"></i> Medications
            <?php if ($pending_meds > 0): ?><span class="sb-badge"><?= $pending_meds ?></span><?php endif; ?>
        </a>
        <a href="caregiver_appointments.php"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="caregiver_messages.php" class="active">
            <i class="fa-solid fa-comments"></i> Messages
            <?php if ($unread_messages > 0): ?><span class="msg-badge"><?= $unread_messages ?></span><?php endif; ?>
        </a>
        <a href="caregiver_ai_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="ai_suggestions.php"><i class="fa-solid fa-wand-magic-sparkles"></i> Routine Suggestions</a>
        <a href="caregiver_reports.php"><i class="fa-solid fa-chart-line"></i> Health Reports</a>
    </div>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <!-- Topbar -->
    <div class="topbar">
        <div>
            <h4>
                <i class="fas fa-comments me-2" style="font-size:18px;color:var(--s500);"></i>Messages
                <?php if ($unread_count > 0): ?>
                    <span class="badge-pill badge-unread ms-2" style="font-size:12px;padding:3px 10px;"><?= $unread_count ?> unread</span>
                <?php endif; ?>
            </h4>
            <p>Communicate with your assigned residents &amp; admin</p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt"></i>Logout</button>
        </form>
    </div>

    <!-- Flash alerts -->
    <?php if ($success): ?>
        <div class="alert alert-success-msg"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?><button type="button" style="margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;font-size:16px;" onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error-msg"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?><button type="button" style="margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;font-size:16px;" onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>

    <!-- Compose New Message -->
    <div class="card-panel" id="compose-panel">
        <div class="section-header">
            <h5><i class="fas fa-pen"></i>Compose New Message</h5>
        </div>
        <div class="section-body">
            <form method="POST" class="compose-form">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Send To:</label>
                        <select name="receiver_id" id="compose-receiver" class="form-select" required>
                            <option value="">Select Recipient</option>
                            <?php foreach ($recipients_arr as $r): ?>
                                <option value="<?= $r['user_id'] ?>"
                                    <?= (isset($_GET['reply_to']) && $_GET['reply_to'] == $r['user_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['full_name']) ?>
                                    <?= $r['role'] === 'admin' ? ' (Admin)' : ' (Resident)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Message:</label>
                        <textarea id="compose-textarea" name="message_content" class="form-control" rows="2" placeholder="Type your message here..." required></textarea>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="send_message" class="btn-primary w-100">
                            <i class="fas fa-paper-plane me-1"></i>Send
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Conversation View -->
    <?php if ($chat_user_id && $chatUser): ?>
    <div class="card-panel">
        <div class="section-header d-flex justify-content-between align-items-center">
            <h5><i class="fas fa-comments"></i>Conversation with <?= htmlspecialchars($chatUser['full_name']) ?></h5>
            <a href="caregiver_messages.php" class="close-conv-btn"><i class="fas fa-times"></i>Close</a>
        </div>
        <div class="section-body">
            <div class="conversation-container" id="conversation-box">
                <?php while ($m = $conversationMessages->fetch_assoc()): ?>
                    <?php $is_mine = ($m['sender_id'] == $caregiver_id); ?>
                    <div class="chat-bubble-wrap <?= $is_mine ? 'mine' : 'theirs' ?>">
                        <div class="chat-avatar">
                            <?= strtoupper(substr($is_mine ? $caregiver['full_name'] : $m['sender_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="bubble">
                                <?= nl2br(htmlspecialchars($m['message_content'])) ?>
                                <div class="bubble-time"><?= date('M j, g:i A', strtotime($m['sent_at'])) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
            <form method="POST">
                <input type="hidden" name="receiver_id" value="<?= $chat_user_id ?>">
                <div class="d-flex gap-2 align-items-end">
                    <textarea name="message_content" class="form-control flex-grow-1" rows="2" placeholder="Type your reply..." required autofocus></textarea>
                    <button type="submit" name="send_message" class="btn-primary px-4" style="height:46px;"><i class="fas fa-paper-plane"></i></button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Inbox / Sent Tabs -->
    <div class="card-panel">
        <div class="section-header">
            <h5><i class="fas fa-inbox"></i>Your Messages</h5>
        </div>
        <div class="section-body">
            <ul class="nav nav-tabs" id="msgTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#inbox" type="button">
                        <i class="fas fa-inbox me-2"></i>Inbox
                        <?php if ($unread_count > 0): ?>
                            <span class="badge-pill badge-unread ms-1"><?= $unread_count ?></span>
                        <?php elseif (count($received_messages) > 0): ?>
                            <span class="badge-pill ms-1" style="background:var(--s100);color:var(--s700);"><?= count($received_messages) ?></span>
                        <?php endif; ?>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#sent" type="button">
                        <i class="fas fa-paper-plane me-2"></i>Sent
                        <?php if (count($sent_messages) > 0): ?>
                            <span class="badge-pill ms-1" style="background:var(--s100);color:var(--s700);"><?= count($sent_messages) ?></span>
                        <?php endif; ?>
                    </button>
                </li>
            </ul>

            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" id="msg-search" class="form-control" placeholder="Search messages…">
            </div>

            <div class="tab-content">
                <!-- INBOX -->
                <div class="tab-pane fade show active" id="inbox">
                    <div class="message-list" id="inbox-list">
                        <?php if (count($received_messages)): ?>
                            <?php foreach ($received_messages as $msg): ?>
                                <div class="message-item <?= !$msg['is_read'] ? 'unread' : '' ?>"
                                     data-content="<?= htmlspecialchars(strtolower($msg['sender_name'].' '.$msg['message_content'])) ?>">
                                    <div class="d-flex align-items-center">
                                        <div class="message-avatar avatar-received">
                                            <?php if (!$msg['is_read']): ?>
                                                <span class="unread-dot"></span>
                                            <?php else: ?>
                                                <?= strtoupper(substr($msg['sender_name'], 0, 1)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="sender-name"><?= htmlspecialchars($msg['sender_name']) ?></span>
                                                <span class="message-time"><?= date('M j, g:i A', strtotime($msg['sent_at'])) ?></span>
                                            </div>
                                            <div class="message-preview"><?= htmlspecialchars($msg['message_content']) ?></div>
                                            <div class="message-actions mt-2 d-flex gap-2">
                                                <a href="?reply_to=<?= $msg['sender_id'] ?>"
                                                   onclick="scrollToCompose(<?= $msg['sender_id'] ?>); return false;"
                                                   class="btn-outline-success">
                                                    <i class="fas fa-reply"></i>Reply
                                                </a>
                                                <a href="caregiver_messages.php?chat=<?= $msg['sender_id'] ?>"
                                                   class="btn-outline-primary">
                                                    <i class="fas fa-comments"></i>Conversation
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <h5>No Messages</h5>
                                <p class="mb-0" style="font-size:13px;">You haven't received any messages yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- SENT -->
                <div class="tab-pane fade" id="sent">
                    <div class="message-list" id="sent-list">
                        <?php if (count($sent_messages)): ?>
                            <?php foreach ($sent_messages as $msg): ?>
                                <div class="message-item"
                                     data-content="<?= htmlspecialchars(strtolower($msg['recipient_name'].' '.$msg['message_content'])) ?>">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-grow-1 overflow-hidden">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="sender-name">To: <?= htmlspecialchars($msg['recipient_name']) ?></span>
                                                <span class="message-time"><?= date('M j, g:i A', strtotime($msg['sent_at'])) ?></span>
                                            </div>
                                            <div class="message-preview"><?= htmlspecialchars($msg['message_content']) ?></div>
                                            <div class="mt-2 d-flex align-items-center gap-2 flex-wrap">
                                                <span class="badge-delivered"><i class="fas fa-check me-1"></i>Sent</span>
                                                <a href="caregiver_messages.php?chat=<?= $msg['receiver_id'] ?>" class="btn-outline-primary">
                                                    <i class="fas fa-comments"></i>Conversation
                                                </a>
                                                <div class="message-actions ms-auto">
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this message?');">
                                                        <input type="hidden" name="delete_message" value="1">
                                                        <input type="hidden" name="message_id" value="<?= $msg['message_id'] ?>">
                                                        <button type="submit" class="btn-delete"><i class="fas fa-trash"></i></button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="message-avatar avatar-sent ms-3"><i class="fas fa-user"></i></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-paper-plane"></i>
                                <h5>No Sent Messages</h5>
                                <p class="mb-0" style="font-size:13px;">You haven't sent any messages yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('textarea').forEach(ta => {
    ta.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
    });
});

const convBox = document.getElementById('conversation-box');
if (convBox) convBox.scrollTop = convBox.scrollHeight;

function scrollToCompose(senderId) {
    const sel = document.getElementById('compose-receiver');
    if (sel) { sel.value = senderId; sel.dispatchEvent(new Event('change')); }
    document.getElementById('compose-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    setTimeout(() => document.getElementById('compose-textarea')?.focus(), 400);
}

const searchInput = document.getElementById('msg-search');
searchInput.addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    const activeTab = document.querySelector('.tab-pane.active');
    const items = activeTab ? activeTab.querySelectorAll('.message-item') : [];
    items.forEach(item => {
        const text = item.dataset.content || '';
        item.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
});

document.querySelectorAll('[data-bs-toggle="tab"]').forEach(btn => {
    btn.addEventListener('shown.bs.tab', () => {
        searchInput.value = '';
        document.querySelectorAll('.message-item').forEach(i => i.style.display = '');
    });
});
</script>
</body>
</html>