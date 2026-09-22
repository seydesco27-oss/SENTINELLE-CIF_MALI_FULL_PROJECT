# Niveau 2 — Données publiques enrichies (IBM AMLSim)

Hackathon CIF DigiCoop-WA+ — Thématique 01

## Ce qu'on a obtenu

La **topologie de transactions et les labels de typologies de blanchiment
sont authentiques** : ils proviennent du moteur de simulation open source
d'IBM Research (`github.com/IBM/AMLSim`), utilisé dans plusieurs
publications académiques sur la détection de blanchiment par graphes
(ex. "Scalable Graph Learning for Anti-Money Laundering", arXiv:1812.00076).

- **12 043 comptes**, ~63 000 transactions générées
- **742 comptes labellisés** répartis sur **100 épisodes d'alerte réels**,
  couvrant 3 typologies formelles : `fan_in`, `fan_out`, `cycle`
- Après filtrage rigoureux (voir plus bas) : **129 transactions** identifiées
  avec certitude comme faisant partie d'une structure de blanchiment (0,23%)

## Obstacle technique rencontré, et comment on l'a contourné

Le projet IBM AMLSim n'est plus maintenu depuis 2021 et ne fonctionne pas
tel quel sur un environnement Python moderne. Trois blocages rencontrés,
tous corrigés :

1. **`fractions.gcd` introuvable** (déplacé vers `math.gcd` depuis Python 3.9)
   → patché dans la librairie networkx installée.
2. **`lib2to3` manquant** (retiré des installations Python minimales)
   → installé via `apt install python3-lib2to3`.
3. **`random.sample()` refuse les sets** depuis Python 3.11 (le code de 2019
   s'appuyait sur l'ancien comportement) → correctif global ajouté en tête
   de `transaction_graph_generator.py`.

La partie **Java** du projet (qui calcule normalement les montants et
horodatages réels) n'a pas pu être compilée ici : elle nécessite Maven
Central, inaccessible depuis cet environnement de développement. **Sur ta
machine personnelle (accès internet complet), `sh scripts/build_AMLSim.sh`
puis `sh scripts/run_AMLSim.sh conf.json` devraient fonctionner nativement**
et donneraient des montants/horodatages calculés par le moteur original
plutôt que par notre approximation FCFA — à tenter si tu veux la version la
plus fidèle possible avant le 2 octobre.

## Comment on a labellisé sans le moteur Java

`enrich_and_export.py` : une transaction n'est marquée comme faisant partie
d'une typologie de blanchiment que si **ses deux comptes (source ET
destination) appartiennent au même épisode d'alerte**. Un premier essai
moins strict (compte source OU destination flagué) faisait exploser le taux
de risque à 17% — totalement irréaliste pour de l'AML. Après correction,
on retombe sur un taux de 0,23%, cohérent avec le Niveau 3.

Les montants sont ensuite générés dans les plages min/max définies par
chaque épisode d'alerte réel, remis à l'échelle FCFA/SFD plutôt que
USD/banque classique.

## Fusion avec le Niveau 3

`dataset_entrainement_combine.csv` (à la racine des outputs) fusionne :
- Niveau 2 (AMLSim, 56 194 tx, typologies `fan_in`/`fan_out`/`cycle`)
- Niveau 3 (générateur maison, 9 769 tx, typologies `structuring`/
  `layering`/`reactivation_dormant`/`corridor_sensible`)

Total : **65 963 transactions, 194 labellisées à risque (0,29%)**, avec une
colonne `source` pour tracer la provenance de chaque ligne — utile pour
évaluer si le modèle généralise entre les deux origines de données.

## Fichiers

| Fichier | Contenu |
|---|---|
| `accounts_amlsim.csv` | Comptes générés par AMLSim (topologie réelle) |
| `transactions_topologie_amlsim.csv` | Structure brute (src/dst), sans montant |
| `alert_members_amlsim.csv` | Vérité terrain : comptes par épisode d'alerte |
| `transactions_amlsim_enrichi_10k.csv` | Version enrichie en FCFA, prête à l'emploi |
| `enrich_and_export.py` | Script d'enrichissement (rejouable, commenté) |
| `conf.json` | Configuration de simulation utilisée (échelle 10K) |

## Ce qu'on peut dire dans le dossier

Le fait d'avoir corrigé un projet de recherche IBM non maintenu pour le
faire tourner, plutôt que de se contenter d'un CSV Kaggle statique, est un
vrai argument de sérieux technique — peu d'équipes iront jusque-là.
