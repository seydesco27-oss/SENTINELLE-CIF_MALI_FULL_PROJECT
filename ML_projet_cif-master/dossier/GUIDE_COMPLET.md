# GUIDE COMPLET — Préparer et entraîner le modèle, du début à la fin

Hackathon CIF DigiCoop-WA+ — Thématique 01 (Filtrage clients LBC/FT/FP)

Ce guide t'emmène de zéro (dossier vide) jusqu'au modèle entraîné et évalué.
Suis les étapes dans l'ordre, sans en sauter.

---

## 0. Prérequis

```bash
python --version   # 3.10, 3.11 ou 3.12 recommandé (3.13 fonctionne aussi, voir notes)
pip install pandas numpy scikit-learn imbalanced-learn joblib
```

Dézippe l'archive du projet. Tu dois obtenir cette arborescence :

```
projet_cif/
├── README.md
├── niveau1_screening/
├── niveau2_amlsim/
├── niveau3_generateur_cif/
└── fusion_et_modele/
```

**Tu n'as besoin de RIEN télécharger d'autre.** Toutes les données sources
sont déjà générées et incluses (`data/` dans chaque dossier de niveau).

---

## Vue d'ensemble du pipeline

```
niveau1_screening/        (INDÉPENDANT — pas d'entraînement, pas de lien
                            avec le reste, voir section 5)

niveau2_amlsim/data/                    niveau3_generateur_cif/data/
transactions_amlsim_enrichi.csv         transactions_niveau3.csv
        |                                        |
        +------------------+---------------------+
                           |
                  fusion_et_modele/merge_datasets.py
                           |
                           v
          data/dataset_entrainement_combine.csv
                           |
                  fusion_et_modele/build_features.py
                           |
                           v
                   data/features.csv
                           |
                  fusion_et_modele/train_model.py
                           |
                           v
        Métriques + data/resultats_*.csv
```

---

## 1. (Optionnel) Régénérer les données sources

Les fichiers `data/` sont déjà fournis et prêts à l'emploi — **tu peux
sauter directement à l'étape 2** si tu veux juste reproduire le résultat
obtenu.

Si tu veux régénérer toi-même (plus de volume, paramètres différents) :

### 1.a Niveau 3 (rapide, ~10 secondes)

```bash
cd niveau3_generateur_cif
python generator.py
```

Écrit `clients.csv` et `transactions.csv` dans le dossier courant. Copie-les
ensuite dans `data/` :
```bash
cp clients.csv data/clients.csv
cp transactions.csv data/transactions_niveau3.csv
```

Pour ajuster le volume ou les typologies injectées, modifie `config.py`
(nombre de clients, taux de contamination, poids par typologie...).

### 1.b Niveau 2 (plus long, nécessite AMLSim)

C'est plus lourd — voir `niveau2_amlsim/` pour le détail (clonage du dépôt
IBM AMLSim, script `transaction_graph_generator_patched.py`, puis
`enrich_and_export.py`). **Recommandé de garder les données déjà fournies**
sauf si tu as un besoin spécifique de plus de volume.

---

## 2. Fusionner les Niveaux 2 et 3

```bash
cd fusion_et_modele
python merge_datasets.py
```

**Ce que ça fait** : empile les transactions du Niveau 2 (AMLSim, schémas
réels fan-in/fan-out/cycle) et du Niveau 3 (générateur maison CIF,
structuring/layering/corridor/réactivation) en un seul fichier, avec une
colonne `source` pour garder la trace de la provenance de chaque ligne.

**Résultat attendu** :
```
Niveau 2 (AMLSim)      : 56622 transactions
Niveau 3 (maison)      : 9522 transactions
Total combine          : 66144 transactions
Total a risque         : 633 (0.96%)
Fichier ecrit : data/dataset_entrainement_combine.csv
```

---

## 3. Construire les features

```bash
python build_features.py
```

**Ce que ça fait** — transforme chaque transaction brute en variables
exploitables par un modèle :

| Catégorie | Features |
|---|---|
| Montant | `montant_log`, `ratio_seuil`, `proche_seuil` |
| Type | `type_depot`, `type_retrait`, `type_transfert_sortant`, etc. (one-hot) |
| Temporel | `jour_semaine` |
| Comportement client | `montant_moyen_client`, `montant_zscore`, `nb_transactions_client` |
| Fenêtre glissante 7j | `nb_transactions_7j`, `montant_cumule_7j` |
| **Réseau** | `in_degree`, `out_degree` (comptes distincts qui envoient/reçoivent) |

Les features réseau (`in_degree`/`out_degree`) sont essentielles : ce sont
elles qui donnent au modèle une chance de repérer les schémas structurels
du Niveau 2 (fan-in = beaucoup d'in-degree, fan-out = beaucoup d'out-degree)
plutôt que de se fier uniquement aux montants.

**Résultat attendu** : `data/features.csv`, 66 144 lignes, 29 colonnes.

---

## 4. Entraîner et évaluer le modèle

```bash
python train_model.py
```

**Ce que ça fait, dans l'ordre** :

1. Charge les features et vérifie le déséquilibre de classes (0,96% de
   transactions à risque)
2. **Vérifie une fuite possible entre les 2 sources de données** (compare
   les distributions de montants et les taux de risque par source — un
   garde-fou pour éviter que le modèle apprenne juste à distinguer "quel
   générateur a produit cette ligne")
3. Entraîne et évalue par **validation croisée stratifiée à 5 plis** :
   - **Régression logistique** (`class_weight="balanced"`) : modèle
     interprétable, sert de référence
   - **MLP** avec **SMOTE** appliqué uniquement sur les données
     d'entraînement de chaque pli (le MLP de scikit-learn ne supporte pas
     `class_weight`, SMOTE compense ce manque sans fuite vers le test)
4. Réentraîne sur l'ensemble des données et **évalue séparément par
   source** (Niveau 2 vs Niveau 3) pour détecter une éventuelle
   sur-spécialisation

**Résultat attendu (résumé)** :

| Modèle | Précision | Rappel | AUC-PR global |
|---|---|---|---|
| Régression logistique | 6,4% | 98,7% | 0,129 |
| **MLP** | 17,1% | 93,4% | **0,316** |

**Par source** (le détail qui compte pour la crédibilité du dossier) :

| Source | AUC-PR (MLP) | AUC-PR (RegLog) |
|---|---|---|
| Niveau 2 — AMLSim (réel) | 0,298 | 0,092 |
| Niveau 3 — maison CIF | 0,959 | 0,370 |

**Fichiers écrits** : `data/resultats_mlp.csv`, `data/resultats_regression_logistique.csv`
(métriques détaillées par pli), et `data/modele_risque_mlp.joblib` (modèle,
normaliseur et liste des variables pour le backend).

---

## 5. Niveau 1 — Screening PPE (indépendant, à part)

Ce module ne participe PAS à l'entraînement du modèle ci-dessus. Il
s'utilise séparément :

```bash
cd ../niveau1_screening
python demo.py
```

Ou dans ton propre code :
```python
from sanctions_screening import SanctionsScreener, load_watchlist_csv

watchlist = load_watchlist_csv("sample_data/watchlist_exemple.csv")
screener = SanctionsScreener(watchlist)
resultat = screener.screen("Nom du client à vérifier")
print(resultat.risk_level)  # "alerte" | "a_verifier" | "aucun"
```

**Avant le hackathon**, remplacer `sample_data/watchlist_exemple.csv`
(données fictives) par les vraies listes OFAC/ONU/UE — voir le README de
ce dossier pour la marche à suivre exacte.

---

## 6. Comment lire les résultats de façon honnête (pour le dossier)

- **Le chiffre à mettre en avant** : AUC-PR de 0,298 sur les données
  AMLSim (Niveau 2) — c'est basé sur de vrais schémas de recherche, donc
  le plus défendable devant un jury. Comparé à une détection aléatoire
  (~0,010 sur ce sous-ensemble), c'est **~30x plus performant**.
- **Le score de 0,959 sur le Niveau 3** est plus élevé car ce sont nos
  propres règles — à présenter comme validation complémentaire sur le
  contexte spécifique CIF, pas comme le résultat principal.
- **Ne jamais présenter un score de 1,000 (parfait)** comme une réussite —
  c'est un signal que les données sont artificiellement trop faciles, pas
  que le modèle est excellent. Si tu regénères les données toi-même et que
  tu obtiens un score parfait, élargis les fourchettes dans `config.py`
  (variable `STRUCTURING_MIN_RATIO`/`MAX_RATIO` et
  `TAUX_TRANSACTIONS_LEGITIMES_ELEVEES`) avant de t'en servir.

---

## 7. Intégration backend

`train_model.py` exporte automatiquement
`data/modele_risque_mlp.joblib`. Le module `risk_predictor.py` charge cet
artefact une seule fois par processus et expose une fonction directe :

```python
from risk_predictor import predire_risque

score = predire_risque({"montant_log": 14.2, "ratio_seuil": 0.92})
```

En production, transmettre toutes les variables de `features.csv`, surtout
les variables comportementales et réseau calculées à partir de l'historique
client. Les variables absentes sont mises à zéro uniquement pour des essais
techniques.

---

## Dépannage rapide

| Erreur | Cause probable | Solution |
|---|---|---|
| `FileNotFoundError` sur un CSV | Script lancé depuis le mauvais dossier | Toujours lancer depuis `fusion_et_modele/` |
| Erreur de parsing de date | Version de pandas différente | Déjà géré via `format="mixed"` dans `build_features.py` |
| `ModuleNotFoundError: imblearn` | Package manquant | `pip install imbalanced-learn` |
| Résultats légèrement différents des tiens | Aléatoire non figé | Vérifie que `RANDOM_SEED = 42` est bien présent en haut de `train_model.py` |
