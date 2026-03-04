# =============================================================
#  SmartCare Guardian — Caregiver Match Flask API
#  FILE: app.py   PORT: 5002
#  START: python app.py
# =============================================================
from flask import Flask, request, jsonify
import pickle
import json
import re
import os

app = Flask(__name__)

# ── Load trained model ────────────────────────────────────────
MODEL_PATH   = os.path.join(os.path.dirname(__file__), "caregiver_match_model.pkl")
METRICS_PATH = os.path.join(os.path.dirname(__file__), "model_metrics.json")

model_data = None
def load_model():
    global model_data
    if not os.path.exists(MODEL_PATH):
        return False
    with open(MODEL_PATH, "rb") as f:
        model_data = pickle.load(f)
    return True

load_model()

# ── Condition → skill keyword map (same as training) ─────────
CONDITION_SKILL_MAP = {
    "diabetes":     ["diabetes care", "blood sugar monitoring", "insulin management", "diabetic care"],
    "hypertension": ["blood pressure monitoring", "cardiac care", "cardiovascular care", "hypertension management"],
    "dementia":     ["dementia care", "alzheimer care", "memory care", "cognitive support", "mental health"],
    "stroke":       ["stroke rehabilitation", "physiotherapy", "neurological care", "mobility assistance"],
    "parkinson":    ["parkinson care", "mobility assistance", "physiotherapy", "neurological care"],
    "arthritis":    ["arthritis care", "mobility assistance", "pain management", "physiotherapy"],
    "copd":         ["respiratory care", "oxygen therapy", "pulmonary care", "breathing support"],
    "depression":   ["mental health", "counselling", "psychiatric care", "emotional support"],
    "heart":        ["cardiac care", "cardiovascular care", "ecg monitoring", "heart care"],
    "kidney":       ["renal care", "dialysis support", "fluid management", "kidney care"],
    "osteoporosis": ["falls prevention", "bone care", "mobility assistance", "physiotherapy"],
    "cancer":       ["oncology care", "palliative care", "chemotherapy support"],
    "mobility":     ["mobility assistance", "physiotherapy", "walking aid", "falls prevention"],
}

def normalise(text):
    return re.sub(r'\s+', ' ', (text or '').lower().strip())

def extract_conditions(text):
    if not text:
        return []
    return [normalise(p) for p in re.split(r'[,;/\n]+', text) if p.strip()]

def skill_condition_overlap(conditions, skills_text):
    count = 0
    for cond in conditions:
        relevant = []
        for key, kws in CONDITION_SKILL_MAP.items():
            if key in cond:
                relevant.extend(kws)
        if not relevant:
            relevant = [w for w in cond.split() if len(w) > 3]
        if any(kw in skills_text for kw in relevant):
            count += 1
    return count

def build_features(cg, conditions, risk_level):
    """Build the exact same 8 features used during training."""
    skills_text = normalise(cg.get("skills", "") or "")
    exp         = int(cg.get("experience_years") or 0)
    workload    = int(cg.get("current_assignments") or 0)
    risk_enc    = {"low": 0, "medium": 1, "high": 2}
    risk_floor  = {"low": 0, "medium": 2, "high": 4}
    match_count = skill_condition_overlap(conditions, skills_text)

    return [
        exp,                                           # experience_years
        match_count,                                   # skill_match_count
        round(match_count / max(len(conditions), 1), 4),  # skill_match_ratio
        workload,                                      # current_assignments
        risk_enc.get(risk_level, 0),                   # risk_level_encoded
        1 if exp >= risk_floor.get(risk_level, 0) else 0,  # exp_meets_risk
        len(skills_text.split(",")) if skills_text else 0,  # total_skills
        len(conditions),                               # condition_count
    ]

def generate_reasons(cg, conditions, risk_level, match_count):
    """Human-readable reasons for the suggestion."""
    reasons, warnings = [], []
    exp      = int(cg.get("experience_years") or 0)
    workload = int(cg.get("current_assignments") or 0)
    risk_floor = {"low": 0, "medium": 2, "high": 4}

    if   exp >= 8:  reasons.append(f"{exp} years experience (senior)")
    elif exp >= 5:  reasons.append(f"{exp} years experience")
    elif exp >= 2:  reasons.append(f"{exp} years experience")
    elif exp >= 1:  reasons.append(f"{exp} year experience")
    else:           warnings.append("Less than 1 year of experience")

    if match_count > 0:
        reasons.append(f"Skills match {match_count} of {len(conditions)} condition(s)")
    elif conditions:
        warnings.append("No direct skill match for resident's conditions")

    if   workload == 0: reasons.append("Available — no current residents")
    elif workload <= 2: reasons.append(f"Light workload ({workload} assigned)")
    elif workload <= 4: reasons.append(f"Moderate workload ({workload} assigned)")
    else:               warnings.append(f"High workload — {workload} residents already")

    if exp >= risk_floor.get(risk_level, 0):
        if risk_level == "high": reasons.append("Experienced for high-risk resident care")
    else:
        warnings.append(f"Risk is {risk_level.upper()} — recommend {risk_floor[risk_level]}+ yrs experience")

    return reasons, warnings

def match_grade(prob):
    if prob >= 0.80: return "Excellent"
    if prob >= 0.60: return "Good"
    if prob >= 0.40: return "Fair"
    return "Poor"


# ── Routes ────────────────────────────────────────────────────

@app.route('/', methods=['GET'])
def status():
    return jsonify({
        "service":      "Caregiver Match AI",
        "status":       "running",
        "port":         5002,
        "model_loaded": model_data is not None,
        "accuracy":     model_data.get("accuracy") if model_data else None,
    })

@app.route('/model/info', methods=['GET'])
def model_info():
    if not model_data:
        return jsonify({"success": False, "error": "Model not loaded"}), 503
    metrics = {}
    if os.path.exists(METRICS_PATH):
        with open(METRICS_PATH) as f:
            metrics = json.load(f)
    return jsonify({"success": True, "model": metrics})

@app.route('/suggest/match', methods=['POST'])
def suggest_match():
    if not model_data:
        return jsonify({"success": False, "error": "Model not loaded. Run train_model.py first."}), 503

    try:
        data       = request.get_json(force=True)
        risk_level = normalise(data.get("risk_level") or "low")
        caregivers = data.get("caregivers", [])
        conditions = extract_conditions(data.get("medical_conditions", ""))

        if not caregivers:
            return jsonify({"success": False, "error": "No caregivers provided"}), 400

        rf_model  = model_data["model"]
        scored    = []

        for cg in caregivers:
            features    = build_features(cg, conditions, risk_level)
            probability = rf_model.predict_proba([features])[0][1]  # prob of good_match=1
            prediction  = rf_model.predict([features])[0]

            skills_text  = normalise(cg.get("skills", "") or "")
            match_count  = skill_condition_overlap(conditions, skills_text)
            reasons, warnings = generate_reasons(cg, conditions, risk_level, match_count)

            scored.append({
                "caregiver_id":        cg.get("caregiver_id"),
                "user_id":             cg.get("user_id"),
                "name":                cg.get("name", "Unknown"),
                "experience_years":    int(cg.get("experience_years") or 0),
                "skills":              cg.get("skills", ""),
                "current_assignments": int(cg.get("current_assignments") or 0),
                "match_probability":   round(float(probability) * 100, 1),
                "prediction":          int(prediction),
                "match_grade":         match_grade(probability),
                "reasons":             reasons,
                "warnings":            warnings,
            })

        # Sort by match probability descending, return top 3
        scored.sort(key=lambda x: x["match_probability"], reverse=True)

        return jsonify({
            "success":         True,
            "resident_id":     data.get("resident_id"),
            "risk_level":      risk_level,
            "conditions_used": conditions,
            "suggestions":     scored[:3],
            "total_evaluated": len(scored),
            "model_accuracy":  model_data.get("accuracy"),
        })

    except Exception as e:
        return jsonify({"success": False, "error": str(e)}), 500


if __name__ == '__main__':
    print("=" * 45)
    print("  Caregiver Match AI — Port 5002")
    if model_data:
        print(f"  Model loaded — Accuracy: {model_data.get('accuracy')}%")
    else:
        print("  WARNING: Model not found — run train_model.py first")
    print("=" * 45)
    app.run(host='0.0.0.0', port=5002, debug=False)
