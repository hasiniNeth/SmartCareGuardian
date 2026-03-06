<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

include 'db_connection.php';

class AIServiceMatch {
    private string  $api_url = 'http://127.0.0.1:5002';
    private int     $timeout = 30;
    private ?mysqli $db;

    public function __construct(?mysqli $db = null) { $this->db = $db; }

    private function makeRequest(string $ep, string $method = 'GET', ?array $data = null): array {
        $ch = curl_init($this->api_url . $ep);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout]);
        if ($method === 'POST') {
            $json = json_encode($data);
            curl_setopt_array($ch, [
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Content-Length: ' . strlen($json)]
            ]);
        }
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);
        if ($err)  return ['success' => false, 'error' => 'Connection error: ' . $err];
        if (!$res) return ['success' => false, 'error' => 'Empty response from AI service. Is Flask running on port 5002?'];
        $result = json_decode($res, true);
        return ($code >= 200 && $code < 300)
            ? ['success' => true,  'data'  => $result]
            : ['success' => false, 'error' => $result['error'] ?? 'Unknown error (HTTP ' . $code . ')'];
    }

    public function getCaregiverMatches(int $resident_id): array {
        if (!$this->db) return ['success' => false, 'error' => 'No DB connection'];
        $stmt = $this->db->prepare("
            SELECT r.user_id, r.medical_conditions, r.allergies, r.dietary_restrictions,
                   COALESCE(
                       (SELECT risk_level FROM ai_risk_logs WHERE resident_id = r.user_id ORDER BY predicted_at DESC LIMIT 1),
                       'low'
                   ) AS risk_level
            FROM residents r WHERE r.user_id = ?
        ");
        $stmt->bind_param("i", $resident_id);
        $stmt->execute();
        $resident = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$resident) return ['success' => false, 'error' => 'Resident not found (user_id=' . $resident_id . ')'];
        $cgs = $this->db->query("
            SELECT c.caregiver_id, c.user_id, u.full_name AS name,
                   COALESCE(c.experience_years, 0) AS experience_years,
                   COALESCE(c.skills, '') AS skills,
                   COUNT(ca.resident_id) AS current_assignments
            FROM caregivers c
            JOIN users u ON c.user_id = u.user_id
            LEFT JOIN caregiver_assignments ca ON ca.caregiver_id = c.user_id
            WHERE u.status = 'active' AND u.role = 'caregiver'
            GROUP BY c.caregiver_id, c.user_id, u.full_name, c.experience_years, c.skills
        ");
        if (!$cgs) return ['success' => false, 'error' => 'DB query failed: ' . $this->db->error];
        $caregivers = $cgs->fetch_all(MYSQLI_ASSOC);
        if (empty($caregivers)) return ['success' => false, 'error' => 'No active caregivers found in database'];
        $result = $this->makeRequest('/suggest/match', 'POST', [
            'resident_id'        => $resident_id,
            'medical_conditions' => $resident['medical_conditions'] ?? '',
            'risk_level'         => $resident['risk_level'],
            'caregivers'         => $caregivers,
        ]);
        if ($result['success'] && !empty($result['data']['suggestions'])) {
            $json  = json_encode($result['data']['suggestions']);
            $risk  = $resident['risk_level'];
            $conds = $resident['medical_conditions'] ?? '';
            $s = $this->db->prepare("
                INSERT INTO caregiver_match_logs (resident_id, suggestions_json, resident_risk_level, resident_conditions, suggested_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $s->bind_param("isss", $resident_id, $json, $risk, $conds);
            $s->execute(); $s->close();
        }
        return $result;
    }

    public function getLatestMatchLog(int $resident_id): ?array {
        if (!$this->db) return null;
        $s = $this->db->prepare("SELECT * FROM caregiver_match_logs WHERE resident_id = ? ORDER BY suggested_at DESC LIMIT 1");
        $s->bind_param("i", $resident_id); $s->execute();
        $row = $s->get_result()->fetch_assoc(); $s->close();
        if ($row) $row['suggestions'] = json_decode($row['suggestions_json'], true) ?? [];
        return $row ?: null;
    }

    public function markMatchUsed(int $log_id, int $caregiver_id): void {
        if (!$this->db) return;
        $s = $this->db->prepare("UPDATE caregiver_match_logs SET was_used=1, used_at=NOW(), assigned_caregiver_id=? WHERE id=?");
        $s->bind_param("ii", $caregiver_id, $log_id); $s->execute(); $s->close();
    }
}

$ai_match       = new AIServiceMatch($conn);
$ai_suggestions = [];
$ai_error       = '';
$match_log_id   = 0;
$selected_res   = isset($_GET['resident_id']) ? (int)$_GET['resident_id'] : 0;

if ($selected_res > 0 && isset($_GET['ai_suggest'])) {
    $result = $ai_match->getCaregiverMatches($selected_res);
    if ($result['success']) {
        $ai_suggestions = $result['data']['suggestions'] ?? [];
        $latest         = $ai_match->getLatestMatchLog($selected_res);
        $match_log_id   = $latest ? (int)$latest['id'] : 0;
    } else {
        $ai_error = $result['error'] ?? 'Unknown error';
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'get_residents' && isset($_GET['caregiver_id'])) {
    $caregiver_id = intval($_GET['caregiver_id']);
    $stmt = $conn->prepare("
        SELECT u.user_id, u.full_name, u.email, u.status
        FROM caregiver_assignments a
        JOIN users u ON a.resident_id = u.user_id
        WHERE a.caregiver_id = ? ORDER BY u.full_name
    ");
    $stmt->bind_param("i", $caregiver_id); $stmt->execute();
    $res = $stmt->get_result(); $data = [];
    while ($row = $res->fetch_assoc()) $data[] = $row;
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'residents' => $data]);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'chart_data') {
    $res = $conn->query("
        SELECT c.user_id, c.full_name, IFNULL(COUNT(a.resident_id),0) AS assigned_count
        FROM users c LEFT JOIN caregiver_assignments a ON a.caregiver_id = c.user_id
        WHERE c.role = 'caregiver' GROUP BY c.user_id, c.full_name ORDER BY c.full_name
    ");
    $labels = []; $counts = [];
    while ($row = $res->fetch_assoc()) { $labels[] = $row['full_name']; $counts[] = intval($row['assigned_count']); }
    header('Content-Type: application/json');
    echo json_encode(['labels' => $labels, 'counts' => $counts]);
    exit();
}

$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_submit'])) {
    $resident_id  = intval($_POST['resident_id']);
    $caregiver_id = intval($_POST['caregiver_id']);
    $log_id       = intval($_POST['match_log_id'] ?? 0);
    $c = $conn->prepare("SELECT user_id FROM users WHERE user_id=? AND role='resident'");
    $c->bind_param("i", $resident_id); $c->execute();
    if ($c->get_result()->num_rows === 0) {
        $message = "<div class='alert alert-danger'>Selected resident does not exist.</div>";
    } else {
        $check = $conn->prepare("SELECT * FROM caregiver_assignments WHERE resident_id = ?");
        $check->bind_param("i", $resident_id); $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $message = "<div class='alert alert-warning'>This resident is already assigned. Remove the previous assignment first.</div>";
        } else {
            $stmt = $conn->prepare("INSERT INTO caregiver_assignments (caregiver_id, resident_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $caregiver_id, $resident_id);
            if ($stmt->execute()) {
                if ($log_id > 0) $ai_match->markMatchUsed($log_id, $caregiver_id);
                $message = "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Caregiver assigned successfully!</div>";
            } else {
                $message = "<div class='alert alert-danger'>Failed to assign. Please try again.</div>";
            }
            $stmt->close();
        }
    }
}

if (isset($_GET['remove_id'])) {
    $remove_id = intval($_GET['remove_id']);
    $del = $conn->prepare("DELETE FROM caregiver_assignments WHERE id = ?");
    $del->bind_param("i", $remove_id);
    if ($del->execute()) { header("Location: assign_caregiver.php?removed=1"); exit(); }
    else $message = "<div class='alert alert-danger'>Failed to remove assignment.</div>";
}

$caregivers  = $conn->query("SELECT user_id, full_name, email FROM users WHERE role='caregiver' AND status='active' ORDER BY full_name");
$residents   = $conn->query("SELECT user_id, full_name, email FROM users WHERE role='resident' AND status='active' ORDER BY full_name");
$assignments = $conn->query("
    SELECT a.id, a.caregiver_id, a.resident_id,
           c.full_name AS caregiver_name, r.full_name AS resident_name
    FROM caregiver_assignments a
    JOIN users c ON a.caregiver_id = c.user_id
    JOIN users r ON a.resident_id  = r.user_id
    ORDER BY c.full_name, r.full_name
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Assign Caregiver - Admin | SmartCare Guardian</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
    <style>
    /* ═══════════════════════════════════════════════════════════
       SMARTCARE GUARDIAN — AYURVEDIC DESIGN SYSTEM
       Assign Caregiver · Professional scale (15px base)
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
        --purple-bg:#EDE9F8;--purple-text:#4A2A7A;
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
        color:var(--st700);min-height:100vh;margin:0;overflow-x:hidden;
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

    /* ── Page header ── */
    .page-header{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;padding:22px 26px;border-radius:var(--radius-lg);margin-bottom:22px;position:relative;overflow:hidden;}
    .page-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.18) 0%,transparent 60%);pointer-events:none;}
    .page-header h2{font-family:'Outfit',sans-serif;font-weight:700;font-size:20px;color:white;margin-bottom:4px;position:relative;}
    .page-header p{font-size:13px;color:rgba(255,255,255,.7);margin:0;position:relative;}
    .feature-icon{font-size:2rem;margin-bottom:10px;color:rgba(255,255,255,.85);position:relative;display:block;}
    .floating{animation:floating 3s ease-in-out infinite;}
    @keyframes floating{0%,100%{transform:translate(0,0)}50%{transform:translate(0,-8px)}}

    /* ── Cards ── */
    .card{background:white;border-radius:var(--radius-lg);border:1px solid rgba(196,217,180,.3);box-shadow:var(--shadow-card);overflow:hidden;}
    .card.p-4{padding:22px!important;}

    /* ── Form controls ── */
    .form-control,.form-select{border:2px solid var(--s100);border-radius:var(--radius-md);padding:10px 14px;font-size:14px;font-family:'Outfit',sans-serif;transition:border-color .2s;background:var(--w50);color:var(--st700);}
    .form-control:focus,.form-select:focus{border-color:var(--s400);box-shadow:none;outline:none;}
    .form-label{font-weight:700;font-size:13px;color:var(--s800);margin-bottom:6px;}
    .info-note{font-size:12px;color:var(--st300);margin-top:6px;}

    /* ── Buttons ── */
    .btn-primary{background:linear-gradient(135deg,var(--s500),var(--s800));border:none;padding:10px 22px;border-radius:var(--radius-md);font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;color:white;transition:all .2s;position:relative;overflow:hidden;display:inline-flex;align-items:center;gap:7px;}
    .btn-primary::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(135deg,var(--s400),var(--s700));transition:left .35s ease;}
    .btn-primary:hover::before{left:0;}
    .btn-primary span,.btn-primary i{position:relative;z-index:2;}
    .btn-primary:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(94,138,64,.35);color:white;}
    .btn-primary:disabled{opacity:.55;cursor:not-allowed;transform:none;}
    .btn-success{background:linear-gradient(135deg,var(--s400),var(--s600));border:none;padding:10px 22px;border-radius:var(--radius-md);font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;color:white;transition:all .2s;}
    .btn-success:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(94,138,64,.35);color:white;}
    .btn-outline-success{border:2px solid var(--s400);color:var(--s600);padding:9px 20px;border-radius:var(--radius-md);font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;background:white;transition:all .2s;}
    .btn-outline-success:hover{background:var(--s400);color:white;}
    .btn-outline-primary{border:2px solid var(--s600);color:var(--s700);padding:9px 20px;border-radius:var(--radius-md);font-weight:700;font-size:13px;font-family:'Outfit',sans-serif;background:white;transition:all .2s;}
    .btn-outline-primary:hover{background:var(--s600);color:white;}
    .btn-danger{background:linear-gradient(135deg,#C87A7A,#8B3A3A);border:none;padding:7px 14px;border-radius:var(--radius-sm);font-weight:700;font-size:12px;font-family:'Outfit',sans-serif;color:white;transition:all .2s;}
    .btn-danger:hover{opacity:.9;transform:translateY(-1px);color:white;}
    .btn-secondary{background:var(--s50);border:1px solid var(--s100);color:var(--st500);padding:8px 18px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;font-family:'Outfit',sans-serif;transition:all .2s;}
    .btn-secondary:hover{background:var(--s100);color:var(--s800);}

    /* ── Alerts ── */
    .alert{border-radius:var(--radius-md);border:none;padding:13px 18px;margin-bottom:18px;font-size:14px;font-weight:600;}
    .alert-success{background:var(--green-bg);color:var(--green-text);}
    .alert-danger  {background:var(--red-bg);  color:var(--red-text);}
    .alert-warning {background:var(--amber-bg);color:var(--amber-text);}

    /* ── Table ── */
    .table{margin:0;background:transparent;}
    .table thead{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;position:relative;}
    .table thead::after{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(157,192,126,.15) 0%,transparent 60%);pointer-events:none;}
    .table th{border:none;padding:13px 12px;font-weight:700;font-family:'Outfit',sans-serif;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:white;position:relative;}
    .table td{border-color:var(--s50);padding:13px 12px;vertical-align:middle;font-size:14px;color:var(--st700);}
    .table tbody tr{transition:all .2s;border-bottom:1px solid var(--s50);}
    .table tbody tr:hover{background:var(--s50);}
    .view-residents-link{color:var(--s600);text-decoration:none;font-weight:700;font-size:14px;}
    .view-residents-link:hover{color:var(--s400);text-decoration:underline;}

    /* ── Chart ── */
    .chart-container{padding:16px;border-radius:var(--radius-md);background:var(--w50);border:1px solid var(--s100);}

    /* ── AI Panel ── */
    .ai-panel{border:2px solid rgba(138,106,178,.3);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:0;background:white;box-shadow:var(--shadow-card);}
    .ai-panel-header{background:linear-gradient(135deg,#5A3A7A,#3A2050);padding:15px 22px;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;}
    .ai-panel-header::before{content:'';position:absolute;inset:0;background-image:radial-gradient(ellipse 100% 80% at 100% 50%,rgba(138,106,178,.25) 0%,transparent 60%);pointer-events:none;}
    .ai-panel-header h5{color:white;margin:0;font-family:'Outfit',sans-serif;font-weight:700;font-size:14px;position:relative;display:flex;align-items:center;gap:8px;}
    .ai-panel-header h5 small{font-size:11px;font-weight:400;opacity:.75;}
    .ai-ready-badge{background:rgba(255,255,255,.15);color:white;padding:4px 13px;border-radius:20px;font-size:12px;font-weight:700;position:relative;}
    .ai-panel-body{padding:22px;}

    /* ── Suggestion cards ── */
    .suggestion-card{border-radius:var(--radius-md);padding:18px;position:relative;transition:all .25s;}
    .suggestion-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-card);}
    .score-circle{width:58px;height:58px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;}
    .assign-from-ai{width:100%;border:none;border-radius:var(--radius-sm);padding:9px;font-weight:700;font-size:12px;cursor:pointer;color:white;transition:all .2s;font-family:'Outfit',sans-serif;}
    .assign-from-ai:hover{transform:translateY(-2px);box-shadow:var(--shadow-soft);}
    .tag-green {background:var(--green-bg);color:var(--green-text);padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;display:inline-block;margin:2px;}
    .tag-orange{background:var(--amber-bg);color:var(--amber-text);padding:3px 9px;border-radius:10px;font-size:11px;font-weight:700;display:inline-block;margin:2px;}
    .rank-badge{position:absolute;top:-11px;left:16px;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:800;font-family:'Outfit',sans-serif;}
    .ai-error-box{background:var(--red-bg);border:2px solid rgba(107,34,34,.15);border-radius:var(--radius-md);padding:14px 18px;color:var(--red-text);font-size:13px;}
    .ai-error-box code{background:rgba(107,34,34,.08);padding:2px 6px;border-radius:4px;font-size:12px;}
    .ai-error-box a{color:var(--red-text);font-weight:700;}
    .ai-empty{text-align:center;padding:36px 20px;color:var(--st300);}
    .ai-empty i{font-size:2.5rem;display:block;margin-bottom:12px;opacity:.3;}
    .ai-empty p{font-size:13px;margin-bottom:4px;}

    /* ── Modal ── */
    .modal-content{border-radius:var(--radius-lg);border:none;box-shadow:var(--shadow-lift);}
    .modal-header{background:linear-gradient(135deg,var(--s600),var(--s800));color:white;border-radius:var(--radius-lg) var(--radius-lg) 0 0;border:none;padding:16px 22px;}
    .modal-header h5{font-family:'Outfit',sans-serif;font-weight:700;font-size:15px;color:white;}
    .modal-header .btn-close{filter:brightness(0) invert(1);opacity:.85;}
    .modal-body{padding:20px 22px;}
    .modal-footer{border-top:1px solid var(--s100);background:var(--s50);border-radius:0 0 var(--radius-lg) var(--radius-lg);padding:14px 22px;}
    .list-group-item{border-color:var(--s100);padding:12px 14px;font-size:14px;}
    .badge.bg-success{background:var(--green-bg)!important;color:var(--green-text)!important;}
    .badge.bg-secondary{background:var(--s50)!important;color:var(--st300)!important;}
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
        <a href="#" class="active"><i class="fa-solid fa-link"></i>Assign Caregivers</a>
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
        <span class="feature-icon floating"><i class="fas fa-link"></i></span>
        <h2>Assign Caregivers</h2>
        <p>Manage caregiver assignments and monitor workloads</p>
    </div>

    <?php if ($message): ?>
        <div class="mb-4"><?= $message; ?></div>
    <?php elseif (isset($_GET['removed'])): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Assignment removed successfully.</div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h4 style="font-size:20px;font-weight:500;color:var(--s800);margin-bottom:4px;">Care Management</h4>
            <p class="info-note mb-0">Assign caregivers to residents and monitor assignment distribution</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="add_caregiver.php" class="btn-outline-success btn"><i class="fas fa-user-plus me-2"></i>Add Caregiver</a>
            <a href="add_resident.php"  class="btn-outline-primary  btn"><i class="fas fa-user-plus me-2"></i>Add Resident</a>
        </div>
    </div>

    <div class="row g-4">

        <!-- Assignment Form -->
        <div class="col-12 col-xl-6">
            <div class="card p-4">
                <h5 style="font-family:'Outfit',sans-serif;font-weight:700;font-size:16px;color:var(--s800);margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-user-plus" style="color:var(--s500);"></i>New Assignment
                </h5>
                <form method="POST" id="assignForm">
                    <div class="mb-4">
                        <label class="form-label"><i class="fas fa-user me-2" style="color:var(--s500);"></i>Select Resident</label>
                        <select name="resident_id" class="form-select" required>
                            <option value="">-- Choose Resident --</option>
                            <?php while ($r = $residents->fetch_assoc()): ?>
                                <option value="<?= $r['user_id']; ?>"><?= htmlspecialchars($r['full_name'] . ' — ' . $r['email']); ?></option>
                            <?php endwhile; ?>
                        </select>
                        <div class="info-note"><i class="fas fa-info-circle me-1"></i>Each resident can have one caregiver. Reassign by removing previous assignment first.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label"><i class="fas fa-hand-holding-heart me-2" style="color:var(--s500);"></i>Select Caregiver</label>
                        <select name="caregiver_id" class="form-select" required>
                            <option value="">-- Choose Caregiver --</option>
                            <?php
                            $careq = $conn->query("SELECT user_id, full_name, email FROM users WHERE role='caregiver' AND status='active' ORDER BY full_name");
                            while ($c = $careq->fetch_assoc()): ?>
                                <option value="<?= $c['user_id']; ?>"><?= htmlspecialchars($c['full_name'] . ' — ' . $c['email']); ?></option>
                            <?php endwhile; ?>
                        </select>
                        <div class="info-note"><i class="fas fa-info-circle me-1"></i>A caregiver may be assigned to multiple residents.</div>
                    </div>
                    <input type="hidden" name="match_log_id" value="0">
                    <button name="assign_submit" class="btn-primary btn"><i class="fas fa-check"></i><span>Assign Caregiver</span></button>
                </form>
            </div>
        </div>

        <!-- Workload Chart -->
        <div class="col-12 col-xl-6">
            <div class="card p-4">
                <h5 style="font-family:'Outfit',sans-serif;font-weight:700;font-size:16px;color:var(--s800);margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-chart-bar" style="color:var(--s500);"></i>Caregiver Workload
                </h5>
                <div class="chart-container"><canvas id="workloadChart" height="200"></canvas></div>
                <div class="info-note mt-3"><i class="fas fa-chart-line me-1"></i>Number of residents assigned to each caregiver</div>
            </div>
        </div>

        <!-- AI Panel -->
        <div class="col-12">
            <div class="ai-panel">
                <div class="ai-panel-header">
                    <h5>
                        <i class="fas fa-brain"></i>AI Caregiver Matching Suggestions
                        <small>Random Forest Model · Port 5002</small>
                    </h5>
                    <?php if (!empty($ai_suggestions)): ?>
                        <span class="ai-ready-badge"><i class="fas fa-check me-1"></i><?= count($ai_suggestions); ?> suggestions ready</span>
                    <?php endif; ?>
                </div>

                <div class="ai-panel-body">
                    <!-- Resident selector + button -->
                    <div class="row g-3 mb-4 align-items-end">
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-user me-2" style="color:var(--s500);"></i>Select Resident for AI Analysis</label>
                            <select id="aiResidentSelect" class="form-select"
                                    onchange="if(this.value) window.location='assign_caregiver.php?resident_id='+this.value;">
                                <option value="">-- Choose Resident --</option>
                                <?php
                                $res_q = $conn->query("
                                    SELECT u.user_id, u.full_name, r.medical_conditions
                                    FROM users u JOIN residents r ON r.user_id = u.user_id
                                    WHERE u.role='resident' AND u.status='active' ORDER BY u.full_name
                                ");
                                while ($r = $res_q->fetch_assoc()):
                                    $sel   = ($selected_res === (int)$r['user_id']) ? 'selected' : '';
                                    $conds = $r['medical_conditions'] ? ' — ' . substr($r['medical_conditions'], 0, 28) . '…' : '';
                                ?>
                                    <option value="<?= $r['user_id']; ?>" <?= $sel; ?>><?= htmlspecialchars($r['full_name'] . $conds); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <?php if ($selected_res > 0): ?>
                                <a href="assign_caregiver.php?resident_id=<?= $selected_res; ?>&ai_suggest=1" class="btn btn-primary w-100">
                                    <i class="fas fa-magic"></i><span>Get AI Suggestions</span>
                                </a>
                            <?php else: ?>
                                <button class="btn btn-primary w-100" disabled><i class="fas fa-magic"></i><span>Get AI Suggestions</span></button>
                            <?php endif; ?>
                        </div>
                        <?php if ($selected_res > 0 && !empty($ai_suggestions)): ?>
                        <div class="col-md-3">
                            <a href="assign_caregiver.php?resident_id=<?= $selected_res; ?>&ai_suggest=1" class="btn btn-outline-success w-100">
                                <i class="fas fa-rotate-right me-2"></i>Refresh
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Error -->
                    <?php if ($ai_error): ?>
                        <div class="ai-error-box mb-3">
                            <i class="fas fa-triangle-exclamation me-2"></i>
                            <strong>AI Service Error:</strong> <?= htmlspecialchars($ai_error); ?>
                            <div style="margin-top:8px;font-size:12px;">
                                <strong>Fix:</strong> Make sure Flask is running →
                                <code>cd C:\wamp64\www\SmartCareGuardian\ai-service\assign_caregiver_suggestions</code>
                                then <code>python app.py</code>
                                and check <a href="http://127.0.0.1:5002/" target="_blank">http://127.0.0.1:5002/</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Suggestion cards -->
                    <?php if (!empty($ai_suggestions)):
                        $rname_q = $conn->prepare("SELECT full_name FROM users WHERE user_id=?");
                        $rname_q->bind_param("i", $selected_res); $rname_q->execute();
                        $rname = $rname_q->get_result()->fetch_assoc()['full_name'] ?? '';
                    ?>
                        <p style="font-size:13px;color:var(--st300);margin-bottom:18px;">
                            <i class="fas fa-info-circle me-1" style="color:var(--purple-text);"></i>
                            Top <?= count($ai_suggestions); ?> caregiver matches for <strong style="color:var(--s800);"><?= htmlspecialchars($rname); ?></strong>
                            — ranked by Random Forest match probability.
                        </p>
                        <div class="row g-3">
                        <?php
                        $grade_colors = [
                            'Excellent' => ['bg'=>'#DDEFD8','fg'=>'#3A6830','bd'=>'#9DC07E'],
                            'Good'      => ['bg'=>'#DBEEFF','fg'=>'#1A4870','bd'=>'#6AAED4'],
                            'Fair'      => ['bg'=>'#FAECC8','fg'=>'#7A5010','bd'=>'#C8A44C'],
                            'Poor'      => ['bg'=>'#F5DADA','fg'=>'#6A2020','bd'=>'#C87A7A'],
                        ];
                        $medals = ['🥇 Best Match','🥈 2nd Match','🥉 3rd Match'];
                        foreach ($ai_suggestions as $i => $sg):
                            $grade = $sg['match_grade'] ?? 'Fair';
                            $c     = $grade_colors[$grade] ?? $grade_colors['Fair'];
                            $prob  = $sg['match_probability'];
                        ?>
                        <div class="col-md-4">
                            <div class="suggestion-card" style="border:2px solid <?= $c['bd'] ?>;background:linear-gradient(135deg,<?= $c['bg'] ?>,#fff);">
                                <span class="rank-badge" style="background:<?= $c['bd'] ?>;color:<?= $c['fg'] ?>;">
                                    <?= $medals[$i] ?? '#'.($i+1); ?>
                                </span>
                                <div class="d-flex align-items-center gap-3 mt-2 mb-3">
                                    <div class="score-circle" style="background:<?= $c['bg'] ?>;border:3px solid <?= $c['bd'] ?>;">
                                        <span style="font-size:1rem;font-weight:800;color:<?= $c['fg'] ?>;line-height:1;"><?= $prob; ?>%</span>
                                        <span style="font-size:10px;color:<?= $c['fg'] ?>;font-weight:700;">match</span>
                                    </div>
                                    <div>
                                        <div style="font-weight:700;font-size:14px;color:var(--s800);"><?= htmlspecialchars($sg['name']); ?></div>
                                        <div style="font-size:12px;color:var(--st300);">
                                            <i class="fas fa-briefcase me-1"></i><?= (int)$sg['experience_years']; ?> yrs &nbsp;·&nbsp;
                                            <i class="fas fa-users me-1"></i><?= (int)$sg['current_assignments']; ?> residents
                                        </div>
                                        <span style="background:<?= $c['bg'] ?>;color:<?= $c['fg'] ?>;border:1px solid <?= $c['bd'] ?>;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:800;"><?= $grade; ?></span>
                                    </div>
                                </div>
                                <div style="margin-bottom:12px;">
                                    <?php foreach (($sg['reasons'] ?? []) as $r): ?>
                                        <span class="tag-green"><i class="fas fa-check me-1"></i><?= htmlspecialchars($r); ?></span>
                                    <?php endforeach; ?>
                                    <?php foreach (($sg['warnings'] ?? []) as $w): ?>
                                        <span class="tag-orange"><i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($w); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="resident_id"  value="<?= $selected_res; ?>">
                                    <input type="hidden" name="caregiver_id" value="<?= (int)$sg['user_id']; ?>">
                                    <input type="hidden" name="match_log_id" value="<?= $match_log_id; ?>">
                                    <button type="submit" name="assign_submit" class="assign-from-ai"
                                            style="background:linear-gradient(135deg,<?= $c['bd'] ?>,<?= $c['fg'] ?>);">
                                        <i class="fas fa-link me-2"></i>Assign This Caregiver
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>

                    <?php elseif ($selected_res > 0 && !$ai_error): ?>
                        <div class="ai-empty">
                            <i class="fas fa-robot" style="color:var(--purple-bg);"></i>
                            <p style="font-weight:700;color:var(--s800);">Click "Get AI Suggestions" above</p>
                            <p>The Random Forest model will score all active caregivers and show the top 3 matches</p>
                        </div>
                    <?php elseif (!$ai_error): ?>
                        <div class="ai-empty">
                            <i class="fas fa-brain" style="color:var(--purple-bg);"></i>
                            <p style="font-weight:700;color:var(--s800);">Select a resident to get started</p>
                            <p>The AI will analyse caregiver skills, experience, and workload against the resident's medical conditions</p>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <!-- Assignments Table -->
        <div class="col-12">
            <div class="card p-4">
                <h5 style="font-family:'Outfit',sans-serif;font-weight:700;font-size:16px;color:var(--s800);margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-list-check" style="color:var(--s500);"></i>Current Assignments
                </h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><i class="fas fa-user-nurse me-2"></i>Caregiver</th>
                                <th><i class="fas fa-user me-2"></i>Resident</th>
                                <th style="width:140px;"><i class="fas fa-gears me-2"></i>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($assignments->num_rows > 0): ?>
                            <?php while ($row = $assignments->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <a href="#" class="view-residents-link" data-caregiver-id="<?= $row['caregiver_id']; ?>">
                                        <i class="fas fa-user-nurse me-2" style="color:var(--s400);"></i><?= htmlspecialchars($row['caregiver_name']); ?>
                                    </a>
                                </td>
                                <td><i class="fas fa-user me-2" style="color:var(--st300);"></i><?= htmlspecialchars($row['resident_name']); ?></td>
                                <td>
                                    <a href="remove_assignment.php?id=<?= $row['id']; ?>"
                                       class="btn btn-danger btn-sm"
                                       onclick="return confirm('Remove this assignment?');">
                                        <i class="fas fa-trash me-1"></i>Remove
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" style="text-align:center;padding:40px 20px;color:var(--st300);">
                                    <i class="fas fa-link" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:.25;"></i>
                                    <h5 style="font-family:'Outfit',sans-serif;font-weight:700;color:var(--st500);">No Assignments Found</h5>
                                    <p style="font-size:13px;">Use the form above or AI suggestions to assign a caregiver.</p>
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

<!-- Modal -->
<div class="modal fade" id="residentsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-group me-2"></i>Assigned Residents</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body"><div id="residentsList"><div class="text-center py-4" style="color:var(--st300);"><i class="fas fa-spinner fa-spin me-2"></i>Loading…</div></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    fetch('assign_caregiver.php?action=chart_data')
        .then(r => r.json())
        .then(data => {
            new Chart(document.getElementById('workloadChart'), {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Assigned Residents',
                        data: data.counts,
                        backgroundColor: data.counts.map(c => c === 0 ? 'rgba(184,176,164,0.4)' : 'rgba(94,138,64,0.75)'),
                        borderColor: 'rgba(54,82,32,0.8)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1, color: '#B8B0A4' }, grid: { color: 'rgba(36,56,22,.04)' } },
                              x: { ticks: { color: '#7A7268' }, grid: { display: false } } },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'white', titleColor: '#243816', bodyColor: '#7A7268',
                            borderColor: '#E3EDDB', borderWidth: 1, cornerRadius: 10, padding: 10,
                            callbacks: { label: c => `${c.parsed.y} residents` }
                        }
                    }
                }
            });
        });

    document.querySelectorAll('.view-residents-link').forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const cid       = this.getAttribute('data-caregiver-id');
            const modal     = new bootstrap.Modal(document.getElementById('residentsModal'));
            const container = document.getElementById('residentsList');
            container.innerHTML = '<div class="text-center py-4" style="color:var(--st300);"><i class="fas fa-spinner fa-spin me-2"></i>Loading…</div>';
            fetch(`assign_caregiver.php?action=get_residents&caregiver_id=${encodeURIComponent(cid)}`)
                .then(r => r.json())
                .then(json => {
                    if (!json.success || json.residents.length === 0) {
                        container.innerHTML = '<div class="text-center py-4" style="color:var(--st300);">No residents assigned.</div>';
                        return;
                    }
                    container.innerHTML = '<div class="list-group">' +
                        json.residents.map(r => `
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div><strong>${esc(r.full_name)}</strong><br><small style="color:var(--st300);">${esc(r.email)}</small></div>
                                <span class="badge bg-${r.status === 'active' ? 'success' : 'secondary'}">${r.status}</span>
                            </div>`).join('') + '</div>';
                })
                .catch(() => { container.innerHTML = '<div style="color:var(--red-text);">Network error.</div>'; });
            modal.show();
        });
    });

    function esc(s) {
        return s.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
    }
});
</script>
</body>
</html>