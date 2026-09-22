"""
train_model_ensemble.py - Entraînement sur ensemble Niveau 2 + Niveau 3 fusionnés

Combine les données AMLSim (Niveau 2) et synthétiques maison (Niveau 3)
pour un entraînement global avec 65k+ transactions.
"""

from __future__ import annotations

from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import RandomForestClassifier
from sklearn.impute import SimpleImputer
from sklearn.metrics import (
    average_precision_score,
    classification_report,
    confusion_matrix,
    roc_auc_score,
)
from sklearn.model_selection import StratifiedGroupKFold
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder
from imblearn.over_sampling import SMOTE

from features import (
    CATEGORICAL_FEATURES,
    NUMERIC_FEATURES,
    build_base_frame,
    compute_features,
    extract_xy,
    prepare_datasets,
    split_by_client,
)


BASE_DIR = Path(__file__).resolve().parent


def load_dataset():
    """Charger les données fusionnées (Niveau 2 + Niveau 3)"""
    clients = pd.read_csv(BASE_DIR / "clients_combined.csv")
    transactions = pd.read_csv(BASE_DIR / "transactions_combined.csv", parse_dates=["date_transaction"])
    return clients, transactions


def print_percentage_report(report: dict, stage: str = "test") -> None:
    print(f"\nRapport de classification {stage} (en %) :")
    print(f"- Accuracy : {report['accuracy'] * 100:.2f}%")

    for label in ["False", "True"]:
        metrics = report.get(label, {})
        if not metrics:
            continue
        print(
            f"- Classe {label} : precision={metrics.get('precision', 0.0) * 100:.2f}%, "
            f"recall={metrics.get('recall', 0.0) * 100:.2f}%, "
            f"f1-score={metrics.get('f1-score', 0.0) * 100:.2f}%, "
            f"support={int(metrics.get('support', 0))}"
        )

    for label in ["macro avg", "weighted avg"]:
        metrics = report.get(label, {})
        if not metrics:
            continue
        print(
            f"- {label} : precision={metrics.get('precision', 0.0) * 100:.2f}%, "
            f"recall={metrics.get('recall', 0.0) * 100:.2f}%, "
            f"f1-score={metrics.get('f1-score', 0.0) * 100:.2f}%"
        )


def print_confusion_matrix(cm: np.ndarray) -> None:
    print("\nMatrice de confusion :")
    print(f"             Pred=0    Pred=1")
    print(f"Actual=0     {cm[0, 0]:6d}    {cm[0, 1]:6d}")
    print(f"Actual=1     {cm[1, 0]:6d}    {cm[1, 1]:6d}")
    print(f"\nInterprétation :")
    print(f"- Vrais négatifs (TN)    : {cm[0, 0]}")
    print(f"- Faux positifs (FP)     : {cm[0, 1]}")
    print(f"- Faux négatifs (FN)     : {cm[1, 0]}")
    print(f"- Vrais positifs (TP)    : {cm[1, 1]}")


def _make_preprocessor() -> ColumnTransformer:
    return ColumnTransformer(
        transformers=[
            (
                "num",
                Pipeline([("imputer", SimpleImputer(strategy="median"))]),
                NUMERIC_FEATURES,
            ),
            (
                "cat",
                Pipeline([
                    ("imputer", SimpleImputer(strategy="most_frequent")),
                    ("onehot", OneHotEncoder(handle_unknown="ignore")),
                ]),
                CATEGORICAL_FEATURES,
            ),
        ]
    )


def _safe_smote(X_train, y_train, random_state=42):
    n_pos = (y_train == True).sum()
    if n_pos <= 1:
        return X_train, y_train
    smote = SMOTE(random_state=random_state, k_neighbors=min(3, n_pos - 1))
    try:
        return smote.fit_resample(X_train, y_train)
    except ValueError:
        return X_train, y_train


def train_model_with_kfold():
    """Entraînement avec split par client (GroupKFold) et holdout de clients indépendants."""
    clients, transactions = load_dataset()
    base = build_base_frame(transactions, clients)
    base_cv_raw, base_holdout_raw = split_by_client(base)

    X_cv, y_cv, clients_cv, X_test_holdout, y_test_holdout, clients_test = prepare_datasets(
        transactions, clients
    )

    print("=" * 78)
    print("ENTRAINEMENT ENSEMBLE - NIVEAU 2 + NIVEAU 3 FUSIONNES")
    print("=" * 78)
    print("\nDISTRIBUTION DES CLASSES (split par CLIENT, sans fuite)")
    print("=" * 78)
    print(f"Dataset complet : {len(base)} transactions, {base['client_id'].nunique()} clients")
    print(f"  - Classe 0 (normal)  : {(base['is_risky'] == False).sum()} ({(base['is_risky'] == False).mean() * 100:.2f}%)")
    print(f"  - Classe 1 (risque)  : {(base['is_risky'] == True).sum()} ({(base['is_risky'] == True).mean() * 100:.2f}%)")
    print(f"\nCV set (80% clients)  : {len(y_cv)} transactions, {clients_cv.nunique()} clients")
    print(f"  - Classe 0  : {(y_cv == False).sum()} ({(y_cv == False).mean() * 100:.2f}%)")
    print(f"  - Classe 1  : {(y_cv == True).sum()} ({(y_cv == True).mean() * 100:.2f}%)")
    print(f"\nHoldout test (20% clients)  : {len(y_test_holdout)} transactions, {clients_test.nunique()} clients")
    print(f"  - Classe 0  : {(y_test_holdout == False).sum()} ({(y_test_holdout == False).mean() * 100:.2f}%)")
    print(f"  - Classe 1  : {(y_test_holdout == True).sum()} ({(y_test_holdout == True).mean() * 100:.2f}%)")

    overlap = set(clients_cv) & set(clients_test)
    print(f"\nClients partages CV/holdout : {len(overlap)} (attendu : 0)")

    preprocessor = _make_preprocessor()

    print("\n" + "=" * 78)
    print("VALIDATION CROISEE (K-FOLD PAR CLIENT, k=5)")
    print("=" * 78)

    skgf = StratifiedGroupKFold(n_splits=5, shuffle=True, random_state=42)
    cv_scores = {"accuracy": [], "roc_auc": [], "avg_precision": []}

    for fold, (train_idx, val_idx) in enumerate(skgf.split(base_cv_raw, base_cv_raw["is_risky"], groups=base_cv_raw["client_id"]), 1):
        base_train = base_cv_raw.iloc[train_idx]
        base_val = base_cv_raw.iloc[val_idx]

        X_train_fold, y_train_fold, clients_train_fold = extract_xy(
            compute_features(base_train, reference=base_train)
        )
        X_val_fold, y_val_fold, clients_val_fold = extract_xy(
            compute_features(base_val, reference=base_train)
        )

        print(
            f"  Fold {fold}: Train={len(train_idx)} tx ({clients_train_fold.nunique()} clients), "
            f"Val={len(val_idx)} tx ({clients_val_fold.nunique()} clients)"
        )

        X_train_preprocessed = preprocessor.fit_transform(X_train_fold)
        X_val_preprocessed = preprocessor.transform(X_val_fold)

        X_train_smote, y_train_smote = _safe_smote(X_train_preprocessed, y_train_fold)

        clf = RandomForestClassifier(n_estimators=100, random_state=42, n_jobs=-1, max_depth=15)
        clf.fit(X_train_smote, y_train_smote)

        y_pred_val = clf.predict(X_val_preprocessed)
        y_proba_val = clf.predict_proba(X_val_preprocessed)[:, 1]

        accuracy = (y_pred_val == y_val_fold).mean()
        try:
            roc_auc = roc_auc_score(y_val_fold, y_proba_val)
        except ValueError:
            roc_auc = 0.0
        try:
            avg_precision = average_precision_score(y_val_fold, y_proba_val)
        except ValueError:
            avg_precision = 0.0

        cv_scores["accuracy"].append(accuracy)
        cv_scores["roc_auc"].append(roc_auc)
        cv_scores["avg_precision"].append(avg_precision)

        print(f"    -> Accuracy={accuracy * 100:.2f}%, AUC-ROC={roc_auc:.3f}, AUC-PR={avg_precision:.3f}")

    print("\n" + "-" * 78)
    print("Resultats moyens CV :")
    print(f"  Accuracy    : {np.mean(cv_scores['accuracy']) * 100:.2f}% +/- {np.std(cv_scores['accuracy']) * 100:.2f}%")
    print(f"  AUC-ROC     : {np.mean(cv_scores['roc_auc']):.3f} +/- {np.std(cv_scores['roc_auc']):.3f}")
    print(f"  AUC-PR      : {np.mean(cv_scores['avg_precision']):.3f} +/- {np.std(cv_scores['avg_precision']):.3f}")

    print("\n" + "=" * 78)
    print("MODELE FINAL (ENTRAINE SUR TOUT LE CV SET)")
    print("=" * 78)

    X_cv_preprocessed = preprocessor.fit_transform(X_cv)
    X_test_preprocessed = preprocessor.transform(X_test_holdout)

    X_cv_smote, y_cv_smote = _safe_smote(X_cv_preprocessed, y_cv)

    clf_final = RandomForestClassifier(n_estimators=100, random_state=42, n_jobs=-1, max_depth=15)
    clf_final.fit(X_cv_smote, y_cv_smote)

    y_pred_test = clf_final.predict(X_test_preprocessed)
    y_proba_test = clf_final.predict_proba(X_test_preprocessed)[:, 1]

    roc_auc_test = roc_auc_score(y_test_holdout, y_proba_test)
    avg_precision_test = average_precision_score(y_test_holdout, y_proba_test)
    report_test = classification_report(y_test_holdout, y_pred_test, output_dict=True, zero_division=0)
    cm = confusion_matrix(y_test_holdout, y_pred_test)

    print(f"\nAUC-ROC test holdout : {roc_auc_test:.3f}")
    print(f"AUC-PR test holdout  : {avg_precision_test:.3f}")
    print_percentage_report(report_test, stage="holdout test")
    print_confusion_matrix(cm)

    print("\n" + "=" * 78)
    print("IMPORTANCE DES FEATURES")
    print("=" * 78)
    if hasattr(clf_final, "feature_importances_"):
        importances = clf_final.feature_importances_
        print(f"Total de features apres encoding: {len(importances)}")
        print(f"\nTop 15 features par importance (indices) :")
        sorted_idx = np.argsort(importances)[::-1][:15]
        for rank, idx in enumerate(sorted_idx, 1):
            print(f"  {rank:2d}. Feature_{idx}: {importances[idx] * 100:.2f}%")

        print(f"\nMapping (premieres features = numeric_features):")
        for fname in NUMERIC_FEATURES:
            print(f"  - {fname}")

    return {
        "cv_scores": cv_scores,
        "test_roc_auc": roc_auc_test,
        "test_avg_precision": avg_precision_test,
        "test_report": report_test,
        "confusion_matrix": cm,
    }


if __name__ == "__main__":
    train_model_with_kfold()
