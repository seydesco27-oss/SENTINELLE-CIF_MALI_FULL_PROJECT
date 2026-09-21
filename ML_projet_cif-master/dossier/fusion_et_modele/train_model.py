"""
train_model.py (v2) - Entraînement et évaluation du modèle de scoring de
risque, avec corrections méthodologiques suite à la revue qualité du
dataset :

    1. MLPClassifier de scikit-learn ne supporte PAS class_weight -> on
       utilise SMOTE (sur-échantillonnage synthétique) appliqué UNIQUEMENT
       sur les données d'entraînement de chaque pli, jamais sur le test
       (fuite sinon).
    2. Vérification explicite d'une fuite possible entre les deux sources
       de données (Niveau 2 AMLSim vs Niveau 3 maison) : le modèle est
       aussi évalué séparément sur chaque source.
    3. MLP réduit et régularisé (moins de paramètres, dropout via alpha)
       pour limiter le sur-apprentissage vu le nombre encore limité
       d'exemples positifs.

Usage : python train_model.py
"""

import os
import warnings

import joblib
import numpy as np
import pandas as pd
from imblearn.over_sampling import SMOTE
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import StratifiedKFold
from sklearn.neural_network import MLPClassifier
from sklearn.metrics import (
    average_precision_score, precision_score, recall_score, f1_score,
)
from sklearn.preprocessing import StandardScaler

warnings.filterwarnings("ignore")

BASE = os.path.dirname(os.path.abspath(__file__))

FEATURE_COLUMNS = [
    "montant_log", "ratio_seuil", "proche_seuil", "a_destinataire",
    "jour_semaine", "type_decaissement_credit", "type_depot",
    "type_remboursement_credit", "type_retrait", "type_transfert_entrant",
    "type_transfert_sortant", "montant_moyen_client", "montant_std_client",
    "nb_transactions_client", "nb_destinataires_distincts_client",
    "montant_zscore", "nb_transactions_7j", "montant_cumule_7j",
    "in_degree", "out_degree",
]

RANDOM_SEED = 42


def exporter_modele(modele, scaler, chemin_sortie=None):
    """Sauvegarde les artefacts necessaires au scoring backend."""
    if chemin_sortie is None:
        chemin_sortie = os.path.join(BASE, "data", "modele_risque_mlp.joblib")

    artefact = {
        "model": modele,
        "scaler": scaler,
        "feature_columns": FEATURE_COLUMNS,
        "model_name": "MLPClassifier",
        "random_seed": RANDOM_SEED,
    }
    joblib.dump(artefact, chemin_sortie)
    print(f"Modele exporte : {chemin_sortie}")
    return chemin_sortie


def charger_features(path=None):
    if path is None:
        path = os.path.join(BASE, "data", "features.csv")
    df = pd.read_csv(path)
    X = df[FEATURE_COLUMNS].fillna(0)
    y = df["is_risky"].astype(int)
    return X, y, df


def verifier_fuite_entre_sources(df: pd.DataFrame):
    """Compare les distributions de montants entre les deux sources de
    donnees. Un ecart trop marque signale un risque que le modele apprenne
    a distinguer 'quel generateur' plutot que le vrai risque."""
    print(f"\n{'=' * 78}")
    print("VERIFICATION - FUITE POSSIBLE ENTRE SOURCES")
    print(f"{'=' * 78}")
    stats = df.groupby("source")["montant_fcfa"].agg(["mean", "median", "std", "count"])
    print(stats.to_string())

    taux_risque_par_source = df.groupby("source")["is_risky"].mean() * 100
    print("\nTaux de risque par source :")
    print(taux_risque_par_source.to_string())
    print("\n-> Si un modele obtient un tres bon score sur une source et un")
    print("   score proche de l'aleatoire sur l'autre, c'est le signe qu'il")
    print("   a appris les particularites d'une source plutot qu'un vrai")
    print("   signal de risque generalisable.")


def evaluer_par_source(model, scaler, X, y, sources, nom_modele):
    """Évalue le modèle déjà entraîné séparément sur chaque source pour
    détecter une éventuelle sur-spécialisation."""
    print(f"\nPerformance de {nom_modele} par source (evaluation croisee-source) :")
    for source in sources.unique():
        mask = sources == source
        if y[mask].sum() < 2:
            print(f"  {source:30s} : trop peu de positifs pour evaluer ({y[mask].sum()})")
            continue
        X_s = scaler.transform(X[mask])
        y_scores = model.predict_proba(X_s)[:, 1]
        auc_pr = average_precision_score(y[mask], y_scores)
        print(f"  {source:30s} : AUC-PR = {auc_pr:.3f} (n={mask.sum()}, positifs={y[mask].sum()})")


def evaluer_modele(nom_modele, model, X, y, n_splits=5, utiliser_smote=False):
    skf = StratifiedKFold(n_splits=n_splits, shuffle=True, random_state=RANDOM_SEED)
    resultats = []

    for fold, (train_idx, test_idx) in enumerate(skf.split(X, y), start=1):
        X_train, X_test = X.iloc[train_idx], X.iloc[test_idx]
        y_train, y_test = y.iloc[train_idx], y.iloc[test_idx]

        scaler = StandardScaler()
        X_train_s = scaler.fit_transform(X_train)
        X_test_s = scaler.transform(X_test)

        if utiliser_smote:
            # SMOTE uniquement sur le train, jamais sur le test (fuite sinon)
            smote = SMOTE(random_state=RANDOM_SEED, k_neighbors=min(5, y_train.sum() - 1))
            X_train_s, y_train = smote.fit_resample(X_train_s, y_train)

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


if __name__ == "__main__":
    X, y, df = charger_features()

    print(f"Dataset : {len(X)} transactions, {y.sum()} a risque ({y.mean()*100:.2f}%)")

    verifier_fuite_entre_sources(df)

    modele_log = LogisticRegression(class_weight="balanced", max_iter=1000, random_state=RANDOM_SEED)
    resultats_log = evaluer_modele("Regression Logistique (class_weight balanced)", modele_log, X, y)

    # MLP reduit (16,8 au lieu de 32,16) + regularisation (alpha) +
    # SMOTE pour compenser l'absence de class_weight sur ce modele
    modele_mlp = MLPClassifier(
        hidden_layer_sizes=(16, 8), max_iter=500, random_state=RANDOM_SEED,
        early_stopping=True, alpha=0.01,
    )
    resultats_mlp = evaluer_modele("MLP (SMOTE + regularise)", modele_mlp, X, y, utiliser_smote=True)

    # -- entrainement final sur tout le dataset, avec verification par source --
    scaler_final = StandardScaler()
    X_s = scaler_final.fit_transform(X)
    smote_final = SMOTE(random_state=RANDOM_SEED)
    X_s_smote, y_smote = smote_final.fit_resample(X_s, y)

    modele_mlp_final = MLPClassifier(
        hidden_layer_sizes=(16, 8), max_iter=500, random_state=RANDOM_SEED,
        early_stopping=True, alpha=0.01,
    )
    modele_mlp_final.fit(X_s_smote, y_smote)

    evaluer_par_source(modele_mlp_final, scaler_final, X, y, df["source"], "MLP")

    modele_log_final = LogisticRegression(class_weight="balanced", max_iter=1000, random_state=RANDOM_SEED)
    modele_log_final.fit(scaler_final.transform(X), y)
    evaluer_par_source(modele_log_final, scaler_final, X, y, df["source"], "Regression Logistique")

    os.makedirs(os.path.join(BASE, "data"), exist_ok=True)
    resultats_log.to_csv(os.path.join(BASE, "data", "resultats_regression_logistique.csv"), index=False)
    resultats_mlp.to_csv(os.path.join(BASE, "data", "resultats_mlp.csv"), index=False)
    exporter_modele(modele_mlp_final, scaler_final)
