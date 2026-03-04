<?php
/**
 * SmartCare Guardian — AI Service Connector v2
 * FIXED: 30-min dedup in savePrediction (stops repeat rows on page reload)
 * FIXED: autoCreateAlert uses alert_type='ai_risk' always (dedup now works)
 */
class AIService {
    private string  $api_url = 'http://127.0.0.1:5000';
    private int     $timeout = 30;
    private ?mysqli $db;

    public function __construct(?mysqli $db = null) { $this->db = $db; }

    private function makeRequest(string $ep, string $method = 'GET', ?array $data = null): array {
        $ch = curl_init($this->api_url . $ep);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout]);
        if ($method === 'POST') {
            $json = json_encode($data);
            curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$json,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json','Content-Length: '.strlen($json)]]);
        }
        $res=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_errno($ch)?curl_error($ch):null;
        curl_close($ch);
        if($err) return ['success'=>false,'error'=>'Connection error: '.$err];
        $result=json_decode($res,true);
        return ($code>=200&&$code<300)?['success'=>true,'data'=>$result]:['success'=>false,'error'=>$result['message']??'Unknown error'];
    }
    public function checkStatus(): array  { return $this->makeRequest('/'); }
    public function getModelInfo(): array { return $this->makeRequest('/model/info'); }
    public function getPrediction(int $rid, array $vitals): array {
        return $this->makeRequest('/predict','POST',array_merge(['resident_id'=>$rid],$vitals));
    }
    public function getBatchPredictions(array $readings): array {
        return $this->makeRequest('/predict/batch','POST',['readings'=>$readings]);
    }

    public function savePrediction(int $rid, array $pred, array $alert_info, array $vitals, bool $fallback=false, string $model='Unknown'): int {
        if (!$this->db) return 0;

        // Round incoming vitals for comparison
        $bp_s = isset($vitals['blood_pressure_systolic'])  ? round((float)$vitals['blood_pressure_systolic'],1)  : null;
        $bp_d = isset($vitals['blood_pressure_diastolic']) ? round((float)$vitals['blood_pressure_diastolic'],1) : null;
        $bs   = isset($vitals['blood_sugar'])              ? round((float)$vitals['blood_sugar'],1)              : null;
        $pu   = isset($vitals['pulse'])                    ? round((float)$vitals['pulse'],1)                    : null;
        $wt   = isset($vitals['weight'])                   ? round((float)$vitals['weight'],1)                   : null;
        $tm   = isset($vitals['temperature'])              ? round((float)$vitals['temperature'],1)              : null;
        $o2   = isset($vitals['oxygen_saturation'])        ? round((float)$vitals['oxygen_saturation'],1)        : null;

        // DEDUP: if vitals match the last saved row exactly, this is a page refresh — skip
        $prev_row = $this->getLatestSaved($rid);
        if ($prev_row) {
            $same = $this->eq($prev_row['bp_systolic'], $bp_s)
                && $this->eq($prev_row['blood_sugar'],  $bs)
                && $this->eq($prev_row['pulse'],        $pu)
                && $this->eq($prev_row['oxygen_saturation'], $o2);
            if ($same) return (int)$prev_row['id'];
        }

        $level  = $pred['risk_level']       ?? 'low';
        $pct    = (float)($pred['risk_percentage']  ?? 0);
        $prob   = (float)($pred['risk_probability'] ?? $pct/100);
        $pred_b = (int)($pred['risk_prediction']    ?? ($level!=='low'?1:0));
        $flagged= $alert_info['alerts'] ?? [];
        $tflag  = count($flagged);
        $fjson  = json_encode($flagged);
        $fb     = (int)$fallback;
        $prev_lv= $prev_row ? $prev_row['risk_level'] : null;
        $esc    = (int)$this->isEscalation($prev_lv, $level);

        $s = $this->db->prepare("INSERT INTO ai_risk_logs
            (resident_id,risk_level,risk_percentage,risk_probability,risk_prediction,
            model_used,is_fallback,bp_systolic,bp_diastolic,blood_sugar,pulse,weight,temperature,oxygen_saturation,
            flagged_vitals,total_flagged,is_escalation,previous_risk_level,predicted_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $s->bind_param("isddiisdddddddsiii",
            $rid,$level,$pct,$prob,$pred_b,$model,$fb,
            $bp_s,$bp_d,$bs,$pu,$wt,$tm,$o2,
            $fjson,$tflag,$esc,$prev_lv);
        $s->execute();
        $log_id = (int)$this->db->insert_id;
        $s->close();
        if (!$log_id) return 0;

        $alert_id = 0;
        if ($level==='high' || ($esc && in_array($level,['high','medium']))) {
            $alert_id = $this->autoCreateAlert($rid,$level,$pct,$flagged,(bool)$esc,$prev_lv);
        }
        if ($alert_id) {
            $u = $this->db->prepare("UPDATE ai_risk_logs SET alert_created=1,alert_id=? WHERE id=?");
            $u->bind_param("ii",$alert_id,$log_id); $u->execute(); $u->close();
        }
        return $log_id;
    }

    // ── Caregiver Match Suggestion ────────────────────────────────────────────

    public function getCaregiverMatches(int $resident_id): array {
    if (!$this->db) return ['success' => false, 'error' => 'No DB'];
    $res = $this->db->prepare("
        SELECT r.user_id, r.medical_conditions, r.allergies, r.dietary_restrictions,
               COALESCE((SELECT risk_level FROM ai_risk_logs
                         WHERE resident_id = r.user_id
                         ORDER BY predicted_at DESC LIMIT 1), 'low') AS risk_level
        FROM residents r WHERE r.user_id = ?
    ");
    $res->bind_param("i", $resident_id);
    $res->execute();
    $resident = $res->get_result()->fetch_assoc();
    $res->close();
    if (!$resident) return ['success' => false, 'error' => 'Resident not found'];

    $cgs = $this->db->query("
        SELECT c.caregiver_id, c.user_id, u.full_name AS name,
               c.experience_years, c.skills,
               COUNT(ca.resident_id) AS current_assignments
        FROM caregivers c
        JOIN users u ON c.user_id = u.user_id
        LEFT JOIN caregiver_assignments ca ON ca.caregiver_id = c.user_id
        WHERE u.status = 'active'
        GROUP BY c.caregiver_id
    ")->fetch_all(MYSQLI_ASSOC);

    if (empty($cgs)) return ['success' => false, 'error' => 'No caregivers available'];

    $result = $this->makeRequest('/suggest/match', 'POST', [
        'resident_id'        => $resident_id,
        'medical_conditions' => $resident['medical_conditions'] ?? '',
        'risk_level'         => $resident['risk_level'],
        'caregivers'         => $cgs,
    ]);

    if ($result['success'] && !empty($result['data']['suggestions'])) {
        $json  = json_encode($result['data']['suggestions']);
        $risk  = $resident['risk_level'];
        $conds = $resident['medical_conditions'] ?? '';
        $s = $this->db->prepare("INSERT INTO caregiver_match_logs
            (resident_id, suggestions_json, resident_risk_level, resident_conditions, suggested_at)
            VALUES (?, ?, ?, ?, NOW())");
        $s->bind_param("isss", $resident_id, $json, $risk, $conds);
        $s->execute(); $s->close();
    }
    return $result;
}

public function markMatchUsed(int $log_id, int $caregiver_id): void {
    if (!$this->db) return;
    $s = $this->db->prepare("UPDATE caregiver_match_logs
        SET was_used=1, used_at=NOW(), assigned_caregiver_id=? WHERE id=?");
    $s->bind_param("ii", $caregiver_id, $log_id);
    $s->execute(); $s->close();
}

public function getLatestMatchLog(int $resident_id): ?array {
    if (!$this->db) return null;
    $s = $this->db->prepare("SELECT * FROM caregiver_match_logs
        WHERE resident_id=? ORDER BY suggested_at DESC LIMIT 1");
    $s->bind_param("i", $resident_id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc(); $s->close();
    if ($row) $row['suggestions'] = json_decode($row['suggestions_json'], true) ?? [];
    return $row ?: null;
}

    private function eq($a, $b): bool {
        if ($a===null && $b===null) return true;
        if ($a===null || $b===null) return false;
        return abs((float)$a-(float)$b) < 0.05;
    }

    private function autoCreateAlert(int $rid, string $level, float $pct, array $flagged, bool $esc, ?string $prev): int {
        if (!$this->db) return 0;

        // ONE ai_risk alert per resident per calendar day — no exceptions
        $chk = $this->db->prepare("
            SELECT alert_id FROM alerts
            WHERE  resident_id = ? AND alert_type = 'ai_risk' AND DATE(created_at) = CURDATE()
            LIMIT  1
        ");
        $chk->bind_param("i",$rid); $chk->execute();
        $ex = $chk->get_result()->fetch_assoc(); $chk->close();
        if ($ex) return (int)$ex['alert_id'];

        $summary='';
        if (!empty($flagged)) {
            $parts=array_map(fn($a)=>$a['vital_sign'].' ('.$a['status'].')',array_slice($flagged,0,3));
            $summary=' — '.implode(', ',$parts);
        }
        $msg = $esc && $prev
            ? 'AI Risk ESCALATED from '.strtoupper($prev).' to '.strtoupper($level).' ('.$pct.'%)'.$summary
            : 'AI Risk: '.strtoupper($level).' ('.$pct.'%)'.$summary;

        $ins=$this->db->prepare("INSERT INTO alerts (resident_id,alert_type,alert_message,resolved,created_at) VALUES (?,'ai_risk',?,0,NOW())");
        $ins->bind_param("is",$rid,$msg); $ins->execute();
        $id=(int)$this->db->insert_id; $ins->close();
        return $id;
    }

    private function isEscalation(?string $prev, string $cur): bool {
        $r=['low'=>0,'medium'=>1,'high'=>2];
        return $prev!==null&&($r[$cur]??0)>($r[$prev]??0);
    }

    private function getLatestSaved(int $rid): ?array {
        if (!$this->db) return null;
        $s=$this->db->prepare("
            SELECT id, risk_level, bp_systolic, blood_sugar, pulse, oxygen_saturation, predicted_at
            FROM   ai_risk_logs
            WHERE  resident_id = ?
            ORDER  BY predicted_at DESC LIMIT 1
        ");
        $s->bind_param("i",$rid); $s->execute();
        $r=$s->get_result()->fetch_assoc(); $s->close();
        return $r ?: null;
    }

    public function getTrend(int $rid, int $days=14): array {
        if(!$this->db) return [];
        $s=$this->db->prepare("SELECT DATE(predicted_at) AS date, ROUND(AVG(risk_percentage),1) AS avg_pct, (SELECT risk_level FROM ai_risk_logs a2 WHERE a2.resident_id=? AND DATE(a2.predicted_at)=DATE(a.predicted_at) GROUP BY risk_level ORDER BY COUNT(*) DESC LIMIT 1) AS level FROM ai_risk_logs a WHERE resident_id=? AND predicted_at>=DATE_SUB(CURDATE(),INTERVAL ? DAY) GROUP BY DATE(predicted_at) ORDER BY date ASC");
        $s->bind_param("iii",$rid,$rid,$days); $s->execute();
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
        return $rows;
    }

    public function getHistory(int $rid, int $limit=50, int $offset=0): array {
        if(!$this->db) return [];
        $s=$this->db->prepare("SELECT id,risk_level,risk_percentage,model_used,is_fallback,bp_systolic,bp_diastolic,blood_sugar,pulse,weight,temperature,oxygen_saturation,flagged_vitals,total_flagged,is_escalation,previous_risk_level,alert_created,predicted_at FROM ai_risk_logs WHERE resident_id=? ORDER BY predicted_at DESC LIMIT ? OFFSET ?");
        $s->bind_param("iii",$rid,$limit,$offset); $s->execute();
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
        foreach($rows as &$r){
            $r['flagged_vitals']=json_decode($r['flagged_vitals']??'[]',true)??[];
            $r['predicted_at_fmt']=date('d M Y, g:i A',strtotime($r['predicted_at']));
        }
        return $rows;
    }

    public function getLatestRisksForResidents(array $ids): array {
        if(!$this->db||empty($ids)) return [];
        $ph=implode(',',array_fill(0,count($ids),'?')); $tp=str_repeat('i',count($ids));
        $s=$this->db->prepare("SELECT l.resident_id,l.risk_level,l.risk_percentage,l.total_flagged,l.is_escalation,l.predicted_at FROM ai_risk_logs l INNER JOIN (SELECT resident_id,MAX(predicted_at) AS mt FROM ai_risk_logs WHERE resident_id IN ($ph) GROUP BY resident_id) lt ON l.resident_id=lt.resident_id AND l.predicted_at=lt.mt");
        $s->bind_param($tp,...$ids); $s->execute();
        $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
        $out=[]; foreach($rows as $r) $out[(int)$r['resident_id']]=$r;
        return $out;
    }

    public function formatRiskLevel(string $l): array {
        return ['low'=>['class'=>'success','icon'=>'check-circle','text'=>'Low Risk','color'=>'#28a745'],
                'medium'=>['class'=>'warning','icon'=>'exclamation-triangle','text'=>'Medium Risk','color'=>'#ffc107'],
                'high'=>['class'=>'danger','icon'=>'exclamation-circle','text'=>'High Risk','color'=>'#dc3545']][$l]
            ??['class'=>'secondary','icon'=>'minus','text'=>'Unknown','color'=>'#6c757d'];
    }

    public function formatVitalStatus(string $s): array {
        return ['LOW'=>['class'=>'primary','icon'=>'arrow-down','text'=>'Below Normal'],
                'HIGH'=>['class'=>'danger','icon'=>'arrow-up','text'=>'Above Normal']][$s]
            ??['class'=>'secondary','icon'=>'minus','text'=>'Normal'];
    }
}
?>