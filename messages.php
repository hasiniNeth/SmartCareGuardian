<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

include 'db_connection.php';

$admin_id = $_SESSION['user_id'];

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
        } else { $error = "Failed to send message. Please try again."; }
        $stmt->close();
    } else { $error = "Please select a recipient and type a message."; }
}

if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $stmt   = $conn->prepare("DELETE FROM messages WHERE message_id = ? AND sender_id = ?");
    $stmt->bind_param("ii", $del_id, $admin_id);
    $stmt->execute(); $stmt->close();
    $_SESSION['success'] = "Message deleted.";
    $back_chat = isset($_GET['chat']) ? '&chat=' . (int)$_GET['chat'] : '';
    header("Location: messages.php?tab=caregiver$back_chat"); exit();
}

$success = $_SESSION['success'] ?? null;
$error   = $error ?? null;
unset($_SESSION['success']);

$active_tab   = $_GET['tab']  ?? 'contact';
$chat_user_id = isset($_GET['chat']) ? (int)$_GET['chat'] : null;

$cg_list   = $conn->query("SELECT user_id, full_name FROM users WHERE role = 'caregiver' ORDER BY full_name ASC");
$caregivers = [];
while ($r = $cg_list->fetch_assoc()) $caregivers[] = $r;

$unread_res   = $conn->query("
    SELECT COUNT(*) AS cnt FROM messages m
    JOIN users u ON m.sender_id = u.user_id
    WHERE m.receiver_id = $admin_id AND m.is_read = 0 AND u.role = 'caregiver'
");
$unread_count = $unread_res->fetch_assoc()['cnt'] ?? 0;

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

$conversationMessages = null;
$chatUser = null;
if ($chat_user_id) {
    $u = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
    $u->bind_param("i", $chat_user_id); $u->execute();
    $chatUser = $u->get_result()->fetch_assoc(); $u->close();

    $mark = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
    $mark->bind_param("ii", $chat_user_id, $admin_id); $mark->execute(); $mark->close();

    $c = $conn->prepare("
        SELECT m.*, s.full_name AS sender_name FROM messages m
        JOIN users s ON m.sender_id = s.user_id
        WHERE (m.sender_id = ? AND m.receiver_id = ?)
           OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.sent_at ASC
    ");
    $c->bind_param("iiii", $admin_id, $chat_user_id, $chat_user_id, $admin_id);
    $c->execute(); $conversationMessages = $c->get_result(); $c->close();
}

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
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════════════════════
   SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
   Messages · Professional scale (15px base)
═══════════════════════════════════════════════════════════ */
:root{
    --s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;
    --s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;
    --s600:#4A6E30;--s700:#365220;--s800:#243816;
    --w50:#FDFAF5;--w100:#F7F1E5;
    --st300:#B8B0A4;--st500:#7A7268;--st700:#4A4540;
    --green-bg:#DDEFD8;--green-text:#3A6830;
    --red-bg:#F5DADA;--red-text:#6A2020;
    --radius-sm:8px;--radius-md:12px;--radius-lg:20px;
    --shadow-soft:0 2px 12px rgba(36,56,22,.07),0 1px 3px rgba(36,56,22,.05);
    --shadow-card:0 4px 24px rgba(36,56,22,.09),0 1px 4px rgba(36,56,22,.06);
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
.sidebar-footer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 8px;position:relative;}
.sidebar-footer a{margin:0;color:rgba(255,255,255,.5)!important;font-size:13.5px;}
.sidebar-footer a:hover{color:rgba(255,255,255,.8)!important;}

/* ── Layout ── */
.content{margin-left:240px;padding:24px;min-height:100vh;}

/* ── Topbar ── */
.topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);margin-bottom:22px;border:1px solid rgba(196,217,180,.3);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
.topbar h4{font-family:'Outfit',sans-serif;font-weight:700;font-size:18px;color:var(--s800);margin-bottom:3px;}
.topbar p{font-size:13px;color:var(--st300);margin:0;}
.logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;transition:all .2s;cursor:pointer;}
.logout-btn:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(139,58,58,.35);}

/* ── Flash ── */
.flash-ok{background:var(--green-bg);border:none;border-radius:var(--radius-md);color:var(--green-text);padding:11px 18px;font-weight:700;font-size:14px;margin-bottom:18px;}
.flash-err{background:var(--red-bg);border:none;border-radius:var(--radius-md);color:var(--red-text);padding:11px 18px;font-weight:700;font-size:14px;margin-bottom:18px;}

/* ── Tab nav ── */
.tab-nav{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;}
.tab-btn{padding:9px 22px;border-radius:var(--radius-md);border:2px solid var(--s200);background:white;color:var(--s700);font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;position:relative;}
.tab-btn:hover,.tab-btn.active{background:linear-gradient(135deg,var(--s500),var(--s800));color:white;border-color:transparent;box-shadow:0 4px 14px rgba(94,138,64,.3);}
.tab-badge{position:absolute;top:-7px;right:-7px;background:var(--red-text);color:white;border-radius:50%;width:20px;height:20px;font-size:11px;display:flex;align-items:center;justify-content:center;font-weight:700;border:2px solid white;}

/* ── Panel ── */
.panel{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);overflow:hidden;margin-bottom:22px;border:1px solid rgba(196,217,180,.3);}
.panel-header{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:15px 22px;display:flex;align-items:center;gap:10px;position:relative;overflow:hidden;}
.panel-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
.panel-header h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;position:relative;}
.panel-header i{position:relative;}

/* ── Two-col caregiver layout ── */
.cg-layout{display:flex;}
.cg-sidebar{width:260px;flex-shrink:0;border-right:1px solid var(--s100);display:flex;flex-direction:column;}
.cg-main{flex:1;min-width:0;display:flex;flex-direction:column;}

/* ── Compose pane ── */
.compose-pane{padding:16px;background:var(--s50);border-bottom:1px solid var(--s100);}
.compose-pane .form-label{font-weight:700;color:var(--s800);font-size:12px;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em;}
.form-select,.form-control{border:2px solid var(--s100);border-radius:var(--radius-sm);padding:8px 12px;font-family:'Outfit',sans-serif;font-size:13px;width:100%;transition:border-color .2s;background:var(--w50);color:var(--st700);}
.form-select:focus,.form-control:focus{border-color:var(--s400);outline:none;box-shadow:none;}
.send-btn{background:linear-gradient(135deg,var(--s500),var(--s800));border:none;border-radius:var(--radius-sm);color:white;padding:8px 16px;font-weight:700;font-family:'Outfit',sans-serif;font-size:13px;cursor:pointer;transition:all .2s;width:100%;margin-top:8px;}
.send-btn:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(94,138,64,.35);}

/* ── Conversation list ── */
.conv-list{flex:1;overflow-y:auto;}
.conv-item{display:flex;align-items:center;gap:10px;padding:12px 14px;cursor:pointer;border-bottom:1px solid var(--s50);transition:background .2s;text-decoration:none;color:inherit;}
.conv-item:hover{background:var(--s50);}
.conv-item.active-conv{background:var(--s50);border-left:3px solid var(--s400);}
.conv-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--s400),var(--s700));display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:13px;flex-shrink:0;}
.conv-name{font-weight:700;font-size:13px;color:var(--s800);}
.conv-time{font-size:11px;color:var(--st300);}
.unread-badge{background:var(--red-text);color:white;border-radius:50%;min-width:18px;height:18px;font-size:10px;display:flex;align-items:center;justify-content:center;font-weight:700;margin-left:auto;flex-shrink:0;padding:0 3px;}

/* ── Chat ── */
.chat-head{padding:13px 18px;background:var(--s50);border-bottom:1px solid var(--s100);display:flex;align-items:center;gap:10px;flex-shrink:0;}
.chat-area{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:11px;background:var(--w50);min-height:320px;max-height:420px;}
.chat-area::-webkit-scrollbar{width:4px;}.chat-area::-webkit-scrollbar-thumb{background:var(--s100);border-radius:3px;}
.brow{display:flex;align-items:flex-end;gap:6px;max-width:72%;}
.brow.me{align-self:flex-end;flex-direction:row-reverse;}
.brow.them{align-self:flex-start;}
.bubble{padding:9px 13px;border-radius:16px;font-size:13px;line-height:1.55;}
.bubble.me{background:linear-gradient(135deg,var(--s500),var(--s800));color:white;border-bottom-right-radius:4px;}
.bubble.them{background:white;color:var(--st700);border:1px solid var(--s100);border-bottom-left-radius:4px;}
.btime{font-size:11px;color:var(--st300);margin-top:3px;}
.btime.me{text-align:right;}.btime.them{text-align:left;}
.mini-av{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0;}
.mini-av.me{background:linear-gradient(135deg,var(--s500),var(--s800));color:white;}
.mini-av.them{background:linear-gradient(135deg,var(--s200),var(--s500));color:white;}
.reply-bar{padding:11px 14px;border-top:1px solid var(--s100);display:flex;gap:8px;align-items:flex-end;background:white;flex-shrink:0;}
.reply-bar textarea{flex:1;border:2px solid var(--s100);border-radius:var(--radius-sm);padding:8px 11px;font-family:'Outfit',sans-serif;font-size:13px;resize:none;min-height:40px;max-height:110px;transition:border-color .2s;color:var(--st700);}
.reply-bar textarea:focus{border-color:var(--s400);outline:none;}
.reply-send-btn{background:linear-gradient(135deg,var(--s500),var(--s800));border:none;border-radius:var(--radius-sm);color:white;padding:8px 16px;font-weight:700;cursor:pointer;transition:all .2s;flex-shrink:0;}
.reply-send-btn:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(94,138,64,.35);}
.no-chat{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--st300);gap:9px;padding:40px;min-height:320px;}
.no-chat i{font-size:2.8rem;opacity:.2;}
.no-chat p{margin:0;font-size:13px;}

/* ── Contact table ── */
.table{margin:0;}
.table thead{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;position:relative;}
.table thead::after{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
.table thead th{border:none;padding:13px 12px;font-weight:700;font-family:'Outfit',sans-serif;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:white;position:relative;}
.table tbody tr{border-bottom:1px solid var(--s50);transition:background .2s;}
.table tbody tr:hover{background:var(--s50);}
.table tbody td{padding:13px 12px;vertical-align:middle;border:none;color:var(--st700);font-size:14px;}
.msg-trunc{max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;font-size:13px;}
.msg-trunc.expanded{white-space:normal;text-overflow:unset;max-width:none;}
.user-info{display:flex;align-items:center;gap:10px;}
.user-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--s400),var(--s700));display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:13px;flex-shrink:0;}
.badge{padding:4px 11px;border-radius:20px;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.04em;}
.badge-pending{background:var(--red-bg)!important;color:var(--red-text)!important;border:1px solid rgba(107,34,34,.12);}
.badge-replied{background:var(--green-bg)!important;color:var(--green-text)!important;border:1px solid rgba(58,104,48,.12);}
.reply-inline textarea{width:100%;border:2px solid var(--s100);border-radius:var(--radius-sm);padding:8px 11px;font-family:'Outfit',sans-serif;resize:vertical;min-height:62px;font-size:13px;transition:border-color .2s;color:var(--st700);}
.reply-inline textarea:focus{border-color:var(--s400);outline:none;}
.reply-inline-btn{background:linear-gradient(135deg,var(--s500),var(--s800));border:none;border-radius:var(--radius-sm);color:white;padding:7px 14px;font-weight:700;font-size:13px;cursor:pointer;transition:all .2s;margin-top:6px;font-family:'Outfit',sans-serif;}
.reply-inline-btn:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(94,138,64,.35);}

/* ── Reply quote ── */
.reply-quote{background:var(--s50);border-left:3px solid var(--s400);border-radius:0 var(--radius-sm) var(--radius-sm) 0;padding:8px 11px;margin-top:7px;}
.reply-quote small{color:var(--s600);font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.04em;}
.reply-quote div{font-size:13px;color:var(--st500);}

/* ── Empty state ── */
.empty-st{text-align:center;padding:44px 20px;color:var(--st300);}
.empty-st i{font-size:2.6rem;display:block;margin-bottom:12px;opacity:.2;}
.empty-st p{margin:0;font-size:13px;}

@media(max-width:900px){.cg-layout{flex-direction:column;}.cg-sidebar{width:100%;border-right:none;border-bottom:1px solid var(--s100);}}
@media(max-width:768px){.sidebar{width:100%;height:auto;position:relative;}.content{margin-left:0;padding:16px;}}
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
        <small>Administrator Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="admin_dashboard.php"><i class="fa-solid fa-gauge-high"></i>Dashboard</a>
        <a href="manage_users.php"><i class="fa-solid fa-users"></i>Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i>Manage Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i>Manage Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i>Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i>Manage Services</a>
        <a href="#" class="active"><i class="fa-solid fa-envelope"></i>Messages</a>
        <a href="admin_alerts.php"><i class="fa-solid fa-bell"></i>Health Alerts</a>
        <a href="admin_reports.php"><i class="fa-solid fa-chart-line"></i>Reports & Analytics</a>
    </div>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <div class="topbar">
        <div>
            <h4>Messages</h4>
            <p>Manage contact inquiries &amp; communicate with caregivers</p>
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

    <!-- ═══ TAB 1 · Contact Inquiries ═══════════════════════════════════ -->
    <div id="tab-contact" style="display:<?= $active_tab === 'contact' ? 'block' : 'none' ?>">

        <!-- Pending -->
        <div class="panel">
            <div class="panel-header"><i class="fas fa-envelope-open-text"></i><h5>Pending Inquiries</h5></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Contact</th><th>Message</th><th>Date</th><th>Status</th><th style="min-width:250px">Reply</th></tr></thead>
                    <tbody>
                        <?php if ($pending_contacts->num_rows === 0): ?>
                            <tr><td colspan="5"><div class="empty-st"><i class="fas fa-inbox"></i><p>No pending inquiries.</p></div></td></tr>
                        <?php else: while ($m = $pending_contacts->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar"><?= strtoupper(substr($m['fullname'],0,1)) ?></div>
                                    <div>
                                        <strong style="color:var(--s800);font-size:14px;"><?= htmlspecialchars($m['fullname']) ?></strong>
                                        <div style="font-size:12px;color:var(--st300);"><?= htmlspecialchars($m['email']) ?></div>
                                        <div style="font-size:12px;color:var(--st300);"><?= htmlspecialchars($m['phone']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><div class="msg-trunc" onclick="this.classList.toggle('expanded')" title="Click to expand"><?= htmlspecialchars($m['message']) ?></div></td>
                            <td><small style="color:var(--st300);font-size:12px;"><?= date('M j, Y g:i A', strtotime($m['created_at'])) ?></small></td>
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
            <div class="panel-header"><i class="fas fa-check-double"></i><h5>Replied Inquiries</h5></div>
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
                                        <strong style="color:var(--s800);font-size:14px;"><?= htmlspecialchars($m['fullname']) ?></strong>
                                        <div style="font-size:12px;color:var(--st300);"><?= htmlspecialchars($m['email']) ?></div>
                                        <div style="font-size:12px;color:var(--st300);"><?= htmlspecialchars($m['phone']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="msg-trunc" onclick="this.classList.toggle('expanded')" title="Click to expand"><?= htmlspecialchars($m['message']) ?></div>
                                <div class="reply-quote mt-2">
                                    <small>Your Reply:</small>
                                    <div><?= htmlspecialchars($m['reply_message']) ?></div>
                                </div>
                            </td>
                            <td><small style="color:var(--st300);font-size:12px;"><?= date('M j, Y g:i A', strtotime($m['created_at'])) ?></small></td>
                            <td><span class="badge badge-replied"><i class="fas fa-check-circle me-1"></i>Replied</span></td>
                            <td><small style="color:var(--st300);font-size:12px;"><?= date('M j, Y', strtotime($m['replied_at'])) ?></small></td>
                        </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div><!-- /tab-contact -->

    <!-- ═══ TAB 2 · Caregiver Messages ══════════════════════════════════ -->
    <div id="tab-caregiver" style="display:<?= $active_tab === 'caregiver' ? 'block' : 'none' ?>">
        <div class="panel">
            <div class="panel-header"><i class="fas fa-comments"></i><h5>Caregiver Conversations</h5></div>
            <div class="cg-layout">

                <!-- Left: compose + list -->
                <div class="cg-sidebar">
                    <div class="compose-pane">
                        <label class="form-label"><i class="fas fa-pen-to-square me-1" style="color:var(--s500);"></i>New Message</label>
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
                            <div class="empty-st" style="padding:24px 14px;"><i class="fas fa-comments"></i><p>No conversations yet.</p></div>
                        <?php endif; ?>
                    </div>
                </div><!-- /cg-sidebar -->

                <!-- Right: chat window -->
                <div class="cg-main">
                    <?php if ($chat_user_id && $chatUser): ?>
                        <div class="chat-head">
                            <div class="conv-avatar"><?= strtoupper(substr($chatUser['full_name'],0,1)) ?></div>
                            <div>
                                <div style="font-weight:700;color:var(--s800);font-size:14px;"><?= htmlspecialchars($chatUser['full_name']) ?></div>
                                <div style="font-size:11px;color:var(--st300);">Caregiver</div>
                            </div>
                        </div>
                        <div class="chat-area" id="chatArea">
                            <?php if ($conversationMessages->num_rows === 0): ?>
                                <div class="no-chat" style="min-height:unset;flex:1;">
                                    <i class="fas fa-comment-dots"></i>
                                    <p>No messages yet. Start the conversation!</p>
                                </div>
                            <?php else: while ($msg = $conversationMessages->fetch_assoc()):
                                $is_me = ($msg['sender_id'] == $admin_id); ?>
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
                                                &nbsp;<i class="fas fa-check<?= $msg['is_read'] ? '-double' : '' ?>" style="font-size:10px;"></i>
                                                &nbsp;<a href="messages.php?delete=<?= $msg['message_id'] ?>&tab=caregiver&chat=<?= $chat_user_id ?>"
                                                   onclick="return confirm('Delete this message?')"
                                                   style="color:var(--red-text);font-size:11px;text-decoration:none;opacity:.7;">
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
                                <input type="hidden" name="receiver_id"  value="<?= $chat_user_id ?>">
                                <input type="hidden" name="chat_user_id" value="<?= $chat_user_id ?>">
                                <textarea name="message_content" placeholder="Type a message… (Ctrl+Enter to send)" rows="1" required id="replyTa"></textarea>
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
function switchTab(name, btn) {
    document.getElementById('tab-contact').style.display   = name === 'contact'   ? 'block' : 'none';
    document.getElementById('tab-caregiver').style.display = name === 'caregiver' ? 'block' : 'none';
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    history.replaceState(null, '', 'messages.php?tab=' + name + <?= $chat_user_id ? "+'&chat=<?= $chat_user_id ?>'" : "''" ?>);
}
const ca = document.getElementById('chatArea');
if (ca) ca.scrollTop = ca.scrollHeight;
document.querySelectorAll('textarea').forEach(ta => {
    ta.addEventListener('input', function () { this.style.height = 'auto'; this.style.height = this.scrollHeight + 'px'; });
});
const replyTa = document.getElementById('replyTa');
if (replyTa) {
    replyTa.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { this.closest('form').submit(); }
    });
}
</script>
</body>
</html>