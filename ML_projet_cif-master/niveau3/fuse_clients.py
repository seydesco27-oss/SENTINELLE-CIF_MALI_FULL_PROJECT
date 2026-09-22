"""
fuse_clients.py - Fusionner les clients Niveau 2 et Niveau 3 pour l'ensemble
"""

import pandas as pd

print("=" * 78)
print("FUSION DES CLIENTS NIVEAU 2 + NIVEAU 3")
print("=" * 78)

# Charger les clients adaptés des deux niveaux
clients_n3 = pd.read_csv("clients.csv")
clients_n2 = pd.read_csv("clients_n2_adapted.csv")

print(f"\nNiveau 3 : {len(clients_n3)} clients")
print(f"  Colonnes : {list(clients_n3.columns)}")
print(f"  Exemples :\n{clients_n3.head()}")

print(f"\nNiveau 2 (adapté) : {len(clients_n2)} clients")
print(f"  Colonnes : {list(clients_n2.columns)}")
print(f"  Exemples :\n{clients_n2.head()}")

# Fusionner
clients_combined = pd.concat([clients_n3, clients_n2], ignore_index=True)
clients_combined.to_csv("clients_combined.csv", index=False)

print(f"\n" + "=" * 78)
print(f"FUSION TERMINEE")
print(f"=" * 78)
print(f"Clients fusionnes : {len(clients_combined)} lignes")
print(f"  - Niveau 3 : {len(clients_n3)}")
print(f"  - Niveau 2 : {len(clients_n2)}")
print(f"\nFichier sauvegarde : clients_combined.csv")
