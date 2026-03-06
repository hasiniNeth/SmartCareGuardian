<?php
session_start();
include 'db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

if (isset($_GET['delete'])) {
    $user_id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    header("Location: manage_users.php");
    exit();
}

$search      = isset($_GET['search']) ? $_GET['search'] : '';
$role_filter = isset($_GET['role'])   ? $_GET['role']   : '';

$query  = "SELECT user_id, full_name, email, role, status FROM users WHERE 1=1";
$params = [];
$types  = "";

if (!empty($search)) {
    $query .= " AND (full_name LIKE ? OR email LIKE ?)";
    $search_term = "%" . $search . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
}

if (!empty($role_filter) && $role_filter !== 'all') {
    $query .= " AND role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

$query .= " ORDER BY role ASC, full_name ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();

$role_counts = [];
$count_query = $conn->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
while ($row = $count_query->fetch_assoc()) { $role_counts[$row['role']] = $row['count']; }
$total_users = array_sum($role_counts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - SmartCare Guardian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* ═══════════════════════════════════════════════════════════
       SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
       Manage Users · Professional scale (15px base)
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
    .sidebar-footer{flex-shrink:0;border-top:1px solid rgba(255,255,255,.08);padding:12px 8px;position:relative;}
    .sidebar-footer a{margin:0;color:rgba(255,255,255,.5)!important;font-size:13.5px;}
    .sidebar-footer a:hover{color:rgba(255,255,255,.8)!important;}

    /* ── Layout ── */
    .content{margin-left:240px;padding:24px;min-height:100vh;}

    /* ── Topbar ── */
    .topbar{background:white;border-radius:var(--radius-lg);padding:18px 24px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;}
    .topbar h4{font-size:22px;font-weight:500;color:var(--s800);margin-bottom:2px;}
    .topbar p{font-size:13px;color:var(--st300);margin:0;}
    .logout-btn{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;border-radius:var(--radius-md);color:white;padding:9px 20px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;}
    .logout-btn:hover{opacity:.9;transform:translateY(-1px);}

    /* ── Filter container ── */
    .filter-container{background:white;border-radius:var(--radius-lg);padding:18px 22px;box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);margin-bottom:18px;}
    .filter-row{display:flex;gap:13px;align-items:center;flex-wrap:wrap;}
    .search-box{flex:1;min-width:240px;position:relative;}
    .search-input{width:100%;padding:10px 42px 10px 14px;border:2px solid var(--s100);border-radius:var(--radius-md);font-family:'Outfit',sans-serif;font-size:14px;transition:border-color .2s;background:var(--w50);color:var(--st700);}
    .search-input:focus{border-color:var(--s400);outline:none;}
    .search-btn{position:absolute;right:5px;top:50%;transform:translateY(-50%);background:linear-gradient(135deg,var(--s500),var(--s800));border:none;border-radius:var(--radius-sm);color:white;padding:7px 13px;cursor:pointer;transition:all .2s;}
    .search-btn:hover{transform:translateY(-50%) scale(1.04);}
    .filter-select{padding:10px 14px;border:2px solid var(--s100);border-radius:var(--radius-md);font-family:'Outfit',sans-serif;font-size:14px;background:var(--w50);color:var(--st700);min-width:180px;cursor:pointer;transition:border-color .2s;}
    .filter-select:focus{border-color:var(--s400);outline:none;}
    .filter-badge{background:var(--s50);color:var(--s700);padding:7px 14px;border-radius:20px;font-weight:700;font-size:13px;display:flex;align-items:center;gap:7px;border:1px solid var(--s100);}
    .reset-btn{background:white;border:2px solid var(--s100);color:var(--st500);padding:9px 18px;border-radius:var(--radius-md);font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;}
    .reset-btn:hover{border-color:var(--s400);color:var(--s700);}

    /* ── Results info ── */
    .results-info{color:var(--st500);font-weight:700;font-size:13px;margin-bottom:12px;padding:9px 0;border-bottom:2px solid var(--s100);}

    /* ── Table ── */
    .table-container{background:white;border-radius:var(--radius-lg);box-shadow:var(--shadow-card);border:1px solid rgba(196,217,180,.3);overflow:hidden;}
    .table{margin:0;background:transparent;}
    .table thead{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;position:relative;}
    .table thead::after{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
    .table thead th{border:none;padding:15px 14px;font-weight:700;font-family:'Outfit',sans-serif;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:white;position:relative;}
    .table tbody tr{transition:all .2s;border-bottom:1px solid var(--s50);}
    .table tbody tr:hover{background:var(--s50);transform:translateY(-1px);}
    .table tbody td{padding:14px;vertical-align:middle;border:none;color:var(--st700);font-size:14px;}

    /* ── Badges ── */
    .badge{padding:5px 11px;border-radius:20px;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.04em;}
    .bg-success  {background:var(--green-bg)!important;color:var(--green-text)!important;}
    .bg-danger   {background:var(--red-bg)!important;  color:var(--red-text)!important;}
    .bg-secondary{background:var(--s50)!important;     color:var(--s700)!important;}

    /* ── Action buttons ── */
    .btn-edit{background:linear-gradient(135deg,var(--s400),var(--s600));color:white;border:none;padding:7px 11px;border-radius:var(--radius-sm);transition:all .2s;margin:0 3px;display:inline-flex;align-items:center;}
    .btn-delete{background:linear-gradient(135deg,#C87A7A,#8B3A3A);color:white;border:none;padding:7px 11px;border-radius:var(--radius-sm);transition:all .2s;margin:0 3px;display:inline-flex;align-items:center;}
    .btn-edit:hover  {transform:translateY(-2px);box-shadow:0 4px 12px rgba(94,138,64,.35);}
    .btn-delete:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(139,58,58,.35);}

    /* ── User avatar ── */
    .user-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--s300),var(--s600));display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:12px;flex-shrink:0;}

    /* ── Role chips (in table) ── */
    .role-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
    .rc-admin    {background:var(--s50);color:var(--s700);}
    .rc-caregiver{background:var(--green-bg);color:var(--green-text);}
    .rc-resident {background:var(--blue-bg); color:var(--blue-text);}

    /* ── Empty state ── */
    .empty-cell{text-align:center;padding:44px 20px;color:var(--st300);}
    .empty-cell i{font-size:2.4rem;display:block;margin-bottom:12px;opacity:.25;}
    .empty-cell h5{font-family:'Outfit',sans-serif;font-weight:700;color:var(--st500);margin-bottom:6px;}
    .btn-sage{background:linear-gradient(135deg,var(--s400),var(--s700));color:white;border:none;border-radius:var(--radius-md);padding:9px 20px;font-size:13px;font-weight:700;font-family:'Outfit',sans-serif;cursor:pointer;transition:all .2s;}
    .btn-sage:hover{opacity:.9;transform:translateY(-1px);}

    @media(max-width:768px){
        .sidebar{width:100%;height:auto;position:relative;}
        .content{margin-left:0;}
        .filter-row{flex-direction:column;}
        .search-box,.filter-select{width:100%;min-width:unset;}
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
        <a href="#" class="active"><i class="fa-solid fa-users"></i>Manage Users</a>
        <a href="manage_caregivers.php"><i class="fa-solid fa-hand-holding-heart"></i>Manage Caregivers</a>
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
    <div class="topbar">
        <div>
            <h4><i class="fas fa-users me-2" style="font-size:18px;color:var(--s500);"></i>Manage Users</h4>
            <p>View and manage all system users</p>
        </div>
        <form action="logout.php" method="POST">
            <button type="submit" class="logout-btn"><i class="fa-solid fa-sign-out-alt me-2"></i>Logout</button>
        </form>
    </div>

    <!-- Filter -->
    <div class="filter-container">
        <form method="GET" action="" id="filterForm">
            <div class="filter-row">
                <div class="search-box">
                    <input type="text" name="search" class="search-input"
                           placeholder="Search by name or email…"
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
                </div>
                <select name="role" class="filter-select" onchange="this.form.submit()">
                    <option value="all"      <?php echo $role_filter === 'all' || empty($role_filter) ? 'selected' : ''; ?>>All Roles</option>
                    <option value="admin"    <?php echo $role_filter === 'admin'     ? 'selected' : ''; ?>>Admin</option>
                    <option value="caregiver"<?php echo $role_filter === 'caregiver' ? 'selected' : ''; ?>>Caregiver</option>
                    <option value="resident" <?php echo $role_filter === 'resident'  ? 'selected' : ''; ?>>Resident</option>
                </select>
                <div class="filter-badge">
                    <i class="fas fa-users"></i>
                    <?php $display_count = $result->num_rows; echo "Showing {$display_count} of {$total_users} users"; ?>
                </div>
                <?php if (!empty($search) || (!empty($role_filter) && $role_filter !== 'all')): ?>
                <button type="button" class="reset-btn" onclick="resetFilters()">
                    <i class="fas fa-times me-1"></i>Reset Filters
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Results info -->
    <div class="results-info">
        <?php if (!empty($search)): ?>
            <i class="fas fa-search me-2"></i>Search results for: "<strong><?php echo htmlspecialchars($search); ?></strong>"
        <?php endif; ?>
        <?php if (!empty($role_filter) && $role_filter !== 'all'): ?>
            <?php if (!empty($search)): ?> · <?php endif; ?>
            <i class="fas fa-filter me-2"></i>Filter: <strong><?php echo ucfirst($role_filter); ?></strong>
        <?php endif; ?>
    </div>

    <div class="table-container">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><strong style="color:var(--s600);">#<?php echo $row['user_id']; ?></strong></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="user-avatar"><?= strtoupper(substr($row['full_name'], 0, 1)) ?></div>
                                    <strong style="color:var(--s800);"><?php echo htmlspecialchars($row['full_name']); ?></strong>
                                </div>
                            </td>
                            <td style="color:var(--st500);"><?php echo htmlspecialchars($row['email']); ?></td>
                            <td>
                                <span class="role-chip rc-<?= $row['role'] ?>">
                                    <i class="fas fa-<?= $row['role'] === 'admin' ? 'crown' : ($row['role'] === 'caregiver' ? 'hands-helping' : 'user') ?>"></i>
                                    <?php echo $row['role']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['status'] === 'active'): ?>
                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex justify-content-center">
                                    <a href="edit_user.php?id=<?php echo $row['user_id']; ?>" class="btn-edit" title="Edit User">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                    <a href="manage_users.php?delete=<?php echo $row['user_id']; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>"
                                       onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.');"
                                       class="btn-delete" title="Delete User">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-cell">
                                <i class="fas fa-search"></i>
                                <h5>No users found</h5>
                                <p style="font-size:13px;color:var(--st300);">
                                    <?php if (!empty($search) || (!empty($role_filter) && $role_filter !== 'all')): ?>
                                        Try adjusting your search or filter criteria
                                    <?php else: ?>
                                        No users are currently registered in the system
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($search) || (!empty($role_filter) && $role_filter !== 'all')): ?>
                                    <button class="btn-sage" onclick="resetFilters()"><i class="fas fa-redo me-2"></i>Reset Filters</button>
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

<script>
function resetFilters() { window.location.href = 'manage_users.php'; }

let searchTimeout;
const searchInput = document.querySelector('.search-input');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => { document.getElementById('filterForm').submit(); }, 500);
    });
}

document.querySelectorAll('.btn-delete').forEach(link => {
    link.addEventListener('click', function(e) {
        const url = new URL(this.href);
        const search = '<?php echo urlencode($search); ?>';
        const role   = '<?php echo urlencode($role_filter); ?>';
        if (search) url.searchParams.set('search', decodeURIComponent(search));
        if (role && role !== 'all') url.searchParams.set('role', decodeURIComponent(role));
        this.href = url.toString();
    });
});
</script>
</body>
</html>