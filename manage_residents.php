<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

include 'db_connection.php';

$search = "";
if (isset($_GET['search'])) {
    $search = $_GET['search'];
}

$query = "SELECT * FROM users WHERE role='resident' AND (full_name LIKE ? OR email LIKE ?) ORDER BY user_id DESC";
$stmt = $conn->prepare($query);
$searchParam = "%" . $search . "%";
$stmt->bind_param("ss", $searchParam, $searchParam);
$stmt->execute();
$residents = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Residents - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* ═══════════════════════════════════════════════════════════
       SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
       Manage Residents · Professional scale (15px base)
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
    .content{padding:24px;position:relative;z-index:1;min-height:100vh;}

    /* ── Page header ── */
    .page-header{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:24px 26px;border-radius:var(--radius-lg);margin-bottom:22px;position:relative;overflow:hidden;}
    .page-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.18) 0%,transparent 60%);pointer-events:none;}
    .page-header h2{font-family:'Outfit',sans-serif;font-weight:700;font-size:20px;color:white;margin-bottom:4px;position:relative;}
    .page-header p{font-size:13px;color:rgba(255,255,255,.7);margin:0;position:relative;}
    .feature-icon{font-size:2.2rem;margin-bottom:10px;color:rgba(255,255,255,.85);position:relative;display:block;}
    .floating{animation:floating 3s ease-in-out infinite;}
    @keyframes floating{0%{transform:translate(0,0);}50%{transform:translate(0,-5px);}100%{transform:translate(0,0);}}

    /* ── Search + action card ── */
    .card{background:white;border-radius:var(--radius-lg);border:1px solid rgba(196,217,180,.3);box-shadow:var(--shadow-card);overflow:hidden;margin-bottom:20px;}
    .card-header{background:white;border-bottom:1px solid var(--s100);padding:15px 20px;}
    .card-body{padding:18px 20px;}
    .card.p-3{padding:16px 20px!important;}

    /* ── Search box ── */
    .search-box{background:var(--w50);border:2px solid var(--s100);border-radius:var(--radius-md);padding:9px 14px;transition:border-color .2s;font-family:'Outfit',sans-serif;font-size:14px;color:var(--st700);}
    .search-box:focus{border-color:var(--s400);box-shadow:none;outline:none;}

    /* ── Buttons ── */
    .btn-primary{background:linear-gradient(135deg,var(--s500),var(--s800));border:none;padding:10px 22px;border-radius:var(--radius-md);font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;color:white;transition:all .2s;position:relative;overflow:hidden;display:inline-flex;align-items:center;gap:7px;}
    .btn-primary::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(135deg,var(--s400),var(--s700));transition:left .35s ease;}
    .btn-primary:hover::before{left:0;}
    .btn-primary span{position:relative;z-index:2;}
    .btn-primary i{position:relative;z-index:2;}
    .btn-primary:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(94,138,64,.35);}
    .btn-success{background:linear-gradient(135deg,var(--s400),var(--s600));border:none;padding:9px 18px;border-radius:var(--radius-md);font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;color:white;transition:all .2s;}
    .btn-success:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(94,138,64,.35);}
    .btn-edit{background:linear-gradient(135deg,var(--s400),var(--s600));color:white;border:none;padding:7px 13px;border-radius:var(--radius-sm);transition:all .2s;margin:0 3px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;display:inline-flex;align-items:center;gap:5px;}
    .btn-delete{background:linear-gradient(135deg,#C87A7A,#8B3A3A);color:white;border:none;padding:7px 13px;border-radius:var(--radius-sm);transition:all .2s;margin:0 3px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;display:inline-flex;align-items:center;gap:5px;}
    .btn-edit:hover,.btn-delete:hover{transform:translateY(-2px);box-shadow:var(--shadow-soft);}

    /* ── Table ── */
    .table-container{border-radius:0;overflow:hidden;}
    .table{border-collapse:collapse;margin:0;}
    .table thead{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;position:relative;}
    .table thead::after{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
    .table th{border:none;padding:13px 12px;font-weight:700;font-family:'Outfit',sans-serif;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:white;position:relative;}
    .table td{border-color:var(--s50);padding:12px;vertical-align:middle;font-size:14px;color:var(--st700);}
    .table tbody tr{transition:all .2s;border-bottom:1px solid var(--s50);}
    .table tbody tr:hover{background:var(--s50);}

    /* ── Badges ── */
    .badge{padding:4px 11px;border-radius:20px;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.04em;}
    .bg-success  {background:var(--green-bg)!important;color:var(--green-text)!important;}
    .bg-secondary{background:var(--s50)!important;     color:var(--st300)!important;}

    /* ── Input group ── */
    .input-group-text{background:var(--w50);border:2px solid var(--s100);border-right:none;border-radius:var(--radius-md) 0 0 var(--radius-md);}
    .input-group .search-box{border-radius:0;border-left:none;border-right:none;}
    .input-group .btn-success{border-radius:0 var(--radius-md) var(--radius-md) 0;}

    /* ── Empty state ── */
    .text-muted-state{text-align:center;padding:44px 20px;color:var(--st300);}
    .text-muted-state i{font-size:2.8rem;margin-bottom:14px;display:block;opacity:.25;}
    .text-muted-state h5{font-family:'Outfit',sans-serif;font-weight:700;color:var(--st500);margin-bottom:6px;}

    /* ── Mobile card view ── */
    .mobile-hidden{display:none;}
    .mobile-card-view{display:none;}
    .resident-card{background:white;border-radius:var(--radius-md);padding:14px;margin-bottom:10px;box-shadow:var(--shadow-soft);border-left:4px solid var(--s400);border:1px solid rgba(196,217,180,.3);border-left-width:4px;}
    .btn-group-mobile{display:flex;gap:8px;justify-content:center;margin-top:10px;}

    /* ── Desktop/responsive ── */
    @media(min-width:992px){
        .sidebar{width:240px;height:100vh;position:fixed;}
        .sidebar a{display:flex;padding:10px 10px 10px 20px;border-radius:var(--radius-sm);margin:1px 8px;font-size:13.5px;}
        .sidebar a:hover{border-left:none;transform:translateX(0);}
        .content{margin-left:240px;padding:24px;}
        .page-header{text-align:left;}
        .mobile-hidden{display:table-cell;}
    }
    @media(max-width:991px) and (min-width:768px){.content{padding:22px;}}
    @media(max-width:767px){
        .table-desktop{display:none;}
        .mobile-card-view{display:block;}
        .btn-edit,.btn-delete{flex:1;padding:8px 12px;}
    }
    @media(max-width:576px){
        .content{padding:14px;}
        .page-header{padding:18px 16px;margin-bottom:16px;}
        .search-box{font-size:16px;}
        .sidebar a{padding:10px 12px;font-size:13px;}
    }
    @media(max-width:380px){
        .sidebar a{padding:8px 10px;font-size:12px;}
        .sidebar a span{display:none;}
        .sidebar a i{margin-right:0;}
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
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i>Manage Caregivers</a>
        <a href="#" class="active"><i class="fa-solid fa-user-group"></i>Manage Residents</a>
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

    <div class="page-header">
        <span class="feature-icon floating"><i class="fas fa-user-group"></i></span>
        <h2>Manage Residents</h2>
        <p>View and manage all elderly residents in the system</p>
    </div>

    <!-- Search + Add -->
    <div class="card p-3 mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-md-8">
                <form method="GET">
                    <div class="input-group">
                        <span class="input-group-text border-0"><i class="fas fa-search" style="color:var(--st300);"></i></span>
                        <input type="text" class="form-control search-box" placeholder="Search residents by name or email…" name="search" value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-success"><i class="fas fa-search"></i></button>
                    </div>
                </form>
            </div>
            <div class="col-md-4 text-md-end text-center">
                <a href="add_resident.php" class="btn-primary"><i class="fas fa-user-plus"></i><span>Add Resident</span></a>
            </div>
        </div>
    </div>

    <!-- Desktop Table -->
    <div class="card table-desktop">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="fas fa-list" style="color:var(--s500);font-size:14px;"></i>
            <span style="font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;color:var(--s800);">Resident List</span>
        </div>
        <div class="card-body p-0">
            <div class="table-container">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th><i class="fas fa-id-card me-2"></i>ID</th>
                                <th><i class="fas fa-user me-2"></i>Full Name</th>
                                <th class="mobile-hidden"><i class="fas fa-envelope me-2"></i>Email</th>
                                <th><i class="fas fa-circle me-2"></i>Status</th>
                                <th style="width:200px;"><i class="fas fa-gears me-2"></i>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($residents->num_rows > 0): ?>
                                <?php while ($row = $residents->fetch_assoc()): ?>
                                <tr>
                                    <td><strong style="color:var(--s600);">#<?php echo $row['user_id']; ?></strong></td>
                                    <td style="font-weight:700;color:var(--s800);"><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td class="mobile-hidden" style="color:var(--st500);"><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $row['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                            <i class="fas fa-<?php echo $row['status'] === 'active' ? 'check' : 'pause'; ?>-circle me-1"></i>
                                            <?php echo ucfirst($row['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2 justify-content-center">
                                            <a href="edit_resident.php?id=<?php echo $row['user_id']; ?>" class="btn btn-edit btn-sm">
                                                <i class="fas fa-pen-to-square"></i>Edit
                                            </a>
                                            <a href="delete_resident.php?id=<?php echo $row['user_id']; ?>"
                                               class="btn btn-delete btn-sm"
                                               onclick="return confirm('Are you sure you want to delete this resident? This action cannot be undone.');">
                                                <i class="fas fa-trash"></i>Delete
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="5">
                                    <div class="text-muted-state">
                                        <i class="fas fa-users"></i>
                                        <h5>No Residents Found</h5>
                                        <p style="font-size:13px;">No residents match your search criteria.</p>
                                        <a href="manage_residents.php" class="btn btn-success mt-2"><i class="fas fa-refresh me-2"></i>View All Residents</a>
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

    <!-- Mobile Card View -->
    <div class="mobile-card-view">
        <?php if ($residents->num_rows > 0): ?>
            <?php $residents->data_seek(0); while ($row = $residents->fetch_assoc()): ?>
            <div class="resident-card">
                <div class="row">
                    <div class="col-6"><strong style="color:var(--s600);">ID:</strong> #<?php echo $row['user_id']; ?></div>
                    <div class="col-6 text-end">
                        <span class="badge bg-<?php echo $row['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($row['status']); ?></span>
                    </div>
                </div>
                <div class="row mt-1">
                    <div class="col-12"><strong style="color:var(--s800);">Name:</strong> <?php echo htmlspecialchars($row['full_name']); ?></div>
                </div>
                <div class="row mt-1">
                    <div class="col-12" style="font-size:13px;color:var(--st500);"><strong>Email:</strong> <?php echo htmlspecialchars($row['email']); ?></div>
                </div>
                <div class="btn-group-mobile">
                    <a href="edit_resident.php?id=<?php echo $row['user_id']; ?>" class="btn btn-edit"><i class="fas fa-pen-to-square"></i>Edit</a>
                    <a href="delete_resident.php?id=<?php echo $row['user_id']; ?>"
                       class="btn btn-delete"
                       onclick="return confirm('Are you sure you want to delete this resident? This action cannot be undone.');">
                        <i class="fas fa-trash"></i>Delete
                    </a>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="card text-center py-5">
                <div class="text-muted-state">
                    <i class="fas fa-users"></i>
                    <h5>No Residents Found</h5>
                    <p style="font-size:13px;">No residents match your search criteria.</p>
                    <a href="manage_residents.php" class="btn btn-success mt-2"><i class="fas fa-refresh me-2"></i>View All Residents</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div><!-- /content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) { searchInput.focus(); }
    function handleResize() {
        const sidebar = document.querySelector('.sidebar');
        if (window.innerWidth < 992) {
            document.body.style.paddingTop = sidebar.offsetHeight + 'px';
        } else {
            document.body.style.paddingTop = '0';
        }
    }
    handleResize();
    window.addEventListener('resize', handleResize);
});
</script>
</body>
</html>