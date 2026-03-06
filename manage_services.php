<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$services = $conn->query("
    SELECT s.*, c.category_name
    FROM services s
    JOIN service_categories c ON s.category_id = c.category_id
    ORDER BY s.created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Services - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* ═══════════════════════════════════════════════════════════
       SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
       Manage Services · Professional scale (15px base)
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
        color:var(--st700);min-height:100vh;margin:0;padding:0;position:relative;overflow-x:hidden;
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
    .content{margin-left:240px;padding:24px;position:relative;z-index:1;min-height:100vh;}

    /* ── Page header ── */
    .page-header{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:22px 26px;border-radius:var(--radius-lg);margin-bottom:22px;position:relative;overflow:hidden;}
    .page-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.18) 0%,transparent 60%);pointer-events:none;}
    .page-header h2{font-family:'Outfit',sans-serif;font-weight:700;font-size:20px;color:white;margin-bottom:4px;position:relative;}
    .page-header p{font-size:13px;color:rgba(255,255,255,.7);margin:0;position:relative;}
    .feature-icon{font-size:2rem;margin-bottom:10px;color:rgba(255,255,255,.85);position:relative;display:block;}
    .floating{animation:floating 3s ease-in-out infinite;}
    @keyframes floating{0%,100%{transform:translate(0,0)}50%{transform:translate(0,-8px)}}

    /* ── Buttons ── */
    .btn-primary{background:linear-gradient(135deg,var(--s500),var(--s800));border:none;padding:10px 22px;border-radius:var(--radius-md);font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;color:white;transition:all .2s;position:relative;overflow:hidden;display:inline-flex;align-items:center;gap:7px;}
    .btn-primary::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(135deg,var(--s400),var(--s700));transition:left .35s ease;}
    .btn-primary:hover::before{left:0;}
    .btn-primary span,.btn-primary i{position:relative;z-index:2;}
    .btn-primary:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(94,138,64,.35);color:white;}
    .btn-success{background:linear-gradient(135deg,var(--s400),var(--s600));border:none;padding:10px 22px;border-radius:var(--radius-md);font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;color:white;transition:all .2s;}
    .btn-success:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(94,138,64,.35);color:white;}
    .btn-warning{background:linear-gradient(135deg,#C8A44C,#8B6820);border:none;padding:7px 14px;border-radius:var(--radius-sm);font-weight:700;font-size:12px;font-family:'Outfit',sans-serif;color:white;transition:all .2s;}
    .btn-warning:hover{opacity:.9;transform:translateY(-1px);color:white;}
    .btn-danger {background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;padding:7px 14px;border-radius:var(--radius-sm);font-weight:700;font-size:12px;font-family:'Outfit',sans-serif;color:white;transition:all .2s;}
    .btn-danger:hover {opacity:.9;transform:translateY(-1px);color:white;}

    /* ── Card ── */
    .card{background:white;border-radius:var(--radius-lg);border:1px solid rgba(196,217,180,.3);box-shadow:var(--shadow-card);overflow:hidden;}
    .card-header{background:white;border-bottom:1px solid var(--s100);padding:14px 20px;display:flex;align-items:center;gap:8px;}
    .card-body.p-0{padding:0!important;}

    /* ── Table ── */
    .table-container{border-radius:0;overflow:hidden;}
    .table{margin:0;background:transparent;}
    .table thead{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;position:relative;}
    .table thead::after{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
    .table th{border:none;padding:13px 12px;font-weight:700;font-family:'Outfit',sans-serif;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:white;position:relative;}
    .table td{border-color:var(--s50);padding:13px 12px;vertical-align:middle;font-size:14px;color:var(--st700);}
    .table tbody tr{transition:all .2s;border-bottom:1px solid var(--s50);}
    .table tbody tr:hover{background:var(--s50);transform:translateY(-1px);}

    /* ── Category badge ── */
    .cat-badge{background:var(--s50);color:var(--s700);padding:4px 11px;border-radius:20px;font-size:12px;font-weight:700;border:1px solid var(--s100);display:inline-block;}

    /* ── Service image ── */
    .service-image{width:78px;height:58px;object-fit:cover;border-radius:var(--radius-sm);border:2px solid var(--s100);transition:all .25s;}
    .service-image:hover{transform:scale(1.08);border-color:var(--s300);}

    /* ── Empty state ── */
    .empty-state{text-align:center;padding:48px 20px;color:var(--st300);}
    .empty-state i{font-size:3rem;display:block;margin-bottom:14px;opacity:.25;}
    .empty-state h5{font-family:'Outfit',sans-serif;font-weight:700;color:var(--st500);margin-bottom:6px;}

    @media(max-width:768px){
        .sidebar{width:100%;height:auto;position:relative;}
        .content{margin-left:0;padding:16px;}
        .sidebar-footer{position:relative;margin-top:20px;}
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
    <nav class="sidebar-nav">
        <a href="admin_dashboard.php"><i class="fa-solid fa-gauge-high"></i>Dashboard</a>
        <a href="manage_users.php"><i class="fa-solid fa-users"></i>Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i>Manage Caregivers</a>
        <a href="manage_residents.php"><i class="fa-solid fa-user-group"></i>Manage Residents</a>
        <a href="assign_caregiver.php"><i class="fa-solid fa-link"></i>Assign Caregivers</a>
        <a href="#" class="active"><i class="fa-solid fa-spa"></i>Manage Services</a>
        <a href="messages.php"><i class="fa-solid fa-envelope"></i>Messages</a>
        <a href="admin_alerts.php"><i class="fa-solid fa-bell"></i>Health Alerts</a>
        <a href="admin_reports.php"><i class="fa-solid fa-chart-line"></i>Reports & Analytics</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fas fa-right-from-bracket"></i>Logout</a>
    </div>
</div>

<!-- ══ CONTENT ════════════════════════════════════════════════════ -->
<div class="content">

    <div class="page-header">
        <span class="feature-icon floating"><i class="fas fa-spa"></i></span>
        <h2>Manage Services</h2>
        <p>View and manage all healthcare services offered</p>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h4 style="font-size:20px;font-weight:500;color:var(--s800);margin-bottom:4px;">Service Catalog</h4>
            <p style="font-size:13px;color:var(--st300);margin:0;">Manage your healthcare service offerings and categories</p>
        </div>
        <a href="add_service.php" class="btn-primary btn">
            <i class="fas fa-plus-circle"></i><span>Add New Service</span>
        </a>
    </div>

    <div class="card">
        <div class="card-header">
            <i class="fas fa-list" style="color:var(--s500);font-size:14px;"></i>
            <span style="font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;color:var(--s800);">Service List</span>
        </div>
        <div class="card-body p-0">
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th><i class="fas fa-image me-2"></i>Image</th>
                                <th><i class="fas fa-heading me-2"></i>Title</th>
                                <th><i class="fas fa-tags me-2"></i>Category</th>
                                <th><i class="fas fa-align-left me-2"></i>Description</th>
                                <th style="width:200px;"><i class="fas fa-gears me-2"></i>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($services->num_rows > 0): ?>
                            <?php while($row = $services->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <img src="<?= $row['image_path'] ?>"
                                         class="service-image"
                                         alt="<?= htmlspecialchars($row['title']) ?>"
                                         onerror="this.src='https://via.placeholder.com/80x60/9DC07E/ffffff?text=Service'">
                                </td>
                                <td style="font-weight:700;color:var(--s800);"><?= htmlspecialchars($row['title']) ?></td>
                                <td><span class="cat-badge"><?= htmlspecialchars($row['category_name']) ?></span></td>
                                <td style="color:var(--st300);"><?= substr(htmlspecialchars($row['description']), 0, 60) ?>…</td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a href="edit_service.php?id=<?= $row['service_id'] ?>" class="btn btn-warning btn-sm">
                                            <i class="fas fa-edit me-1"></i>Edit
                                        </a>
                                        <a href="delete_service.php?id=<?= $row['service_id'] ?>"
                                           class="btn btn-danger btn-sm"
                                           onclick="return confirm('Are you sure you want to delete this service? This action cannot be undone.');">
                                            <i class="fas fa-trash me-1"></i>Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <i class="fas fa-spa"></i>
                                        <h5>No Services Found</h5>
                                        <p style="font-size:13px;">Get started by adding your first healthcare service.</p>
                                        <a href="add_service.php" class="btn btn-success mt-2"><i class="fas fa-plus-circle me-2"></i>Add First Service</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>