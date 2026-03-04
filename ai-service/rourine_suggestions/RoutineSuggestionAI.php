<?php
/**
 * SmartCare Guardian — AI Routine Suggestion Bridge v2
 * =====================================================
 * Overlap checks:
 *   1. Dedup    — skip re-generating if same routine_type already suggested TODAY (status=pending)
 *   2. Demote   — if AI says 'add' but a pending routine of that type already exists → demote to 'change'
 *   3. Conflict — flag time_conflict=1 if another routine is scheduled within ±30 min (uses schedule_time)
 * Saves every new suggestion to ai_routine_suggestions table.
 *
 * Routines table statuses : 'pending' | 'completed' | 'cancelled'
 * Routines table time col : schedule_time
 */
class RoutineSuggestionAI {

    private string $apiUrl = "http://127.0.0.1:5001";
    private mysqli $db;

    public function __construct(mysqli $db) {
        $this->db = $db;
    }

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function getSuggestionsForResident(int $residentId, int $caregiverId): array {
        $data = $this->buildResidentData($residentId);
        if (!$data) return ["error" => "Resident not found"];

        $api = $this->callApi($data);
        if (!$api || isset($api["error"])) return ["error" => "AI service unavailable"];

        $suggestions = $this->processAndStore($api['suggestions'] ?? [], $residentId, $caregiverId);
        return [
            "status"      => "success",
            "resident_id" => $residentId,
            "total"       => count($suggestions),
            "suggestions" => $suggestions,
        ];
    }

    public function acceptSuggestion(int $id, int $caregiverId): bool {
        $s = $this->db->prepare(
            "UPDATE ai_routine_suggestions SET status='accepted', acted_at=NOW() WHERE id=? AND caregiver_id=?"
        );
        $s->bind_param("ii", $id, $caregiverId);
        $s->execute();
        $ok = $s->affected_rows > 0;
        $s->close();
        return $ok;
    }

    public function dismissSuggestion(int $id, int $caregiverId): bool {
        $s = $this->db->prepare(
            "UPDATE ai_routine_suggestions SET status='dismissed', acted_at=NOW() WHERE id=? AND caregiver_id=?"
        );
        $s->bind_param("ii", $id, $caregiverId);
        $s->execute();
        $ok = $s->affected_rows > 0;
        $s->close();
        return $ok;
    }

    // =========================================================================
    // OVERLAP CHECK 1 — same type already suggested today (still pending)
    // =========================================================================

    private function alreadySuggestedToday(int $residentId, string $type): bool {
        $s = $this->db->prepare("
            SELECT id FROM ai_routine_suggestions
            WHERE  resident_id        = ?
              AND  routine_type       = ?
              AND  DATE(generated_at) = CURDATE()
              AND  status             = 'pending'
            LIMIT 1
        ");
        $s->bind_param("is", $residentId, $type);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        return $row !== null;
    }

    private function loadPendingSuggestion(int $residentId, string $type): ?array {
        $s = $this->db->prepare("
            SELECT id, routine_type, action, title, description,
                   confidence, suggested_time, time_conflict, status
            FROM   ai_routine_suggestions
            WHERE  resident_id        = ?
              AND  routine_type       = ?
              AND  DATE(generated_at) = CURDATE()
              AND  status             = 'pending'
            ORDER  BY id DESC LIMIT 1
        ");
        $s->bind_param("is", $residentId, $type);
        $s->execute();
        $r = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$r) return null;
        return [
            'id'             => (int)$r['id'],
            'routine_type'   => $r['routine_type'],
            'action'         => $r['action'],
            'title'          => $r['title'],
            'description'    => $r['description'],
            'confidence'     => (float)$r['confidence'],
            'suggested_time' => $r['suggested_time'],
            'time_conflict'  => (int)$r['time_conflict'],
            'status'         => $r['status'],
            '_from_cache'    => true,
        ];
    }

    // =========================================================================
    // OVERLAP CHECK 2 — a pending routine of this type already exists
    // 'pending' in routines = scheduled but not yet completed
    // =========================================================================

    private function activeExists(int $userId, string $type): bool {
        $s = $this->db->prepare("
            SELECT id FROM routines
            WHERE  resident_id         = ?
              AND  LOWER(routine_type) = ?
              AND  status              = 'pending'
            LIMIT 1
        ");
        $s->bind_param("is", $userId, $type);
        $s->execute();
        $r = $s->get_result()->fetch_assoc();
        $s->close();
        return $r !== null;
    }

    // =========================================================================
    // OVERLAP CHECK 3 — schedule_time conflict within ±30 minutes
    // =========================================================================

    private function checkTimeConflict(int $userId, string $type): array {
        // Get schedule_time of the existing pending routine of this type
        $s = $this->db->prepare("
            SELECT schedule_time FROM routines
            WHERE  resident_id         = ?
              AND  LOWER(routine_type) = ?
              AND  schedule_time IS NOT NULL
              AND  status              = 'pending'
            ORDER  BY id DESC LIMIT 1
        ");
        $s->bind_param("is", $userId, $type);
        $s->execute();
        $r = $s->get_result()->fetch_assoc();
        $s->close();

        if (!$r) return ['conflict' => false, 'time' => null];

        $existingTime = $r['schedule_time'];

        // Check if any OTHER routine type is scheduled within 30 min of this one
        $c = $this->db->prepare("
            SELECT id FROM routines
            WHERE  resident_id         = ?
              AND  LOWER(routine_type) != ?
              AND  schedule_time IS NOT NULL
              AND  status              = 'pending'
              AND  ABS(TIMESTAMPDIFF(
                       MINUTE,
                       SEC_TO_TIME(TIME_TO_SEC(schedule_time)),
                       SEC_TO_TIME(TIME_TO_SEC(?))
                   )) <= 30
            LIMIT 1
        ");
        $c->bind_param("iss", $userId, $type, $existingTime);
        $c->execute();
        $conflict = $c->get_result()->fetch_assoc();
        $c->close();

        return ['conflict' => $conflict !== null, 'time' => $existingTime];
    }

    // =========================================================================
    // PROCESS + STORE SUGGESTIONS
    // =========================================================================

    private function processAndStore(array $suggestions, int $residentId, int $caregiverId): array {
        // routines table uses user_id as the resident FK
        $s = $this->db->prepare("SELECT user_id FROM residents WHERE resident_id = ?");
        $s->bind_param("i", $residentId);
        $s->execute();
        $r      = $s->get_result()->fetch_assoc();
        $s->close();
        $userId = $r ? (int)$r['user_id'] : 0;

        $final = [];

        foreach ($suggestions as $sg) {
            $type   = strtolower($sg['routine_type']);
            $action = strtolower($sg['action']);

            // ── Check 1: already suggested today → reuse the existing record ──
            if ($this->alreadySuggestedToday($residentId, $type)) {
                $existing = $this->loadPendingSuggestion($residentId, $type);
                if ($existing) $final[] = $existing;
                continue;
            }

            // ── Check 2: pending routine exists → demote 'add' to 'change' ──
            if ($action === 'add' && $userId && $this->activeExists($userId, $type)) {
                $sg['action']      = 'change';
                $sg['title']       = 'Review Existing ' . ucfirst($type) . ' Routine';
                $sg['description'] = 'A ' . $type . ' routine is already scheduled. ' . $sg['description'];
            }

            // ── Check 3: time conflict ─────────────────────────────────────
            $ti   = $userId ? $this->checkTimeConflict($userId, $type) : ['conflict' => false, 'time' => null];
            $conf = round((float)$sg['confidence'], 2);
            $tc   = $ti['conflict'] ? 1 : 0;

            // ── Save to DB ─────────────────────────────────────────────────
            $ins = $this->db->prepare("
                INSERT INTO ai_routine_suggestions
                    (resident_id, caregiver_id, routine_type, action,
                     title, description, confidence,
                     suggested_time, time_conflict, status, generated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $ins->bind_param(
                "iissssdsi",
                $residentId, $caregiverId,
                $sg['routine_type'], $sg['action'],
                $sg['title'], $sg['description'],
                $conf, $ti['time'], $tc
            );
            $ins->execute();
            $newId = $this->db->insert_id;
            $ins->close();

            $sg['id']             = $newId;
            $sg['time_conflict']  = $tc;
            $sg['suggested_time'] = $ti['time'];
            $final[] = $sg;
        }

        return $final;
    }

    // =========================================================================
    // BUILD RESIDENT DATA PAYLOAD FOR AI
    // =========================================================================

    private function buildResidentData(int $residentId): ?array {
        $s = $this->db->prepare("
            SELECT r.resident_id, r.user_id, r.gender,
                   TIMESTAMPDIFF(YEAR, r.dob, CURDATE()) AS age,
                   r.medical_conditions, r.dietary_restrictions
            FROM   residents r
            WHERE  r.resident_id = ?
        ");
        $s->bind_param("i", $residentId);
        $s->execute();
        $res = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$res) return null;

        $uid = (int)$res['user_id'];

        // Average vitals — last 30 days
        $s = $this->db->prepare("
            SELECT AVG(blood_pressure_systolic)  AS bp_s,
                   AVG(blood_pressure_diastolic) AS bp_d,
                   AVG(blood_sugar)              AS bs,
                   AVG(pulse)                    AS pulse,
                   AVG(weight)                   AS weight,
                   AVG(temperature)              AS temp,
                   AVG(oxygen_saturation)        AS spo2
            FROM   health_logs
            WHERE  resident_id = ?
              AND  logged_at  >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $s->bind_param("i", $residentId);
        $s->execute();
        $v = $s->get_result()->fetch_assoc();
        $s->close();

        // Existing routine types — exclude cancelled
        $s = $this->db->prepare("
            SELECT LOWER(routine_type) AS rt
            FROM   routines
            WHERE  resident_id = ?
              AND  status IN ('pending', 'completed')
        ");
        $s->bind_param("i", $uid);
        $s->execute();
        $types = array_column($s->get_result()->fetch_all(MYSQLI_ASSOC), 'rt');
        $s->close();

        $c = strtolower($res['medical_conditions']   ?? '');
        $d = strtolower($res['dietary_restrictions'] ?? '');

        return [
            "resident_id"               => (int)$residentId,
            "age"                       => (int)$res['age'],
            "gender"                    => ucfirst(strtolower($res['gender'] ?? 'female')),
            "blood_pressure_systolic"   => $this->safe($v['bp_s'],  130),
            "blood_pressure_diastolic"  => $this->safe($v['bp_d'],   80),
            "blood_sugar"               => $this->safe($v['bs'],    110),
            "pulse"                     => $this->safe($v['pulse'],  75),
            "weight"                    => $this->safe($v['weight'], 65),
            "temperature"               => $this->safe($v['temp'], 36.7),
            "oxygen_saturation"         => $this->safe($v['spo2'],   97),
            "has_hypertension"          => (int)(str_contains($c, 'hypertension') || str_contains($c, 'high blood pressure')),
            "has_diabetes"              => (int)(str_contains($c, 'diabetes')     || str_contains($c, 'diabetic')),
            "has_heart_disease"         => (int)str_contains($c, 'heart'),
            "has_arthritis"             => (int)str_contains($c, 'arthritis'),
            "has_osteoporosis"          => (int)str_contains($c, 'osteoporosis'),
            "has_obesity"               => (int)(str_contains($c, 'obesity') || str_contains($c, 'obese')),
            "is_low_sodium_diet"        => (int)(str_contains($d, 'low sodium') || str_contains($d, 'low-sodium')),
            "is_diabetic_diet"          => (int)str_contains($d, 'diabetic'),
            "has_meal_routine"          => (int)in_array('meal',     $types),
            "has_exercise_routine"      => (int)in_array('exercise', $types),
            "has_checkup_routine"       => (int)in_array('checkup',  $types),
            "has_hygiene_routine"       => (int)in_array('hygiene',  $types),
            "has_therapy_routine"       => (int)in_array('therapy',  $types),
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function callApi(array $data): ?array {
        $ch = curl_init($this->apiUrl . "/suggest");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$res || $code !== 200) return null;
        return json_decode($res, true);
    }

    private function safe($v, float $d): float {
        return ($v !== null && $v !== '') ? (float)$v : $d;
    }
}
?>