"""
train_model_calibrated.py - Pipeline ML robuste avec calibration et nested CV

Objectifs :
1. Réduire la complexité du modèle (max_depth, régularisation)
2. Nested cross-validation (inner CV pour tuning, outer CV pour évaluation)
3. Calibration des probabilités (isotonic regression)
4. Courbe de calibration pour vérifier la cohérence
5. Comparaison de plusieurs modèles

Ce script remet les pieds sur terre : avec une vraie régularisation et une vraie
calibration, les scores descendent vers des valeurs réalistes.
"""

from __future__ import annotations

from pathlib import Path

import numpy as np
import pandas as pd
from sklearn.calibration import CalibratedClassifierCV
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import RandomForestClassifier
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    average_precision_score,
    brier_score_loss,
    classification_report,
    confusion_matrix,
    roc_auc_score,
)
from sklearn.model_selection import GridSearchCV, StratifiedGroupKFold, StratifiedKFold
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler
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
    clients = pd.read_csv(BASE_DIR / "clients.csv")
    transactions = pd.read_csv(BASE_DIR / "transactions.csv", parse_dates=["date_transaction"])
    return clients, transactions


def _safe_smote(X_train, y_train, random_state=42):
    n_pos = (y_train == True).sum()
    if n_pos <= 1:
        return X_train, y_train
    smote = SMOTE(random_state=random_state, k_neighbors=min(3, n_pos - 1))
    try:
        return smote.fit_resample(X_train, y_train)
    except ValueError:
        return X_train, y_train


def print_calibration_info(y_true, y_proba, y_pred):
    """Affiche la calibration : les proba doivent matcher les fréquences observées"""
    print("\n" + "=" * 78)
    print("CALIBRATION DES PROBABILITÉS")
    print("=" * 78)
    
    # Brier score : mesure la précision des probabilités (0 = parfait)
    brier = brier_score_loss(y_true, y_proba)
    print(f"Brier Score : {brier:.4f} (0 = parfait, <0.15 = bon)")
    
    # Vérifier la cohérence : par bin de proba, la fréquence d'événements doit correspondre
    bins = np.linspace(0, 1, 11)
    inds = np.digitize(y_proba, bins) - 1
    
    print("\nCalibration par bin (probabilité prédite vs fréquence observée) :")
    print("Bin          | Proba moyenne | Fréq. événements | Nb samples | Cohérence")
    print("-" * 78)
    
    for i in range(len(bins) - 1):
        mask = inds == i
        if mask.sum() == 0:
            continue
        mean_proba = y_proba[mask].mean()
        event_rate = y_true[mask].mean()
        count = mask.sum()
        diff = abs(mean_proba - event_rate)
        coherence = "✓" if diff < 0.1 else "✗ (mal calibré)"
        print(f"[{bins[i]:.1f}-{bins[i+1]:.1f}]   |    {mean_proba:.3f}      |     {event_rate:.3f}      |  {count:4d}    | {coherence}")


def train_with_calibration():
    """Entraînement complet avec calibration et nested CV"""
    clients, transactions = load_dataset()
    base = build_base_frame(transactions, clients)
    base_cv_raw, _ = split_by_client(base)
    X_cv, y_cv, clients_cv, X_test_holdout, y_test_holdout, _ = prepare_datasets(transactions, clients)

    preprocessor = ColumnTransformer(
        transformers=[
            ("num", Pipeline([("imputer", SimpleImputer(strategy="median")), ("scaler", StandardScaler())]), NUMERIC_FEATURES),
            ("cat", Pipeline([("imputer", SimpleImputer(strategy="most_frequent")), ("onehot", OneHotEncoder(handle_unknown="ignore"))]), CATEGORICAL_FEATURES),
        ]
    )

    print("=" * 78)
    print("NESTED CROSS-VALIDATION AVEC CALIBRATION")
    print("=" * 78)

    # Outer CV : évaluation robuste
    outer_cv = StratifiedGroupKFold(n_splits=5, shuffle=True, random_state=42)
    
    # Inner CV : tuning d'hyperparamètres
    inner_cv = StratifiedKFold(n_splits=3, shuffle=True, random_state=42)

    results_per_model = {}

    # Modèle 1 : LogisticRegression (simple, régularisé)
    print("\n[1/2] LogisticRegression (simple, régularisé)...")
    
    lr_pipeline = Pipeline([
        ("preprocess", preprocessor),
        ("clf", LogisticRegression(random_state=42, max_iter=1000, C=1.0, solver="lbfgs"))
    ])
    
    lr_param_grid = {"clf__C": [0.01, 0.1, 1.0]}
    lr_search = GridSearchCV(lr_pipeline, lr_param_grid, cv=inner_cv, scoring="roc_auc", n_jobs=-1)

    outer_scores_lr = {"roc_auc": [], "avg_precision": [], "brier": []}
    
    for fold, (train_idx, val_idx) in enumerate(outer_cv.split(base_cv_raw, base_cv_raw["is_risky"], groups=base_cv_raw["client_id"]), 1):
        base_train = base_cv_raw.iloc[train_idx]
        base_val = base_cv_raw.iloc[val_idx]
        X_train, y_train, _ = extract_xy(compute_features(base_train, reference=base_train))
        X_val, y_val, _ = extract_xy(compute_features(base_val, reference=base_train))

        lr_search.fit(X_train, y_train)
        
        # Évaluer sur validation
        y_proba_val = lr_search.predict_proba(X_val)[:, 1]
        
        try:
            roc_auc = roc_auc_score(y_val, y_proba_val)
            avg_prec = average_precision_score(y_val, y_proba_val)
            brier = brier_score_loss(y_val, y_proba_val)
        except:
            roc_auc, avg_prec, brier = 0.0, 0.0, 1.0
        
        outer_scores_lr["roc_auc"].append(roc_auc)
        outer_scores_lr["avg_precision"].append(avg_prec)
        outer_scores_lr["brier"].append(brier)
        
        print(f"  Fold {fold}: AUC-ROC={roc_auc:.3f}, AUC-PR={avg_prec:.3f}, Brier={brier:.4f}")
    
    results_per_model["LogisticRegression"] = outer_scores_lr

    # Modèle 2 : RandomForest régularisé
    print("\n[2/2] RandomForest (max_depth limité)...")
    
    outer_scores_rf = {"roc_auc": [], "avg_precision": [], "brier": []}
    
    for fold, (train_idx, val_idx) in enumerate(outer_cv.split(base_cv_raw, base_cv_raw["is_risky"], groups=base_cv_raw["client_id"]), 1):
        base_train = base_cv_raw.iloc[train_idx]
        base_val = base_cv_raw.iloc[val_idx]
        X_train, y_train, _ = extract_xy(compute_features(base_train, reference=base_train))
        X_val, y_val, _ = extract_xy(compute_features(base_val, reference=base_train))

        X_train_preprocessed = preprocessor.fit_transform(X_train)
        X_val_preprocessed = preprocessor.transform(X_val)

        X_train_smote, y_train_smote = _safe_smote(X_train_preprocessed, y_train)
        
        # GridSearch sur RandomForest uniquement (pas de pipeline)
        rf_search_fit = GridSearchCV(
            RandomForestClassifier(random_state=42, n_jobs=-1),
            {"max_depth": [5, 10, 15], "n_estimators": [50, 100]},
            cv=inner_cv,
            scoring="roc_auc"
        )
        rf_search_fit.fit(X_train_smote, y_train_smote)
        
        # Évaluer sur validation (sans SMOTE)
        y_proba_val = rf_search_fit.predict_proba(X_val_preprocessed)[:, 1]
        
        try:
            roc_auc = roc_auc_score(y_val, y_proba_val)
            avg_prec = average_precision_score(y_val, y_proba_val)
            brier = brier_score_loss(y_val, y_proba_val)
        except:
            roc_auc, avg_prec, brier = 0.0, 0.0, 1.0
        
        outer_scores_rf["roc_auc"].append(roc_auc)
        outer_scores_rf["avg_precision"].append(avg_prec)
        outer_scores_rf["brier"].append(brier)
        
        print(f"  Fold {fold}: AUC-ROC={roc_auc:.3f}, AUC-PR={avg_prec:.3f}, Brier={brier:.4f}")
    
    results_per_model["RandomForest"] = outer_scores_rf

    # Afficher les résultats
    print("\n" + "=" * 78)
    print("RÉSUMÉ NESTED CV")
    print("=" * 78)
    
    for model_name, scores in results_per_model.items():
        print(f"\n{model_name}:")
        print(f"  AUC-ROC  : {np.mean(scores['roc_auc']):.3f} ± {np.std(scores['roc_auc']):.3f}")
        print(f"  AUC-PR   : {np.mean(scores['avg_precision']):.3f} ± {np.std(scores['avg_precision']):.3f}")
        print(f"  Brier    : {np.mean(scores['brier']):.4f} ± {np.std(scores['brier']):.4f}")

    # Entraîner le modèle final avec calibration
    print("\n" + "=" * 78)
    print("MODÈLE FINAL AVEC CALIBRATION (ISOTONIC REGRESSION)")
    print("=" * 78)

    X_cv_preprocessed = preprocessor.fit_transform(X_cv)
    X_test_preprocessed = preprocessor.transform(X_test_holdout)

    # Utiliser Random Forest optimal de la nested CV
    rf_final = RandomForestClassifier(n_estimators=100, max_depth=10, random_state=42, n_jobs=-1)
    
    # Appliquer SMOTE
    X_cv_smote, y_cv_smote = _safe_smote(X_cv_preprocessed, y_cv)
    
    # Entraîner + calibrer
    rf_final.fit(X_cv_smote, y_cv_smote)
    
    # Calibration avec isotonic regression (5-fold CV interne pour éviter overfitting)
    calibrated_clf = CalibratedClassifierCV(rf_final, method="isotonic", cv=5)
    calibrated_clf.fit(X_cv_smote, y_cv_smote)
    
    # Évaluer sur holdout
    y_pred_test = calibrated_clf.predict(X_test_preprocessed)
    y_proba_test = calibrated_clf.predict_proba(X_test_preprocessed)[:, 1]
    
    roc_auc_test = roc_auc_score(y_test_holdout, y_proba_test)
    avg_precision_test = average_precision_score(y_test_holdout, y_proba_test)
    brier_test = brier_score_loss(y_test_holdout, y_proba_test)
    report_test = classification_report(y_test_holdout, y_pred_test, output_dict=True, zero_division=0)
    cm = confusion_matrix(y_test_holdout, y_pred_test)
    
    print(f"\nTest holdout (après calibration) :")
    print(f"  AUC-ROC : {roc_auc_test:.3f}")
    print(f"  AUC-PR  : {avg_precision_test:.3f}")
    print(f"  Brier   : {brier_test:.4f}")
    
    print("\nRapport de classification (%) :")
    print(f"  Accuracy : {report_test['accuracy'] * 100:.2f}%")
    print(f"  Classe False : precision={report_test.get('False', {}).get('precision', 0.0) * 100:.2f}%, recall={report_test.get('False', {}).get('recall', 0.0) * 100:.2f}%")
    print(f"  Classe True  : precision={report_test.get('True', {}).get('precision', 0.0) * 100:.2f}%, recall={report_test.get('True', {}).get('recall', 0.0) * 100:.2f}%")
    
    print("\nMatrice de confusion :")
    print(f"             Pred=0    Pred=1")
    print(f"Actual=0     {cm[0, 0]:6d}    {cm[0, 1]:6d}")
    print(f"Actual=1     {cm[1, 0]:6d}    {cm[1, 1]:6d}")
    
    # Calibration check
    print_calibration_info(y_test_holdout, y_proba_test, y_pred_test)
    
    return {
        "nested_cv_results": results_per_model,
        "test_roc_auc": roc_auc_test,
        "test_avg_precision": avg_precision_test,
        "test_brier": brier_test,
    }


if __name__ == "__main__":
    train_with_calibration()
