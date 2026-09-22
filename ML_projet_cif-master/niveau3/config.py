"""
config.py - Paramètres du générateur de transactions synthétiques
Hackathon CIF DigiCoop-WA+ - Thématique 01

Tout est centralisé ici pour pouvoir recalibrer rapidement pendant les 72h
(par ex. si la CIF fournit des vraies statistiques de volumétrie).
"""

from datetime import datetime

# -- Volumétrie --------------------------------------------------------
N_CLIENTS = 800
N_JOURS_HISTORIQUE = 180          # fenêtre temporelle simulée
DATE_FIN = datetime(2026, 8, 1)
TRANSACTIONS_PAR_CLIENT_MOYENNE = 12   # sur toute la période, comportement normal

# -- Contexte SFD / microfinance (pas une banque classique) ------------
# Montants en FCFA - distribution log-normale pour refléter beaucoup de
# petites transactions et quelques plus grosses.
MONTANT_MEDIAN_FCFA = 45_000
MONTANT_SIGMA = 1.1               # dispersion de la log-normale

TYPES_TRANSACTION = [
    "depot", "retrait", "transfert_sortant", "transfert_entrant",
    "remboursement_credit", "decaissement_credit",
]
# poids réalistes : dépôts/retraits dominent largement en microfinance
POIDS_TYPES_TRANSACTION = [0.30, 0.28, 0.12, 0.10, 0.12, 0.08]

PROFILS_CLIENT = ["rural", "urbain"]
POIDS_PROFIL = [0.6, 0.4]          # réseau CIF fortement rural

# -- Seuil réglementaire (déclaration CENTIF) ---------------------------
SEUIL_DECLARATION_FCFA = 10_000_000

# -- Corridors géographiques (cf. couloirs sensibles identifiés) --------
CORRIDORS = ["local", "regional_uemoa", "transfrontalier_sensible"]
POIDS_CORRIDORS = [0.80, 0.15, 0.05]
CORRIDORS_SENSIBLES_LABELS = [
    "Bamako-Abidjan", "Bamako-Dakar",  # couloirs à surveiller
]

# -- Contamination : proportion de clients avec comportement à risque --
TAUX_CONTAMINATION = 0.02          # ~2% des clients, réaliste pour un jeu déséquilibré

# répartition des typologies injectées parmi les clients à risque
POIDS_TYPOLOGIES = {
    "structuring": 0.35,
    "layering": 0.30,
    "reactivation_dormant": 0.20,
    "corridor_sensible": 0.15,
}

# -- Noms fictifs (pour lisibilité et lien éventuel avec le screening PPE) --
PRENOMS = [
    "Amadou", "Fatoumata", "Ibrahim", "Mariam", "Ousmane", "Aissata",
    "Moussa", "Kadiatou", "Seydou", "Aminata", "Boubacar", "Awa",
    "Souleymane", "Djeneba", "Modibo", "Hawa", "Adama", "Bintou",
]
NOMS = [
    "Traore", "Diallo", "Kone", "Sangare", "Toure", "Coulibaly",
    "Diarra", "Sidibe", "Konate", "Keita", "Sanogo", "Fofana",
    "Cisse", "Maiga", "Diakite", "Ouattara", "Bah", "Barry",
]

RANDOM_SEED = 42
