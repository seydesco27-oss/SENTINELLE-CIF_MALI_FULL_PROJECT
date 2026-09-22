# Diagnostic du Surapprentissage - Niveau 3

## Constat

Bien que les scores de validation croisée soient parfaits (99.83% accuracy, AUC-ROC=1.000), le modèle **n'a pas appris de vrai pattern AML** — il a appris les **règles déterministes de génération synthétique**.

## Preuve

### 1. **Feature Importances révélatrices**

Les 3 features basées sur le montant représentent **80.91%** de l'importance totale :
- `log_amount` : 36.20%
- `amount_ratio_to_client_median` : 29.94%
- `amount_zscore` : 14.77%

Les autres features (corridor, type_transaction, client_age, is_weekend) = **19.09%** seulement.

### 2. **Analyse du générateur**

**Structuring** (9.0M–9.8M FCFA) :
```python
montant = cfg.SEUIL_DECLARATION_FCFA * rng.uniform(0.90, 0.99)  # 10M * 0.90-0.99
```
→ Toujours 90–99% du seuil, jamais dans la distribution normale.

**Layering** (2M–8M FCFA) :
```python
montant_initial = float(rng.uniform(2_000_000, 8_000_000))
```
→ Montants 50–100× plus élevés que la médiane normale (45k FCFA).

**Reactivation dormant** (1.5M–6M FCFA) :
```python
montant = float(rng.uniform(1_500_000, 6_000_000))
```
→ Même décalage massif.

**Corridor sensible** :
Codé en dur sur `transfrontalier_sensible`, combiné à des montants > 1,1M.

### 3. **Séparabilité artificiellement nette**

| Métrique | Observation |
|---|---|
| Montants normaux | Médiane 45k, max ~800k |
| Montants "structuring" | 9.0M–9.8M (100–200× plus élevés) |
| Montants "layering" | 2M–8M (50–100× plus élevés) |
| Faux positifs | 0 |
| Faux négatifs | 0 |

**Un arbre de décision n'a besoin que de 2–3 seuils pour discriminer parfaitement.**

### 4. **Pas de fuite client** ✓

Split validé avec `StratifiedGroupKFold` :
- Train : 640–641 clients différents par fold
- Validation : 159–161 clients **complètement différents**
- Holdout test : 729 clients distincts

La fuite n'est pas au niveau client, mais au niveau **génération déterministe**.

---

## Étude d'ablation — **Preuve irréfutable**

### Résultats comparatifs

| Modèle | Features | CV AUC-ROC | CV AUC-PR | Test AUC-ROC | Test AUC-PR | Accuracy |
|---|---|---|---|---|---|---|
| **COMPLET** | Montant + Comportement | 1.000 ± 0.001 | 0.973 ± 0.035 | 1.000 | 1.000 | 100.00% |
| **SANS MONTANT** | Comportement uniquement | 0.696 ± 0.046 | 0.085 ± 0.065 | 0.950 | 0.339 | 99.13% |
| **MONTANT SEUL** | log_amount + ratio + zscore | 0.991 ± 0.017 | 0.939 ± 0.069 | 1.000 | 0.980 | 99.80% |

### Interprétation

**La dégradation en supprimant le montant : 0.050 (AUC-ROC), mais surtout 0.661 (AUC-PR)** ⚠️

- **SANS MONTANT** → AUC-PR s'effondre de **1.000 à 0.339** (71% de perte)
- **MONTANT SEUL** → AUC-ROC reste **1.000**, performance quasi-intacte
- **Features comportementales** → Presque inutiles face aux seuils déterministes du montant

**Conclusion : Le modèle apprend PRINCIPALEMENT les seuils de montant, pas des patterns comportementaux.**

---

## Recommandations pour la prochaine itération

### 1. **Resserrer la génération synthétique**

Rendre les montants "risqués" moins séparables des montants normaux :

```python
# Avant : montants risqués 50–100× la médiane
montant = cfg.SEUIL_DECLARATION_FCFA * rng.uniform(0.90, 0.99)  # ❌ 9M–9.8M

# Après : montants dans la queue haute des normaux (mais pas 10× la médiane)
montant = rng.lognormal(mean=np.log(cfg.MONTANT_MEDIAN_FCFA * 20), sigma=0.5)  # ✓ Mieux réaliste
```

### 2. **Ajouter des faux positifs plausibles**

Générer des transactions **légitimes** dans des corridors sensibles ou avec montants élevés :
- Dépôt de salaire dans un corridor international
- Rapatriement de fonds légal
- Remboursement de crédit en gros montant

Forcer le modèle à différencier sur des **features comportementales**, pas juste le montant.

### 3. **Enrichir les features comportementales**

Pour que le modèle apprenne des patterns réalistes :
- Fréquence de transactions par jour (pic subit = anomalie)
- Écart-type des montants du client (cohérence)
- Nombre de destinations uniques en 48h (layering signal)
- Compte actif depuis combien de jours avant ce dépôt (reactivation)
- Heures de transaction (hors heures ouvrables = anomalie)

### 4. **Validation comportementale sans montant**

Tester la performance du modèle **en supprimant toutes les features basées sur le montant** :
```python
X_behavioral = X[["client_age_days", "is_weekend", "transaction_type", "corridor", "client_profile"]]
```

Cible : si le modèle garde >60% AUC-ROC sur ces seules features, ça signifie qu'il a appris quelque chose de robuste.

---

## Conclusion pour le hackathon

**Ce n'est pas un bug** — c'est une **conséquence réaliste de la génération synthétique**.

Pour la présentation, tu peux dire :
> *"Sur ce jeu de données synthétique, les cas injektés sont déterministes par montant. Nous avons validé que le pipeline (validation k-fold, SMOTE, split par client) **est robuste** ; la prochaine itération enrichira la génération avec des cas limites plus réalistes pour que le modèle apprenne des patterns comportementaux plutôt que des seuils."*

Cela montre une compréhension fine du problème, plutôt qu'un aveuglement face à des scores trop beaux.
