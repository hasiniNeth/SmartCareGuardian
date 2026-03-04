# =============================================================
#  SmartCare Guardian — Generate Synthetic Training Dataset
#  RUN FIRST: python generate_dataset.py
# =============================================================
import pandas as pd
import numpy as np
import random

random.seed(42)
np.random.seed(42)

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

ALL_CONDITIONS = list(CONDITION_SKILL_MAP.keys())
ALL_SKILLS = list({s for skills in CONDITION_SKILL_MAP.values() for s in skills}) + [
    "wound care", "medication management", "personal hygiene", "nutrition support",
    "end of life care", "patient communication", "first aid", "vital signs monitoring",
    "elderly care", "general nursing",
]

def skill_condition_overlap(conditions, skills):
    skills_lower = [s.lower() for s in skills]
    return sum(1 for c in conditions if any(r in skills_lower for r in CONDITION_SKILL_MAP.get(c, [])))

def compute_features(conditions, skills, exp, workload, risk):
    risk_floor  = {"low": 0, "medium": 2, "high": 4}
    risk_enc    = {"low": 0, "medium": 1, "high": 2}
    match_count = skill_condition_overlap(conditions, skills)
    return {
        "experience_years":    exp,
        "skill_match_count":   match_count,
        "skill_match_ratio":   round(match_count / max(len(conditions), 1), 4),
        "current_assignments": workload,
        "risk_level_encoded":  risk_enc.get(risk, 0),
        "exp_meets_risk":      1 if exp >= risk_floor.get(risk, 0) else 0,
        "total_skills":        len(skills),
        "condition_count":     len(conditions),
    }

def label_match(f, noise_prob=0.05):
    good = (f["exp_meets_risk"] == 1 and f["skill_match_count"] >= 1
            and f["current_assignments"] <= 4 and f["experience_years"] >= 1)
    if f["experience_years"] >= 6 and f["skill_match_count"] >= 2:
        good = True
    if f["current_assignments"] >= 6:
        good = False
    if random.random() < noise_prob:
        good = not good
    return int(good)

records = []
for _ in range(1200):
    conditions = random.sample(ALL_CONDITIONS, random.randint(1, 4))
    skills     = random.sample(ALL_SKILLS, random.randint(2, 7))
    exp        = random.randint(0, 15)
    workload   = random.randint(0, 7)
    risk       = random.choice(["low", "medium", "high"])
    f = compute_features(conditions, skills, exp, workload, risk)
    records.append({**f, "good_match": label_match(f)})

df = pd.DataFrame(records)
good = df[df["good_match"] == 1]
bad  = df[df["good_match"] == 0]
n    = min(len(good), len(bad))
df_balanced = pd.concat([good.sample(n, random_state=42),
                         bad.sample(n,  random_state=42)]).sample(frac=1, random_state=42).reset_index(drop=True)

df_balanced.to_csv("caregiver_match_dataset.csv", index=False)
print(f"Done! Saved caregiver_match_dataset.csv — {len(df_balanced)} rows")
print(f"Good matches: {df_balanced['good_match'].sum()}  |  Bad: {(df_balanced['good_match']==0).sum()}")