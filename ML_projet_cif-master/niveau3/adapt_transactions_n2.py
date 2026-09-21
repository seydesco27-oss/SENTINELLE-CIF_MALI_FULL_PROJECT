"""
adapt_transactions_n2.py - Ajouter les colonnes manquantes aux transactions AMLSim
"""

import pandas as pd

transactions = pd.read_csv("transactions_n2.csv")
print(f"Colonnes existantes: {list(transactions.columns)}")
print(f"Nombre de transactions: {len(transactions)}")

# Ajouter la colonne 'corridor' manquante
# Valeur par défaut : "local" pour la plupart, "transfrontalier_sensible" pour certains
transactions["corridor"] = "local"

# Optionnel : si compte_destination_id est vide, c'est pas une transaction transfrontalière
# Pour les données AMLSim, utiliser une logique simple
import numpy as np
transactions.loc[transactions["compte_destination_id"].isna(), "corridor"] = "transfrontalier_sensible"

print(f"\nColonnes adaptees: {list(transactions.columns)}")
print(f"Distribution corridor:\n{transactions['corridor'].value_counts()}")

# Sauvegarder
transactions.to_csv("transactions_n2.csv", index=False)
print(f"\nFichier adapte: transactions_n2.csv")
