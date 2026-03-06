<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'caregiver') {
    header("Location: login.php");
    exit();
}

include '../db_connection.php';

$caregiver_id = $_SESSION['user_id'];
$caregiver_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
$caregiver_stmt->bind_param("i", $caregiver_id);
$caregiver_stmt->execute();
$caregiver_result = $caregiver_stmt->get_result();
$caregiver = $caregiver_result->fetch_assoc();

$assigned_residents_stmt = $conn->prepare("
    SELECT 
        u.user_id, u.full_name, u.email, u.status,
        r.phone, r.gender, r.dob, r.emergency_contact,
        r.medical_conditions, r.blood_type, r.primary_physician
    FROM users u 
    LEFT JOIN residents r ON u.user_id = r.user_id
    JOIN caregiver_assignments ca ON u.user_id = ca.resident_id 
    WHERE ca.caregiver_id = ? AND u.role = 'resident'
    ORDER BY u.full_name ASC
");
$assigned_residents_stmt->bind_param("i", $caregiver_id);
$assigned_residents_stmt->execute();
$assigned_residents = $assigned_residents_stmt->get_result();

$health_alerts_stmt = $conn->prepare("SELECT resident_id, COUNT(*) as alert_count FROM alerts WHERE resolved = 0 GROUP BY resident_id");
$health_alerts_stmt->execute();
$alerts_result = $health_alerts_stmt->get_result();
$alerts_count = [];
while ($alert = $alerts_result->fetch_assoc()) {
    $alerts_count[$alert['resident_id']] = $alert['alert_count'];
}

$med_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM medications m JOIN caregiver_assignments ca ON ca.resident_id = m.resident_id WHERE ca.caregiver_id = ? AND m.taken = 0 AND m.medication_date = CURDATE()");
$med_stmt->bind_param("i", $caregiver_id);
$med_stmt->execute();
$pending_meds = $med_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$med_stmt->close();

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
    <title>My Residents – SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════
           SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
           Caregiver Residents · Professional scale
        ═══════════════════════════════════════════════════════════ */
        :root {
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
        .sb-badge,.msg-badge{margin-left:auto;background:#8B3A3A;color:white;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px;}
        .msg-badge{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:#8B3A3A;border-radius:50%;min-width:18px;height:18px;font-size:.65rem;display:flex;align-items:center;justify-content:center;padding:0 3px;}
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

        /* ── Residents grid ── */
        .residents-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:20px;margin-bottom:24px;}

        /* ── Resident card ── */
        .resident-card{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);overflow:hidden;transition:all .25s;opacity:0;transform:translateY(16px);}
        .resident-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lift);}
        .resident-card.visible{opacity:1;transform:translateY(0);}

        /* Card header */
        .resident-header{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:22px 22px 20px;position:relative;overflow:hidden;}
        .resident-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 80% 80% at 110% 20%,rgba(157,192,126,.2) 0%,transparent 55%);pointer-events:none;}
        .resident-avatar{width:66px;height:66px;background:rgba(255,255,255,.18);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.7rem;font-weight:800;margin-bottom:12px;border:2px solid rgba(255,255,255,.3);position:relative;}
        .alert-badge{position:absolute;top:18px;right:18px;background:linear-gradient(135deg,#C87A7A,#8B3A3A);color:white;border-radius:20px;padding:4px 10px;font-size:11px;font-weight:700;display:flex;align-items:center;gap:5px;position:absolute;}
        .resident-header h5{font-family:'Outfit',sans-serif;font-size:15px;font-weight:700;color:white;margin-bottom:8px;position:relative;}
        .header-meta{display:flex;justify-content:space-between;align-items:center;position:relative;}
        .status-pill{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;}
        .status-active{background:rgba(157,192,126,.25);color:#C8E6A0;}
        .status-inactive{background:rgba(255,255,255,.15);color:rgba(255,255,255,.7);}
        .header-email{font-size:11px;color:rgba(255,255,255,.55);display:flex;align-items:center;gap:4px;max-width:55%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

        /* Card body */
        .resident-body{padding:20px 22px;}
        .info-table{width:100%;margin-bottom:14px;}
        .info-table tr{border-bottom:1px solid var(--s50);}
        .info-table tr:last-child{border-bottom:none;}
        .info-table td{padding:7px 0;font-size:13px;vertical-align:top;}
        .info-label{font-weight:700;color:var(--s700);width:48%;padding-right:8px;}
        .info-value{color:var(--st500);}

        /* Medical conditions box */
        .medical-box{background:var(--s50);border-radius:var(--radius-md);padding:12px 14px;margin-bottom:16px;border-left:3px solid var(--s400);}
        .medical-title{font-size:12px;font-weight:700;color:var(--s700);text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;display:flex;align-items:center;gap:6px;}
        .medical-text{font-size:13px;color:var(--st500);line-height:1.5;}

        /* Action buttons */
        .action-buttons{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;}
        .btn-sage{background:linear-gradient(135deg,var(--s400),var(--s600));color:white;border:none;border-radius:var(--radius-md);padding:9px 14px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;text-align:center;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;}
        .btn-sage:hover{opacity:.9;transform:translateY(-1px);color:white;box-shadow:var(--shadow-soft);}
        .btn-outline-sage{background:transparent;border:2px solid var(--s200);border-radius:var(--radius-md);color:var(--s600);padding:9px 14px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;text-align:center;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;}
        .btn-outline-sage:hover{background:var(--s50);border-color:var(--s300);color:var(--s700);}

        /* ── Empty state ── */
        .empty-state{text-align:center;padding:60px 30px;background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);}
        .empty-icon{font-size:3.5rem;color:var(--s200);margin-bottom:16px;}

        @media(max-width:768px){
            .sidebar{width:100%;height:auto;position:relative;}
            .content{margin-left:0;padding:14px;}
            .residents-grid{grid-template-columns:1fr;}
            .action-buttons{grid-template-columns:1fr;}
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
        <a href="caregiver_residents.php" class="active"><i class="fa-solid fa-user-group"></i> My Residents</a>
        <a href="log_health.php"><i class="fa-solid fa-heart-pulse"></i> Log Health Data</a>
        <a href="manage_routines.php"><i class="fa-solid fa-calendar-check"></i> Manage Routines</a>
        <a href="manage_medications.php">
            <i class="fa-solid fa-pills"></i> Medications
            <?php if ($pending_meds > 0): ?><span class="sb-badge"><?= $pending_meds ?></span><?php endif; ?>
        </a>
        <a href="caregiver_appointments.php"><i class="fa-solid fa-calendar-days"></i> Appointments</a>
        <a href="caregiver_messages.php">
            <i class="fa-solid fa-comments"></i> Messages
            <?php if ($unread_messages > 0): ?><span class="msg-badge"><?= $unread_messages ?></span><?php endif; ?>
        </a>
        <a href="caregiver_ai_alerts.php"><i class="fa-solid fa-bell"></i> Health Alerts</a>
        <a href="ai_suggestions.php"><i class="fa-solid fa-wand-magic-sparkles"></i> Routine Suggestions</a>
        <a href="caregiver_reports.php"><i class="fa-solid fa-chart-line"></i> Health Reports</a>
    </div>
    <div class="sidebar-footer">
        <a href="/SmartCareGuardian/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
</div>

<!-- ══ MAIN CONTENT ════════════════════════════════════════════════ -->
<div class="content">

    <div class="topbar">
        <div>
            <h4><i class="fas fa-user-group me-2" style="font-size:18px;color:var(--s500);"></i>My Residents</h4>
            <p>Manage and view all your assigned residents</p>
        </div>
        <form action="/SmartCareGuardian/logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt"></i>Logout</button>
        </form>
    </div>

    <?php if ($assigned_residents->num_rows > 0): ?>
        <div class="residents-grid">
            <?php while ($resident = $assigned_residents->fetch_assoc()):
                $alert_count = $alerts_count[$resident['user_id']] ?? 0;
                $age = $resident['dob'] ? floor((time() - strtotime($resident['dob'])) / 31556926) : 'Not set';
            ?>
            <div class="resident-card">
                <div class="resident-header">
                    <?php if ($alert_count > 0): ?>
                        <div class="alert-badge">
                            <i class="fas fa-exclamation-triangle"></i><?= $alert_count ?> Alert<?= $alert_count > 1 ? 's' : '' ?>
                        </div>
                    <?php endif; ?>

                    <div class="resident-avatar"><?= strtoupper(substr($resident['full_name'],0,1)) ?></div>
                    <h5><?= htmlspecialchars($resident['full_name']) ?></h5>
                    <div class="header-meta">
                        <span class="status-pill <?= $resident['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                            <i class="fas fa-<?= $resident['status'] === 'active' ? 'check-circle' : 'clock' ?> me-1"></i><?= ucfirst($resident['status']) ?>
                        </span>
                        <span class="header-email"><i class="fas fa-envelope"></i><?= htmlspecialchars($resident['email']) ?></span>
                    </div>
                </div>

                <div class="resident-body">
                    <table class="info-table">
                        <tr><td class="info-label">Age</td><td class="info-value"><?= $age ?> years</td></tr>
                        <tr><td class="info-label">Gender</td><td class="info-value"><?= $resident['gender'] ?? 'Not specified' ?></td></tr>
                        <tr><td class="info-label">Phone</td><td class="info-value"><?= $resident['phone'] ?? 'Not set' ?></td></tr>
                        <tr><td class="info-label">Blood Type</td><td class="info-value"><?= $resident['blood_type'] ?? 'Not set' ?></td></tr>
                        <tr><td class="info-label">Emergency Contact</td><td class="info-value"><?= $resident['emergency_contact'] ?? 'Not set' ?></td></tr>
                        <?php if (!empty($resident['primary_physician'])): ?>
                        <tr><td class="info-label">Primary Physician</td><td class="info-value"><?= htmlspecialchars($resident['primary_physician']) ?></td></tr>
                        <?php endif; ?>
                    </table>

                    <?php if (!empty($resident['medical_conditions'])): ?>
                    <div class="medical-box">
                        <div class="medical-title"><i class="fas fa-file-medical"></i>Medical Conditions</div>
                        <div class="medical-text"><?= htmlspecialchars($resident['medical_conditions']) ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="action-buttons">
                        <a href="log_health.php?resident_id=<?= $resident['user_id'] ?>" class="btn-sage">
                            <i class="fas fa-heart-pulse"></i>Log Health
                        </a>
                        <a href="resident_profile.php?id=<?= $resident['user_id'] ?>" class="btn-outline-sage">
                            <i class="fas fa-user"></i>View Profile
                        </a>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>

    <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-users"></i></div>
            <h4 style="font-family:'Outfit',sans-serif;font-size:17px;font-weight:700;color:var(--s700);margin-bottom:8px;">No Residents Assigned</h4>
            <p style="color:var(--st300);font-size:13px;margin-bottom:20px;">You don't have any residents assigned to you yet.</p>
            <div class="d-flex justify-content-center gap-3">
                <a href="caregiver_dashboard.php" class="btn-sage" style="text-decoration:none;">
                    <i class="fas fa-arrow-left"></i>Back to Dashboard
                </a>
                <button class="btn-outline-sage"><i class="fas fa-question-circle"></i>Contact Admin</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const cards = document.querySelectorAll('.resident-card');
        cards.forEach((card, index) => {
            setTimeout(() => {
                card.style.transition = 'opacity .4s ease, transform .4s ease';
                card.classList.add('visible');
            }, index * 80);
        });
    });
</script>
</body>
</html>