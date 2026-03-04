from flask import Blueprint, request, jsonify
import re

match_bp = Blueprint('match', __name__)

CONDITION_SKILL_MAP = {
    "diabetes":     ["diabetes", "blood sugar", "glucose", "insulin", "diabetic"],
    "hypertension": ["hypertension", "blood pressure", "cardiac", "cardiovascular"],
    "heart":        ["cardiac", "cardiovascular", "heart", "hypertension"],
    "dementia":     ["dementia", "alzheimer", "memory", "cognitive", "mental health"],
    "alzheimer":    ["dementia", "alzheimer", "memory", "cognitive"],
    "stroke":       ["stroke", "rehabilitation", "physiotherapy", "mobility", "neurological"],
    "parkinson":    ["parkinson", "mobility", "physiotherapy", "neurological"],
    "arthritis":    ["arthritis", "mobility", "physiotherapy", "joint", "pain management"],
    "copd":         ["respiratory", "oxygen", "copd", "breathing", "pulmonary"],
    "asthma":       ["respiratory", "asthma", "breathing", "oxygen"],
    "kidney":       ["renal", "kidney", "dialysis", "fluid management"],
    "renal":        ["renal", "kidney", "dialysis"],
    "depression":   ["mental health", "depression", "psychiatric", "counselling"],
    "anxiety":      ["mental health", "anxiety", "psychiatric", "counselling"],
    "obesity":      ["nutrition", "dietary", "weight management", "exercise"],
    "osteoporosis": ["osteoporosis", "falls prevention", "mobility", "physiotherapy"],
    "cancer":       ["oncology", "palliative", "cancer"],
    "wound":        ["wound care", "dressing", "nursing"],
    "mobility":     ["mobility", "physiotherapy", "transfer", "walking aid"],
    "fall":         ["falls prevention", "mobility", "physiotherapy"],
}

RISK_EXPERIENCE_FLOOR = {"high": 4, "medium": 2, "low": 0}
MAX_CASELOAD = 4

def normalise(text):
    return re.sub(r'\s+', ' ', (text or '').lower().strip())

def extract_conditions(text):
    if not text:
        return []
    return [normalise(p) for p in re.split(r'[,;/\n]+', text) if p.strip()]

def score_caregiver(cg, conditions, risk_level):
    reasons, warnings, score = [], [], 0
    skills = normalise(cg.get("skills", "") or "")
    exp    = int(cg.get("experience_years") or 0)
    load   = int(cg.get("current_assignments") or 0)

    # 1. Experience (0–30)
    if   exp >= 10: es = 30; reasons.append(f"{exp} years experience (senior)")
    elif exp >= 7:  es = 25; reasons.append(f"{exp} years experience")
    elif exp >= 5:  es = 20; reasons.append(f"{exp} years experience")
    elif exp >= 3:  es = 14; reasons.append(f"{exp} years experience")
    elif exp >= 1:  es = 8;  reasons.append(f"{exp} year(s) experience")
    else:           es = 3;  warnings.append("Less than 1 year of experience")
    score += es

    # 2. Condition-skill match (0–40)
    cs, matched = 0, []
    for cond in conditions:
        kws = []
        for key, vals in CONDITION_SKILL_MAP.items():
            if key in cond:
                kws.extend(vals)
        if not kws:
            kws = [w for w in cond.split() if len(w) > 3]
        if any(k in skills for k in kws):
            cs += 10
            matched.append(cond.title())
    score += min(cs, 40)
    if matched:
        reasons.append(f"Skills match: {', '.join(matched[:3])}")
    elif conditions:
        warnings.append("No direct skill match for resident's conditions")

    # 3. Workload (0–20)
    if   load == 0:          wl = 20; reasons.append("Available — no current residents")
    elif load <= 2:          wl = 16; reasons.append(f"Light workload ({load} assigned)")
    elif load <= MAX_CASELOAD: wl = 10; reasons.append(f"Moderate workload ({load} assigned)")
    else:                    wl = 2;  warnings.append(f"High workload — {load} residents already")
    score += wl

    # 4. Risk readiness (0–10)
    floor = RISK_EXPERIENCE_FLOOR.get(risk_level, 0)
    if exp >= floor:
        if   risk_level == "high"   and exp >= 4: score += 10; reasons.append("Experienced for high-risk care")
        elif risk_level == "medium" and exp >= 2: score += 6
        elif risk_level == "low":                 score += 4
    else:
        warnings.append(f"Risk is {risk_level.upper()} — recommend {floor}+ years experience")

    return {"score": min(round(score), 100), "reasons": reasons, "warnings": warnings}

def _grade(s):
    if s >= 80: return "Excellent"
    if s >= 60: return "Good"
    if s >= 40: return "Fair"
    return "Poor"

@match_bp.route('/suggest/match', methods=['POST'])
def suggest_match():
    try:
        data       = request.get_json(force=True)
        risk_level = normalise(data.get("risk_level") or "low")
        caregivers = data.get("caregivers", [])
        conditions = extract_conditions(data.get("medical_conditions", ""))
        if not caregivers:
            return jsonify({"success": False, "error": "No caregivers provided"}), 400
        scored = []
        for cg in caregivers:
            r = score_caregiver(cg, conditions, risk_level)
            scored.append({
                "caregiver_id":        cg.get("caregiver_id"),
                "user_id":             cg.get("user_id"),
                "name":                cg.get("name", "Unknown"),
                "experience_years":    int(cg.get("experience_years") or 0),
                "skills":              cg.get("skills", ""),
                "current_assignments": int(cg.get("current_assignments") or 0),
                "score":               r["score"],
                "reasons":             r["reasons"],
                "warnings":            r["warnings"],
                "match_grade":         _grade(r["score"]),
            })
        scored.sort(key=lambda x: x["score"], reverse=True)
        return jsonify({
            "success":         True,
            "resident_id":     data.get("resident_id"),
            "risk_level":      risk_level,
            "conditions_used": conditions,
            "suggestions":     scored[:3],
            "total_evaluated": len(scored),
        })
    except Exception as e:
        return jsonify({"success": False, "error": str(e)}), 500

# In your app.py add:
# from ai_routes_match import match_bp
# app.register_blueprint(match_bp)