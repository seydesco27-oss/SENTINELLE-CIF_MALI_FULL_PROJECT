# Integration backend CIF

`dataset/dataset_C_hybrid_aml.csv` est le contrat d'echange canonique avec le
backend. Une requete de scoring doit employer exactement ces noms de colonnes.
Les tables `dataset_A_transaction_features.csv` et
`dataset_B_customer_features.csv` sont des tables de detail transactionnel et
client; elles permettent au backend de construire ou de verifier les colonnes
historisees de la table C.

## Role des niveaux

- Niveau 1 : `SanctionsScreener` produit le signal PPE/sanctions. Le backend
  renseigne `max_sanction_match_score`, `sanction_match_flag` et, si besoin,
  `screening_match_count`.
- Niveau 2 : les transactions AMLSim servent a l'entrainement et aux motifs
  de graphe. Le backend calcule `in_degree` et `out_degree` a partir des
  comptes et beneficiaires connus.
- Niveau 3 : les scenarios CIF (structuring, layering, reactivation et
  corridor) enrichissent le jeu d'entrainement et justifient les regles
  `structuring_match`, `rapid_transfer_match`, `dormant_account_match` et
  `high_risk_corridor_match` deja presentes dans la table C.

## Ordre d'appel

1. Le backend construit une ligne conforme a `dataset_C_hybrid_aml.csv`.
2. Il calcule uniquement avec l'historique anterieur les champs
   `previous_*`, `cash_ratio_30d`, `country_count_30d` et les degres du graphe.
3. Il appelle `CIFRiskService.assess(transaction, client_name)`.
4. Le service adapte les colonnes backend vers les 20 features du modele,
   effectue le screening Niveau 1 et retourne `model_score`, `rule_score`,
   `screening_score` et `final_score`.

Le service HTTP charge par defaut la watchlist normalisee de demonstration
`niveau1_screening/sample_data/watchlist_exemple.csv`. Le champ
`health.screening` indique si le screening est actif et quelle source est
chargee. Avant toute utilisation reelle, remplacer cette watchlist DEMO par
les listes officielles OFAC, ONU et UE adaptees au meme schema.

La base actuelle ne possede pas de compte beneficiaire sur les transactions.
Les champs `in_degree` et `out_degree` envoyes par Laravel sont donc des
proxys de connectivite bases sur les pays des flux historiques; ils ne doivent
pas etre presentes comme un graphe complet avant ajout de cette relation.

## Service HTTP utilise par Laravel

Le modele exporte est expose par `ml_service.py` sur le port `8100`. Laravel
lit `ML_SCORE_URL` et appelle `POST /score`; il ne simule pas le modele.

```powershell
cd ML_projet_cif-master
.\.venv\Scripts\Activate.ps1
python dossier\ml_service.py
```

Verification :

```powershell
curl.exe http://127.0.0.1:8100/health
```

Le dashboard utilise ce meme chemin depuis `Sentinelle Assist`. Pour une
alerte, le frontend envoie `alert_id`; Laravel retrouve sa transaction avant
de construire les features. Les cartes generales du dashboard restent issues
des vues SQL et ne doivent pas etre presentees comme un nouveau scoring temps
reel.

Ne jamais fournir au modele les colonnes de sortie `aml_alert_flag`,
`aml_alert_score`, `aml_alert_priority` ou `target_*`: ce sont des labels ou
des decisions du moteur de regles, et leur emploi comme entree creerait une
fuite de donnees.

## Champs minimaux

Le service exige `amount`, `transaction_type` et
`transaction_day_of_week`. Pour un score utilisable en production, fournir
aussi `previous_transaction_count_30d`, `previous_volume_30d`,
`previous_transaction_count_24h`, `previous_volume_24h`,
`country_count_30d`, `aml_rule_score` et `max_sanction_match_score`.

## Exemple

```python
from integration_service import CIFRiskService

service = CIFRiskService("niveau1_screening/sample_data/watchlist_exemple.csv")
result = service.assess(
    {
        "transaction_id": "TX-2026-000001",
        "amount": 7_500_000,
        "transaction_type": "TRANSFER_OUT",
        "transaction_day_of_week": 1,
        "previous_transaction_count_30d": 2,
        "previous_volume_30d": 7_541_267,
        "previous_transaction_count_24h": 1,
        "previous_volume_24h": 7_500_000,
        "country_count_30d": 1,
        "aml_rule_score": 75,
        "max_sanction_match_score": 0,
    },
    client_name="Amadou Traore Diallo",
)
```
