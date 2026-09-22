"""
validate.py - Vérifications de cohérence du jeu de données synthétique
Hackathon CIF DigiCoop-WA+ - Thématique 01

Lance : python validate.py
(nécessite d'avoir exécuté generator.py avant, pour produire transactions.csv)
"""

import pandas as pd
import config as cfg

tx = pd.read_csv("transactions.csv", parse_dates=["date_transaction"])

print("=" * 78)
print("VALIDATION DU JEU DE DONNEES SYNTHETIQUE")
print("=" * 78)

checks_ok = True

# 1. Les transactions "structuring" doivent être strictement sous le seuil
structuring = tx[tx["type_anomalie"] == "structuring"]
sous_seuil = (structuring["montant_fcfa"] < cfg.SEUIL_DECLARATION_FCFA).all()
proches_seuil = (structuring["montant_fcfa"] > cfg.SEUIL_DECLARATION_FCFA * 0.85).all()
status = "OK" if (sous_seuil and proches_seuil) else "ECHEC"
if status == "ECHEC":
    checks_ok = False
print(f"[{status}] Structuring : {len(structuring)} tx, toutes sous le seuil "
      f"et proches de {cfg.SEUIL_DECLARATION_FCFA:,} FCFA "
      f"(min={structuring['montant_fcfa'].min():,.0f}, "
      f"max={structuring['montant_fcfa'].max():,.0f})")

# 2. Les transactions "layering" doivent se produire sur une fenêtre courte
#    (dépôt + éclatements dans les 48h) par client concerné
layering_clients = tx[tx["type_anomalie"] == "layering"]["client_id"].unique()
fenetres = []
for cid in layering_clients:
    sous_ensemble = tx[(tx["client_id"] == cid) & (tx["type_anomalie"] == "layering")]
    ecart = (sous_ensemble["date_transaction"].max() - sous_ensemble["date_transaction"].min())
    fenetres.append(ecart.total_seconds() / 3600)  # en heures
max_fenetre_h = max(fenetres) if fenetres else 0
status = "OK" if max_fenetre_h <= 72 else "ECHEC"
if status == "ECHEC":
    checks_ok = False
print(f"[{status}] Layering : {len(layering_clients)} clients concernés, "
      f"fenêtre max observée = {max_fenetre_h:.1f}h (attendu <= 72h)")

# 3. Équilibre des classes : doit rester très minoritaire (réaliste AML)
taux = tx["is_risky"].mean() * 100
status = "OK" if 0.1 <= taux <= 5.0 else "ATTENTION"
print(f"[{status}] Taux de transactions à risque : {taux:.2f}% "
      f"(plage réaliste attendue : 0.1% - 5%)")

# 4. Pas de valeurs manquantes critiques
critiques = ["client_id", "montant_fcfa", "type_transaction", "is_risky", "type_anomalie"]
manquants = tx[critiques].isna().sum().sum()
status = "OK" if manquants == 0 else "ECHEC"
if status == "ECHEC":
    checks_ok = False
print(f"[{status}] Valeurs manquantes sur colonnes critiques : {manquants}")

print("\n" + ("=> Jeu de données prêt à l'emploi." if checks_ok
              else "=> Corriger les points en ECHEC avant utilisation."))
