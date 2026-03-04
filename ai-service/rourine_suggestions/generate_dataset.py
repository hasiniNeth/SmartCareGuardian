"""
SmartCare Guardian — Dataset Generator for Routine Suggestion Engine
=====================================================================
Generates a CSV dataset that mirrors your exact database tables:
  - residents  (resident_id, gender, dob, medical_conditions, dietary_restrictions, allergies)
  - health_logs (blood_pressure_systolic, blood_pressure_diastolic, blood_sugar,
                 pulse, weight, temperature, oxygen_saturation)
  - routines   (routine_type: meal, exercise, checkup, hygiene, therapy)

Output: dataset/routine_suggestion_dataset.csv
"""

import pandas as pd
import numpy as np
from datetime import date, timedelta
import os

np.random.seed(42)
os.makedirs("dataset", exist_ok=True)

# ─────────────────────────────────────────────────────────────────
# HELPER FUNCTIONS
# ─────────────────────────────────────────────────────────────────

def clamp(val, lo, hi):
    return round(float(max(lo, min(hi, val))), 1)

def random_dob(min_age=60, max_age=95):
    """Generate a date of birth for an elderly person."""
    days_ago = np.random.randint(min_age * 365, max_age * 365)
    return (date.today() - timedelta(days=int(days_ago))).strftime("%Y-%m-%d")

def calc_age(dob_str):
    dob = date.fromisoformat(dob_str)
    today = date.today()
    return today.year - dob.year - ((today.month, today.day) < (dob.month, dob.day))

# ── Medical conditions used in your medical_conditions column ──
CONDITIONS_POOL = [
    "Hypertension", "Type 2 Diabetes", "Heart Disease",
    "Arthritis", "Osteoporosis", "Asthma", "Chronic Kidney Disease",
    "Obesity", "High Cholesterol", "None"
]

DIETARY_POOL = [
    "Low sodium", "Diabetic diet", "Low fat", "Vegetarian",
    "Soft food diet", "High fiber", "None"
]

ALLERGY_POOL = [
    "Penicillin", "Aspirin", "Sulfa drugs", "Latex",
    "Peanuts", "Shellfish", "None", "No known allergies"
]

def pick_conditions(age, sbp, sugar, weight, gender):
    """Assign realistic medical conditions based on vitals."""
    conditions = []
    if sbp > 135 or np.random.random() < 0.45:
        conditions.append("Hypertension")
    if sugar > 130 or np.random.random() < 0.30:
        conditions.append("Type 2 Diabetes")
    if age > 72 and np.random.random() < 0.25:
        conditions.append("Heart Disease")
    if age > 70 and np.random.random() < 0.50:
        conditions.append("Arthritis")
    if gender == "Female" and age > 68 and np.random.random() < 0.35:
        conditions.append("Osteoporosis")
    bmi_approx = weight / ((1.60 if gender == "Female" else 1.72) ** 2)
    if bmi_approx > 30 or np.random.random() < 0.20:
        conditions.append("Obesity")
    if np.random.random() < 0.15:
        conditions.append("High Cholesterol")
    return ", ".join(conditions) if conditions else "None"

# ─────────────────────────────────────────────────────────────────
# STEP 1 — Generate resident base profiles
# ─────────────────────────────────────────────────────────────────

NUM_RESIDENTS = 500
print(f"Generating {NUM_RESIDENTS} synthetic residents...")

rows = []

for i in range(1, NUM_RESIDENTS + 1):
    gender  = np.random.choice(["Male", "Female"], p=[0.45, 0.55])
    dob     = random_dob()
    age     = calc_age(dob)

    # ── Generate base vitals (realistic for elderly) ──────────────
    sbp     = clamp(np.random.normal(132, 18), 90,  195)
    dbp     = clamp(sbp * np.random.uniform(0.58, 0.67), 55, 115)
    sugar   = clamp(np.random.normal(118, 28), 60,  310)
    pulse   = clamp(np.random.normal(76,  11), 45,  110)
    weight  = clamp(np.random.normal(
                    63 if gender == "Female" else 72, 13), 38, 130)
    temp    = clamp(np.random.normal(36.7, 0.4), 35.5, 38.5)
    spo2    = clamp(np.random.normal(97.0, 1.5), 88,   100)

    # ── Derive conditions from vitals ─────────────────────────────
    medical_conditions    = pick_conditions(age, sbp, sugar, weight, gender)
    dietary_restrictions  = np.random.choice(
        DIETARY_POOL,
        p=[0.20, 0.18, 0.10, 0.08, 0.08, 0.08, 0.28]
    )
    allergies = np.random.choice(
        ALLERGY_POOL,
        p=[0.08, 0.07, 0.06, 0.05, 0.05, 0.05, 0.32, 0.32]
    )

    # ── Boolean flags derived from conditions (used for labelling) ─
    has_hypertension   = int("Hypertension"       in medical_conditions)
    has_diabetes       = int("Type 2 Diabetes"    in medical_conditions)
    has_heart_disease  = int("Heart Disease"       in medical_conditions)
    has_arthritis      = int("Arthritis"           in medical_conditions)
    has_osteoporosis   = int("Osteoporosis"        in medical_conditions)
    has_obesity        = int("Obesity"             in medical_conditions)
    is_low_sodium_diet = int("Low sodium"          in dietary_restrictions)
    is_diabetic_diet   = int("Diabetic diet"       in dietary_restrictions)

    # ── Current routine flags (does resident already have this?) ──
    has_meal_routine     = int(np.random.random() < 0.80)
    has_exercise_routine = int(np.random.random() < 0.55)
    has_checkup_routine  = int(np.random.random() < 0.60)
    has_hygiene_routine  = int(np.random.random() < 0.70)
    has_therapy_routine  = int(np.random.random() < 0.40)

    rows.append({
        # ── Resident identifiers ───────────────────────────────────
        "resident_id"           : i,
        "gender"                : gender,
        "dob"                   : dob,
        "age"                   : age,
        "medical_conditions"    : medical_conditions,
        "dietary_restrictions"  : dietary_restrictions,
        "allergies"             : allergies,

        # ── Health log vitals (30-day average) ─────────────────────
        "blood_pressure_systolic"  : sbp,
        "blood_pressure_diastolic" : dbp,
        "blood_sugar"              : sugar,
        "pulse"                    : pulse,
        "weight"                   : weight,
        "temperature"              : temp,
        "oxygen_saturation"        : spo2,

        # ── Condition flags ────────────────────────────────────────
        "has_hypertension"   : has_hypertension,
        "has_diabetes"       : has_diabetes,
        "has_heart_disease"  : has_heart_disease,
        "has_arthritis"      : has_arthritis,
        "has_osteoporosis"   : has_osteoporosis,
        "has_obesity"        : has_obesity,
        "is_low_sodium_diet" : is_low_sodium_diet,
        "is_diabetic_diet"   : is_diabetic_diet,

        # ── Existing routines ──────────────────────────────────────
        "has_meal_routine"     : has_meal_routine,
        "has_exercise_routine" : has_exercise_routine,
        "has_checkup_routine"  : has_checkup_routine,
        "has_hygiene_routine"  : has_hygiene_routine,
        "has_therapy_routine"  : has_therapy_routine,
    })

df = pd.DataFrame(rows)

# ─────────────────────────────────────────────────────────────────
# STEP 2 — Label: what should the AI suggest?
# ─────────────────────────────────────────────────────────────────
# Each label = a specific suggestion the AI will make to the carer.
# Format: suggest_<action>_<routine_type>
#   action = "add"    → resident doesn't have this routine, AI says add it
#   action = "change" → resident has this routine, AI says modify it
# ─────────────────────────────────────────────────────────────────

def label_row(r):
    sbp   = r["blood_pressure_systolic"]
    dbp   = r["blood_pressure_diastolic"]
    sugar = r["blood_sugar"]
    pulse = r["pulse"]
    weight= r["weight"]
    temp  = r["temperature"]
    spo2  = r["oxygen_saturation"]
    age   = r["age"]

    labels = {}

    # ── 1. MEAL routine ───────────────────────────────────────────
    # Change meal: adjust diet if conditions warrant it
    labels["change_meal_routine"] = int(
        r["has_meal_routine"] == 1 and (
            sbp > 140 or sugar > 140 or r["has_obesity"] or
            r["has_diabetes"] or r["has_hypertension"]
        )
    )
    # Add meal routine: no structured meal plan but conditions exist
    labels["add_meal_routine"] = int(
        r["has_meal_routine"] == 0 and (
            sugar > 130 or r["has_diabetes"] or
            r["has_hypertension"] or r["has_obesity"]
        )
    )
    # Specific meal recommendation (multi-class for meal type)
    # 0=standard, 1=low-sodium, 2=diabetic, 3=low-sodium+diabetic, 4=weight-loss, 5=soft-food
    if sbp > 140 and sugar > 140:
        labels["meal_type_suggestion"] = 3
    elif sbp > 140 or r["has_hypertension"]:
        labels["meal_type_suggestion"] = 1
    elif sugar > 130 or r["has_diabetes"]:
        labels["meal_type_suggestion"] = 2
    elif r["has_obesity"] or weight > (78 if r["gender"] == "Female" else 88):
        labels["meal_type_suggestion"] = 4
    elif age > 82 or r["has_osteoporosis"]:
        labels["meal_type_suggestion"] = 5   # soft food / easy to chew
    else:
        labels["meal_type_suggestion"] = 0

    # ── 2. EXERCISE routine ───────────────────────────────────────
    # Exercise intensity: 0=none/rest, 1=light, 2=moderate, 3=active
    if sbp > 165 or pulse > 105 or spo2 < 92 or r["has_heart_disease"]:
        labels["exercise_intensity"] = 0
    elif sbp > 145 or r["has_arthritis"] or r["has_osteoporosis"] or age > 82:
        labels["exercise_intensity"] = 1   # light: gentle walk, chair yoga
    elif r["has_obesity"] and not r["has_heart_disease"]:
        labels["exercise_intensity"] = 3   # active: needs weight reduction
    else:
        labels["exercise_intensity"] = 2   # moderate

    labels["change_exercise_routine"] = int(
        r["has_exercise_routine"] == 1 and (
            sbp > 145 or pulse > 95 or spo2 < 94 or
            r["has_heart_disease"] or r["has_arthritis"]
        )
    )
    labels["add_exercise_routine"] = int(
        r["has_exercise_routine"] == 0 and
        labels["exercise_intensity"] > 0   # only add if not "rest"
    )

    # ── 3. CHECKUP routine ────────────────────────────────────────
    labels["change_checkup_routine"] = int(
        r["has_checkup_routine"] == 1 and (
            sbp > 160 or sugar > 180 or pulse > 100 or
            spo2 < 93 or temp > 37.8
        )
    )
    # Increase checkup frequency
    labels["checkup_frequency"] = (
        "daily"   if (sbp > 160 or sugar > 200 or spo2 < 92) else
        "weekly"  if (sbp > 140 or sugar > 140 or r["has_heart_disease"]) else
        "monthly"
    )
    labels["add_checkup_routine"] = int(
        r["has_checkup_routine"] == 0 and (
            r["has_hypertension"] or r["has_diabetes"] or
            r["has_heart_disease"] or age > 78
        )
    )

    # ── 4. HYGIENE routine ────────────────────────────────────────
    labels["add_hygiene_routine"] = int(
        r["has_hygiene_routine"] == 0 and age > 72
    )
    labels["change_hygiene_routine"] = int(
        r["has_hygiene_routine"] == 1 and (
            r["has_osteoporosis"] or r["has_arthritis"] or age > 82
        )
    )
    # Hygiene assistance level: 0=independent, 1=supervised, 2=assisted
    if age > 85 or (r["has_arthritis"] and r["has_osteoporosis"]):
        labels["hygiene_assistance_level"] = 2
    elif age > 75 or r["has_arthritis"] or r["has_osteoporosis"]:
        labels["hygiene_assistance_level"] = 1
    else:
        labels["hygiene_assistance_level"] = 0

    # ── 5. THERAPY routine ────────────────────────────────────────
    labels["add_therapy_routine"] = int(
        r["has_therapy_routine"] == 0 and (
            r["has_arthritis"] or r["has_osteoporosis"] or
            r["has_heart_disease"] or sbp > 150
        )
    )
    labels["change_therapy_routine"] = int(
        r["has_therapy_routine"] == 1 and (
            sbp > 155 or r["has_heart_disease"] or spo2 < 94
        )
    )
    # Therapy type: 0=none, 1=physiotherapy, 2=breathing/respiratory,
    #               3=relaxation/stress, 4=occupational
    if spo2 < 94 or r["has_heart_disease"]:
        labels["therapy_type_suggestion"] = 2   # breathing exercises
    elif r["has_arthritis"] or r["has_osteoporosis"]:
        labels["therapy_type_suggestion"] = 1   # physiotherapy
    elif sbp > 150:
        labels["therapy_type_suggestion"] = 3   # relaxation / stress
    elif age > 80:
        labels["therapy_type_suggestion"] = 4   # occupational therapy
    else:
        labels["therapy_type_suggestion"] = 0

    return labels


print("Labelling suggestion targets...")
label_rows = df.apply(label_row, axis=1)
label_df   = pd.DataFrame(list(label_rows))
final_df   = pd.concat([df, label_df], axis=1)

# ─────────────────────────────────────────────────────────────────
# STEP 3 — Save
# ─────────────────────────────────────────────────────────────────
out_path = "dataset/routine_suggestion_dataset.csv"
final_df.to_csv(out_path, index=False)

print(f"\n✅ Dataset saved → {out_path}")
print(f"   Rows    : {len(final_df)}")
print(f"   Columns : {len(final_df.columns)}")
print(f"\n── Feature columns ──────────────────────────────")
feature_cols = [c for c in final_df.columns if not c.startswith(("change_","add_","meal_type","exercise_intensity","checkup_freq","hygiene_assist","therapy_type"))]
print("  " + "\n  ".join(feature_cols))
print(f"\n── Label / target columns ───────────────────────")
label_cols = [c for c in final_df.columns if c not in feature_cols]
print("  " + "\n  ".join(label_cols))

print(f"\n── Label distributions ──────────────────────────")
for col in label_cols:
    print(f"  {col}: {final_df[col].value_counts().to_dict()}")