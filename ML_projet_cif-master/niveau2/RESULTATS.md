# Résultats du modèle de scoring — Thématique 01

Hackathon CIF DigiCoop-WA+ — Filtrage clients LBC/FT/FP

## Dataset

**65 963 transactions**, dont **194 labellisées à risque (0,29%)** — fusion
de deux sources traçables :
- Niveau 2 : topologie authentique du simulateur de recherche IBM AMLSim
  (typologies `fan_in`, `fan_out`, `cycle`)
- Niveau 3 : générateur maison calé sur le contexte CIF/SFD (typologies
  `structuring`, `layering`, `reactivation_dormant`, `corridor_sensible`)

## Méthodologie

- 17 features : montant (brut + log), proximité au seuil réglementaire
  10M FCFA, type de transaction, comportement du client (moyenne, écart-type,
  z-score), fenêtre glissante 7 jours (nombre et montant cumulé de
  transactions récentes)
- Validation croisée stratifiée à 5 plis (un simple split train/test serait
  instable avec seulement 194 exemples positifs au total)
- Pondération de classe (`class_weight="balanced"`) plutôt qu'un
  ré-échantillonnage artificiel
- Métrique principale : **AUC-PR** (aire sous la courbe précision-rappel),
  plus pertinente que l'AUC-ROC sur des classes aussi déséquilibrées

## Résultats

| Modèle | Précision | Rappel | F1 | AUC-PR |
|---|---|---|---|---|
| Régression logistique | 2,3% | **97,4%** | 0,045 | 0,350 |
| MLP | **98,5%** | 17,0% | 0,273 | 0,278 |

**Chiffre clé pour le pitch** : le taux de base (détection aléatoire) donne
un AUC-PR de 0,0029. Nos modèles atteignent 0,28 à 0,35 — soit **95 à 119
fois plus performants qu'un tri aléatoire**.

## Interprétation — le vrai compromis opérationnel

Les deux modèles illustrent exactement le dilemme d'un système de
conformité réel :

- **Régression logistique** : détecte quasiment tous les cas à risque
  (rappel 97%), mais génère énormément de fausses alertes (précision 2%).
  Adaptée si l'objectif est de ne rien laisser passer, au prix d'une
  charge de vérification élevée pour les agents.
- **MLP** : très peu de fausses alertes (précision 98%), mais ne détecte
  qu'un cas à risque sur six (rappel 17%). Adaptée si l'objectif prioritaire
  est de limiter l'alerte-fatigue.

**Ce compromis, réglable via le seuil de décision, est précisément
l'argument à mettre en avant dans le dossier** : proposer un curseur
précision/rappel ajustable par la caisse selon sa capacité de traitement,
plutôt qu'un système figé — peu d'équipes concurrentes penseront à
présenter le problème sous cet angle opérationnel.

## Facteurs de risque les plus influents (explicabilité)

Régression logistique (coefficients) :
1. `nb_transactions_client` (comptes avec peu d'historique = plus suspects)
2. `type_transfert_sortant`
3. `nb_destinataires_distincts_client`
4. `montant_moyen_client`
5. `montant_zscore` (écart au comportement habituel du client)

MLP (importance par permutation) :
1. `montant_cumule_7j` (rafale de transactions sur une semaine)
2. `ratio_seuil` (proximité du seuil réglementaire — capte le structuring)
3. `montant_log`

→ Les deux modèles convergent vers des facteurs interprétables et
défendables devant un agent de conformité, pas des variables abstraites.

## Limites à mentionner honnêtement

- Seulement 194 exemples positifs au total : les métriques varient sensiblement
  d'un pli de validation à l'autre (voir écarts-types dans les CSV détaillés).
  Avec un vrai volume de données CIF, les résultats se stabiliseraient.
- Données majoritairement synthétiques : les corrélations réelles entre
  variables sont probablement plus riches qu'ici.
- Prochaine étape naturelle : calibrer le seuil de décision sur des
  retours d'agents de conformité réels pendant le hackathon, si accès à
  un échantillon CIF obtenu.

## Fichiers

- `resultats_regression_logistique.csv` / `resultats_mlp.csv` : métriques détaillées par pli
- `build_features.py` / `train_model.py` : code source, rejouable
- `features.csv` : table de features complète
