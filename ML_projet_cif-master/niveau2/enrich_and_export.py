"""
enrich_and_export.py - Enrichit la sortie AMLSim (IBM) avec des montants et
dates réalistes en FCFA, calés sur le contexte CIF/SFD.

Contexte : AMLSim génère une topologie de transactions authentique (qui
transacte avec qui, et quels comptes appartiennent à quelle typologie de
blanchiment réelle - fan_in, fan_out, cycle...) via son moteur Python. La
partie Java du projet calcule normalement les montants et horodatages
réels à partir de cette topologie, mais elle nécessite des dépendances
Maven inaccessibles depuis cet environnement.

Ce script complète donc la topologie réelle d'AMLSim avec la même logique
de montants FCFA que le générateur Niveau 3 (voir synthetic_transactions/),
pour obtenir un jeu de données complet et directement exploitable.

Entrée  : tmp/sample/accounts.csv, transactions.csv, alert_members.csv
Sortie  : transactions_amlsim_enrichi.csv
"""

import csv
from datetime import datetime, timedelta

import numpy as np
import pandas as pd

RANDOM_SEED = 42
rng = np.random.default_rng(RANDOM_SEED)

MONTANT_MEDIAN_FCFA = 45_000
MONTANT_SIGMA = 1.1
DATE_FIN = datetime(2026, 8, 1)
N_JOURS_HISTORIQUE = 180

TYPES_TRANSACTION_NORMALES = [
    "depot", "retrait", "transfert_sortant", "transfert_entrant",
    "remboursement_credit", "decaissement_credit",
]


def charger_donnees(simulation_name: str = "cif_10k"):
    base = f"tmp/{simulation_name}"
    accounts = pd.read_csv(f"{base}/accounts.csv")
    transactions = pd.read_csv(f"{base}/transactions.csv")
    alert_members = pd.read_csv(f"{base}/alert_members.csv")
    return accounts, transactions, alert_members


def construire_index_alertes(alert_members: pd.DataFrame) -> dict:
    """Regroupe les comptes par episode d'alerte (alertID). Une transaction
    n'est consideree comme faisant partie de la typologie que si SES DEUX
    comptes (source et destination) appartiennent au meme episode -- sinon
    on capturerait a tort les transactions normales d'un compte par
    ailleurs implique dans une alerte, ce qui gonflerait artificiellement
    le taux de risque."""
    episodes = {}
    for _, row in alert_members.iterrows():
        alert_id = row["alertID"]
        episodes.setdefault(alert_id, {
            "type_anomalie": row["reason"],
            "min_amount": float(row["minAmount"]),
            "max_amount": float(row["maxAmount"]),
            "start_step": int(row["startStep"]),
            "end_step": int(row["endStep"]),
            "comptes": set(),
        })
        episodes[alert_id]["comptes"].add(int(row["accountID"]))

    # index compte -> liste des episodes auxquels il appartient
    compte_vers_episodes = {}
    for alert_id, ep in episodes.items():
        for c in ep["comptes"]:
            compte_vers_episodes.setdefault(c, []).append(ep)

    return compte_vers_episodes


def trouver_episode_commun(src: int, dst: int, compte_vers_episodes: dict):
    """Retourne l'episode d'alerte partage par src et dst, si les deux
    comptes appartiennent au meme episode (donc la transaction fait
    vraisemblablement partie de cette structure de blanchiment)."""
    episodes_src = compte_vers_episodes.get(src, [])
    episodes_dst = compte_vers_episodes.get(dst, [])
    for ep in episodes_src:
        if ep in episodes_dst:
            return ep
    return None


def step_vers_date(step: int, total_steps: int = 720) -> datetime:
    """Convertit un 'step' de simulation en date, ramenée à notre fenêtre
    de 180 jours se terminant le DATE_FIN (au lieu de la base_date 2017
    d'origine, sans intérêt pour notre contexte)."""
    ratio = step / total_steps
    jours_ecoules = ratio * N_JOURS_HISTORIQUE
    return DATE_FIN - timedelta(days=N_JOURS_HISTORIQUE) + timedelta(days=jours_ecoules)


def montant_normal() -> float:
    return float(rng.lognormal(mean=np.log(MONTANT_MEDIAN_FCFA), sigma=MONTANT_SIGMA))


def enrichir(accounts, transactions, alert_index) -> pd.DataFrame:
    rows = []
    for _, tx in transactions.iterrows():
        src, dst = int(tx["src"]), int(tx["dst"])
        alerte = trouver_episode_commun(src, dst, alert_index)

        if alerte:
            montant = round(float(rng.uniform(alerte["min_amount"], alerte["max_amount"]) * 350), -2)
            # x350 : les montants AMLSim sont calibrés en USD sur un contexte
            # bancaire classique (ordres de grandeur élevés) ; on les
            # ramène a l'échelle FCFA/SFD tout en conservant la structure
            # relative min/max définie par la typologie d'origine.
            step_ref = rng.integers(alerte["start_step"], max(alerte["start_step"] + 1, alerte["end_step"] + 1))
            date_tx = step_vers_date(int(step_ref))
            type_anomalie = alerte["type_anomalie"]
            is_risky = True
            type_transaction = "transfert_sortant"
        else:
            montant = round(montant_normal(), -2)
            date_tx = step_vers_date(int(rng.integers(0, 720)))
            type_anomalie = "aucune"
            is_risky = False
            type_transaction = str(rng.choice(TYPES_TRANSACTION_NORMALES))

        rows.append({
            "transaction_id": f"AMLSIM-{tx['id']}",
            "client_id": f"AMLSIM-CLI-{src}",
            "compte_destination_id": f"AMLSIM-CLI-{dst}",
            "date_transaction": date_tx.strftime("%Y-%m-%d"),
            "montant_fcfa": montant,
            "type_transaction": type_transaction,
            "is_risky": is_risky,
            "type_anomalie": type_anomalie,
            "source": "AMLSIM_IBM_TOPOLOGIE_REELLE",
        })

    return pd.DataFrame(rows)


if __name__ == "__main__":
    accounts, transactions, alert_members = charger_donnees()
    alert_index = construire_index_alertes(alert_members)

    df = enrichir(accounts, transactions, alert_index)
    df.to_csv("transactions_amlsim_enrichi_10k.csv", index=False)

    print("=" * 78)
    print("ENRICHISSEMENT TERMINE")
    print("=" * 78)
    print(f"Transactions totales     : {len(df)}")
    print(f"Transactions a risque    : {df['is_risky'].sum()} ({df['is_risky'].mean()*100:.2f}%)")
    print("\nRepartition par typologie (issue du vrai moteur AMLSim IBM) :")
    print(df.loc[df['is_risky'], 'type_anomalie'].value_counts().to_string())
    print("\nFichier ecrit : transactions_amlsim_enrichi_10k.csv")
