"""
features.py - Feature engineering sans fuite de données.

Règles :
- Split holdout par CLIENT (pas par transaction).
- Statistiques de montant calculées uniquement sur les données de référence (train).
- Les transactions du holdout n'influencent jamais les features d'entraînement.
"""

from __future__ import annotations

import numpy as np
import pandas as pd
from sklearn.model_selection import train_test_split

NUMERIC_FEATURES = [
    "log_amount",
    "amount_ratio_to_client_median",
    "amount_zscore",
    "client_age_days",
    "is_weekend",
]
CATEGORICAL_FEATURES = ["transaction_type", "corridor", "client_profile"]
FEATURE_COLUMNS = NUMERIC_FEATURES + CATEGORICAL_FEATURES


def build_base_frame(transactions: pd.DataFrame, clients: pd.DataFrame) -> pd.DataFrame:
    """Fusionne clients/transactions et prépare les colonnes brutes (sans stats client)."""
    merged = transactions.merge(
        clients[["client_id", "profil", "anciennete_compte_jours"]],
        on="client_id",
        how="left",
    ).copy()

    if not pd.api.types.is_datetime64_any_dtype(merged["date_transaction"]):
        merged["date_transaction"] = pd.to_datetime(merged["date_transaction"], errors="coerce")

    merged["client_age_days"] = merged["anciennete_compte_jours"].astype(float)
    merged["is_weekend"] = merged["date_transaction"].dt.dayofweek.isin([5, 6]).astype(int)
    merged["transaction_type"] = merged["type_transaction"].fillna("inconnu")
    merged["corridor"] = merged["corridor"].fillna("inconnu")
    merged["client_profile"] = merged["profil"].fillna("inconnu")

    return merged


def _client_amount_stats(reference: pd.DataFrame) -> pd.DataFrame:
    stats = reference.groupby("client_id")["montant_fcfa"].agg(["median", "std"]).reset_index()
    stats.columns = ["client_id", "client_median_amount", "client_std_amount"]
    return stats


def compute_features(merged: pd.DataFrame, reference: pd.DataFrame) -> pd.DataFrame:
    """
    Calcule les features pour `merged` en utilisant uniquement `reference`
    pour les statistiques de montant par client (évite la fuite train/test).
    """
    stats = _client_amount_stats(reference)
    global_median = reference["montant_fcfa"].median()
    global_std = reference["montant_fcfa"].std()

    out = merged.merge(stats, on="client_id", how="left")
    out["client_median_amount"] = out["client_median_amount"].fillna(global_median)
    out["client_std_amount"] = out["client_std_amount"].fillna(global_std)

    out["log_amount"] = np.log1p(out["montant_fcfa"])
    out["amount_ratio_to_client_median"] = out["montant_fcfa"] / (out["client_median_amount"] + 1)
    out["amount_zscore"] = (out["montant_fcfa"] - out["client_median_amount"]) / (out["client_std_amount"] + 1)

    return out


def extract_xy(base: pd.DataFrame) -> tuple[pd.DataFrame, pd.Series, pd.Series]:
    features = base[FEATURE_COLUMNS]
    target = base["is_risky"]
    client_ids = base["client_id"]
    return features, target, client_ids


def split_by_client(
    base: pd.DataFrame,
    test_size: float = 0.2,
    random_state: int = 42,
) -> tuple[pd.DataFrame, pd.DataFrame]:
    """Sépare les transactions par client (holdout = clients jamais vus à l'entraînement)."""
    client_risk = base.groupby("client_id")["is_risky"].max()
    train_clients, holdout_clients = train_test_split(
        client_risk.index.to_numpy(),
        test_size=test_size,
        stratify=client_risk.values,
        random_state=random_state,
    )

    base_cv = base[base["client_id"].isin(train_clients)].reset_index(drop=True)
    base_holdout = base[base["client_id"].isin(holdout_clients)].reset_index(drop=True)
    return base_cv, base_holdout


def prepare_datasets(
    transactions: pd.DataFrame,
    clients: pd.DataFrame,
    test_size: float = 0.2,
    random_state: int = 42,
) -> tuple[pd.DataFrame, pd.Series, pd.Series, pd.DataFrame, pd.Series, pd.Series]:
    """
    Pipeline complet : base frame → split par client → features sans fuite.
    Les stats de montant du holdout sont calculées uniquement sur le CV set.
    """
    base = build_base_frame(transactions, clients)
    base_cv, base_holdout = split_by_client(base, test_size=test_size, random_state=random_state)

    cv_enriched = compute_features(base_cv, reference=base_cv)
    holdout_enriched = compute_features(base_holdout, reference=base_cv)

    X_cv, y_cv, clients_cv = extract_xy(cv_enriched)
    X_holdout, y_holdout, clients_holdout = extract_xy(holdout_enriched)

    return X_cv, y_cv, clients_cv, X_holdout, y_holdout, clients_holdout
