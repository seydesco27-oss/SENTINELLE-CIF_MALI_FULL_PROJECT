"""
merge_datasets.py - Fusionne le Niveau 2 (AMLSim) et le Niveau 3 (générateur
maison CIF) en un seul dataset d'entraînement.

Usage : python merge_datasets.py   (depuis le dossier fusion_et_modele/)
Sortie : data/dataset_entrainement_combine.csv
"""

import os
import pandas as pd

COLONNES = [
    "transaction_id", "client_id", "compte_destination_id",
    "date_transaction", "montant_fcfa", "type_transaction",
    "is_risky", "type_anomalie", "source",
]

# Chemins relatifs à ce script, quel que soit le dossier depuis lequel on
# le lance
BASE = os.path.dirname(os.path.abspath(__file__))
CHEMIN_N2 = os.path.join(BASE, "..", "niveau2_amlsim", "data", "transactions_amlsim_enrichi.csv")
CHEMIN_N3 = os.path.join(BASE, "..", "niveau3_generateur_cif", "data", "transactions_niveau3.csv")
CHEMIN_SORTIE = os.path.join(BASE, "data", "dataset_entrainement_combine.csv")

# Niveau 2 : sortie de enrich_and_export.py
n2 = pd.read_csv(CHEMIN_N2)

# Niveau 3 : sortie de generator.py (le fichier n'a pas de colonne "source"
# à l'origine, on l'ajoute ici)
n3 = pd.read_csv(CHEMIN_N3)
n3["source"] = "SYNTHETIQUE_CIF"

n2 = n2[COLONNES]
n3 = n3[COLONNES]

combined = pd.concat([n2, n3], ignore_index=True)
os.makedirs(os.path.dirname(CHEMIN_SORTIE), exist_ok=True)
combined.to_csv(CHEMIN_SORTIE, index=False)

print("=" * 78)
print("FUSION TERMINEE")
print("=" * 78)
print(f"Niveau 2 (AMLSim)      : {len(n2)} transactions")
print(f"Niveau 3 (maison)      : {len(n3)} transactions")
print(f"Total combine          : {len(combined)} transactions")
print(f"Total a risque         : {combined['is_risky'].sum()} "
      f"({combined['is_risky'].mean()*100:.2f}%)")
print(f"\nFichier ecrit : {CHEMIN_SORTIE}")
