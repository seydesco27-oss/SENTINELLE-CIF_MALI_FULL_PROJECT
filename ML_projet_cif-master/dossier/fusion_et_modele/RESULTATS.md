# Resultats du modele de scoring

Hackathon CIF DigiCoop-WA+ - Thematique 01 : filtrage clients LBC/FT/FP

## Jeu de donnees de reference

La livraison integree utilise `data/dataset_entrainement_combine.csv` et
`data/features.csv` : **66 144 transactions**, dont **633 a risque (0,96 %)**.
Les lignes sont tracables par leur colonne `source` :

- Niveau 2 - IBM AMLSim : motifs `fan_in`, `fan_out` et `cycle` ;
- Niveau 3 - generateur CIF : `structuring`, `layering`,
  `reactivation_dormant` et `corridor_sensible`.

Les anciens fichiers de travail a 65 963 lignes ne sont pas les artefacts de
reference de la livraison et ne doivent pas servir a la demonstration finale.

## Methode

- 20 variables : montant, proximite du seuil de 10 M FCFA, type de
  transaction, comportement client, fenetre recente et degres de graphe ;
- validation croisee stratifiee a 5 plis ;
- regression logistique avec ponderation des classes ;
- MLP regularise avec SMOTE applique seulement aux plis d'entrainement ;
- AUC-PR comme metrique principale, adaptee au tres fort desequilibre des
  classes.

## Resultats a presenter

| Modele | AUC-PR globale | AUC-PR AMLSim | AUC-PR CIF synthetique |
|---|---:|---:|---:|
| Regression logistique | 0,129 | 0,092 | 0,370 |
| MLP | **0,316** | **0,298** | **0,959** |

Le resultat le plus defendable est l'AUC-PR **0,298 sur AMLSim** : la
reference aleatoire est d'environ 0,01 sur cette source, ce qui montre un
signal utile sans pretendre a une performance parfaite. Le score eleve sur le
Niveau 3 constitue une validation complementaire des scenarios CIF, qui sont
plus previsibles car ils sont construits a partir de regles explicites.

## Limites et usage responsable

Les donnees sont synthetiques ou simulees. Le modele doit servir a prioriser
les controles, jamais a prendre seul une decision de blocage. Avant une mise
en production, il faut tester les seuils sur des donnees CIF anonymisees,
mesurer les faux positifs avec les agents de conformite et charger les listes
officielles OFAC, ONU et UE pour le Niveau 1.

## Artefacts livres

- `train_model.py` : entrainement reproductible et export du modele ;
- `data/modele_risque_mlp.joblib` : modele, normaliseur et ordre des 20
  variables ;
- `backend_adapter.py` : adaptation du contrat backend CIF ;
- `risk_predictor.py` : fonction de scoring chargee une fois par processus ;
- `../../INTEGRATION_BACKEND.md` : contrat et exemple d'appel.
