"""
build_features.py - Ingénierie des features pour le modèle de scoring de
risque (Thématique 01 - Filtrage clients LBC/FT/FP)

Transforme le dataset combiné (Niveau 2 + Niveau 3) en une table de
features exploitable par un modèle de classification.

Usage : python build_features.py
Sortie : data/features.csv
"""

import os
import numpy as np
import pandas as pd

SEUIL_DECLARATION_FCFA = 10_000_000
BASE = os.path.dirname(os.path.abspath(__file__))


def charger_dataset(path=None) -> pd.DataFrame:
    if path is None:
        path = os.path.join(BASE, "data", "dataset_entrainement_combine.csv")
    df = pd.read_csv(path)
    df["date_transaction"] = pd.to_datetime(df["date_transaction"], format="mixed")
    return df.sort_values(["client_id", "date_transaction"]).reset_index(drop=True)


def features_transaction(df: pd.DataFrame) -> pd.DataFrame:
    """Features calculables directement au niveau de la transaction."""
    out = df.copy()
    out["montant_log"] = np.log1p(out["montant_fcfa"])
    out["ratio_seuil"] = out["montant_fcfa"] / SEUIL_DECLARATION_FCFA
    out["proche_seuil"] = (
        (out["montant_fcfa"] >= 0.85 * SEUIL_DECLARATION_FCFA)
        & (out["montant_fcfa"] < SEUIL_DECLARATION_FCFA)
    ).astype(int)
    out["a_destinataire"] = out["compte_destination_id"].notna().astype(int)
    out["jour_semaine"] = out["date_transaction"].dt.dayofweek

    # encodage one-hot du type de transaction
    type_dummies = pd.get_dummies(out["type_transaction"], prefix="type")
    out = pd.concat([out, type_dummies], axis=1)
    # -- features de reseau (in-degree / out-degree) --
    # essentielles pour detecter les schemas structurels (fan-in, fan-out,
    # cycle) qu'aucune feature de montant seule ne peut capter
    out_degree = out.groupby("client_id")["compte_destination_id"].nunique().rename("out_degree")
    in_degree = (
        out.dropna(subset=["compte_destination_id"])
        .groupby("compte_destination_id")["client_id"].nunique()
        .rename("in_degree")
    )
    out = out.merge(out_degree, on="client_id", how="left")
    out = out.merge(in_degree, left_on="client_id", right_index=True, how="left")
    out["in_degree"] = out["in_degree"].fillna(0)
    out["out_degree"] = out["out_degree"].fillna(0)

    return out


def features_comportementales(df: pd.DataFrame) -> pd.DataFrame:
    """Features agrégées par client : comportement global et fenêtre
    glissante de 7 jours, pour capter les écarts au comportement habituel
    et les rafales de transactions (structuring, layering...)."""
    out = df.copy()

    # -- agrégats globaux par client --
    agg = out.groupby("client_id")["montant_fcfa"].agg(
        montant_moyen_client="mean",
        montant_std_client="std",
        nb_transactions_client="count",
        nb_destinataires_distincts_client=lambda x: out.loc[x.index, "compte_destination_id"].nunique(),
    ).reset_index()
    out = out.merge(agg, on="client_id", how="left")
    out["montant_std_client"] = out["montant_std_client"].fillna(0)
    out["montant_zscore"] = (
        (out["montant_fcfa"] - out["montant_moyen_client"])
        / (out["montant_std_client"] + 1.0)
    )

    # -- fenêtre glissante 7 jours (nb transactions et montant cumulé) --
    out = out.set_index("date_transaction")
    rolling_frames = []
    for client_id, group in out.groupby("client_id"):
        group = group.sort_index()
        nb_7j = group["montant_fcfa"].rolling("7D").count()
        montant_7j = group["montant_fcfa"].rolling("7D").sum()
        group = group.assign(nb_transactions_7j=nb_7j, montant_cumule_7j=montant_7j)
        rolling_frames.append(group)
    out = pd.concat(rolling_frames).reset_index()

    return out


def construire_features():
    df = charger_dataset()
    df = features_transaction(df)
    df = features_comportementales(df)
    df.to_csv(os.path.join(BASE, "data", "features.csv"), index=False)

    print("=" * 78)
    print("FEATURES CONSTRUITES")
    print("=" * 78)
    print(f"Lignes  : {len(df)}")
    print(f"Colonnes: {len(df.columns)}")
    print("\nColonnes disponibles :")
    print(list(df.columns))
    return df


if __name__ == "__main__":
    construire_features()
