<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if (isset($_GET['delete'])) {
    $user_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role = 'caregiver'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    header("Location: manage_caregivers.php" . ($search ? "?search=" . urlencode($search) : ""));
    exit();
}

$query = "SELECT 
            u.user_id, u.full_name, u.email, u.status,
            c.caregiver_id, c.phone, c.address, c.gender, c.dob,
            c.experience_years, c.skills, c.created_at
          FROM users u
          LEFT JOIN caregivers c ON u.user_id = c.user_id
          WHERE u.role = 'caregiver'";
$params = [];

if (!empty($search)) {
    $query .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR c.phone LIKE ?)";
    $search_term = "%" . $search . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$query .= " ORDER BY u.full_name ASC";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $types = str_repeat('s', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Caregivers - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* ═══════════════════════════════════════════════════════════
       SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
       Manage Caregivers · Professional scale (15px base)
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
    .sidebar{width:240px;height:100vh;position:fixed;background:var(--s800);display:flex;flex-direction:column;z-index:1030;overflow:hidden;}
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
    .content{margin-left:240px;padding:24px;min-height:100vh;position:relative;z-index:1;}

    /* ── Header section ── */
    .header-section{background:white;border-radius:var(--radius-lg);padding:20px 24px;margin-bottom:20px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
    .header-section h3{font-size:22px;font-weight:500;color:var(--s800);display:flex;align-items:center;gap:8px;margin-bottom:4px;}
    .header-section p{font-size:13px;color:var(--st300);margin:0;}

    /* ── Search section ── */
    .search-section{background:white;border-radius:var(--radius-lg);padding:16px 20px;box-shadow:var(--shadow-soft);border:1px solid rgba(196,217,180,.3);margin-bottom:18px;}
    .search-form{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
    .search-input-group{flex:1;min-width:220px;position:relative;}
    .search-input{width:100%;padding:9px 42px 9px 14px;border:2px solid var(--s100);border-radius:var(--radius-md);font-family:'Outfit',sans-serif;font-size:14px;transition:border-color .2s;background:var(--w50);color:var(--st700);}
    .search-input:focus{border-color:var(--s400);outline:none;}
    .search-btn{position:absolute;right:5px;top:50%;transform:translateY(-50%);background:linear-gradient(135deg,var(--s500),var(--s800));border:none;border-radius:var(--radius-sm);color:white;padding:7px 13px;cursor:pointer;transition:all .2s;}
    .search-btn:hover{transform:translateY(-50%) scale(1.04);}
    .clear-search{background:white;border:2px solid var(--s100);color:var(--st500);padding:8px 18px;border-radius:var(--radius-md);font-weight:700;font-size:13px;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block;font-family:'Outfit',sans-serif;}
    .clear-search:hover{border-color:var(--s400);color:var(--s700);}

    /* ── Add button ── */
    .btn-primary{background:linear-gradient(135deg,var(--s500),var(--s800));border:none;padding:10px 22px;border-radius:var(--radius-md);font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;color:white;transition:all .2s;position:relative;overflow:hidden;display:inline-flex;align-items:center;gap:7px;}
    .btn-primary::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(135deg,var(--s400),var(--s700));transition:left .35s ease;}
    .btn-primary:hover::before{left:0;}
    .btn-primary span{position:relative;z-index:2;}
    .btn-primary i{position:relative;z-index:2;}
    .btn-primary:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(94,138,64,.35);}

    /* ── Table container ── */
    .table-container{background:white;border-radius:var(--radius-lg);padding:0;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);overflow:hidden;}
    .table{margin:0;background:transparent;}
    .table thead{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;position:relative;}
    .table thead::after{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
    .table thead th{border:none;padding:14px;font-weight:700;font-family:'Outfit',sans-serif;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:white;position:relative;}
    .table td{border-color:var(--s50);padding:13px 14px;vertical-align:middle;font-size:14px;color:var(--st700);}
    .table tbody tr{transition:all .2s;border-bottom:1px solid var(--s50);}
    .table tbody tr:hover{background:var(--s50);transform:translateY(-1px);}

    /* ── Action buttons ── */
    .btn-action{padding:7px 13px;border-radius:var(--radius-sm);font-size:13px;font-weight:700;transition:all .2s;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:5px;font-family:'Outfit',sans-serif;cursor:pointer;}
    .btn-edit  {background:linear-gradient(135deg,var(--s400),var(--s600));color:white;}
    .btn-view  {background:linear-gradient(135deg,var(--s200),var(--s500));color:white;}
    .btn-delete{background:linear-gradient(135deg,#C87A7A,#8B3A3A);color:white;}
    .btn-action:hover{transform:translateY(-2px);box-shadow:var(--shadow-soft);}

    /* ── Badges ── */
    .badge{padding:4px 11px;border-radius:20px;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.04em;}
    .bg-success  {background:var(--green-bg)!important;color:var(--green-text)!important;}
    .bg-secondary{background:var(--s50)!important;     color:var(--st300)!important;}

    /* ── Experience badge ── */
    .experience-badge{background:var(--amber-bg);color:var(--amber-text);padding:4px 11px;border-radius:20px;font-weight:700;font-size:11px;display:inline-flex;align-items:center;gap:4px;border:1px solid rgba(122,80,16,.12);}

    /* ── Search results info ── */
    .search-results-info{color:var(--st500);font-weight:700;font-size:13px;margin-bottom:14px;padding:9px 0;border-bottom:2px solid var(--s100);}

    /* ── Empty state ── */
    .empty-state{text-align:center;padding:52px 20px;color:var(--st300);}
    .empty-state i{font-size:3.5rem;margin-bottom:16px;opacity:.25;display:block;}

    /* ── Modal ── */
    .modal{z-index:1060!important;overflow-y:auto!important;}
    .modal-backdrop{z-index:1055!important;background-color:rgba(36,56,22,.45)!important;}
    .modal-dialog{margin:1.75rem auto!important;max-width:800px!important;z-index:1065!important;}
    .modal-content{border-radius:var(--radius-lg);border:none;box-shadow:var(--shadow-lift);max-height:85vh!important;overflow-y:auto!important;}
    .modal-header{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;border-radius:var(--radius-lg) var(--radius-lg) 0 0;border:none;padding:18px 26px;position:sticky;top:0;z-index:1070;}
    .modal-header h5{font-family:'Outfit',sans-serif;font-weight:700;font-size:15px;color:white;display:flex;align-items:center;gap:8px;}
    .modal-header .btn-close{background:none;color:white;opacity:1;filter:brightness(0) invert(1);background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%23fff'%3e%3cpath d='M.293.293a1 1 0 0 1 1.414 0L8 6.586 14.293.293a1 1 0 1 1 1.414 1.414L9.414 8l6.293 6.293a1 1 0 0 1-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 0 1-1.414-1.414L6.586 8 .293 1.707a1 1 0 0 1 0-1.414z'/%3e%3c/svg%3e");}
    .modal-body{padding:24px 26px;overflow-y:visible!important;}
    .modal-body p{font-size:14px;color:var(--st700);margin-bottom:10px;}
    .modal-body strong{color:var(--s800);}
    .modal-footer{border-top:1px solid var(--s100);padding:16px 26px;background:var(--s50);border-radius:0 0 var(--radius-lg) var(--radius-lg);position:sticky;bottom:0;z-index:1070;}
    .caregiver-detail-row{display:flex;margin-bottom:18px;padding-bottom:18px;border-bottom:1px solid var(--s100);}
    .detail-icon{width:38px;height:38px;background:var(--s100);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;color:var(--s700);margin-right:14px;flex-shrink:0;}
    .detail-content{flex:1;}
    .detail-label{font-weight:700;color:var(--s800);margin-bottom:4px;font-size:13px;}
    .detail-value{color:var(--st700);font-size:14px;}
    .skills-tags{display:flex;flex-wrap:wrap;gap:7px;margin-top:5px;}
    .skill-tag{background:var(--s50);color:var(--s700);padding:5px 11px;border-radius:20px;font-size:12px;font-weight:600;border:1px solid var(--s100);}
    body.modal-open{overflow:hidden!important;padding-right:0!important;}
    body.modal-open .content{filter:blur(2px);pointer-events:none;}

    @media(max-width:768px){
        .sidebar{width:100%;height:auto;position:relative;z-index:1030;}
        .content{margin-left:0;padding:16px;}
        .table-container{overflow-x:auto;}
        .search-form{flex-direction:column;}
        .search-input-group{width:100%;}
        .clear-search{width:100%;text-align:center;}
        .modal-dialog{margin:.5rem!important;max-width:95%!important;}
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
        <small>Administrator Panel</small>
    </div>
    <div class="sidebar-nav">
        <a href="admin_dashboard.php"><i class="fa-solid fa-gauge-high"></i>Dashboard</a>
        <a href="manage_users.php"><i class="fa-solid fa-users"></i>Manage Users</a>
        <a href="#" class="active"><i class="fa-solid fa-hand-holding-heart"></i>Manage Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i>Manage Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i>Assign Caregivers</a>
        <a href="manage_services.php"><i class="fa-solid fa-spa"></i>Manage Services</a>
        <a href="messages.php"><i class="fa-solid fa-envelope"></i>Messages</a>
        <a href="admin_alerts.php"><i class="fa-solid fa-bell"></i>Health Alerts</a>
        <a href="admin_reports.php"><i class="fa-solid fa-chart-line"></i>Reports & Analytics</a>
    </div>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <div class="header-section">
        <div>
            <h3><i class="fas fa-user-nurse" style="color:var(--s500);"></i>Manage Caregivers</h3>
            <p>Manage and oversee all caregiver accounts and assignments</p>
        </div>
        <a href="add_caregiver.php" class="btn-primary">
            <i class="fas fa-plus"></i><span>Add Caregiver</span>
        </a>
    </div>

    <!-- Search -->
    <div class="search-section">
        <form method="GET" action="" class="search-form">
            <div class="search-input-group">
                <input type="text" name="search" class="search-input"
                       placeholder="Search caregivers by name, email or phone…"
                       value="<?php echo htmlspecialchars($search); ?>"
                       autocomplete="off">
                <button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
            </div>
            <?php if (!empty($search)): ?>
                <a href="manage_caregivers.php" class="clear-search"><i class="fas fa-times me-1"></i>Clear Search</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Search results info -->
    <?php if (!empty($search)): ?>
    <div class="search-results-info">
        <i class="fas fa-search me-2"></i>
        Search results for: "<strong><?php echo htmlspecialchars($search); ?></strong>"
        <?php
        $total_count  = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'caregiver'")->fetch_assoc()['count'];
        $result_count = $result->num_rows;
        echo " (Showing {$result_count} of {$total_count} caregivers)";
        ?>
    </div>
    <?php endif; ?>

    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th><i class="fas fa-id-card me-2"></i>ID</th>
                        <th><i class="fas fa-user me-2"></i>Full Name</th>
                        <th><i class="fas fa-phone me-2"></i>Contact</th>
                        <th><i class="fas fa-clock me-2"></i>Experience</th>
                        <th><i class="fas fa-circle me-2"></i>Status</th>
                        <th style="width:230px;"><i class="fas fa-bolt me-2"></i>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><strong style="color:var(--s600);">#<?= $row['user_id']; ?></strong></td>
                            <td style="font-weight:700;color:var(--s800);"><?= htmlspecialchars($row['full_name']); ?></td>
                            <td>
                                <div style="display:flex;flex-direction:column;">
                                    <span><?= htmlspecialchars($row['email']); ?></span>
                                    <?php if (!empty($row['phone'])): ?>
                                        <small style="color:var(--st300);"><?= htmlspecialchars($row['phone']); ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($row['experience_years'] > 0): ?>
                                    <span class="experience-badge"><i class="fas fa-medal"></i><?= $row['experience_years']; ?> years</span>
                                <?php else: ?>
                                    <span style="color:var(--st300);font-size:13px;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $row['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                    <i class="fas fa-circle me-1" style="font-size:6px;"></i>
                                    <?= ucfirst($row['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-2 flex-wrap">
                                    <button class="btn-action btn-view view-btn"
                                        data-bs-toggle="modal" data-bs-target="#caregiverModal"
                                        data-id="<?= $row['user_id']; ?>"
                                        data-name="<?= htmlspecialchars($row['full_name']); ?>"
                                        data-email="<?= htmlspecialchars($row['email']); ?>"
                                        data-phone="<?= htmlspecialchars($row['phone'] ?? ''); ?>"
                                        data-gender="<?= htmlspecialchars($row['gender'] ?? ''); ?>"
                                        data-dob="<?= htmlspecialchars($row['dob'] ?? ''); ?>"
                                        data-experience="<?= $row['experience_years'] ?? 0; ?>"
                                        data-address="<?= htmlspecialchars($row['address'] ?? ''); ?>"
                                        data-skills="<?= htmlspecialchars($row['skills'] ?? ''); ?>"
                                        data-created="<?= htmlspecialchars($row['created_at'] ?? ''); ?>"
                                        data-status="<?= htmlspecialchars($row['status']); ?>">
                                        <i class="fas fa-eye"></i>View
                                    </button>
                                    <a href="edit_caregiver.php?id=<?= $row['user_id']; ?>" class="btn-action btn-edit">
                                        <i class="fas fa-edit"></i>Edit
                                    </a>
                                    <a href="manage_caregivers.php?delete=<?= $row['user_id']; ?>&search=<?= urlencode($search); ?>"
                                       class="btn-action btn-delete"
                                       onclick="return confirm('Are you sure you want to delete this caregiver? This action cannot be undone.');">
                                        <i class="fas fa-trash"></i>Delete
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <?php if (!empty($search)): ?>
                                    <i class="fas fa-search"></i>
                                    <h5 style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--st500);">No Caregivers Found</h5>
                                    <p style="font-size:13px;">No caregivers match your search "<strong><?php echo htmlspecialchars($search); ?></strong>"</p>
                                    <a href="manage_caregivers.php" class="btn-action btn-edit"><i class="fas fa-redo"></i>Clear Search</a>
                                <?php else: ?>
                                    <i class="fas fa-user-nurse"></i>
                                    <h5 style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--st500);">No Caregivers Found</h5>
                                    <p style="font-size:13px;">Get started by adding your first caregiver to the system.</p>
                                    <a href="add_caregiver.php" class="btn-primary"><i class="fas fa-plus"></i><span>Add First Caregiver</span></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Caregiver Modal -->
<div class="modal fade" id="caregiverModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-nurse"></i><span id="modalName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Email:</strong> <span id="modalEmail"></span></p>
                        <p><strong>Phone:</strong> <span id="modalPhone"></span></p>
                        <p><strong>Gender:</strong> <span id="modalGender"></span></p>
                        <p><strong>Date of Birth:</strong> <span id="modalDob"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Experience:</strong> <span id="modalExperience"></span></p>
                        <p><strong>Address:</strong> <span id="modalAddress"></span></p>
                        <p><strong>Status:</strong> <span id="modalStatus"></span></p>
                        <p><strong>Joined:</strong> <span id="modalCreated"></span></p>
                    </div>
                    <div class="col-12 mt-3">
                        <strong>Skills:</strong>
                        <div id="modalSkills" class="mt-2 skills-tags"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a id="modalEditBtn" href="#" class="btn-action btn-edit"><i class="fas fa-edit me-1"></i>Edit</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let searchTimeout;
const searchInput = document.querySelector('.search-input');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => { this.form.submit(); }, 500);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    if (searchInput && searchInput.value) { searchInput.focus(); searchInput.select(); }
});

document.querySelectorAll('.btn-delete').forEach(link => {
    link.addEventListener('click', function(e) {
        const url = new URL(this.href);
        const search = '<?php echo urlencode($search); ?>';
        if (search) url.searchParams.set('search', decodeURIComponent(search));
        this.href = url.toString();
    });
});

document.querySelectorAll('.view-btn').forEach(button => {
    button.addEventListener('click', function() {
        document.getElementById('modalName').textContent = this.dataset.name;
        document.getElementById('modalEmail').textContent = this.dataset.email || 'N/A';
        document.getElementById('modalPhone').textContent = this.dataset.phone || 'N/A';
        document.getElementById('modalGender').textContent = this.dataset.gender || 'N/A';
        document.getElementById('modalDob').textContent = this.dataset.dob || 'N/A';
        document.getElementById('modalExperience').textContent = this.dataset.experience > 0 ? this.dataset.experience + ' years' : 'N/A';
        document.getElementById('modalAddress').textContent = this.dataset.address || 'N/A';
        document.getElementById('modalStatus').textContent = this.dataset.status;
        document.getElementById('modalCreated').textContent = this.dataset.created || 'N/A';
        const skillsContainer = document.getElementById('modalSkills');
        skillsContainer.innerHTML = '';
        if (this.dataset.skills) {
            this.dataset.skills.split(',').forEach(skill => {
                const span = document.createElement('span');
                span.className = 'skill-tag';
                span.textContent = skill.trim();
                skillsContainer.appendChild(span);
            });
        } else {
            skillsContainer.textContent = 'No skills listed';
        }
        document.getElementById('modalEditBtn').href = "edit_caregiver.php?id=" + this.dataset.id;
    });
});
</script>
</body>
</html>