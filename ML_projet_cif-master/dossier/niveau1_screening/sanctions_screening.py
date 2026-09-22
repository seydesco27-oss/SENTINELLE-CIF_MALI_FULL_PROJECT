"""
sanctions_screening.py
Module de filtrage PPE / Listes de sanctions - Hackathon CIF DigiCoop-WA+
Thématique 01 - Filtrage des clients LBC/FT/FP

Objectif : donner à une caisse (SFD/coopérative financière) un score de
correspondance entre un client et les listes de sanctions/PPE internationales,
même en cas de faute de frappe, de translittération différente ou d'ordre
des noms inversé (fréquent avec des noms ouest-africains transcrits depuis
plusieurs langues).

Architecture volontairement simple :
    1. WatchlistEntry / DataFrame : schéma commun, indépendant de la source
    2. load_watchlist_csv()       : charge n'importe quelle liste déjà
                                     normalisée en CSV (nom, alias, source...)
    3. SanctionsScreener          : moteur de matching flou (rapidfuzz)

Sources officielles à brancher (téléchargement manuel requis, ces domaines
ne sont pas accessibles depuis cet environnement de développement) :
    - OFAC SDN List      : https://ofac.treasury.gov/sanctions-list-service
                            (formats XML et CSV disponibles)
    - ONU Liste consolidée : https://main.un.org/securitycouncil/en/content/un-sc-consolidated-list
                            (formats XML, HTML, PDF)
    - UE Liste de sanctions : https://webgate.ec.europa.eu/fsd/fsf
                            (export XML "Consolidated list")

Une fois un fichier téléchargé, voir adapt_raw_source() plus bas : c'est le
seul endroit à ajuster selon les noms de colonnes exacts du fichier obtenu
(ils varient légèrement d'une source à l'autre).
"""

from __future__ import annotations

import unicodedata
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional

import pandas as pd
from rapidfuzz import fuzz, process


# --------------------------------------------------------------------------
# 1. Schéma commun
# --------------------------------------------------------------------------

WATCHLIST_COLUMNS = [
    "source",       # "OFAC" | "ONU" | "UE" | ...
    "entry_id",     # identifiant dans la source d'origine
    "full_name",    # nom principal normalisé
    "aliases",      # liste d'alias, séparés par " | "
    "entry_type",   # "individu" | "entite"
    "program",      # programme de sanction / motif (optionnel)
]


@dataclass
class ScreeningMatch:
    matched_name: str
    score: float
    source: str
    entry_type: str
    program: Optional[str] = None


@dataclass
class ScreeningResult:
    query: str
    risk_level: str  # "aucun" | "a_verifier" | "alerte"
    matches: list[ScreeningMatch] = field(default_factory=list)


# --------------------------------------------------------------------------
# 2. Normalisation des noms
# --------------------------------------------------------------------------

def normalize_name(name: str) -> str:
    """Met à plat un nom pour le matching : minuscules, sans accents,
    espaces multiples réduits. Ne PAS utiliser pour l'affichage."""
    if not isinstance(name, str):
        return ""
    nfkd = unicodedata.normalize("NFKD", name)
    without_accents = "".join(c for c in nfkd if not unicodedata.combining(c))
    return " ".join(without_accents.lower().split())


# --------------------------------------------------------------------------
# 3. Chargement d'une liste déjà normalisée (CSV commun)
# --------------------------------------------------------------------------

def load_watchlist_csv(path: str | Path) -> pd.DataFrame:
    """Charge un CSV respectant WATCHLIST_COLUMNS. Plusieurs fichiers sources
    peuvent être concaténés avec pd.concat() avant d'être passés au screener."""
    df = pd.read_csv(path, dtype=str).fillna("")
    missing = set(WATCHLIST_COLUMNS) - set(df.columns)
    if missing:
        raise ValueError(f"Colonnes manquantes dans {path} : {missing}")
    return df


def adapt_raw_source(
    raw_df: pd.DataFrame,
    source_name: str,
    name_column: str,
    id_column: Optional[str] = None,
    alias_column: Optional[str] = None,
    type_column: Optional[str] = None,
    program_column: Optional[str] = None,
) -> pd.DataFrame:
    """
    Adaptateur générique : transforme n'importe quel export brut (CSV issu
    d'une conversion XML->CSV, par ex.) vers le schéma commun.

    C'est LE point à ajuster une fois les vrais fichiers OFAC/ONU/UE en main :
    il suffit de renseigner le nom exact des colonnes du fichier téléchargé.

    Exemple d'appel une fois le fichier OFAC réel disponible :
        raw = pd.read_csv("SDN.CSV")
        ofac_df = adapt_raw_source(
            raw, source_name="OFAC",
            name_column="SDN_Name", id_column="ent_num",
            type_column="SDN_Type", program_column="Program",
        )
    """
    out = pd.DataFrame()
    out["source"] = [source_name] * len(raw_df)
    out["entry_id"] = raw_df[id_column].astype(str) if id_column else range(len(raw_df))
    out["full_name"] = raw_df[name_column].astype(str)
    out["aliases"] = raw_df[alias_column].astype(str) if alias_column else ""
    out["entry_type"] = raw_df[type_column].astype(str) if type_column else "inconnu"
    out["program"] = raw_df[program_column].astype(str) if program_column else ""
    return out[WATCHLIST_COLUMNS]


# --------------------------------------------------------------------------
# 4. Moteur de matching flou
# --------------------------------------------------------------------------

class SanctionsScreener:
    """
    Moteur de filtrage flou. Combine le nom principal ET les alias de chaque
    entrée pour maximiser le rappel (un client peut correspondre à un alias
    plutôt qu'au nom principal).

    Seuils par défaut calibrés pour privilégier le rappel (mieux vaut un
    faux positif à vérifier par un agent qu'un vrai positif manqué) :
        >= alert_threshold      -> "alerte"      (blocage / vérif obligatoire)
        >= review_threshold     -> "a_verifier"  (vérif recommandée)
        < review_threshold      -> "aucun"
    """

    def __init__(
        self,
        watchlist: pd.DataFrame,
        alert_threshold: float = 92.0,
        review_threshold: float = 80.0,
    ):
        self.watchlist = watchlist.reset_index(drop=True)
        self.alert_threshold = alert_threshold
        self.review_threshold = review_threshold

        # on construit un index plat : chaque (nom principal ou alias) pointe
        # vers la ligne d'origine, pour ne rien perdre lors du matching
        rows = []
        for idx, row in self.watchlist.iterrows():
            names_to_index = [row["full_name"]] + [
                a.strip() for a in str(row["aliases"]).split("|") if a.strip()
            ]
            for n in names_to_index:
                rows.append((normalize_name(n), idx))
        self._flat_names = [r[0] for r in rows]
        self._flat_index = [r[1] for r in rows]

    def screen(self, client_name: str, top_n: int = 3) -> ScreeningResult:
        query_norm = normalize_name(client_name)

        # token_sort_ratio : robuste à l'ordre des noms/prénoms inversé,
        # fréquent selon les conventions de transcription.
        raw_matches = process.extract(
            query_norm,
            self._flat_names,
            scorer=fuzz.token_sort_ratio,
            limit=top_n * 3,  # marge avant dédoublonnage par entrée d'origine
        )

        seen_entries = set()
        results: list[ScreeningMatch] = []
        for matched_name, score, flat_pos in raw_matches:
            entry_idx = self._flat_index[flat_pos]
            if entry_idx in seen_entries:
                continue
            seen_entries.add(entry_idx)
            row = self.watchlist.loc[entry_idx]
            results.append(
                ScreeningMatch(
                    matched_name=row["full_name"],
                    score=round(score, 1),
                    source=row["source"],
                    entry_type=row["entry_type"],
                    program=row["program"] or None,
                )
            )
            if len(results) >= top_n:
                break

        top_score = results[0].score if results else 0.0
        if top_score >= self.alert_threshold:
            risk_level = "alerte"
        elif top_score >= self.review_threshold:
            risk_level = "a_verifier"
        else:
            risk_level = "aucun"

        return ScreeningResult(query=client_name, risk_level=risk_level, matches=results)

    def screen_batch(self, client_names: list[str]) -> pd.DataFrame:
        """Screening en masse, pratique pour tester sur tout un portefeuille
        clients ou une base de démonstration."""
        rows = []
        for name in client_names:
            res = self.screen(name)
            best = res.matches[0] if res.matches else None
            rows.append(
                {
                    "client": name,
                    "risk_level": res.risk_level,
                    "best_match": best.matched_name if best else "",
                    "score": best.score if best else 0.0,
                    "source": best.source if best else "",
                }
            )
        return pd.DataFrame(rows)
