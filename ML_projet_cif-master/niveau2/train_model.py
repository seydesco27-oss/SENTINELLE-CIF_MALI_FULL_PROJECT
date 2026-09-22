"""
train_model.py - Entraînement et évaluation du modèle de scoring de risque
Thématique 01 - Filtrage clients LBC/FT/FP

Compare deux modèles :
    - Régression logistique : interprétable (coefficients directs),
      référence attendue par un jury/régulateur qui exige des scores
      justifiables.
    - MLP : capacité à capter des interactions non-linéaires, cohérent
      avec l'approche du module de détection SQLi de l'équipe.

Vu le déséquilibre extrême des classes (~0.3% de positifs), on utilise :
    - une pondération de classe plutôt qu'un ré-échantillonnage brutal
    - une validation croisée stratifiée à 5 plis (peu de positifs, donc
      un simple split train/test serait trop instable pour être fiable)
    - l'AUC-PR (aire sous la courbe précision-rappel) comme métrique
      principale, car l'AUC-ROC est trompeuse sur données très
      déséquilibrées

Usage : python train_model.py
"""

import warnings

import numpy as np
import pandas as pd
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import StratifiedKFold
from sklearn.neural_network import MLPClassifier
from sklearn.metrics import (
    average_precision_score, precision_score, recall_score, f1_score,
)
from sklearn.preprocessing import StandardScaler
from sklearn.inspection import permutation_importance

warnings.filterwarnings("ignore")

FEATURE_COLUMNS = [
    "montant_log", "ratio_seuil", "proche_seuil", "a_destinataire",
    "jour_semaine", "type_decaissement_credit", "type_depot",
    "type_remboursement_credit", "type_retrait", "type_transfert_entrant",
    "type_transfert_sortant", "montant_moyen_client", "montant_std_client",
    "nb_transactions_client", "nb_destinataires_distincts_client",
    "montant_zscore", "nb_transactions_7j", "montant_cumule_7j",
]

RANDOM_SEED = 42


def charger_features(path="features.csv"):
    df = pd.read_csv(path)
    X = df[FEATURE_COLUMNS].fillna(0)
    y = df["is_risky"].astype(int)
    return X, y, df


def evaluer_modele(nom_modele, model, X, y, n_splits=5):
    """Validation croisée stratifiée, retourne les métriques par pli."""
    skf = StratifiedKFold(n_splits=n_splits, shuffle=True, random_state=RANDOM_SEED)
    resultats = []

    for fold, (train_idx, test_idx) in enumerate(skf.split(X, y), start=1):
        X_train, X_test = X.iloc[train_idx], X.iloc[test_idx]
        y_train, y_test = y.iloc[train_idx], y.iloc[test_idx]

        scaler = StandardScaler()
        X_train_s = scaler.fit_transform(X_train)
        X_test_s = scaler.transform(X_test)

        model.fit(X_train_s, y_train)
        y_scores = model.predict_proba(X_test_s)[:, 1]
        y_pred = model.predict(X_test_s)

        resultats.append({
            "fold": fold,
            "precision": precision_score(y_test, y_pred, zero_division=0),
            "rappel": recall_score(y_test, y_pred, zero_division=0),
            "f1": f1_score(y_test, y_pred, zero_division=0),
            "auc_pr": average_precision_score(y_test, y_scores),
            "n_positifs_test": int(y_test.sum()),
        })

    df_resultats = pd.DataFrame(resultats)
    print(f"\n{'=' * 78}")
    print(f"RESULTATS - {nom_modele}")
    print(f"{'=' * 78}")
    print(df_resultats.to_string(index=False))
    print(f"\nMoyenne sur {n_splits} plis :")
    for col in ["precision", "rappel", "f1", "auc_pr"]:
        print(f"  {col:12s} : {df_resultats[col].mean():.3f} (+/- {df_resultats[col].std():.3f})")

    return df_resultats


def entrainement_final_et_importance(X, y):
    """Entraîne sur l'ensemble des données pour extraire les facteurs de
    risque les plus influents (explicabilité) - utile pour justifier une
    alerte auprès d'un agent de conformité."""
    scaler = StandardScaler()
    X_s = scaler.fit_transform(X)

    modele_log = LogisticRegression(class_weight="balanced", max_iter=1000, random_state=RANDOM_SEED)
    modele_log.fit(X_s, y)

    print(f"\n{'=' * 78}")
    print("FACTEURS DE RISQUE LES PLUS INFLUENTS (regression logistique)")
    print(f"{'=' * 78}")
    coefs = pd.Series(modele_log.coef_[0], index=FEATURE_COLUMNS).sort_values(key=abs, ascending=False)
    print(coefs.head(10).to_string())

    modele_mlp = MLPClassifier(
        hidden_layer_sizes=(32, 16), max_iter=500, random_state=RANDOM_SEED,
        early_stopping=True,
    )
    modele_mlp.fit(X_s, y)

    print(f"\n{'=' * 78}")
    print("IMPORTANCE DES FEATURES POUR LE MLP (permutation importance)")
    print(f"{'=' * 78}")
    perm = permutation_importance(
        modele_mlp, X_s, y, n_repeats=5, random_state=RANDOM_SEED, scoring="average_precision"
    )
    importance = pd.Series(perm.importances_mean, index=FEATURE_COLUMNS).sort_values(ascending=False)
    print(importance.head(10).to_string())

    return modele_log, modele_mlp, scaler


if __name__ == "__main__":
    X, y, df = charger_features()

    print(f"Dataset : {len(X)} transactions, {y.sum()} a risque ({y.mean()*100:.2f}%)")

    modele_log = LogisticRegression(class_weight="balanced", max_iter=1000, random_state=RANDOM_SEED)
    resultats_log = evaluer_modele("Regression Logistique (interpretable)", modele_log, X, y)

    modele_mlp = MLPClassifier(
        hidden_layer_sizes=(32, 16), max_iter=500, random_state=RANDOM_SEED,
        early_stopping=True,
    )
    resultats_mlp = evaluer_modele("MLP", modele_mlp, X, y)

    entrainement_final_et_importance(X, y)

    resultats_log.to_csv("resultats_regression_logistique.csv", index=False)
    resultats_mlp.to_csv("resultats_mlp.csv", index=False)
