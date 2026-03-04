"""
SmartCare Guardian — Routine Suggestion Engine API
===================================================
Flask microservice that loads trained ML models and returns
AI-powered routine suggestions for elderly residents.

Run:  python app.py
Port: http://localhost:5001
"""

from flask import Flask, request, jsonify
import pandas as pd
import joblib
import os

app = Flask(__name__)

# ─────────────────────────────────────────────────────────────────
# LOAD SAVED MODELS & ENCODERS
# ─────────────────────────────────────────────────────────────────
BASE = os.path.dirname(os.path.abspath(__file__))
MODELS_DIR = os.path.join(BASE, "models")

print("Loading models...")
all_models    = joblib.load(os.path.join(MODELS_DIR, "all_models.pkl"))
feature_cols  = joblib.load(os.path.join(MODELS_DIR, "feature_cols.pkl"))
le_gender     = joblib.load(os.path.join(MODELS_DIR, "le_gender.pkl"))
freq_map      = joblib.load(os.path.join(MODELS_DIR, "freq_map.pkl"))
print(f"✅ {len(all_models)} models loaded successfully")

# ─────────────────────────────────────────────────────────────────
# LABEL MAPS — Human readable output shown to caregivers
# ─────────────────────────────────────────────────────────────────
MEAL_TYPES = {
    0: "Standard balanced diet",
    1: "Low-sodium diet — reduce salt and processed foods",
    2: "Diabetic diet — control carbohydrates and sugar intake",
    3: "Low-sodium and diabetic diet",
    4: "Weight-loss diet — reduce calorie intake",
    5: "Soft food diet — easy to chew, nutrient-rich meals"
}

EXERCISE_LEVELS = {
    0: "Rest only — avoid physical exertion",
    1: "Light exercise — gentle walks, chair yoga, stretching",
    2: "Moderate exercise — 20 to 30 minutes of daily activity",
    3: "Active exercise — brisk walking, light resistance training"
}

HYGIENE_LEVELS = {
    0: "Independent — resident can manage independently",
    1: "Supervised — caregiver should monitor for safety",
    2: "Assisted — caregiver assistance required"
}

THERAPY_TYPES = {
    0: "No therapy needed at this time",
    1: "Physiotherapy — joint mobility and muscle strength",
    2: "Breathing exercises — respiratory health improvement",
    3: "Relaxation and stress reduction therapy",
    4: "Occupational therapy — daily living skills support"
}

CHECKUP_FREQ = {
    0: "Monthly checkup is sufficient",
    1: "Weekly checkup recommended",
    2: "Daily monitoring required"
}

# ─────────────────────────────────────────────────────────────────
# HELPER — Build feature vector from incoming JSON
# ─────────────────────────────────────────────────────────────────
def build_features(data: dict) -> pd.DataFrame:
    gender_encoded = int(le_gender.transform([data.get("gender", "Female")])[0])

    row = {
        "age"                       : float(data.get("age", 75)),
        "gender_encoded"            : gender_encoded,
        "blood_pressure_systolic"   : float(data.get("blood_pressure_systolic", 130)),
        "blood_pressure_diastolic"  : float(data.get("blood_pressure_diastolic", 80)),
        "blood_sugar"               : float(data.get("blood_sugar", 110)),
        "pulse"                     : float(data.get("pulse", 75)),
        "weight"                    : float(data.get("weight", 65)),
        "temperature"               : float(data.get("temperature", 36.7)),
        "oxygen_saturation"         : float(data.get("oxygen_saturation", 97)),
        "has_hypertension"          : int(data.get("has_hypertension", 0)),
        "has_diabetes"              : int(data.get("has_diabetes", 0)),
        "has_heart_disease"         : int(data.get("has_heart_disease", 0)),
        "has_arthritis"             : int(data.get("has_arthritis", 0)),
        "has_osteoporosis"          : int(data.get("has_osteoporosis", 0)),
        "has_obesity"               : int(data.get("has_obesity", 0)),
        "is_low_sodium_diet"        : int(data.get("is_low_sodium_diet", 0)),
        "is_diabetic_diet"          : int(data.get("is_diabetic_diet", 0)),
        "has_meal_routine"          : int(data.get("has_meal_routine", 0)),
        "has_exercise_routine"      : int(data.get("has_exercise_routine", 0)),
        "has_checkup_routine"       : int(data.get("has_checkup_routine", 0)),
        "has_hygiene_routine"       : int(data.get("has_hygiene_routine", 0)),
        "has_therapy_routine"       : int(data.get("has_therapy_routine", 0)),
    }

    return pd.DataFrame([row])[feature_cols]


# ─────────────────────────────────────────────────────────────────
# HELPER — Run all models and build suggestion list
# ─────────────────────────────────────────────────────────────────
def generate_suggestions(data: dict):
    X = build_features(data)

    preds = {}
    confs = {}
    for target, model in all_models.items():
        preds[target] = int(model.predict(X)[0])
        confs[target] = round(float(max(model.predict_proba(X)[0])), 2)

    suggestions = []

    # ── MEAL ───────────────────────────────────────────────────────
    meal_label = MEAL_TYPES.get(preds["meal_type_suggestion"], "Standard diet")
    if preds["add_meal_routine"] == 1:
        suggestions.append({
            "routine_type" : "meal",
            "action"       : "add",
            "title"        : "Add Meal Routine",
            "description"  : f"No structured meal routine found. Recommended: {meal_label}",
            "confidence"   : confs["add_meal_routine"]
        })
    elif preds["change_meal_routine"] == 1:
        suggestions.append({
            "routine_type" : "meal",
            "action"       : "change",
            "title"        : "Update Meal Routine",
            "description"  : f"Current meal plan should be adjusted. Recommended: {meal_label}",
            "confidence"   : confs["change_meal_routine"]
        })

    # ── EXERCISE ───────────────────────────────────────────────────
    exercise_label = EXERCISE_LEVELS.get(preds["exercise_intensity"], "Light exercise")
    if preds["add_exercise_routine"] == 1:
        suggestions.append({
            "routine_type" : "exercise",
            "action"       : "add",
            "title"        : "Add Exercise Routine",
            "description"  : f"No exercise routine assigned. Recommended: {exercise_label}",
            "confidence"   : confs["add_exercise_routine"]
        })
    elif preds["change_exercise_routine"] == 1:
        suggestions.append({
            "routine_type" : "exercise",
            "action"       : "change",
            "title"        : "Adjust Exercise Routine",
            "description"  : f"Exercise routine needs adjustment. Recommended: {exercise_label}",
            "confidence"   : confs["change_exercise_routine"]
        })

    # ── CHECKUP ────────────────────────────────────────────────────
    checkup_label = CHECKUP_FREQ.get(preds["checkup_frequency_encoded"], "Weekly checkup recommended")
    if preds["add_checkup_routine"] == 1:
        suggestions.append({
            "routine_type" : "checkup",
            "action"       : "add",
            "title"        : "Add Health Checkup Routine",
            "description"  : f"No checkup routine assigned. {checkup_label}",
            "confidence"   : confs["add_checkup_routine"]
        })
    elif preds["change_checkup_routine"] == 1:
        suggestions.append({
            "routine_type" : "checkup",
            "action"       : "change",
            "title"        : "Increase Checkup Frequency",
            "description"  : f"Checkup frequency should be increased. {checkup_label}",
            "confidence"   : confs["change_checkup_routine"]
        })

    # ── HYGIENE ────────────────────────────────────────────────────
    hygiene_label = HYGIENE_LEVELS.get(preds["hygiene_assistance_level"], "Supervised")
    if preds["add_hygiene_routine"] == 1:
        suggestions.append({
            "routine_type" : "hygiene",
            "action"       : "add",
            "title"        : "Add Hygiene Routine",
            "description"  : f"No hygiene routine found. Assistance level: {hygiene_label}",
            "confidence"   : confs["add_hygiene_routine"]
        })
    elif preds["change_hygiene_routine"] == 1:
        suggestions.append({
            "routine_type" : "hygiene",
            "action"       : "change",
            "title"        : "Update Hygiene Routine",
            "description"  : f"Hygiene routine needs updating. Assistance level: {hygiene_label}",
            "confidence"   : confs["change_hygiene_routine"]
        })

    # ── THERAPY ────────────────────────────────────────────────────
    therapy_label = THERAPY_TYPES.get(preds["therapy_type_suggestion"], "Physiotherapy")
    if preds["add_therapy_routine"] == 1:
        suggestions.append({
            "routine_type" : "therapy",
            "action"       : "add",
            "title"        : "Add Therapy Session",
            "description"  : f"Therapy routine recommended. Type: {therapy_label}",
            "confidence"   : confs["add_therapy_routine"]
        })
    elif preds["change_therapy_routine"] == 1:
        suggestions.append({
            "routine_type" : "therapy",
            "action"       : "change",
            "title"        : "Adjust Therapy Routine",
            "description"  : f"Existing therapy should be reviewed. Suggested: {therapy_label}",
            "confidence"   : confs["change_therapy_routine"]
        })

    return suggestions, preds, confs


# ─────────────────────────────────────────────────────────────────
# ROUTES
# ─────────────────────────────────────────────────────────────────

@app.route("/health", methods=["GET"])
def health_check():
    return jsonify({
        "status"        : "ok",
        "service"       : "SmartCare Routine Suggestion API",
        "models_loaded" : len(all_models)
    })


@app.route("/suggest", methods=["POST"])
def suggest():
    try:
        data = request.get_json(force=True)
        if not data:
            return jsonify({"error": "No JSON body received"}), 400

        suggestions, preds, confs = generate_suggestions(data)

        return jsonify({
            "status"         : "success",
            "resident_id"    : data.get("resident_id"),
            "total"          : len(suggestions),
            "suggestions"    : suggestions,
            "raw_predictions": preds,
        }), 200

    except Exception as e:
        return jsonify({"error": str(e)}), 500


if __name__ == "__main__":
    print("🚀 SmartCare Routine Suggestion API")
    print("   Running on: http://localhost:5001")
    print("   Endpoints:  GET  /health")
    print("               POST /suggest")
    app.run(host="0.0.0.0", port=5001, debug=True)