"""
generator.py - Générateur de transactions synthétiques pour SFD/coopérative
financière (contexte CIF - Afrique de l'Ouest)
Hackathon CIF DigiCoop-WA+ - Thématique 01

Produit deux tables :
    - clients.csv      : population de clients avec profil
    - transactions.csv : historique de transactions, avec label ground-truth
                          (is_risky, type_anomalie) pour l'entraînement et
                          l'évaluation du modèle de scoring.

Usage :
    python generator.py
"""

from __future__ import annotations

import uuid
from datetime import timedelta

import numpy as np
import pandas as pd

import config as cfg


rng = np.random.default_rng(cfg.RANDOM_SEED)


# --------------------------------------------------------------------------
# 1. Population de clients
# --------------------------------------------------------------------------

def generate_clients(n: int) -> pd.DataFrame:
    prenoms = rng.choice(cfg.PRENOMS, size=n)
    noms = rng.choice(cfg.NOMS, size=n)
    profils = rng.choice(cfg.PROFILS_CLIENT, size=n, p=cfg.POIDS_PROFIL)
    anciennete = rng.integers(15, 365 * 5, size=n)  # 15 jours à 5 ans

    df = pd.DataFrame({
        "client_id": [f"CLI-{i:05d}" for i in range(n)],
        "nom_complet": [f"{p} {nm}" for p, nm in zip(prenoms, noms)],
        "profil": profils,
        "anciennete_compte_jours": anciennete,
    })
    return df


# --------------------------------------------------------------------------
# 2. Transactions "normales" (comportement de base, pas de label de risque)
# --------------------------------------------------------------------------

def _random_montant() -> float:
    return float(rng.lognormal(mean=np.log(cfg.MONTANT_MEDIAN_FCFA), sigma=cfg.MONTANT_SIGMA))


def _random_date(date_fin, n_jours) -> pd.Timestamp:
    jours_avant = int(rng.integers(0, n_jours))
    return pd.Timestamp(date_fin) - timedelta(days=jours_avant)


def generate_normal_transactions(clients: pd.DataFrame) -> list[dict]:
    transactions = []
    client_ids = clients["client_id"].tolist()

    for _, client in clients.iterrows():
        n_tx = max(1, rng.poisson(cfg.TRANSACTIONS_PAR_CLIENT_MOYENNE))
        for _ in range(n_tx):
            ttype = rng.choice(cfg.TYPES_TRANSACTION, p=cfg.POIDS_TYPES_TRANSACTION)
            corridor = rng.choice(cfg.CORRIDORS, p=cfg.POIDS_CORRIDORS)
            dest = None
            if ttype in ("transfert_sortant", "transfert_entrant"):
                dest = rng.choice(client_ids)

            transactions.append({
                "transaction_id": str(uuid.uuid4())[:8],
                "client_id": client["client_id"],
                "date_transaction": _random_date(cfg.DATE_FIN, cfg.N_JOURS_HISTORIQUE),
                "montant_fcfa": round(_random_montant(), -2),  # arrondi à la centaine
                "type_transaction": ttype,
                "compte_destination_id": dest,
                "corridor": corridor,
                "is_risky": False,
                "type_anomalie": "aucune",
            })
    return transactions


# --------------------------------------------------------------------------
# 3. Injection de typologies de risque (labellisées)
# --------------------------------------------------------------------------

def inject_structuring(client_id: str, date_fin) -> list[dict]:
    """Plusieurs montants juste sous le seuil de déclaration, en rafale."""
    n_operations = int(rng.integers(3, 6))
    base_date = _random_date(date_fin, cfg.N_JOURS_HISTORIQUE)
    tx = []
    for i in range(n_operations):
        montant = cfg.SEUIL_DECLARATION_FCFA * rng.uniform(0.90, 0.99)
        tx.append({
            "transaction_id": str(uuid.uuid4())[:8],
            "client_id": client_id,
            "date_transaction": base_date - timedelta(days=int(rng.integers(0, 5))),
            "montant_fcfa": round(montant, -2),
            "type_transaction": rng.choice(["depot", "transfert_sortant"]),
            "compte_destination_id": None,
            "corridor": "local",
            "is_risky": True,
            "type_anomalie": "structuring",
        })
    return tx


def inject_layering(client_id: str, all_client_ids: list[str], date_fin) -> list[dict]:
    """Dépôt suivi d'un éclatement rapide vers plusieurs comptes différents."""
    base_date = _random_date(date_fin, cfg.N_JOURS_HISTORIQUE)
    montant_initial = float(rng.uniform(2_000_000, 8_000_000))
    tx = [{
        "transaction_id": str(uuid.uuid4())[:8],
        "client_id": client_id,
        "date_transaction": base_date,
        "montant_fcfa": round(montant_initial, -2),
        "type_transaction": "depot",
        "compte_destination_id": None,
        "corridor": "local",
        "is_risky": True,
        "type_anomalie": "layering",
    }]

    n_eclatements = int(rng.integers(3, 7))
    montant_restant = montant_initial
    destinations = rng.choice(
        [c for c in all_client_ids if c != client_id], size=n_eclatements, replace=False
    )
    for i, dest in enumerate(destinations):
        part = montant_restant / (n_eclatements - i) * rng.uniform(0.7, 1.0)
        part = min(part, montant_restant)
        montant_restant -= part
        tx.append({
            "transaction_id": str(uuid.uuid4())[:8],
            "client_id": client_id,
            "date_transaction": base_date + timedelta(hours=int(rng.integers(1, 48))),
            "montant_fcfa": round(part, -2),
            "type_transaction": "transfert_sortant",
            "compte_destination_id": dest,
            "corridor": rng.choice(cfg.CORRIDORS, p=cfg.POIDS_CORRIDORS),
            "is_risky": True,
            "type_anomalie": "layering",
        })
    return tx


def inject_reactivation_dormant(client_id: str, date_fin) -> list[dict]:
    """Compte inactif pendant longtemps, puis transaction brutale et importante."""
    date_reactivation = date_fin - timedelta(days=int(rng.integers(0, 20)))
    montant = float(rng.uniform(1_500_000, 6_000_000))
    return [{
        "transaction_id": str(uuid.uuid4())[:8],
        "client_id": client_id,
        "date_transaction": date_reactivation,
        "montant_fcfa": round(montant, -2),
        "type_transaction": rng.choice(["retrait", "transfert_sortant"]),
        "compte_destination_id": None,
        "corridor": rng.choice(cfg.CORRIDORS, p=cfg.POIDS_CORRIDORS),
        "is_risky": True,
        "type_anomalie": "reactivation_dormant",
    }]


def inject_corridor_sensible(client_id: str, date_fin) -> list[dict]:
    """Transactions répétées sur un couloir géographique sensible, montants élevés."""
    n_operations = int(rng.integers(2, 5))
    base_date = _random_date(date_fin, cfg.N_JOURS_HISTORIQUE)
    tx = []
    for i in range(n_operations):
        montant = float(rng.uniform(800_000, 4_000_000))
        tx.append({
            "transaction_id": str(uuid.uuid4())[:8],
            "client_id": client_id,
            "date_transaction": base_date + timedelta(days=int(rng.integers(0, 10))),
            "montant_fcfa": round(montant, -2),
            "type_transaction": "transfert_sortant",
            "compte_destination_id": None,
            "corridor": "transfrontalier_sensible",
            "is_risky": True,
            "type_anomalie": "corridor_sensible",
        })
    return tx


def inject_risk_typologies(clients: pd.DataFrame) -> list[dict]:
    all_client_ids = clients["client_id"].tolist()
    n_risque = max(1, int(len(clients) * cfg.TAUX_CONTAMINATION))
    clients_risque = rng.choice(all_client_ids, size=n_risque, replace=False)

    typologies = list(cfg.POIDS_TYPOLOGIES.keys())
    poids = list(cfg.POIDS_TYPOLOGIES.values())
    assignation = rng.choice(typologies, size=n_risque, p=poids)

    tx = []
    for client_id, typo in zip(clients_risque, assignation):
        if typo == "structuring":
            tx += inject_structuring(client_id, cfg.DATE_FIN)
        elif typo == "layering":
            tx += inject_layering(client_id, all_client_ids, cfg.DATE_FIN)
        elif typo == "reactivation_dormant":
            tx += inject_reactivation_dormant(client_id, cfg.DATE_FIN)
        elif typo == "corridor_sensible":
            tx += inject_corridor_sensible(client_id, cfg.DATE_FIN)
    return tx, set(clients_risque)


# --------------------------------------------------------------------------
# 4. Orchestration
# --------------------------------------------------------------------------

def generate_dataset():
    clients = generate_clients(cfg.N_CLIENTS)
    normal_tx = generate_normal_transactions(clients)
    risky_tx, clients_risque = inject_risk_typologies(clients)

    all_tx = pd.DataFrame(normal_tx + risky_tx)
    all_tx = all_tx.sort_values("date_transaction").reset_index(drop=True)

    clients["client_a_risque"] = clients["client_id"].isin(clients_risque)

    return clients, all_tx


if __name__ == "__main__":
    clients, transactions = generate_dataset()

    clients.to_csv("clients.csv", index=False)
    transactions.to_csv("transactions.csv", index=False)

    print("=" * 78)
    print("GENERATION TERMINEE")
    print("=" * 78)
    print(f"Clients générés          : {len(clients)}")
    print(f"Clients à risque injectés: {clients['client_a_risque'].sum()} "
          f"({clients['client_a_risque'].mean() * 100:.1f}%)")
    print(f"Transactions totales     : {len(transactions)}")
    print(f"Transactions risquées    : {transactions['is_risky'].sum()} "
          f"({transactions['is_risky'].mean() * 100:.2f}%)")
    print("\nRépartition par typologie :")
    print(transactions.loc[transactions["is_risky"], "type_anomalie"].value_counts().to_string())
    print("\nFichiers écrits : clients.csv, transactions.csv")
