# Module de filtrage PPE / Sanctions — Niveau 1

Hackathon CIF DigiCoop-WA+ — Thématique 01 (Filtrage clients LBC/FT/FP)

## Ce que fait ce module

Compare le nom d'un client (et ses alias) contre une liste de sanctions/PPE
grâce à un **matching flou** (rapidfuzz), robuste aux fautes de frappe, aux
différences d'accentuation et à l'ordre nom/prénom inversé — un vrai problème
avec les noms ouest-africains transcrits selon plusieurs conventions.

Testé et validé sur 10 scénarios (voir `demo.py`) : correspondance exacte,
variantes orthographiques, alias d'entreprise, et vrais négatifs (aucune
fausse alerte sur des noms sans lien).

## Lancer la démo

```bash
pip install rapidfuzz pandas
python demo.py
```

## Brancher les vraies listes (à faire avant le hackathon ou sur place)

Les données actuelles dans `sample_data/` sont **entièrement fictives**,
créées pour tester le moteur. Avant le 2-4 octobre, remplacer par les
vraies listes :

| Source | Où télécharger | Format |
|---|---|---|
| OFAC SDN List | https://ofac.treasury.gov/sanctions-list-service | XML ou CSV |
| ONU Liste consolidée | https://main.un.org/securitycouncil/en/content/un-sc-consolidated-list | XML |
| UE Liste de sanctions | https://webgate.ec.europa.eu/fsd/fsf | XML |

### Étapes

1. Télécharger le fichier depuis la source (réseau non filtré, donc à faire
   sur un poste normal — ces domaines ne sont pas accessibles depuis
   l'environnement de développement utilisé ici).
2. Ouvrir le fichier pour identifier les noms exacts de colonnes (ils varient
   selon la source et le format exporté).
3. Utiliser `adapt_raw_source()` dans `sanctions_screening.py` pour mapper
   ces colonnes vers le schéma commun (`full_name`, `aliases`, etc.) —
   c'est le seul endroit à modifier.
4. Concaténer les 3 sources avec `pd.concat()` avant de créer le
   `SanctionsScreener`.

## Calibrage des seuils

Deux seuils dans `SanctionsScreener` :
- `alert_threshold` (défaut 92) : blocage / vérification obligatoire
- `review_threshold` (défaut 80) : vérification recommandée

À ajuster une fois testé sur les vraies listes — objectif : minimiser les
faux positifs (alerte-fatigue des agents de conformité) sans manquer de
vrais positifs. C'est un vrai argument à mettre en avant dans le pitch.

## Prochaines étapes (à intégrer avec le Niveau 2 - modèle de risque)

Ce module de screening PPE est complémentaire au modèle de scoring de
risque transactionnel (Niveau 2/3) : un client peut avoir un score PPE nul
mais un comportement transactionnel suspect, et inversement. Les deux
scores doivent apparaître ensemble dans l'interface agent.
