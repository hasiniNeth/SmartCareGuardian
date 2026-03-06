<?php
session_start();
include '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $content     = trim($_POST['message_content'] ?? '');
    if ($receiver_id > 0 && $content !== '') {
        $vr = $conn->prepare("SELECT ca.caregiver_id FROM caregiver_assignments ca JOIN users u ON u.user_id = ca.caregiver_id WHERE ca.resident_id = ? AND ca.caregiver_id = ?");
        $vr->bind_param("ii", $user_id, $receiver_id); $vr->execute();
        if ($vr->get_result()->num_rows > 0) {
            $ins = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message_content) VALUES (?,?,?)");
            $ins->bind_param("iis", $user_id, $receiver_id, $content); $ins->execute(); $ins->close();
        }
        $vr->close();
    }
    header("Location: elder_messages.php?with=" . $receiver_id); exit();
}

$open_with = (int)($_GET['with'] ?? 0);
if ($open_with > 0) {
    $mr = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
    $mr->bind_param("ii", $open_with, $user_id); $mr->execute(); $mr->close();
}

$asgn_stmt = $conn->prepare("SELECT ca.caregiver_id, u.full_name, u.role, u.email FROM caregiver_assignments ca JOIN users u ON u.user_id = ca.caregiver_id WHERE ca.resident_id = ? LIMIT 1");
$asgn_stmt->bind_param("i", $user_id); $asgn_stmt->execute();
$assigned_caregiver = $asgn_stmt->get_result()->fetch_assoc(); $asgn_stmt->close();
$assigned_cg_id = $assigned_caregiver ? (int)$assigned_caregiver['caregiver_id'] : 0;

$threads = [];
if ($assigned_cg_id > 0) {
    $ts = $conn->prepare("SELECT other_id,u.full_name AS other_name,u.role AS other_role,MAX(sent_at) AS last_at,SUBSTRING_INDEX(GROUP_CONCAT(message_content ORDER BY sent_at DESC SEPARATOR '|||'),'|||',1) AS last_content,SUM(CASE WHEN sender_id=other_id AND is_read=0 THEN 1 ELSE 0 END) AS unread_count FROM (SELECT CASE WHEN sender_id=? THEN receiver_id ELSE sender_id END AS other_id,message_content,sent_at,is_read,sender_id,receiver_id FROM messages WHERE (sender_id=? OR receiver_id=?) AND (sender_id=? OR receiver_id=?)) t JOIN users u ON u.user_id=t.other_id WHERE t.other_id=? GROUP BY other_id,u.full_name,u.role ORDER BY last_at DESC");
    $ts->bind_param("iiiiii", $user_id, $user_id, $user_id, $assigned_cg_id, $assigned_cg_id, $assigned_cg_id); $ts->execute();
    $threads = $ts->get_result()->fetch_all(MYSQLI_ASSOC); $ts->close();
}

$conversation = []; $contact_info = null;
if ($open_with > 0 && $open_with !== $assigned_cg_id) { header("Location: elder_messages.php"); exit(); }
if ($open_with > 0 && $assigned_cg_id > 0 && $open_with === $assigned_cg_id) {
    $contact_info = ['user_id'=>$assigned_caregiver['caregiver_id'],'full_name'=>$assigned_caregiver['full_name'],'role'=>$assigned_caregiver['role'],'email'=>$assigned_caregiver['email']];
    $cs = $conn->prepare("SELECT m.message_id,m.sender_id,m.receiver_id,m.message_content,m.is_read,m.sent_at,DATE_FORMAT(m.sent_at,'%b %d, %Y') AS date_fmt,DATE_FORMAT(m.sent_at,'%h:%i %p') AS time_fmt,DATE(m.sent_at) AS day_date,u.full_name AS sender_name FROM messages m JOIN users u ON u.user_id=m.sender_id WHERE (m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?) ORDER BY m.sent_at ASC");
    $cs->bind_param("iiii", $user_id, $open_with, $open_with, $user_id); $cs->execute();
    $conversation = $cs->get_result()->fetch_all(MYSQLI_ASSOC); $cs->close();
}

$staff_list   = $assigned_caregiver ? [['user_id'=>$assigned_caregiver['caregiver_id'],'full_name'=>$assigned_caregiver['full_name'],'role'=>$assigned_caregiver['role']]] : [];
$total_unread = array_sum(array_column($threads, 'unread_count'));

$ua = $conn->prepare("SELECT COUNT(*) AS cnt FROM alerts WHERE resident_id=? AND resolved=0");
$ua->bind_param("i",$user_id); $ua->execute();
$unresolved_alerts = $ua->get_result()->fetch_assoc()['cnt'] ?? 0; $ua->close();

function role_label(string $r): string { return ['caregiver'=>'Caregiver','admin'=>'Admin'][$r] ?? ucfirst($r); }
function role_color(string $r): string { return ['caregiver'=>'#1A4870','admin'=>'#5A3A7A'][$r] ?? '#4A4540'; }
function role_bg(string $r): string    { return ['caregiver'=>'#DBEEFF','admin'=>'#EDE9FE'][$r] ?? '#F2F6EF'; }
function rel_time(string $dt): string {
    $diff=time()-strtotime($dt);
    if($diff<60) return 'just now'; if($diff<3600) return floor($diff/60).'m ago';
    if($diff<86400) return floor($diff/3600).'h ago'; if($diff<604800) return floor($diff/86400).'d ago';
    return date('M j',strtotime($dt));
}
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
           Messages · Very large fonts for elderly readability
        ═══════════════════════════════════════════════════════════ */
        :root {
            --s50:#F2F6EF;--s100:#E3EDDB;--s200:#C4D9B4;
            --s300:#9DC07E;--s400:#7AA658;--s500:#5E8A40;
            --s600:#4A6E30;--s700:#365220;--s800:#243816;
            --w50:#FDFAF5;--w100:#F7F1E5;
            --st300:#B8B0A4;--st500:#7A7268;--st700:#4A4540;
            --blue-bg:#DBEEFF;--blue-text:#1A4870;
            --purple-bg:#EDE9FE;--purple-text:#5A3A7A;
            --green-bg:#DDEFD8;--green-text:#3A6830;
            --radius-sm:8px;--radius-md:14px;--radius-lg:22px;
            --shadow-soft:0 2px 12px rgba(36,56,22,.07),0 1px 3px rgba(36,56,22,.05);
            --shadow-card:0 4px 24px rgba(36,56,22,.09),0 1px 4px rgba(36,56,22,.06);
        }
        *,*::before,*::after{box-sizing:border-box;}
        body{
            font-family:'Outfit',sans-serif;
            background:var(--w50);
            background-image:
                radial-gradient(ellipse 70% 50% at 90% 0%,rgba(157,192,126,.09) 0%,transparent 55%),
                radial-gradient(ellipse 50% 40% at 0% 100%,rgba(122,166,88,.06) 0%,transparent 50%);
            color:var(--st700);font-size:18px;line-height:1.75;
            min-height:100vh;margin:0;padding:0;
        }
        h1,h2,h3,h4,h5{font-family:'Cormorant Garamond',serif;color:var(--s800);margin:0;}

        /* ── Sidebar ── */
        .sidebar{width:240px;height:100vh;position:fixed;background:var(--s800);display:flex;flex-direction:column;z-index:1000;overflow:hidden;}
        .sidebar::before{content:'';position:absolute;inset:0;pointer-events:none;background-image:radial-gradient(ellipse 120% 60% at 50% -10%,rgba(157,192,126,.18) 0%,transparent 60%),radial-gradient(ellipse 80% 80% at 110% 110%,rgba(94,138,64,.15) 0%,transparent 55%);}
        .sidebar-header{padding:26px 20px 18px;border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0;position:relative;}
        .brand-mark{display:flex;align-items:center;gap:10px;margin-bottom:5px;}
        .brand-icon{width:34px;height:34px;background:linear-gradient(135deg,var(--s300),var(--s500));border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;color:white;box-shadow:0 3px 10px rgba(0,0,0,.25);flex-shrink:0;}
        .sidebar-header h4{font-family:'Cormorant Garamond',serif;font-size:17px;font-weight:600;color:white;line-height:1.1;}
        .sidebar-header small{font-size:10px;color:rgba(255,255,255,.4);letter-spacing:.08em;text-transform:uppercase;font-weight:500;display:block;margin-left:44px;margin-top:2px;}
        .sidebar-nav{flex:1;overflow-y:auto;padding:10px 0;position:relative;}
        .sidebar-nav::-webkit-scrollbar{width:4px;}.sidebar-nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.15);border-radius:3px;}
        .sidebar a{display:flex;align-items:center;gap:10px;padding:11px 12px 11px 22px;color:rgba(255,255,255,.6);text-decoration:none;font-size:15px;font-weight:500;transition:all .2s;margin:2px 10px;border-radius:var(--radius-sm);position:relative;min-height:46px;}
        .sidebar a:hover{background:rgba(255,255,255,.10);color:white;}
        .sidebar a.active{background:rgba(157,192,126,.2);color:#C8E6A0;}
        .sidebar a.active::before{content:'';position:absolute;left:-10px;top:20%;bottom:20%;width:3px;background:var(--s300);border-radius:0 3px 3px 0;}
        .sidebar i{width:20px;text-align:center;font-size:15px;opacity:.85;}
        .sb-badge{margin-left:auto;background:#8B3A3A;color:white;font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px;}
        .sidebar-footer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:14px 10px;position:relative;}
        .sidebar-footer a{margin:0;color:rgba(255,255,255,.5)!important;font-size:15px;}
        .sidebar-footer a:hover{color:rgba(255,255,255,.8)!important;}

        /* ── Main shell ── */
        .main-content{margin-left:240px;height:100vh;display:flex;flex-direction:column;}

        /* ── Topbar ── */
        .topbar{background:white;padding:18px 28px;box-shadow:var(--shadow-card);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;flex-shrink:0;}
        .topbar h4{font-size:26px;font-weight:500;color:var(--s800);margin-bottom:3px;}
        .topbar p{font-size:15px;color:var(--st300);margin:0;}
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
        .btn-new-msg{background:linear-gradient(135deg,var(--s400),var(--s600));color:white;border:none;border-radius:20px;padding:11px 22px;font-size:15px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;white-space:nowrap;display:inline-flex;align-items:center;gap:7px;}
        .btn-new-msg:hover{opacity:.9;transform:translateY(-1px);box-shadow:var(--shadow-card);color:white;text-decoration:none;}

        /* ── Messaging shell ── */
        .msg-shell{display:flex;flex:1;min-height:0;margin:20px;gap:0;border-radius:var(--radius-lg);overflow:hidden;box-shadow:0 8px 40px rgba(36,56,22,.14);}

        /* ── Thread list ── */
        .thread-list{width:300px;flex-shrink:0;background:white;display:flex;flex-direction:column;border-right:1px solid var(--s100);}
        .thread-header{padding:18px 18px 14px;border-bottom:1px solid var(--s100);flex-shrink:0;}
        .thread-header h5{margin:0;font-size:18px;color:var(--s800);}
        .thread-list-body{flex:1;overflow-y:auto;}
        .thread-list-body::-webkit-scrollbar{width:4px;}.thread-list-body::-webkit-scrollbar-thumb{background:var(--s100);border-radius:3px;}
        .thread-item{display:flex;align-items:center;gap:12px;padding:15px 16px;cursor:pointer;border-bottom:1px solid var(--s50);transition:background .2s;text-decoration:none;color:inherit;}
        .thread-item:hover{background:var(--s50);}
        .thread-item.active{background:var(--green-bg);border-left:3px solid var(--s500);}
        .thread-item.unread{background:#FFFDF0;}
        .thread-item.active.unread{background:var(--green-bg);}
        .t-avatar{width:48px;height:48px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--s400),var(--s600));display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:white;position:relative;}
        .t-avatar.admin-av{background:linear-gradient(135deg,#7C5CBF,#5A3A7A);}
        .unread-dot{position:absolute;top:-2px;right:-2px;width:16px;height:16px;border-radius:50%;background:#8B3A3A;border:2px solid white;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:900;color:white;}
        .t-meta{flex:1;min-width:0;}
        .t-name{font-size:15px;font-weight:700;color:var(--s800);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .t-preview{font-size:13px;color:var(--st300);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:3px;}
        .thread-item.unread .t-preview{color:var(--st700);font-weight:600;}
        .t-time{font-size:12px;color:var(--st300);flex-shrink:0;text-align:right;}
        .t-role-pill{font-size:11px;font-weight:800;padding:2px 8px;border-radius:10px;}

        /* ── Conv panel ── */
        .conv-panel{flex:1;display:flex;flex-direction:column;min-width:0;background:var(--w50);}
        .conv-header{background:white;padding:16px 22px;border-bottom:1px solid var(--s100);display:flex;align-items:center;gap:14px;flex-shrink:0;}
        .conv-header-avatar{width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,var(--s400),var(--s600));display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:white;flex-shrink:0;}
        .conv-header-avatar.admin-av{background:linear-gradient(135deg,#7C5CBF,#5A3A7A);}
        .conv-contact-name{font-size:18px;font-weight:700;color:var(--s800);}

        /* ── Message area ── */
        .msg-scroll{flex:1;overflow-y:auto;padding:20px 24px;display:flex;flex-direction:column;gap:4px;}
        .msg-scroll::-webkit-scrollbar{width:5px;}.msg-scroll::-webkit-scrollbar-thumb{background:var(--s100);border-radius:3px;}
        .day-divider{text-align:center;margin:16px 0 12px;display:flex;align-items:center;gap:10px;}
        .day-divider::before,.day-divider::after{content:'';flex:1;height:1px;background:var(--s100);}
        .day-divider span{font-size:12px;color:var(--st300);font-weight:700;white-space:nowrap;}
        .bubble-row{display:flex;margin-bottom:7px;}
        .bubble-row.sent{justify-content:flex-end;}
        .bubble-row.received{justify-content:flex-start;}
        .bubble{max-width:68%;padding:13px 17px;border-radius:20px;font-size:17px;line-height:1.65;word-break:break-word;}
        .bubble.sent{background:linear-gradient(135deg,var(--s400),var(--s600));color:white;border-bottom-right-radius:5px;}
        .bubble.received{background:white;color:var(--st700);border-bottom-left-radius:5px;box-shadow:var(--shadow-soft);}
        .bubble-time{font-size:12px;margin-top:5px;text-align:right;}
        .bubble.sent .bubble-time{color:rgba(255,255,255,.65);}
        .bubble.received .bubble-time{color:var(--st300);}
        .read-tick{font-size:12px;color:rgba(255,255,255,.65);}
        .read-tick.seen{color:rgba(200,230,160,.9);}

        /* ── Compose ── */
        .compose-box{background:white;border-top:1px solid var(--s100);padding:16px 20px;flex-shrink:0;}
        .compose-form{display:flex;gap:12px;align-items:flex-end;}
        .compose-textarea{flex:1;border:2px solid var(--s100);border-radius:var(--radius-md);padding:13px 16px;font-family:'Outfit',sans-serif;font-size:17px;resize:none;outline:none;line-height:1.55;transition:border-color .2s;max-height:130px;min-height:52px;color:var(--st700);}
        .compose-textarea:focus{border-color:var(--s400);}
        .compose-textarea::placeholder{color:var(--st300);}
        .send-btn{width:52px;height:52px;border-radius:50%;border:none;background:linear-gradient(135deg,var(--s400),var(--s600));color:white;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:18px;transition:all .2s;flex-shrink:0;}
        .send-btn:hover{transform:scale(1.08);box-shadow:0 5px 16px rgba(94,138,64,.4);}
        .send-btn:disabled{opacity:.4;cursor:not-allowed;transform:none;}

        /* ── Empty states ── */
        .conv-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--st300);padding:40px;text-align:center;}
        .conv-empty i{font-size:4rem;margin-bottom:20px;color:var(--s200);animation:float 4s ease-in-out infinite;}
        .conv-empty-title{font-size:20px;font-weight:700;color:var(--s700);margin-bottom:8px;}
        .conv-empty-sub{font-size:16px;color:var(--st300);max-width:280px;margin-bottom:20px;line-height:1.6;}
        @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
        .thread-empty{text-align:center;padding:40px 20px;color:var(--st300);}
        .thread-empty i{font-size:2.8rem;margin-bottom:14px;display:block;color:var(--s200);}

        /* ── Modal ── */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;}
        .modal-overlay.show{display:flex;}
        .modal-box{background:white;border-radius:var(--radius-lg);padding:30px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(36,56,22,.2);animation:slideUp .25s ease;}
        @keyframes slideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
        .modal-title{font-family:'Cormorant Garamond',serif;font-size:24px;color:var(--s800);margin-bottom:20px;}
        .modal-label{font-size:13px;font-weight:700;color:var(--st500);margin-bottom:7px;text-transform:uppercase;letter-spacing:.06em;}
        .modal-textarea{width:100%;border:2px solid var(--s100);border-radius:var(--radius-md);padding:13px 16px;font-family:'Outfit',sans-serif;font-size:17px;resize:none;outline:none;height:110px;transition:border-color .2s;color:var(--st700);}
        .modal-textarea:focus{border-color:var(--s400);}
        .modal-send-btn{width:100%;background:linear-gradient(135deg,var(--s400),var(--s600));color:white;border:none;border-radius:var(--radius-md);padding:14px;font-weight:700;font-family:'Outfit',sans-serif;font-size:17px;cursor:pointer;transition:all .2s;margin-top:16px;}
        .modal-send-btn:hover{opacity:.9;transform:translateY(-2px);box-shadow:var(--shadow-card);}
        .modal-cancel-btn{width:100%;background:var(--s50);color:var(--st500);border:none;border-radius:var(--radius-md);padding:12px;font-weight:700;font-family:'Outfit',sans-serif;font-size:16px;cursor:pointer;transition:all .2s;margin-top:9px;}
        .modal-cancel-btn:hover{background:var(--s100);}

        @media(max-width:768px){
            .sidebar{display:none;}.main-content{margin-left:0;}
            .thread-list{width:100%;}.msg-shell{margin:10px;}
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
        <small>Resident Portal</small>
    </div>
    <div class="sidebar-nav">
        <a href="resident_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="elder_profile.php"><i class="fa-solid fa-user-pen"></i> My Profile</a>
        <a href="elder_health.php"><i class="fa-solid fa-heart-pulse"></i> My Health Data</a>
        <a href="elder_schedule.php"><i class="fa-solid fa-calendar-check"></i> Daily Schedule</a>
        <a href="elder_medications.php"><i class="fa-solid fa-pills"></i> My Medications</a>
        <a href="elder_appointments.php"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="elder_messages.php" class="active">
            <i class="fa-solid fa-comments"></i> Messages
            <?php if ($total_unread > 0): ?><span class="sb-badge"><?= $total_unread ?></span><?php endif; ?>
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

<!-- ══ MAIN CONTENT ════════════════════════════════════════════════ -->
<div class="main-content">

    <!-- Topbar -->
    <div class="topbar">
        <div>
            <h4><i class="fas fa-comments me-2" style="font-size:22px;color:var(--s500);"></i>Messages</h4>
            <p>
                <?php if ($total_unread > 0): ?>
                    <span style="color:#8B3A3A;font-weight:700;"><i class="fas fa-circle me-1" style="font-size:8px;vertical-align:middle;"></i><?= $total_unread ?> unread</span>
                <?php else: ?>
                    <i class="fas fa-check-circle me-1" style="color:var(--s500);"></i>All caught up
                <?php endif; ?>
            </p>
        </div>
        <div class="topbar-actions">
            <button class="topbar-btn" onclick="increaseFontSize()"><i class="fas fa-search-plus"></i>Larger</button>
            <button class="topbar-btn" onclick="decreaseFontSize()"><i class="fas fa-search-minus"></i>Smaller</button>
            <button class="topbar-btn" onclick="resetFontSize()"><i class="fas fa-redo"></i>Reset</button>
            <button class="topbar-btn" onclick="toggleHighContrast()"><i class="fas fa-adjust"></i>Contrast</button>
        </div>
        <button class="btn-new-msg" onclick="<?= $assigned_cg_id > 0 ? "location.href='?with=$assigned_cg_id'" : "openNoCaregiver()" ?>">
            <i class="fas fa-pen"></i>New Message
        </button>
    </div>

    <!-- Messaging shell -->
    <div class="msg-shell">

        <!-- Thread list -->
        <div class="thread-list">
            <div class="thread-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5>Conversations</h5>
                    <?php if (!empty($threads)): ?>
                        <span style="font-size:13px;color:var(--st300);"><?= count($threads) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="thread-list-body">
                <?php if (empty($threads)): ?>
                <div class="thread-empty">
                    <i class="fas fa-comments"></i>
                    <?php if ($assigned_cg_id > 0): ?>
                        <div style="font-size:16px;font-weight:600;color:var(--s700);">No messages yet</div>
                        <div style="font-size:14px;margin-top:5px;margin-bottom:16px;color:var(--st300);">Start a conversation with your caregiver</div>
                        <a href="?with=<?= $assigned_cg_id ?>" class="btn-new-msg" style="display:inline-flex;text-decoration:none;font-size:14px;padding:9px 18px;">
                            <i class="fas fa-pen"></i>Message <?= htmlspecialchars($assigned_caregiver['full_name']) ?>
                        </a>
                    <?php else: ?>
                        <div style="font-size:16px;font-weight:600;color:var(--s700);">No caregiver assigned</div>
                        <div style="font-size:14px;margin-top:5px;color:var(--st300);">Please contact the administration.</div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <?php foreach ($threads as $t):
                    $initials  = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $t['other_name']), 0, 2)));
                    $is_active = ($open_with === (int)$t['other_id']);
                    $has_unread = (int)$t['unread_count'] > 0;
                    $preview   = htmlspecialchars(mb_strimwidth($t['last_content'], 0, 50, '…'));
                ?>
                <a href="?with=<?= $t['other_id'] ?>"
                   class="thread-item <?= $is_active ? 'active' : '' ?> <?= $has_unread ? 'unread' : '' ?>">
                    <div class="t-avatar <?= $t['other_role']==='admin' ? 'admin-av' : '' ?>">
                        <?= $initials ?>
                        <?php if ($has_unread): ?><div class="unread-dot"><?= min((int)$t['unread_count'],9) ?></div><?php endif; ?>
                    </div>
                    <div class="t-meta">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="t-name"><?= htmlspecialchars($t['other_name']) ?></div>
                            <div class="t-time"><?= rel_time($t['last_at']) ?></div>
                        </div>
                        <div class="mt-1">
                            <span class="t-role-pill" style="background:<?= role_bg($t['other_role']) ?>;color:<?= role_color($t['other_role']) ?>;"><?= role_label($t['other_role']) ?></span>
                        </div>
                        <div class="t-preview"><?= $preview ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div><!-- /thread-list -->

        <!-- Conversation panel -->
        <div class="conv-panel">
            <?php if ($open_with > 0 && $contact_info): ?>

            <div class="conv-header">
                <?php $c_initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $contact_info['full_name']), 0, 2))); ?>
                <div class="conv-header-avatar <?= $contact_info['role']==='admin' ? 'admin-av' : '' ?>"><?= $c_initials ?></div>
                <div class="flex-grow-1">
                    <div class="conv-contact-name"><?= htmlspecialchars($contact_info['full_name']) ?></div>
                    <div style="font-size:13px;color:var(--st300);margin-top:2px;">
                        <span class="t-role-pill" style="background:<?= role_bg($contact_info['role']) ?>;color:<?= role_color($contact_info['role']) ?>;"><?= role_label($contact_info['role']) ?></span>
                        <?php if ($contact_info['email']): ?>&nbsp;·&nbsp;<?= htmlspecialchars($contact_info['email']) ?><?php endif; ?>
                    </div>
                </div>
                <div style="font-size:14px;color:var(--st300);"><?= count($conversation) ?> message<?= count($conversation)!==1?'s':'' ?></div>
            </div>

            <div class="msg-scroll" id="msgScroll">
                <?php if (empty($conversation)): ?>
                    <div style="text-align:center;color:var(--st300);padding:50px;font-size:17px;">
                        <i class="fas fa-comment-dots fa-2x mb-3 d-block" style="color:var(--s200);"></i>
                        No messages yet — say hello!
                    </div>
                <?php else:
                    $prev_day = null;
                    foreach ($conversation as $msg):
                        $is_sent = ((int)$msg['sender_id'] === $user_id);
                        $msg_day = $msg['day_date'];
                        if ($msg_day !== $prev_day):
                            $prev_day = $msg_day;
                            $today = date('Y-m-d'); $yesterday = date('Y-m-d',strtotime('-1 day'));
                            $dlabel = $msg_day===$today?'Today':($msg_day===$yesterday?'Yesterday':date('F j, Y',strtotime($msg_day)));
                ?>
                    <div class="day-divider"><span><?= $dlabel ?></span></div>
                <?php     endif; ?>
                    <div class="bubble-row <?= $is_sent ? 'sent' : 'received' ?>">
                        <div class="bubble <?= $is_sent ? 'sent' : 'received' ?>">
                            <?= nl2br(htmlspecialchars($msg['message_content'])) ?>
                            <div class="bubble-time">
                                <?= $msg['time_fmt'] ?>
                                <?php if ($is_sent): ?><i class="fas fa-check-double read-tick ms-1 <?= $msg['is_read'] ? 'seen' : '' ?>"></i><?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="compose-box">
                <form method="POST" action="elder_messages.php" class="compose-form" id="composeForm">
                    <input type="hidden" name="action" value="send">
                    <input type="hidden" name="receiver_id" value="<?= $open_with ?>">
                    <textarea name="message_content" id="composeTA" class="compose-textarea"
                        placeholder="Type a message…" rows="1" maxlength="2000" required></textarea>
                    <button type="submit" class="send-btn" id="sendBtn" disabled>
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            </div>

            <?php elseif ($open_with > 0 && !$contact_info): ?>
            <div class="conv-empty">
                <i class="fas fa-user-slash"></i>
                <div class="conv-empty-title">Contact not found</div>
                <a href="elder_messages.php" style="color:var(--s500);font-size:16px;font-weight:700;">← Back to messages</a>
            </div>

            <?php else: ?>
            <div class="conv-empty">
                <i class="fas fa-comments"></i>
                <div class="conv-empty-title">Your Messages</div>
                <?php if ($assigned_cg_id > 0): ?>
                <div class="conv-empty-sub">You can message your assigned caregiver, <strong><?= htmlspecialchars($assigned_caregiver['full_name']) ?></strong>, directly.</div>
                <a href="?with=<?= $assigned_cg_id ?>" class="btn-new-msg" style="text-decoration:none;">
                    <i class="fas fa-comment"></i>Open Conversation
                </a>
                <?php else: ?>
                <div class="conv-empty-sub">You haven't been assigned a caregiver yet. Please contact the administration.</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div><!-- /conv-panel -->
    </div><!-- /msg-shell -->
</div><!-- /main-content -->

<?php if ($assigned_cg_id > 0): ?>
<div class="modal-overlay" id="newMsgModal">
    <div class="modal-box">
        <div class="modal-title"><i class="fas fa-pen me-2" style="color:var(--s500);"></i>New Message</div>
        <form method="POST" action="elder_messages.php">
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="receiver_id" value="<?= $assigned_cg_id ?>">
            <div class="mb-4">
                <div class="modal-label">To</div>
                <div style="padding:13px 16px;background:var(--s50);border-radius:var(--radius-md);border:2px solid var(--s100);display:flex;align-items:center;gap:12px;">
                    <?php $cg_initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $assigned_caregiver['full_name']), 0, 2))); ?>
                    <div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--s400),var(--s600));display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:white;flex-shrink:0;"><?= $cg_initials ?></div>
                    <div>
                        <div style="font-weight:700;color:var(--s800);font-size:16px;"><?= htmlspecialchars($assigned_caregiver['full_name']) ?></div>
                        <div style="font-size:13px;color:var(--st300);">Your assigned caregiver</div>
                    </div>
                </div>
            </div>
            <div class="mb-2">
                <div class="modal-label">Message</div>
                <textarea name="message_content" class="modal-textarea" placeholder="Write your message…" required maxlength="2000"></textarea>
            </div>
            <button type="submit" class="modal-send-btn"><i class="fas fa-paper-plane me-2"></i>Send Message</button>
            <button type="button" class="modal-cancel-btn" onclick="closeModal()">Cancel</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
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
    const msgScroll = document.getElementById('msgScroll');
    if (msgScroll) msgScroll.scrollTop = msgScroll.scrollHeight;
    const ta = document.getElementById('composeTA'), btn = document.getElementById('sendBtn');
    if (ta && btn) {
        ta.addEventListener('input', () => {
            btn.disabled = ta.value.trim() === '';
            ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 130) + 'px';
        });
        ta.addEventListener('keydown', e => { if (e.key==='Enter' && !e.shiftKey) { e.preventDefault(); if(ta.value.trim()!=='') document.getElementById('composeForm').submit(); } });
    }
    const modal = document.getElementById('newMsgModal');
    function openModal()  { if (modal) modal.classList.add('show'); }
    function closeModal() { if (modal) modal.classList.remove('show'); }
    function openNoCaregiver() { alert('You have not been assigned a caregiver yet. Please contact the administration.'); }
    if (modal) {
        modal.addEventListener('click', e => { if (e.target===modal) closeModal(); });
        document.addEventListener('keydown', e => { if (e.key==='Escape') closeModal(); });
    }
    document.addEventListener('DOMContentLoaded', function() {
        document.documentElement.style.fontSize = sz + 'px';
        if (localStorage.getItem('elderHighContrast') === 'true')
            document.body.classList.add('high-contrast');
    });
})();
</script>
</body>
</html>