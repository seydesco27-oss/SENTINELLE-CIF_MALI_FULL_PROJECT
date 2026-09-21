"""
ablation_study.py - Étude d'ablation pour valider le diagnostic du surapprentissage.

Test 1 : Modèle COMPLET (avec log_amount, ratio, zscore)
Test 2 : Modèle SANS features montant (client_age, is_weekend, corridor, type_transaction, profil)
Test 3 : Modèle MONTANT SEUL (juste log_amount)

Hypothèse : Si le diagnostic est correct, Test 2 devrait avoir des performances bien inférieures.
"""

from pathlib import Path
import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import RandomForestClassifier
from sklearn.impute import SimpleImputer
from sklearn.metrics import average_precision_score, roc_auc_score, classification_report
from sklearn.model_selection import StratifiedGroupKFold, train_test_split
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder
from imblearn.over_sampling import SMOTE


BASE_DIR = Path(__file__).resolve().parent / "niveau3"


def load_dataset():
    clients = pd.read_csv(BASE_DIR / "clients.csv")
    transactions = pd.read_csv(BASE_DIR / "transactions.csv", parse_dates=["date_transaction"])
    return clients, transactions


def build_features_complete(transactions: pd.DataFrame, clients: pd.DataFrame):
    """Modèle COMPLET : avec toutes les features montant"""
    merged = transactions.merge(clients[["client_id", "profil", "anciennete_compte_jours"]], on="client_id", how="left")
    merged = merged.copy()
    if not pd.api.types.is_datetime64_any_dtype(merged["date_transaction"]):
        merged["date_transaction"] = pd.to_datetime(merged["date_transaction"], errors="coerce")
    
    merged["log_amount"] = np.log1p(merged["montant_fcfa"])
    merged["client_age_days"] = merged["anciennete_compte_jours"].astype(float)
    merged["is_weekend"] = merged["date_transaction"].dt.dayofweek.isin([5, 6]).astype(int)
    
    client_amount_stats = merged.groupby("client_id")["montant_fcfa"].agg(["median", "std"]).reset_index()
    client_amount_stats.columns = ["client_id", "client_median_amount", "client_std_amount"]
    merged = merged.merge(client_amount_stats, on="client_id", how="left")
    merged["client_median_amount"] = merged["client_median_amount"].fillna(merged["montant_fcfa"].median())
    merged["client_std_amount"] = merged["client_std_amount"].fillna(merged["montant_fcfa"].std())
    merged["amount_ratio_to_client_median"] = merged["montant_fcfa"] / (merged["client_median_amount"] + 1)
    merged["amount_zscore"] = (merged["montant_fcfa"] - merged["client_median_amount"]) / (merged["client_std_amount"] + 1)
    
    merged["transaction_type"] = merged["type_transaction"].fillna("inconnu")
    merged["corridor"] = merged["corridor"].fillna("inconnu")
    merged["client_profile"] = merged["profil"].fillna("inconnu")

    features = merged[["log_amount", "amount_ratio_to_client_median", "amount_zscore", "client_age_days", "is_weekend", "transaction_type", "corridor", "client_profile"]]
    target = merged["is_risky"]
    client_ids = merged["client_id"]
    
    return features, target, client_ids, "COMPLET (montant + comportement)"


def build_features_behavioral_only(transactions: pd.DataFrame, clients: pd.DataFrame):
    """Modèle SANS MONTANT : uniquement comportement"""
    merged = transactions.merge(clients[["client_id", "profil", "anciennete_compte_jours"]], on="client_id", how="left")
    merged = merged.copy()
    if not pd.api.types.is_datetime64_any_dtype(merged["date_transaction"]):
        merged["date_transaction"] = pd.to_datetime(merged["date_transaction"], errors="coerce")
    
    merged["client_age_days"] = merged["anciennete_compte_jours"].astype(float)
    merged["is_weekend"] = merged["date_transaction"].dt.dayofweek.isin([5, 6]).astype(int)
    merged["transaction_type"] = merged["type_transaction"].fillna("inconnu")
    merged["corridor"] = merged["corridor"].fillna("inconnu")
    merged["client_profile"] = merged["profil"].fillna("inconnu")

    features = merged[["client_age_days", "is_weekend", "transaction_type", "corridor", "client_profile"]]
    target = merged["is_risky"]
    client_ids = merged["client_id"]
    
    return features, target, client_ids, "COMPORTEMENTAL (sans montant)"


def build_features_amount_only(transactions: pd.DataFrame, clients: pd.DataFrame):
    """Modèle MONTANT SEUL"""
    merged = transactions.merge(clients[["client_id", "profil", "anciennete_compte_jours"]], on="client_id", how="left")
    merged = merged.copy()
    
    merged["log_amount"] = np.log1p(merged["montant_fcfa"])
    client_amount_stats = merged.groupby("client_id")["montant_fcfa"].agg(["median", "std"]).reset_index()
    client_amount_stats.columns = ["client_id", "client_median_amount", "client_std_amount"]
    merged = merged.merge(client_amount_stats, on="client_id", how="left")
    merged["client_median_amount"] = merged["client_median_amount"].fillna(merged["montant_fcfa"].median())
    merged["client_std_amount"] = merged["client_std_amount"].fillna(merged["montant_fcfa"].std())
    merged["amount_ratio_to_client_median"] = merged["montant_fcfa"] / (merged["client_median_amount"] + 1)
    merged["amount_zscore"] = (merged["montant_fcfa"] - merged["client_median_amount"]) / (merged["client_std_amount"] + 1)
    
    features = merged[["log_amount", "amount_ratio_to_client_median", "amount_zscore"]]
    target = merged["is_risky"]
    client_ids = merged["client_id"]
    
    return features, target, client_ids, "MONTANT SEUL"


def evaluate_model(X, y, client_ids, label):
    """Entraîner et évaluer un modèle avec split par client"""
    X_cv, X_test_holdout, y_cv, y_test_holdout, clients_cv, clients_test = train_test_split(
        X, y, client_ids, test_size=0.2, random_state=42, stratify=y
    )
    
    numeric_features = X_cv.select_dtypes(include=[np.number]).columns.tolist()
    categorical_features = X_cv.select_dtypes(exclude=[np.number]).columns.tolist()
    
    preprocessor = ColumnTransformer(
        transformers=[
            ("num", Pipeline([("imputer", SimpleImputer(strategy="median"))]), numeric_features),
            ("cat", Pipeline([("imputer", SimpleImputer(strategy="most_frequent")), ("onehot", OneHotEncoder(handle_unknown="ignore"))]), categorical_features),
        ] if categorical_features else [
            ("num", Pipeline([("imputer", SimpleImputer(strategy="median"))]), numeric_features),
        ]
    )
    
    skgf = StratifiedGroupKFold(n_splits=5, shuffle=True, random_state=42)
    cv_roc_auc_scores = []
    cv_avg_precision_scores = []
    
    for fold, (train_idx, val_idx) in enumerate(skgf.split(X_cv, y_cv, groups=clients_cv)):
        X_train_fold = X_cv.iloc[train_idx]
        y_train_fold = y_cv.iloc[train_idx]
        X_val_fold = X_cv.iloc[val_idx]
        y_val_fold = y_cv.iloc[val_idx]
        
        X_train_preprocessed = preprocessor.fit_transform(X_train_fold)
        X_val_preprocessed = preprocessor.transform(X_val_fold)
        
        smote = SMOTE(random_state=42, k_neighbors=min(3, (y_train_fold == True).sum() - 1))
        try:
            X_train_smote, y_train_smote = smote.fit_resample(X_train_preprocessed, y_train_fold)
        except:
            X_train_smote, y_train_smote = X_train_preprocessed, y_train_fold
        
        clf = RandomForestClassifier(n_estimators=100, random_state=42, n_jobs=-1, max_depth=15)
        clf.fit(X_train_smote, y_train_smote)
        
        y_proba_val = clf.predict_proba(X_val_preprocessed)[:, 1]
        
        try:
            roc_auc = roc_auc_score(y_val_fold, y_proba_val)
            avg_precision = average_precision_score(y_val_fold, y_proba_val)
            cv_roc_auc_scores.append(roc_auc)
            cv_avg_precision_scores.append(avg_precision)
        except:
            pass
    
    # Modèle final sur tout le CV set
    X_cv_preprocessed = preprocessor.fit_transform(X_cv)
    smote_final = SMOTE(random_state=42, k_neighbors=min(3, (y_cv == True).sum() - 1))
    try:
        X_cv_smote, y_cv_smote = smote_final.fit_resample(X_cv_preprocessed, y_cv)
    except:
        X_cv_smote, y_cv_smote = X_cv_preprocessed, y_cv
    
    clf_final = RandomForestClassifier(n_estimators=100, random_state=42, n_jobs=-1, max_depth=15)
    clf_final.fit(X_cv_smote, y_cv_smote)
    
    X_test_preprocessed = preprocessor.transform(X_test_holdout)
    y_proba_test = clf_final.predict_proba(X_test_preprocessed)[:, 1]
    y_pred_test = clf_final.predict(X_test_preprocessed)
    
    try:
        roc_auc_test = roc_auc_score(y_test_holdout, y_proba_test)
        avg_precision_test = average_precision_score(y_test_holdout, y_proba_test)
    except:
        roc_auc_test = 0.0
        avg_precision_test = 0.0
    
    report_test = classification_report(y_test_holdout, y_pred_test, output_dict=True, zero_division=0)
    
    return {
        "cv_roc_auc_mean": np.mean(cv_roc_auc_scores),
        "cv_roc_auc_std": np.std(cv_roc_auc_scores),
        "cv_avg_precision_mean": np.mean(cv_avg_precision_scores),
        "cv_avg_precision_std": np.std(cv_avg_precision_scores),
        "test_roc_auc": roc_auc_test,
        "test_avg_precision": avg_precision_test,
        "test_accuracy": report_test.get("accuracy", 0.0),
    }


if __name__ == "__main__":
    print("=" * 78)
    print("ÉTUDE D'ABLATION - DIAGNOSTIC DU SURAPPRENTISSAGE")
    print("=" * 78)
    
    clients, transactions = load_dataset()
    
    # Test 1: Modèle COMPLET
    print("\n[1/3] Modèle COMPLET (montant + comportement)...")
    X1, y1, c1, label1 = build_features_complete(transactions, clients)
    results1 = evaluate_model(X1, y1, c1, label1)
    
    # Test 2: Modèle SANS MONTANT
    print("[2/3] Modèle SANS MONTANT (comportement uniquement)...")
    X2, y2, c2, label2 = build_features_behavioral_only(transactions, clients)
    results2 = evaluate_model(X2, y2, c2, label2)
    
    # Test 3: Modèle MONTANT SEUL
    print("[3/3] Modèle MONTANT SEUL...")
    X3, y3, c3, label3 = build_features_amount_only(transactions, clients)
    results3 = evaluate_model(X3, y3, c3, label3)
    
    # Affichage des résultats
    print("\n" + "=" * 78)
    print("RÉSULTATS DE L'ÉTUDE D'ABLATION")
    print("=" * 78)
    
    print(f"\n{label1}")
    print(f"  CV (5-fold)    : AUC-ROC = {results1['cv_roc_auc_mean']:.3f} ± {results1['cv_roc_auc_std']:.3f}")
    print(f"                   AUC-PR  = {results1['cv_avg_precision_mean']:.3f} ± {results1['cv_avg_precision_std']:.3f}")
    print(f"  Test holdout   : AUC-ROC = {results1['test_roc_auc']:.3f}, AUC-PR = {results1['test_avg_precision']:.3f}, Accuracy = {results1['test_accuracy']*100:.2f}%")
    
    print(f"\n{label2}")
    print(f"  CV (5-fold)    : AUC-ROC = {results2['cv_roc_auc_mean']:.3f} ± {results2['cv_roc_auc_std']:.3f}")
    print(f"                   AUC-PR  = {results2['cv_avg_precision_mean']:.3f} ± {results2['cv_avg_precision_std']:.3f}")
    print(f"  Test holdout   : AUC-ROC = {results2['test_roc_auc']:.3f}, AUC-PR = {results2['test_avg_precision']:.3f}, Accuracy = {results2['test_accuracy']*100:.2f}%")
    
    print(f"\n{label3}")
    print(f"  CV (5-fold)    : AUC-ROC = {results3['cv_roc_auc_mean']:.3f} ± {results3['cv_roc_auc_std']:.3f}")
    print(f"                   AUC-PR  = {results3['cv_avg_precision_mean']:.3f} ± {results3['cv_avg_precision_std']:.3f}")
    print(f"  Test holdout   : AUC-ROC = {results3['test_roc_auc']:.3f}, AUC-PR = {results3['test_avg_precision']:.3f}, Accuracy = {results3['test_accuracy']*100:.2f}%")
    
    print("\n" + "=" * 78)
    print("INTERPRÉTATION")
    print("=" * 78)
    print(f"Degradation en supprimant montant : {(results1['test_roc_auc'] - results2['test_roc_auc']):.3f} (idéal : >> 0.2)")
    print(f"Performance montant seul : {results3['test_roc_auc']:.3f} (idéal : proche de Test COMPLET)")
    print("\nConclusion :")
    if results3['test_roc_auc'] > 0.95:
        print("✓ Le modèle apprend PRINCIPALEMENT du montant → diagnostic CONFIRMÉ")
    if results2['test_roc_auc'] < 0.65:
        print("✓ Sans montant, performance s'effondre → les features comportementales seules sont FAIBLES")
