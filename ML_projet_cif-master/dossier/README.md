# Projet CIF DigiCoop-WA+ — Volet Données & Modèle (Thématique 01)

Hackathon National d'Innovation CIF — Mali, 2-4 octobre 2026

## Structure du projet

```
projet_cif/
│
├── niveau1_screening/          Filtrage PPE / Sanctions
│   ├── sanctions_screening.py     -> fonction screen(nom_client)
│   ├── demo.py                    -> démonstration + tests
│   └── sample_data/               -> données d'exemple (fictives)
│
├── niveau2_amlsim/              Données réelles (simulateur IBM AMLSim)
│   ├── enrich_and_export.py       -> enrichissement FCFA
│   ├── transaction_graph_generator_patched.py
│   ├── conf.json
│   └── data/                      -> comptes, transactions, alertes
│
├── niveau3_generateur_cif/      Générateur synthétique maison
│   ├── generator.py               -> génération calée sur contexte CIF
│   ├── config.py                  -> paramètres ajustables
│   ├── validate.py                -> vérifications de cohérence
│   └── data/
│
├── fusion_et_modele/            Fusion + entraînement du modèle
│   ├── merge_datasets.py          -> fusionne Niveau 2 + Niveau 3
│   ├── build_features.py          -> ingénierie des features
│   ├── train_model.py             -> entraînement + évaluation
│   ├── RESULTATS.md               -> synthèse des résultats
│   └── data/
│
└── dossier_candidature/         Documents pour la candidature CIF
    (note de présentation, fiche équipe, CVs — à compléter)
```

## Pipeline de bout en bout

```bash
cd fusion_et_modele
python merge_datasets.py      # -> dataset_entrainement_combine.csv
python build_features.py      # -> features.csv
python train_model.py         # -> métriques + modèles entraînés
```

Le Niveau 1 (`niveau1_screening/`) est indépendant et s'utilise séparément :

```python
from sanctions_screening import SanctionsScreener, load_watchlist_csv
watchlist = load_watchlist_csv("sample_data/watchlist_exemple.csv")
screener = SanctionsScreener(watchlist)
resultat = screener.screen("Nom du client")
```

## Point d'intégration avec le backend / l'interface

Le contrat de données backend est `../dataset/dataset_C_hybrid_aml.csv`.
Voir `INTEGRATION_BACKEND.md` pour le rôle de chaque niveau, les champs
obligatoires et l'ordre d'appel du service commun.

`train_model.py` exporte maintenant
`fusion_et_modele/data/modele_risque_mlp.joblib`. Cet artefact contient le
MLP, son normaliseur et l'ordre des variables et doit être déployé avec le
backend.

Le module `fusion_et_modele/risk_predictor.py` expose
`predire_risque(transaction) -> score` et met le modèle en cache au premier
appel. La transaction doit contenir les variables de `features.csv`; les
variables comportementales et réseau doivent être calculées depuis
l'historique client par le backend. Les variables manquantes valent `0`,
uniquement pour permettre un test technique.

```python
from fusion_et_modele.risk_predictor import predire_risque

score = predire_risque({"montant_log": 14.2, "ratio_seuil": 0.92})
```

Initialiser `SanctionsScreener` une seule fois au démarrage du service avec
la watchlist chargée, plutôt qu'à chaque requête.

## Statut

| Composant | État |
|---|---|
| Niveau 1 — Screening PPE | ✅ Fonctionnel, testé |
| Niveau 2 — Données AMLSim | ✅ Généré, validé |
| Niveau 3 — Générateur CIF | ✅ Généré, validé |
| Fusion + features | ✅ Fait |
| Modèle entraîné | ✅ Premiers résultats (AUC-PR 0,28-0,35) |
| Export modèle pour backend | ✅ Fait |
| Note de présentation | ⬜ À rédiger (deadline 23 août) |
