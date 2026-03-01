<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

include 'db_connection.php';

$admin_id = $_SESSION['user_id'];

// ── Handle sending a message to a caregiver ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiver_id     = (int)$_POST['receiver_id'];
    $message_content = trim($_POST['message_content']);

    if ($receiver_id && $message_content !== '') {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_content, sent_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $admin_id, $receiver_id, $message_content);
        if ($stmt->execute()) {
            $_SESSION['success'] = "Message sent successfully!";
            $redir_chat = isset($_POST['chat_user_id']) && (int)$_POST['chat_user_id']
                ? '&chat=' . (int)$_POST['chat_user_id']
                : '&chat=' . $receiver_id;
            header("Location: messages.php?tab=caregiver$redir_chat");
            exit();
        } else {
            $error = "Failed to send message. Please try again.";
        }
        $stmt->close();
    } else {
        $error = "Please select a recipient and type a message.";
    }
}

// ── Handle deleting a sent message ───────────────────────────────
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $stmt   = $conn->prepare("DELETE FROM messages WHERE message_id = ? AND sender_id = ?");
    $stmt->bind_param("ii", $del_id, $admin_id);
    $stmt->execute();
    $stmt->close();
    $_SESSION['success'] = "Message deleted.";
    $back_chat = isset($_GET['chat']) ? '&chat=' . (int)$_GET['chat'] : '';
    header("Location: messages.php?tab=caregiver$back_chat");
    exit();
}

// ── Flash messages ────────────────────────────────────────────────
$success = $_SESSION['success'] ?? null;
$error   = $error ?? null;
unset($_SESSION['success']);

// ── Active tab & chat partner ─────────────────────────────────────
$active_tab   = $_GET['tab']  ?? 'contact';
$chat_user_id = isset($_GET['chat']) ? (int)$_GET['chat'] : null;

// ── Fetch caregivers (role = caregiver) for compose dropdown ──────
$cg_list   = $conn->query("SELECT user_id, full_name FROM users WHERE role = 'caregiver' ORDER BY full_name ASC");
$caregivers = [];
while ($r = $cg_list->fetch_assoc()) $caregivers[] = $r;

// ── Unread count from caregivers ──────────────────────────────────
$unread_res   = $conn->query("
    SELECT COUNT(*) AS cnt FROM messages m
    JOIN users u ON m.sender_id = u.user_id
    WHERE m.receiver_id = $admin_id AND m.is_read = 0 AND u.role = 'caregiver'
");
$unread_count = $unread_res->fetch_assoc()['cnt'] ?? 0;

// ── Conversation list (one row per caregiver, latest first) ───────
$conv_list = $conn->query("
    SELECT u.user_id, u.full_name,
           MAX(m.sent_at) AS last_at,
           SUM(CASE WHEN m.receiver_id = $admin_id AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread
    FROM messages m
    JOIN users u ON (
        CASE WHEN m.sender_id = $admin_id THEN m.receiver_id ELSE m.sender_id END = u.user_id
    )
    WHERE (m.sender_id = $admin_id OR m.receiver_id = $admin_id)
      AND u.role = 'caregiver'
    GROUP BY u.user_id, u.full_name
    ORDER BY last_at DESC
");

// ── Load conversation with selected caregiver ─────────────────────
$conversationMessages = null;
$chatUser = null;
if ($chat_user_id) {
    $u = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
    $u->bind_param("i", $chat_user_id);
    $u->execute();
    $chatUser = $u->get_result()->fetch_assoc();
    $u->close();

    // Mark incoming messages as read
    $mark = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
    $mark->bind_param("ii", $chat_user_id, $admin_id);
    $mark->execute();
    $mark->close();

    $c = $conn->prepare("
        SELECT m.*, s.full_name AS sender_name
        FROM messages m
        JOIN users s ON m.sender_id = s.user_id
        WHERE (m.sender_id = ? AND m.receiver_id = ?)
           OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.sent_at ASC
    ");
    $c->bind_param("iiii", $admin_id, $chat_user_id, $chat_user_id, $admin_id);
    $c->execute();
    $conversationMessages = $c->get_result();
    $c->close();
}

// ── Contact form messages ─────────────────────────────────────────
$pending_contacts = $conn->query("SELECT * FROM contact_messages WHERE reply_message IS NULL ORDER BY created_at DESC");
$replied_contacts = $conn->query("SELECT * FROM contact_messages WHERE reply_message IS NOT NULL ORDER BY replied_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages - SmartCare Guardian</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Quicksand:wght@300;400;500;600&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --sage-green:  #87A96B;
    --mint-cream:  #F0FFF0;
    --seafoam:     #9FE2BF;
    --forest-mist: #B8E0D2;
    --dusty-teal:  #6D9B8E;
    --deep-emerald:#4A766E;
    --light-sage:  #E8F5E8;
}
*, *::before, *::after { box-sizing: border-box; }

body {
    font-family: 'Quicksand', sans-serif;
    background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
    margin: 0; padding: 0; min-height: 100vh;
}
h1,h2,h3,h4,h5 { font-family: 'Playfair Display', serif; color: var(--deep-emerald); }
.brand-font     { font-family: 'Jost', sans-serif; font-weight: 600; }

/* Sidebar */
.sidebar {
    width: 280px; height: 100vh; position: fixed;
    background: linear-gradient(180deg, var(--sage-green) 0%, var(--dusty-teal) 100%);
    color: white; box-shadow: 4px 0 20px rgba(0,0,0,.1);
    z-index: 1000; display: flex; flex-direction: column;
}
.sidebar-header { text-align:center; padding:30px 20px 20px; border-bottom:1px solid rgba(255,255,255,.2); flex-shrink:0; }
.sidebar-nav    { flex:1; overflow-y:auto; padding:20px 0; }
.sidebar-footer { flex-shrink:0; border-top:1px solid rgba(255,255,255,.2); padding:20px; }
.sidebar a {
    color:white; display:flex; align-items:center; padding:15px 25px;
    text-decoration:none; transition:all .3s; margin:5px 15px;
    border-radius:12px; font-weight:500;
}
.sidebar a:hover  { background:rgba(255,255,255,.15); transform:translateX(5px); }
.sidebar a.active { background:rgba(255,255,255,.25); box-shadow:0 4px 15px rgba(0,0,0,.1); }
.sidebar i        { width:25px; margin-right:12px; font-size:1.1rem; }
.sidebar-nav::-webkit-scrollbar { width:6px; }
.sidebar-nav::-webkit-scrollbar-thumb { background:rgba(255,255,255,.3); border-radius:3px; }

/* Content */
.content { margin-left:280px; padding:30px; min-height:100vh; }

.topbar {
    background:rgba(255,255,255,.95); backdrop-filter:blur(10px);
    border-radius:20px; padding:20px 30px;
    box-shadow:0 8px 32px rgba(0,0,0,.1);
    margin-bottom:30px; border:1px solid rgba(255,255,255,.2);
}
.logout-btn {
    background:linear-gradient(135deg,#ff6b6b,#ee5a52);
    border:none; border-radius:50px; color:white;
    padding:10px 25px; font-weight:600; transition:all .3s;
}
.logout-btn:hover { transform:translateY(-2px); box-shadow:0 5px 15px rgba(255,107,107,.4); }

/* Tab nav */
.tab-nav { display:flex; gap:10px; margin-bottom:25px; flex-wrap:wrap; }
.tab-btn {
    padding:12px 28px; border-radius:50px;
    border:2px solid var(--sage-green); background:white;
    color:var(--deep-emerald); font-weight:600;
    font-family:'Jost',sans-serif; cursor:pointer;
    transition:all .3s; position:relative;
}
.tab-btn:hover, .tab-btn.active {
    background:linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
    color:white; border-color:transparent;
    box-shadow:0 4px 15px rgba(135,169,107,.4);
}
.tab-badge {
    position:absolute; top:-7px; right:-7px;
    background:#ff6b6b; color:white; border-radius:50%;
    width:22px; height:22px; font-size:.7rem;
    display:flex; align-items:center; justify-content:center;
    font-weight:700; border:2px solid white;
}

/* Panel */
.panel {
    background:rgba(255,255,255,.95); border-radius:20px;
    box-shadow:0 8px 32px rgba(0,0,0,.09);
    overflow:hidden; margin-bottom:25px;
    border:1px solid rgba(255,255,255,.2);
}
.panel-header {
    background:linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
    color:white; padding:18px 25px;
    display:flex; align-items:center; gap:12px;
}
.panel-header h5 { color:white; margin:0; font-family:'Jost',sans-serif; font-weight:600; }

/* Two-column caregiver layout */
.cg-layout  { display:flex; }
.cg-sidebar { width:270px; flex-shrink:0; border-right:1px solid rgba(0,0,0,.07); display:flex; flex-direction:column; }
.cg-main    { flex:1; min-width:0; display:flex; flex-direction:column; }

/* Compose pane */
.compose-pane {
    padding:18px; background:var(--light-sage);
    border-bottom:1px solid rgba(0,0,0,.07);
}
.compose-pane .form-label { font-weight:700; color:var(--deep-emerald); font-size:.83rem; display:block; margin-bottom:5px; }
.form-select, .form-control {
    border:2px solid var(--forest-mist); border-radius:10px;
    padding:9px 12px; font-family:'Quicksand',sans-serif;
    font-size:.88rem; width:100%; transition:all .3s;
}
.form-select:focus, .form-control:focus {
    border-color:var(--sage-green); outline:none;
    box-shadow:0 0 0 .2rem rgba(135,169,107,.2);
}
.send-btn {
    background:linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
    border:none; border-radius:10px; color:white;
    padding:9px 18px; font-weight:700; font-family:'Jost',sans-serif;
    cursor:pointer; transition:all .3s; white-space:nowrap; width:100%; margin-top:8px;
}
.send-btn:hover { transform:translateY(-2px); box-shadow:0 5px 14px rgba(135,169,107,.4); }

/* Conversation list */
.conv-list { flex:1; overflow-y:auto; }
.conv-item {
    display:flex; align-items:center; gap:10px;
    padding:13px 16px; cursor:pointer;
    border-bottom:1px solid rgba(0,0,0,.06);
    transition:background .2s; text-decoration:none; color:inherit;
}
.conv-item:hover       { background:var(--light-sage); }
.conv-item.active-conv { background:var(--light-sage); border-left:4px solid var(--sage-green); }
.conv-avatar {
    width:40px; height:40px; border-radius:50%;
    background:linear-gradient(135deg, var(--forest-mist), var(--dusty-teal));
    display:flex; align-items:center; justify-content:center;
    color:white; font-weight:700; flex-shrink:0;
}
.conv-name { font-weight:700; font-size:.88rem; color:var(--deep-emerald); }
.conv-time { font-size:.73rem; color:#aaa; }
.unread-badge {
    background:#ff6b6b; color:white; border-radius:50%;
    min-width:20px; height:20px; font-size:.7rem;
    display:flex; align-items:center; justify-content:center;
    font-weight:700; margin-left:auto; flex-shrink:0; padding:0 4px;
}

/* Chat window */
.chat-head {
    padding:15px 20px; background:var(--light-sage);
    border-bottom:1px solid rgba(0,0,0,.07);
    display:flex; align-items:center; gap:12px; flex-shrink:0;
}
.chat-area {
    flex:1; overflow-y:auto; padding:18px;
    display:flex; flex-direction:column; gap:12px;
    background:#fafafa; min-height:350px; max-height:430px;
}
.chat-area::-webkit-scrollbar { width:5px; }
.chat-area::-webkit-scrollbar-thumb { background:#ddd; border-radius:3px; }

.brow { display:flex; align-items:flex-end; gap:7px; max-width:72%; }
.brow.me   { align-self:flex-end; flex-direction:row-reverse; }
.brow.them { align-self:flex-start; }
.bubble {
    padding:10px 14px; border-radius:18px;
    font-size:.88rem; line-height:1.55;
}
.bubble.me   { background:linear-gradient(135deg, var(--sage-green), var(--dusty-teal)); color:white; border-bottom-right-radius:4px; }
.bubble.them { background:white; color:#333; border:1px solid var(--forest-mist); border-bottom-left-radius:4px; }
.btime { font-size:.7rem; color:#bbb; margin-top:3px; }
.btime.me   { text-align:right; }
.btime.them { text-align:left; }
.mini-av {
    width:28px; height:28px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-weight:700; font-size:.75rem; flex-shrink:0;
}
.mini-av.me   { background:linear-gradient(135deg, var(--sage-green), var(--dusty-teal)); color:white; }
.mini-av.them { background:linear-gradient(135deg, var(--forest-mist), var(--dusty-teal)); color:white; }

.reply-bar {
    padding:12px 16px; border-top:1px solid rgba(0,0,0,.07);
    display:flex; gap:9px; align-items:flex-end; background:white; flex-shrink:0;
}
.reply-bar textarea {
    flex:1; border:2px solid var(--forest-mist); border-radius:10px;
    padding:9px 12px; font-family:'Quicksand',sans-serif;
    font-size:.88rem; resize:none; min-height:42px; max-height:110px;
    transition:border-color .3s;
}
.reply-bar textarea:focus { border-color:var(--sage-green); outline:none; }
.reply-send-btn {
    background:linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
    border:none; border-radius:10px; color:white;
    padding:9px 18px; font-weight:700; cursor:pointer;
    transition:all .3s; flex-shrink:0;
}
.reply-send-btn:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(135,169,107,.4); }

.no-chat {
    flex:1; display:flex; flex-direction:column;
    align-items:center; justify-content:center;
    color:#bbb; gap:10px; padding:40px; min-height:350px;
}
.no-chat i { font-size:3rem; opacity:.3; }
.no-chat p { margin:0; font-size:.9rem; }

/* Contact table */
.table { margin:0; }
.table thead { background:linear-gradient(135deg, var(--sage-green), var(--dusty-teal)); color:white; }
.table thead th { border:none; padding:16px 14px; font-weight:600; font-family:'Jost',sans-serif; color:white; }
.table tbody tr { border-bottom:1px solid rgba(0,0,0,.05); transition:background .2s; }
.table tbody tr:hover { background:var(--light-sage); }
.table tbody td { padding:14px; vertical-align:middle; border:none; color:var(--deep-emerald); }
.msg-trunc { max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; cursor:pointer; }
.msg-trunc.expanded { white-space:normal; text-overflow:unset; max-width:none; }
.user-info   { display:flex; align-items:center; gap:10px; }
.user-avatar {
    width:40px; height:40px; border-radius:50%;
    background:linear-gradient(135deg, var(--forest-mist), var(--dusty-teal));
    display:flex; align-items:center; justify-content:center;
    color:white; font-weight:700; flex-shrink:0;
}
.badge { padding:6px 12px; border-radius:20px; font-weight:600; font-size:.75rem; }
.badge-pending { background:linear-gradient(135deg,#ff6b6b,#ee5a52)!important; color:white; }
.badge-replied { background:linear-gradient(135deg, var(--seafoam), var(--sage-green))!important; color:white; }
.reply-inline textarea {
    width:100%; border:2px solid var(--forest-mist); border-radius:10px;
    padding:9px 12px; font-family:'Quicksand',sans-serif;
    resize:vertical; min-height:68px; font-size:.88rem; transition:all .3s;
}
.reply-inline textarea:focus { border-color:var(--sage-green); outline:none; box-shadow:0 0 0 .2rem rgba(135,169,107,.2); }
.reply-inline-btn {
    background:linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
    border:none; border-radius:10px; color:white;
    padding:8px 16px; font-weight:600; cursor:pointer; transition:all .3s; margin-top:7px;
}
.reply-inline-btn:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(135,169,107,.4); }

/* Flash */
.flash-ok  { background:linear-gradient(135deg,#d4edda,#c3e6cb); border:none; border-radius:14px; color:#155724; padding:12px 20px; font-weight:600; margin-bottom:20px; }
.flash-err { background:linear-gradient(135deg,#f8d7da,#f5c6cb); border:none; border-radius:14px; color:#721c24; padding:12px 20px; font-weight:600; margin-bottom:20px; }

.empty-st { text-align:center; padding:44px 20px; color:#bbb; }
.empty-st i { font-size:2.8rem; display:block; margin-bottom:12px; opacity:.3; }
.empty-st p { margin:0; font-size:.9rem; }

@media (max-width:900px) { .cg-layout { flex-direction:column; } .cg-sidebar { width:100%; border-right:none; border-bottom:1px solid rgba(0,0,0,.07); } }
@media (max-width:768px) { .sidebar { width:100%; height:auto; position:relative; } .content { margin-left:0; padding:16px; } }
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-header">
        <h4 class="brand-font mb-2"><i class="fa-solid fa-shield-heart me-2"></i>SmartCare Guardian</h4>
        <small class="opacity-75">Administrator Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="admin_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="manage_users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i> Manage Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i> Manage Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i> Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i> Manage Services</a>
        <a href="#" class="active"><i class="fa-solid fa-envelope"></i> Messages</a>
        <a href="admin_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="admin_reports.php"><i class="fa-solid fa-chart-line"></i> Reports & Analytics</a>
    </div>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<!-- Content -->
<div class="content">

    <div class="topbar d-flex justify-content-between align-items-center">
        <div>
            <h4 class="brand-font mb-1">Messages</h4>
            <p class="text-muted mb-0">Manage contact inquiries &amp; communicate with caregivers</p>
        </div>
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt me-2"></i>Logout</button>
        </form>
    </div>

    <?php if ($success): ?><div class="flash-ok"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="flash-err"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Tab buttons -->
    <div class="tab-nav">
        <button class="tab-btn <?= $active_tab === 'contact'   ? 'active' : '' ?>" onclick="switchTab('contact',this)">
            <i class="fas fa-inbox me-2"></i>Contact Inquiries
        </button>
        <button class="tab-btn <?= $active_tab === 'caregiver' ? 'active' : '' ?>" onclick="switchTab('caregiver',this)" style="position:relative;">
            <i class="fas fa-comments me-2"></i>Caregiver Messages
            <?php if ($unread_count > 0): ?><span class="tab-badge"><?= $unread_count ?></span><?php endif; ?>
        </button>
    </div>


    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- TAB 1 · Contact Inquiries                                      -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div id="tab-contact" style="display:<?= $active_tab === 'contact' ? 'block' : 'none' ?>">

        <!-- Pending -->
        <div class="panel">
            <div class="panel-header"><i class="fas fa-envelope-open-text fa-lg"></i><h5>Pending Inquiries</h5></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Contact</th><th>Message</th><th>Date</th><th>Status</th><th style="min-width:260px">Reply</th></tr></thead>
                    <tbody>
                        <?php if ($pending_contacts->num_rows === 0): ?>
                            <tr><td colspan="5"><div class="empty-st"><i class="fas fa-inbox"></i><p>No pending inquiries.</p></div></td></tr>
                        <?php else: while ($m = $pending_contacts->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar"><?= strtoupper(substr($m['fullname'],0,1)) ?></div>
                                    <div>
                                        <strong><?= htmlspecialchars($m['fullname']) ?></strong>
                                        <div class="text-muted small"><?= htmlspecialchars($m['email']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($m['phone']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><div class="msg-trunc" onclick="this.classList.toggle('expanded')" title="Click to expand"><?= htmlspecialchars($m['message']) ?></div></td>
                            <td><small class="text-muted"><?= date('M j, Y g:i A', strtotime($m['created_at'])) ?></small></td>
                            <td><span class="badge badge-pending"><i class="fas fa-clock me-1"></i>Pending</span></td>
                            <td>
                                <form method="POST" action="reply_message.php" class="reply-inline">
                                    <input type="hidden" name="message_id" value="<?= $m['id'] ?>">
                                    <textarea name="reply" placeholder="Type your reply…" required></textarea>
                                    <button type="submit" class="reply-inline-btn" onclick="return confirm('Send this reply?')">
                                        <i class="fas fa-paper-plane me-1"></i>Send
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Replied -->
        <div class="panel">
            <div class="panel-header"><i class="fas fa-check-double fa-lg"></i><h5>Replied Inquiries</h5></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Contact</th><th>Message &amp; Reply</th><th>Date</th><th>Status</th><th>Replied On</th></tr></thead>
                    <tbody>
                        <?php if ($replied_contacts->num_rows === 0): ?>
                            <tr><td colspan="5"><div class="empty-st"><i class="fas fa-comments"></i><p>No replied inquiries yet.</p></div></td></tr>
                        <?php else: while ($m = $replied_contacts->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar"><?= strtoupper(substr($m['fullname'],0,1)) ?></div>
                                    <div>
                                        <strong><?= htmlspecialchars($m['fullname']) ?></strong>
                                        <div class="text-muted small"><?= htmlspecialchars($m['email']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($m['phone']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="msg-trunc" onclick="this.classList.toggle('expanded')" title="Click to expand"><?= htmlspecialchars($m['message']) ?></div>
                                <div class="mt-2 p-2 rounded" style="background:#f0fff0;border-left:3px solid var(--sage-green);">
                                    <small style="color:var(--sage-green);font-weight:700;">Your Reply:</small>
                                    <div class="small"><?= htmlspecialchars($m['reply_message']) ?></div>
                                </div>
                            </td>
                            <td><small class="text-muted"><?= date('M j, Y g:i A', strtotime($m['created_at'])) ?></small></td>
                            <td><span class="badge badge-replied"><i class="fas fa-check-circle me-1"></i>Replied</span></td>
                            <td><small class="text-muted"><?= date('M j, Y', strtotime($m['replied_at'])) ?></small></td>
                        </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div><!-- /tab-contact -->


    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- TAB 2 · Caregiver Messages                                     -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div id="tab-caregiver" style="display:<?= $active_tab === 'caregiver' ? 'block' : 'none' ?>">
        <div class="panel">
            <div class="panel-header"><i class="fas fa-comments fa-lg"></i><h5>Caregiver Conversations</h5></div>

            <div class="cg-layout">

                <!-- Left sidebar: compose + conversation list -->
                <div class="cg-sidebar">

                    <!-- Compose new message -->
                    <div class="compose-pane">
                        <label class="form-label"><i class="fas fa-pen-to-square me-1" style="color:var(--sage-green)"></i> New Message</label>
                        <form method="POST" id="composeForm">
                            <input type="hidden" name="chat_user_id" value="0">
                            <select name="receiver_id" class="form-select mb-2" id="composeSelect" required>
                                <option value="" disabled selected>— Select caregiver —</option>
                                <?php foreach ($caregivers as $cg): ?>
                                    <option value="<?= $cg['user_id'] ?>"><?= htmlspecialchars($cg['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <textarea name="message_content" class="form-control" rows="2" placeholder="Type your message…" required></textarea>
                            <button type="submit" name="send_message" class="send-btn"><i class="fas fa-paper-plane me-2"></i>Send</button>
                        </form>
                    </div>

                    <!-- Previous conversations -->
                    <div class="conv-list">
                        <?php
                        $had = false;
                        while ($cv = $conv_list->fetch_assoc()):
                            $had = true;
                            $url = "messages.php?tab=caregiver&chat=" . $cv['user_id'];
                            $act = ($chat_user_id == $cv['user_id']) ? 'active-conv' : '';
                        ?>
                        <a href="<?= $url ?>" class="conv-item <?= $act ?>">
                            <div class="conv-avatar"><?= strtoupper(substr($cv['full_name'],0,1)) ?></div>
                            <div style="flex:1;min-width:0;">
                                <div class="conv-name"><?= htmlspecialchars($cv['full_name']) ?></div>
                                <div class="conv-time"><?= date('M j, g:i A', strtotime($cv['last_at'])) ?></div>
                            </div>
                            <?php if ($cv['unread'] > 0): ?>
                                <span class="unread-badge"><?= $cv['unread'] ?></span>
                            <?php endif; ?>
                        </a>
                        <?php endwhile;
                        if (!$had): ?>
                            <div class="empty-st" style="padding:28px 14px;">
                                <i class="fas fa-comments"></i><p>No conversations yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div><!-- /cg-sidebar -->

                <!-- Right: chat window -->
                <div class="cg-main">
                    <?php if ($chat_user_id && $chatUser): ?>

                        <div class="chat-head">
                            <div class="conv-avatar"><?= strtoupper(substr($chatUser['full_name'],0,1)) ?></div>
                            <div>
                                <div style="font-weight:700;color:var(--deep-emerald);"><?= htmlspecialchars($chatUser['full_name']) ?></div>
                                <div style="font-size:.77rem;color:#aaa;">Caregiver</div>
                            </div>
                        </div>

                        <div class="chat-area" id="chatArea">
                            <?php if ($conversationMessages->num_rows === 0): ?>
                                <div class="no-chat" style="min-height:unset;flex:1;">
                                    <i class="fas fa-comment-dots"></i>
                                    <p>No messages yet. Start the conversation!</p>
                                </div>
                            <?php else: while ($msg = $conversationMessages->fetch_assoc()):
                                $is_me = ($msg['sender_id'] == $admin_id);
                            ?>
                                <div class="brow <?= $is_me ? 'me' : 'them' ?>">
                                    <div class="mini-av <?= $is_me ? 'me' : 'them' ?>">
                                        <?= $is_me ? 'A' : strtoupper(substr($msg['sender_name'],0,1)) ?>
                                    </div>
                                    <div>
                                        <div class="bubble <?= $is_me ? 'me' : 'them' ?>">
                                            <?= nl2br(htmlspecialchars($msg['message_content'])) ?>
                                        </div>
                                        <div class="btime <?= $is_me ? 'me' : 'them' ?>">
                                            <?= date('M j, g:i A', strtotime($msg['sent_at'])) ?>
                                            <?php if ($is_me): ?>
                                                &nbsp;<i class="fas fa-check<?= $msg['is_read'] ? '-double' : '' ?>" style="font-size:.65rem;"></i>
                                                &nbsp;<a href="messages.php?delete=<?= $msg['message_id'] ?>&tab=caregiver&chat=<?= $chat_user_id ?>"
                                                   onclick="return confirm('Delete this message?')"
                                                   style="color:#ff9999;font-size:.72rem;text-decoration:none;">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; endif; ?>
                        </div>

                        <div class="reply-bar">
                            <form method="POST" style="display:flex;gap:8px;width:100%;align-items:flex-end;">
                                <input type="hidden" name="receiver_id"    value="<?= $chat_user_id ?>">
                                <input type="hidden" name="chat_user_id"   value="<?= $chat_user_id ?>">
                                <textarea name="message_content" placeholder="Type a message…" rows="1" required id="replyTa"></textarea>
                                <button type="submit" name="send_message" class="reply-send-btn"><i class="fas fa-paper-plane"></i></button>
                            </form>
                        </div>

                    <?php else: ?>
                        <div class="no-chat">
                            <i class="fas fa-comments"></i>
                            <p>Select a conversation from the left, or compose a new message to a caregiver.</p>
                        </div>
                    <?php endif; ?>
                </div><!-- /cg-main -->

            </div><!-- /cg-layout -->
        </div>
    </div><!-- /tab-caregiver -->

</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Tab switching
function switchTab(name, btn) {
    document.getElementById('tab-contact').style.display   = name === 'contact'   ? 'block' : 'none';
    document.getElementById('tab-caregiver').style.display = name === 'caregiver' ? 'block' : 'none';
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    history.replaceState(null, '', 'messages.php?tab=' + name + <?= $chat_user_id ? "+'&chat=<?= $chat_user_id ?>'" : "''" ?>);
}

// Auto-scroll chat to bottom
const ca = document.getElementById('chatArea');
if (ca) ca.scrollTop = ca.scrollHeight;

// Auto-grow textareas
document.querySelectorAll('textarea').forEach(ta => {
    ta.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
    });
});

// Send with Ctrl+Enter in reply bar
const replyTa = document.getElementById('replyTa');
if (replyTa) {
    replyTa.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            this.closest('form').submit();
        }
    });
}
</script>
</body>
</html>