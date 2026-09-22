# Générateur de transactions synthétiques — Niveau 3

Hackathon CIF DigiCoop-WA+ — Thématique 01 (Filtrage clients LBC/FT/FP)

## Ce que ça fait

Génère une population de clients + un historique de transactions calé sur
le contexte réel d'une coopérative financière ouest-africaine (montants en
FCFA, forte proportion rurale, seuil réglementaire à 10M FCFA), avec 4
typologies de risque injectées et labellisées :

| Typologie | Description |
|---|---|
| `structuring` | Montants répétés juste sous le seuil de déclaration CENTIF (10M FCFA) |
| `layering` | Dépôt important suivi d'un éclatement rapide vers plusieurs comptes |
| `reactivation_dormant` | Compte inactif longtemps puis transaction brutale |
| `corridor_sensible` | Transactions répétées sur un couloir géographique à risque |

## Lancer

```bash
pip install pandas numpy
python generator.py    # génère clients.csv et transactions.csv
python validate.py     # vérifie la cohérence des règles métier injectées
```

## Résultats de référence (seed fixée à 42, reproductible)

- 800 clients, ~9 800 transactions sur 180 jours
- ~2% des clients porteurs d'un comportement à risque
- ~0.6-0.7% des transactions labellisées à risque — déséquilibre de classes
  volontairement réaliste (comme en fraude/AML réel)

## Utilisation prévue

1. Entraîner un modèle de classification (MLP, gradient boosting...) sur
   `transactions.csv`, cible = `is_risky` (ou `type_anomalie` pour une
   classification multi-classe plus fine)
2. Gérer le déséquilibre (pondération de classe ou SMOTE)
3. Combiner avec le module Niveau 1 (screening PPE) : un client peut
   cumuler un score PPE et un score comportemental
4. Prioriser précision/rappel et AUC-PR (pas AUC-ROC, trompeur ici) —
   objectif affiché dans le pitch : minimiser les faux positifs

## Ajuster pour le hackathon

Tous les paramètres (volumétrie, seuils, poids des typologies, corridors)
sont centralisés dans `config.py` — à recalibrer rapidement si la CIF
fournit de vraies statistiques de volumétrie le jour J.

## Limites à mentionner honnêtement dans le pitch

- Données synthétiques : les corrélations entre variables sont plus
  simples que dans la réalité (le modèle réel devra être re-calibré sur
  données CIF si accès obtenu)
- Les typologies injectées sont des règles connues (structuring, layering...)
  — un vrai système devra aussi anticiper des schémas non répertoriés
  (d'où l'intérêt de compléter par une approche d'anomaly detection non
  supervisée en complément du modèle supervisé)
