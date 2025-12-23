<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php");
    exit();
}

include '../db_connection.php';

// Get caregiver info
$caregiver_id = $_SESSION['user_id'];
$chat_user_id = isset($_GET['chat']) ? (int)$_GET['chat'] : null;
$caregiver_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$caregiver_stmt->bind_param("i", $caregiver_id);
$caregiver_stmt->execute();
$caregiver_result = $caregiver_stmt->get_result();
$caregiver = $caregiver_result->fetch_assoc();

// Get assigned elders for message recipients
// Get assigned residents + admin for message recipients
$recipients_stmt = $conn->prepare("
    SELECT u.user_id, u.full_name, u.email, u.role
    FROM users u
    WHERE 
        u.role = 'admin'
        OR u.user_id IN (
            SELECT ca.resident_id
            FROM caregiver_assignments ca
            WHERE ca.caregiver_id = ?
        )
    ORDER BY u.role DESC, u.full_name ASC
");
$recipients_stmt->bind_param("i", $caregiver_id);
$recipients_stmt->execute();
$recipients = $recipients_stmt->get_result();

$conversationMessages = null;
$chatUser = null;

if ($chat_user_id) {
    // Get chat user info
    $u = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
    $u->bind_param("i", $chat_user_id);
    $u->execute();
    $chatUser = $u->get_result()->fetch_assoc();
    $u->close();

    // Get full conversation
    $c = $conn->prepare("
        SELECT m.*, 
               s.full_name AS sender_name,
               r.full_name AS receiver_name
        FROM messages m
        JOIN users s ON m.sender_id = s.user_id
        JOIN users r ON m.receiver_id = r.user_id
        WHERE 
            (m.sender_id = ? AND m.receiver_id = ?)
            OR
            (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.sent_at ASC
    ");
    $c->bind_param("iiii", $caregiver_id, $chat_user_id, $chat_user_id, $caregiver_id);
    $c->execute();
    $conversationMessages = $c->get_result();
    $c->close();
}

// Get messages sent by caregiver
$sent_messages_stmt = $conn->prepare("
    SELECT m.*, u.full_name as recipient_name 
    FROM messages m 
    JOIN users u ON m.receiver_id = u.user_id 
    WHERE m.sender_id = ? 
    ORDER BY m.sent_at DESC
");
$sent_messages_stmt->bind_param("i", $caregiver_id);
$sent_messages_stmt->execute();
$sent_messages = $sent_messages_stmt->get_result();

// Get messages received by caregiver
$received_messages_stmt = $conn->prepare("
    SELECT m.*, u.full_name as sender_name 
    FROM messages m 
    JOIN users u ON m.sender_id = u.user_id 
    WHERE m.receiver_id = ? 
    ORDER BY m.sent_at DESC
");
$received_messages_stmt->bind_param("i", $caregiver_id);
$received_messages_stmt->execute();
$received_messages = $received_messages_stmt->get_result();

// Handle sending new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiver_id = $_POST['receiver_id'];
    $message_content = trim($_POST['message_content']);
    
    if (!empty($message_content)) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_content, sent_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $caregiver_id, $receiver_id, $message_content);
        
        if ($stmt->execute()) {
            header("Location: caregiver_messages.php?sent=1");
            exit();
        } else {
            $error = "Failed to send message. Please try again.";
        }
    } else {
        $error = "Please enter a message.";
    }
}

if (isset($_GET['sent'])) {
    $success = "Message sent successfully!";
}

// Handle message deletion
if (isset($_GET['delete'])) {
    $message_id = intval($_GET['delete']);
    $delete_stmt = $conn->prepare("DELETE FROM messages WHERE message_id = ? AND sender_id = ?");
    $delete_stmt->bind_param("ii", $message_id, $caregiver_id);
    
    if ($delete_stmt->execute()) {
        $success = "Message deleted successfully!";
        header("Location: caregiver_messages.php?success=1");
        exit();
    }
}
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
            --sage-green: #87A96B;
            --mint-cream: #F0FFF0;
            --seafoam: #9FE2BF;
            --forest-mist: #B8E0D2;
            --dusty-teal: #6D9B8E;
            --deep-emerald: #4A766E;
            --light-sage: #E8F5E8;
        }
        
        body {
            font-family: 'Quicksand', sans-serif;
            background: linear-gradient(135deg, var(--mint-cream) 0%, var(--forest-mist) 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        h1, h2, h3, h4, h5 {
            font-family: 'Playfair Display', serif;
            color: var(--deep-emerald);
        }
        
        .brand-font {
            font-family: 'Jost', sans-serif;
            font-weight: 600;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            height: 100vh;
            position: fixed;
            background: linear-gradient(180deg, var(--sage-green) 0%, var(--dusty-teal) 100%);
            color: white;
            box-shadow: 4px 0 20px rgba(0,0,0,0.1);
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-header {
            text-align: center;
            padding: 30px 20px 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            flex-shrink: 0;
        }
        
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 20px 0;
        }
        
        .sidebar-footer {
            flex-shrink: 0;
            border-top: 1px solid rgba(255,255,255,0.2);
            padding: 20px;
        }
        
        .sidebar a {
            color: white;
            display: flex;
            align-items: center;
            padding: 15px 25px;
            text-decoration: none;
            transition: all 0.3s ease;
            margin: 5px 15px;
            border-radius: 12px;
            font-weight: 500;
        }
        
        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }
        
        .sidebar a.active {
            background: rgba(255, 255, 255, 0.25);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .sidebar i {
            width: 25px;
            margin-right: 12px;
            font-size: 1.1rem;
        }

        /* Main Content */
        .content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
        }
        
        .topbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 20px 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .logout-btn {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            border: none;
            border-radius: 50px;
            color: white;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
        }

        /* Messages Container */
        .messages-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .section-header {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            color: white;
            padding: 20px 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .section-body {
            padding: 25px 30px;
        }

        /* Compose Form */
        .compose-form {
            background: var(--light-sage);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .form-control {
            border: 2px solid var(--forest-mist);
            border-radius: 12px;
            padding: 12px 15px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .form-control:focus {
            border-color: var(--sage-green);
            box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
            transform: translateY(-2px);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
            border: none;
            padding: 12px 25px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(141, 182, 154, 0.4);
        }

        /* Messages List */
        .message-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .message-item {
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 20px 0;
            transition: all 0.3s ease;
        }
        
        .message-item:last-child {
            border-bottom: none;
        }
        
        .message-item:hover {
            background: var(--light-sage);
            padding-left: 15px;
            padding-right: 15px;
            margin: 0 -15px;
            border-radius: 10px;
        }
        
        .message-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            margin-right: 15px;
        }
        
        .avatar-sent { background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal)); color: white; }
        .avatar-received { background: linear-gradient(135deg, var(--seafoam), var(--sage-green)); color: white; }
        
        .message-text {
            background: white;
            border-radius: 15px;
            padding: 15px;
            border: 1px solid var(--forest-mist);
            position: relative;
        }
        
        .message-text.sent {
            background: linear-gradient(135deg, var(--light-sage), #ffffff);
            border-left: 4px solid var(--sage-green);
        }
        
        .message-text.received {
            background: linear-gradient(135deg, #ffffff, var(--light-sage));
            border-left: 4px solid var(--dusty-teal);
        }
        
        .message-time {
            font-size: 0.8rem;
            color: var(--dusty-teal);
            margin-top: 5px;
        }
        
        .message-actions {
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .message-item:hover .message-actions {
            opacity: 1;
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #ff6b6b, #ee5a52);
            border: none;
            border-radius: 8px;
            color: white;
            padding: 5px 10px;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }
        
        .btn-delete:hover {
            transform: scale(1.1);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--dusty-teal);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Tabs */
        .nav-tabs {
            border-bottom: 2px solid var(--forest-mist);
            margin-bottom: 20px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: var(--dusty-teal);
            font-weight: 600;
            padding: 12px 25px;
            margin-right: 10px;
            border-radius: 10px 10px 0 0;
            transition: all 0.3s ease;
        }
        
        .nav-tabs .nav-link:hover {
            background: rgba(135, 169, 107, 0.1);
        }
        
        .nav-tabs .nav-link.active {
            background: var(--sage-green);
            color: white;
            border: none;
        }

        /* Conversation View */
        .conversation-container {
            max-height: 500px;
            overflow-y: auto;
            padding: 20px;
            background: var(--light-sage);
            border-radius: 15px;
            margin-bottom: 20px;
        }
        
        .conversation-message {
            margin-bottom: 20px;
            clear: both;
        }
        
        .message-from-you {
            float: right;
            text-align: right;
            max-width: 70%;
        }
        
        .message-from-them {
            float: left;
            text-align: left;
            max-width: 70%;
        }
        
        .conversation-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .you-avatar { background: var(--sage-green); color: white; }
        .them-avatar { background: var(--dusty-teal); color: white; }

        /* Badges */
        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.7rem;
        }
        
        .badge-success {
            background: linear-gradient(135deg, var(--seafoam), var(--sage-green)) !important;
            color: white;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            
            .content {
                margin-left: 0;
                padding: 20px;
            }
            
            .message-item {
                padding: 15px 0;
            }
        }

        /* Custom scrollbar */
        .sidebar-nav::-webkit-scrollbar,
        .message-list::-webkit-scrollbar,
        .conversation-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar-nav::-webkit-scrollbar-track,
        .message-list::-webkit-scrollbar-track,
        .conversation-container::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
        }
        
        .sidebar-nav::-webkit-scrollbar-thumb,
        .message-list::-webkit-scrollbar-thumb,
        .conversation-container::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 3px;
        }
        
        .sidebar-nav::-webkit-scrollbar-thumb:hover,
        .message-list::-webkit-scrollbar-thumb:hover,
        .conversation-container::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.5);
        }
        
        /* Clear floats for conversation */
        .conversation-container::after {
            content: "";
            display: table;
            clear: both;
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <div class="sidebar-header">
        <h4 class="brand-font mb-2"><i class="fa-solid fa-shield-heart me-2"></i>SmartCare Guardian</h4>
        <small class="opacity-75">Caregiver Panel</small>
    </div>
    
    <div class="sidebar-nav">
        <a href="caregiver_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="caregiver_elders.php"><i class="fa-solid fa-user-group"></i> My Residents</a>
        <a href="log_health.php"><i class="fa-solid fa-heart-pulse"></i> Log Health Data</a>
        <a href="manage_routines.php"><i class="fa-solid fa-calendar-check"></i> Manage Routines</a>
        <a href="caregiver_appointments.php"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="#" class="active"><i class="fa-solid fa-comments"></i> Messages</a>
        <a href="caregiver_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="caregiver_reports.php"><i class="fa-solid fa-chart-line"></i> Health Reports</a>
    </div>
    
    <div class="sidebar-footer">
        <a href="logout.php" class="logout-sidebar">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>
</div>

<!-- Main Content -->
<div class="content">
    <div class="topbar d-flex justify-content-between align-items-center">
        <div>
            <h4 class="brand-font mb-1">Messages</h4>
            <p class="text-muted mb-0">Communicate with your assigned residents</p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-sign-out-alt me-2"></i>Logout
            </button>
        </form>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius: 15px; border: none; background: linear-gradient(135deg, var(--seafoam), var(--sage-green)); color: white;">
            <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius: 15px; border: none; background: linear-gradient(135deg, #ff6b6b, #ee5a52); color: white;">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Compose New Message -->
    <div class="messages-container">
        <div class="section-header">
            <h5 class="brand-font mb-0"><i class="fas fa-pen me-2"></i>Compose New Message</h5>
        </div>
        <div class="section-body">
            <form method="POST" class="compose-form">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-bold" style="color: var(--deep-emerald);">Send To:</label>
                        <select name="receiver_id" class="form-control" required>
                            <option value="">Select Recipient</option>
                            <?php while ($r = $recipients->fetch_assoc()): ?>
                                <option value="<?= $r['user_id']; ?>"
                                    <?= (isset($_GET['reply_to']) && $_GET['reply_to'] == $r['user_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['full_name']); ?>
                                    <?= $r['role'] === 'admin' ? ' (Admin)' : ' (Resident)' ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold" style="color: var(--deep-emerald);">Message:</label>
                        <textarea name="message_content" class="form-control" rows="2" placeholder="Type your message here..." required></textarea>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="send_message" class="btn btn-primary w-100">
                            <i class="fas fa-paper-plane me-2"></i>Send
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($chat_user_id && $conversationMessages): ?>
    <div class="messages-container mb-4">
        <div class="section-header">
            <h5 class="brand-font mb-0">
                <i class="fas fa-comments me-2"></i>
                Conversation with <?= htmlspecialchars($chatUser['full_name']) ?>
            </h5>
        </div>
        <div class="section-body">
            <div class="conversation-container">
                <?php while ($m = $conversationMessages->fetch_assoc()): ?>
                    <?php $is_you = $m['sender_id'] == $caregiver_id; ?>
                    <div class="conversation-message <?= $is_you ? 'message-from-you' : 'message-from-them' ?>">
                        <div class="conversation-avatar <?= $is_you ? 'you-avatar' : 'them-avatar' ?>">
                            <?= strtoupper(substr($is_you ? 'You' : $m['sender_name'], 0, 1)) ?>
                        </div>
                        <div class="message-text <?= $is_you ? 'sent' : 'received' ?>" style="border-radius: 18px;">
                            <?= nl2br(htmlspecialchars($m['message_content'])) ?>
                            <div class="message-time">
                                <?= date('M j, g:i A', strtotime($m['sent_at'])) ?>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>

            <!-- Quick reply -->
            <form method="POST" class="mt-3">
                <input type="hidden" name="receiver_id" value="<?= $chat_user_id ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-md-10">
                        <textarea name="message_content" class="form-control" rows="2" placeholder="Type your reply..." required></textarea>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="send_message" class="btn btn-primary w-100">
                            <i class="fas fa-paper-plane"></i> Send
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Messages Tabs -->
    <div class="messages-container">
        <div class="section-header">
            <h5 class="brand-font mb-0"><i class="fas fa-inbox me-2"></i>Your Messages</h5>
        </div>
        <div class="section-body">
            <ul class="nav nav-tabs" id="messagesTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="inbox-tab" data-bs-toggle="tab" data-bs-target="#inbox" type="button" role="tab">
                        <i class="fas fa-inbox me-2"></i>Inbox
                        <?php if ($received_messages->num_rows > 0): ?>
                            <span class="badge badge-success ms-2"><?php echo $received_messages->num_rows; ?></span>
                        <?php endif; ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="sent-tab" data-bs-toggle="tab" data-bs-target="#sent" type="button" role="tab">
                        <i class="fas fa-paper-plane me-2"></i>Sent
                        <?php if ($sent_messages->num_rows > 0): ?>
                            <span class="badge badge-success ms-2"><?php echo $sent_messages->num_rows; ?></span>
                        <?php endif; ?>
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="messagesTabContent">
                <!-- Inbox -->
                <div class="tab-pane fade show active" id="inbox" role="tabpanel">
                    <div class="message-list">
                        <?php if ($received_messages->num_rows > 0): ?>
                            <?php while ($message = $received_messages->fetch_assoc()): ?>
                                <div class="message-item">
                                    <div class="d-flex">
                                        <div class="message-avatar avatar-received">
                                            <?php echo strtoupper(substr($message['sender_name'], 0, 1)); ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="message-content">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <h6 class="brand-font mb-0"><?php echo htmlspecialchars($message['sender_name']); ?></h6>
                                                    <div class="message-time">
                                                        <?php echo date('M j, g:i A', strtotime($message['sent_at'])); ?>
                                                    </div>
                                                </div>
                                                <div class="message-text received">
                                                    <?php echo nl2br(htmlspecialchars($message['message_content'])); ?>
                                                </div>
                                                <div class="message-actions mt-2">
                                                    <a href="?reply_to=<?php echo $message['sender_id']; ?>&name=<?php echo urlencode($message['sender_name']); ?>"
                                                        class="btn btn-sm btn-outline-success me-2">
                                                        <i class="fas fa-reply me-1"></i>Reply
                                                    </a>
                                                    <a href="caregiver_messages.php?chat=<?= $message['sender_id'] ?>"
                                                        class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-comments me-1"></i>Conversation
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <h5 class="brand-font">No Messages</h5>
                                <p>You haven't received any messages yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Sent Messages -->
                <div class="tab-pane fade" id="sent" role="tabpanel">
                    <div class="message-list">
                        <?php if ($sent_messages->num_rows > 0): ?>
                            <?php while ($message = $sent_messages->fetch_assoc()): ?>
                                <div class="message-item">
                                    <div class="d-flex">
                                        <div class="flex-grow-1">
                                            <div class="message-content">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <h6 class="brand-font mb-0">
                                                        To: <?php echo htmlspecialchars($message['recipient_name']); ?>
                                                    </h6>
                                                    <div class="d-flex align-items-center">
                                                        <div class="message-time me-3">
                                                            <?php echo date('M j, g:i A', strtotime($message['sent_at'])); ?>
                                                        </div>
                                                        <div class="message-actions">
                                                            <a href="caregiver_messages.php?delete=<?php echo $message['message_id']; ?>" 
                                                               onclick="return confirm('Delete this message?');" 
                                                               class="btn-delete" title="Delete">
                                                                <i class="fas fa-trash"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="message-text sent">
                                                    <?php echo nl2br(htmlspecialchars($message['message_content'])); ?>
                                                </div>
                                                <div class="mt-2">
                                                    <span class="badge badge-success me-2">Delivered</span>
                                                    <a href="caregiver_messages.php?chat=<?= $message['receiver_id'] ?>"
                                                        class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-comments me-1"></i>Conversation
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="message-avatar avatar-sent ms-3">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-paper-plane"></i>
                                <h5 class="brand-font">No Sent Messages</h5>
                                <p>You haven't sent any messages yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Auto-expand textarea as user types
    document.querySelectorAll('textarea').forEach(textarea => {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    });
    
    // Show confirmation before deleting message
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this message?')) {
                e.preventDefault();
            }
        });
    });
    
    // Auto-scroll to bottom of conversation
    const conversationContainer = document.querySelector('.conversation-container');
    if (conversationContainer) {
        conversationContainer.scrollTop = conversationContainer.scrollHeight;
    }
</script>
</body>
</html>