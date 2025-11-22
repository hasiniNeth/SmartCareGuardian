<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

include 'db_connection.php';

// Fetch all messages
$messages = $conn->query("SELECT * FROM contact_messages ORDER BY created_at DESC");
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
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

/* Table Styling */
.table {
    margin: 0;
    background: transparent;
}

.table thead {
    background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
    color: white;
}

.table thead th {
    border: none;
    padding: 20px 15px;
    font-weight: 600;
    font-family: 'Jost', sans-serif;
}

.table tbody tr {
    transition: all 0.3s ease;
    border-bottom: 1px solid rgba(0,0,0,0.05);
}

.table tbody tr:hover {
    background: var(--light-sage);
    transform: translateY(-1px);
}

.table tbody td {
    padding: 18px 15px;
    vertical-align: middle;
    border: none;
    color: var(--deep-emerald);
}

/* Message Text */
.message-text {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    cursor: pointer;
}

.message-text.expanded {
    white-space: normal;
    text-overflow: unset;
    max-width: none;
}

/* Reply Form */
.reply-form {
    background: var(--light-sage);
    border-radius: 12px;
    padding: 15px;
    margin-top: 10px;
    border-left: 4px solid var(--sage-green);
}

.reply-textarea {
    width: 100%;
    border: 2px solid var(--forest-mist);
    border-radius: 10px;
    padding: 12px;
    font-family: 'Quicksand', sans-serif;
    resize: vertical;
    min-height: 80px;
    transition: all 0.3s ease;
}

.reply-textarea:focus {
    border-color: var(--sage-green);
    box-shadow: 0 0 0 0.2rem rgba(135, 169, 107, 0.25);
    outline: none;
}

.reply-btn {
    background: linear-gradient(135deg, var(--sage-green), var(--dusty-teal));
    border: none;
    border-radius: 10px;
    color: white;
    padding: 10px 20px;
    font-weight: 600;
    transition: all 0.3s ease;
    margin-top: 10px;
}

.reply-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(141, 182, 154, 0.4);
}

/* Badges */
.badge {
    padding: 8px 12px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.75rem;
}

.badge-unreplied {
    background: linear-gradient(135deg, #ff6b6b, #ee5a52) !important;
    color: white;
}

.badge-replied {
    background: linear-gradient(135deg, var(--seafoam), var(--sage-green)) !important;
    color: white;
}

/* User Info */
.user-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.user-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--forest-mist), var(--dusty-teal));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
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
    }
    
    .table-responsive {
        font-size: 0.9rem;
    }
}

/* Custom scrollbar */
.sidebar-nav::-webkit-scrollbar {
    width: 6px;
}

.sidebar-nav::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
    border-radius: 3px;
}

.sidebar-nav::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 3px;
}

.sidebar-nav::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.5);
}
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
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i> Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i> Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i> Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i> Manage Services</a>
        <a href="#" class="active"><i class="fa-solid fa-envelope"></i> Messages</a>
        <a href="view_alerts.php"><i class="fa-solid fa-bell"></i> Alerts</a>
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
            <h4 class="brand-font mb-1">Contact Messages</h4>
            <p class="text-muted mb-0">Manage and reply to customer inquiries</p>
        </div>
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn">
                <i class="fa-solid fa-sign-out-alt me-2"></i>Logout
            </button>
        </form>
    </div>

    <div class="messages-container">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Contact Info</th>
                        <th>Message</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Reply</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $messages_result = mysqli_query($conn, "SELECT * FROM contact_messages WHERE reply_message IS NULL");
                    while ($message = mysqli_fetch_assoc($messages_result)) { ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar">
                                        <?php echo strtoupper(substr($message['fullname'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <strong><?= htmlspecialchars($message['fullname']) ?></strong>
                                        <div class="text-muted small"><?= htmlspecialchars($message['email']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($message['phone']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="message-text" onclick="this.classList.toggle('expanded')">
                                    <?= htmlspecialchars($message['message']) ?>
                                </div>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= date('M j, Y g:i A', strtotime($message['created_at'])) ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge badge-unreplied">
                                    <i class="fas fa-clock me-1"></i>Pending Reply
                                </span>
                            </td>
                            <td>
                                <form method="POST" action="reply_message.php" class="reply-form">
                                    <input type="hidden" name="message_id" value="<?= $message['id'] ?>">
                                    <textarea name="reply" class="reply-textarea" placeholder="Type your reply here..." required></textarea>
                                    <button type="submit" class="reply-btn">
                                        <i class="fas fa-paper-plane me-2"></i>Send Reply
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                    
                    <?php
                    // Show replied messages too
                    $replied_messages = mysqli_query($conn, "SELECT * FROM contact_messages WHERE reply_message IS NOT NULL ORDER BY created_at DESC");
                    while ($message = mysqli_fetch_assoc($replied_messages)) { ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar">
                                        <?php echo strtoupper(substr($message['fullname'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <strong><?= htmlspecialchars($message['fullname']) ?></strong>
                                        <div class="text-muted small"><?= htmlspecialchars($message['email']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($message['phone']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="message-text" onclick="this.classList.toggle('expanded')">
                                    <?= htmlspecialchars($message['message']) ?>
                                </div>
                                <div class="mt-2 p-2 bg-light rounded">
                                    <strong class="text-success">Reply:</strong>
                                    <div><?= htmlspecialchars($message['reply_message']) ?></div>
                                </div>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= date('M j, Y g:i A', strtotime($message['created_at'])) ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge badge-replied">
                                    <i class="fas fa-check-circle me-1"></i>Replied
                                </span>
                            </td>
                            <td>
                                <span class="text-muted small">Replied on <?= date('M j, Y', strtotime($message['replied_at'])) ?></span>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Auto-expand textarea as user types
document.querySelectorAll('.reply-textarea').forEach(textarea => {
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
});

// Show confirmation before sending reply
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const textarea = this.querySelector('textarea');
        if (textarea.value.trim() === '') {
            e.preventDefault();
            alert('Please enter a reply message.');
            return;
        }
        
        if (!confirm('Are you sure you want to send this reply?')) {
            e.preventDefault();
        }
    });
});
</script>

</body>
</html>