# =============================================================
#  SmartCare Guardian — Train Caregiver Match Model
#  RUN SECOND: python train_model.py
#  Algorithm: Random Forest Classifier
# =============================================================
import pandas as pd
import pickle
import json
from sklearn.ensemble        import RandomForestClassifier
from sklearn.model_selection import train_test_split, cross_val_score
from sklearn.metrics         import (classification_report, confusion_matrix,
                                     accuracy_score, roc_auc_score)

print("=" * 55)
print("  SmartCare Guardian — Caregiver Match Model Training")
print("=" * 55)

df = pd.read_csv("caregiver_match_dataset.csv")
print(f"\n[1] Dataset: {len(df)} rows | Classes: {df['good_match'].value_counts().to_dict()}")

FEATURE_COLS = [
    "experience_years", "skill_match_count", "skill_match_ratio",
    "current_assignments", "risk_level_encoded", "exp_meets_risk",
    "total_skills", "condition_count",
]

X = df[FEATURE_COLS]
y = df["good_match"]

X_train, X_test, y_train, y_test = train_test_split(
    X, y, test_size=0.20, random_state=42, stratify=y)
print(f"[2] Split: {len(X_train)} train / {len(X_test)} test")

print("[3] Training Random Forest (200 trees)...")
model = RandomForestClassifier(
    n_estimators=200, max_depth=10, min_samples_split=4,
    min_samples_leaf=2, class_weight="balanced", random_state=42, n_jobs=-1)
model.fit(X_train, y_train)

y_pred      = model.predict(X_test)
y_pred_prob = model.predict_proba(X_test)[:, 1]
accuracy    = accuracy_score(y_test, y_pred)
auc         = roc_auc_score(y_test, y_pred_prob)

print(f"\n[4] Results:")
print(f"    Accuracy : {accuracy * 100:.2f}%")
print(f"    ROC-AUC  : {auc:.4f}")
print(f"\n{classification_report(y_test, y_pred, target_names=['Bad Match','Good Match'])}")

cm = confusion_matrix(y_test, y_pred)
print(f"    Confusion Matrix:  TN={cm[0][0]} FP={cm[0][1]} | FN={cm[1][0]} TP={cm[1][1]}")

cv = cross_val_score(model, X, y, cv=5, scoring="accuracy")
print(f"\n[5] 5-fold CV: {[round(s*100,2) for s in cv]}  Mean={cv.mean()*100:.2f}%")

print("\n[6] Feature Importance:")
for feat, imp in sorted(zip(FEATURE_COLS, model.feature_importances_), key=lambda x: -x[1]):
    print(f"    {feat:<25} {imp:.4f}  {'█' * int(imp * 40)}")

# Save model
with open("caregiver_match_model.pkl", "wb") as f:
    pickle.dump({"model": model, "feature_cols": FEATURE_COLS,
                 "accuracy": round(accuracy*100, 2), "auc": round(auc, 4)}, f)

# Save metrics for PHP
with open("model_metrics.json", "w") as f:
    json.dump({
        "algorithm": "Random Forest Classifier", "n_estimators": 200,
        "accuracy": round(accuracy*100, 2), "auc": round(auc, 4),
        "cv_mean": round(cv.mean()*100, 2), "cv_std": round(cv.std()*100, 2),
        "train_size": len(X_train), "test_size": len(X_test),
        "features": FEATURE_COLS,
        "feature_importance": dict(zip(FEATURE_COLS,
                               [round(i,4) for i in model.feature_importances_])),
    }, f, indent=2)

print(f"\n  Saved: caregiver_match_model.pkl")
print(f"  Saved: model_metrics.json")
print(f"\n  Model ready! Accuracy: {accuracy*100:.2f}%  AUC: {auc:.4f}")
print("=" * 55)