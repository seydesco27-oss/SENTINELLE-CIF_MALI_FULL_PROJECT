"""
adapt_clients_n2.py - Adapter les clients AMLSim au format attendu par train_model.py

AMLSim fournit : ACCOUNT_ID, CUSTOMER_ID, etc.
On a besoin de : client_id, profil, anciennete_compte_jours
"""

import pandas as pd

# Charger les clients AMLSim
clients_raw = pd.read_csv("clients_n2.csv")
print(f"Colonnes AMLSim brutes: {list(clients_raw.columns)}")
print(f"Nombre de clients: {len(clients_raw)}")

# Charger les transactions pour voir le type de client_id
transactions = pd.read_csv("transactions_n2.csv")
print(f"\nType de client_id dans transactions_n2: {transactions['client_id'].dtype}")
print(f"Exemples: {transactions['client_id'].head().tolist()}")

# Créer un dataframe adapté
# Attention : harmoniser le type de client_id avec celui des transactions
clients_adapted = pd.DataFrame({
    "client_id": "AMLSIM-CLI-" + clients_raw["ACCOUNT_ID"].astype(str),  # Format compatible avec transactions
    "profil": clients_raw.get("ACCOUNT_TYPE", "unknown"),  # Utiliser ACCOUNT_TYPE si disponible
    "anciennete_compte_jours": 365,  # Valeur par défaut
})

print(f"\nColonnes adaptées: {list(clients_adapted.columns)}")
print(f"Type de client_id adapté: {clients_adapted['client_id'].dtype}")
print(f"Premiers lignes:\n{clients_adapted.head()}")

# Sauvegarder
clients_adapted.to_csv("clients_n2_adapted.csv", index=False)
print(f"\nFichier adapte sauvegarde: clients_n2_adapted.csv")

