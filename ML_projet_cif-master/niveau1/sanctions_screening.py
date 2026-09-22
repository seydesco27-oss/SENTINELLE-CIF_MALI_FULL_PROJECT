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

import re
import unicodedata
import xml.etree.ElementTree as ET
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
    compact = re.sub(r"[^a-z0-9]+", " ", without_accents.lower())
    return " ".join(compact.split())


def score_name_match(query: str, candidate: str, score_cutoff: float = 0.0) -> float:
    """Évalue la similarité entre deux noms en combinant plusieurs stratégies
    tout en pénalisant fortement les cas où le patronyme est différent."""
    query_norm = normalize_name(query)
    candidate_norm = normalize_name(candidate)

    if not query_norm or not candidate_norm:
        return 0.0
    if query_norm == candidate_norm:
        return 100.0

    base_score = max(
        fuzz.token_sort_ratio(query_norm, candidate_norm),
        fuzz.token_set_ratio(query_norm, candidate_norm),
        fuzz.partial_ratio(query_norm, candidate_norm),
    )

    query_tokens = query_norm.split()
    candidate_tokens = candidate_norm.split()
    if len(query_tokens) >= 2 and len(candidate_tokens) >= 2:
        query_last_name = query_tokens[-1]
        candidate_last_name = candidate_tokens[-1]
        last_name_score = fuzz.ratio(query_last_name, candidate_last_name)

        if last_name_score < 60:
            base_score = min(base_score, 55.0)
        elif last_name_score < 75:
            base_score = min(base_score, 70.0)

    return round(base_score, 1)


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


def _read_value(row: pd.Series, *columns: str) -> str:
    for column in columns:
        value = row.get(column, "")
        if isinstance(value, str):
            value = value.strip()
        else:
            value = str(value).strip() if not pd.isna(value) else ""
        if value:
            return value
    return ""


def _join_name_parts(*parts: str) -> str:
    return " ".join(part.strip() for part in parts if part and str(part).strip())


def adapt_eu_source(raw_df: pd.DataFrame) -> pd.DataFrame:
    """Mappe un export UE vers le schéma commun du screener."""
    rows = []
    for _, row in raw_df.iterrows():
        whole_name = _read_value(row, "NameAlias_WholeName")
        first_name = _read_value(row, "NameAlias_FirstName")
        middle_name = _read_value(row, "NameAlias_MiddleName")
        last_name = _read_value(row, "NameAlias_LastName")

        full_name = whole_name or _join_name_parts(first_name, middle_name, last_name)
        if not full_name:
            continue

        aliases = []
        for candidate in [whole_name, _join_name_parts(first_name, middle_name, last_name)]:
            if candidate and candidate != full_name:
                aliases.append(candidate)

        rows.append(
            {
                "source": "EU",
                "entry_id": _read_value(row, "Entity_EU_ReferenceNumber", "Entity_LogicalId"),
                "full_name": full_name,
                "aliases": " | ".join(aliases),
                "entry_type": "person" if _read_value(row, "Entity_SubjectType") == "P" else "entity",
                "program": _read_value(row, "Entity_Regulation_Programme", "Entity_Regulation_NumberTitle"),
            }
        )

    return pd.DataFrame(rows, columns=WATCHLIST_COLUMNS)


def adapt_ofac_source(raw_df: pd.DataFrame) -> pd.DataFrame:
    """Mappe un export OFAC au format CSV brut vers le schéma commun."""
    rows = []
    for _, row in raw_df.iterrows():
        values = [str(value).strip() for value in row.tolist() if str(value).strip()]
        if not values:
            continue
        name = values[1] if len(values) > 1 else values[0]
        if name in {"-0-", ""}:
            continue
        alias_value = values[-1] if len(values) > 1 and values[-1] not in {"-0-", ""} else ""
        rows.append(
            {
                "source": "OFAC",
                "entry_id": values[0] if values else "",
                "full_name": name,
                "aliases": alias_value,
                "entry_type": "entity",
                "program": "",
            }
        )
    return pd.DataFrame(rows, columns=WATCHLIST_COLUMNS)


def adapt_onu_source(raw_df: pd.DataFrame) -> pd.DataFrame:
    """Mappe un export ONU XML vers le schéma commun."""
    rows = []
    for _, row in raw_df.iterrows():
        first_name = _read_value(row, "FIRST_NAME")
        second_name = _read_value(row, "SECOND_NAME")
        third_name = _read_value(row, "THIRD_NAME")
        fourth_name = _read_value(row, "FOURTH_NAME")
        full_name = _join_name_parts(first_name, second_name, third_name, fourth_name)
        if not full_name:
            continue

        aliases = []
        for alias in row.get("aliases", []):
            if alias:
                aliases.append(alias)
        rows.append(
            {
                "source": "ONU",
                "entry_id": row.get("entry_id", ""),
                "full_name": full_name,
                "aliases": " | ".join(aliases),
                "entry_type": "person",
                "program": row.get("program", ""),
            }
        )
    return pd.DataFrame(rows, columns=WATCHLIST_COLUMNS)


def load_official_watchlist(base_dir: str | Path | None = None) -> pd.DataFrame:
    """Charge les sources officielles présentes dans le dossier courant et les
    convertit vers le schéma commun du screener."""
    base_path = Path(base_dir or Path(__file__).resolve().parent)
    frames: list[pd.DataFrame] = []

    eu_path = base_path / "20260805-FULL-1_1.csv"
    if eu_path.exists():
        eu_df = pd.read_csv(eu_path, sep=";", dtype=str, encoding="utf-8-sig").fillna("")
        eu_watchlist = adapt_eu_source(eu_df)
        if not eu_watchlist.empty:
            frames.append(eu_watchlist)

    sdn_path = base_path / "sdn.csv"
    if sdn_path.exists():
        sdn_df = pd.read_csv(sdn_path, header=None, dtype=str, encoding="latin-1").fillna("")
        ofac_watchlist = adapt_ofac_source(sdn_df)
        if not ofac_watchlist.empty:
            frames.append(ofac_watchlist)

    xml_path = base_path / "consolidatedLegacyByNAME.xml"
    if xml_path.exists():
        tree = ET.parse(xml_path)
        root = tree.getroot()
        un_rows = []
        for individual in root.findall(".//INDIVIDUAL"):
            first_name = _read_value_from_xml(individual, "FIRST_NAME")
            second_name = _read_value_from_xml(individual, "SECOND_NAME")
            third_name = _read_value_from_xml(individual, "THIRD_NAME")
            fourth_name = _read_value_from_xml(individual, "FOURTH_NAME")
            full_name = _join_name_parts(first_name, second_name, third_name, fourth_name)
            if not full_name:
                continue
            aliases = [
                alias.text.strip()
                for alias in individual.findall(".//INDIVIDUAL_ALIAS/ALIAS_NAME")
                if alias.text and alias.text.strip()
            ]
            un_rows.append(
                {
                    "name": full_name,
                    "id": _read_value_from_xml(individual, "DATAID", default=""),
                    "alias": " | ".join(aliases),
                    "type": "person",
                    "program": _read_value_from_xml(individual, "UN_LIST_TYPE"),
                }
            )
        if un_rows:
            frames.append(
                adapt_raw_source(
                    pd.DataFrame(un_rows),
                    source_name="ONU",
                    name_column="name",
                    id_column="id",
                    alias_column="alias",
                    type_column="type",
                    program_column="program",
                )
            )

    if not frames:
        return pd.DataFrame(columns=WATCHLIST_COLUMNS)
    return pd.concat(frames, ignore_index=True)


def _read_value_from_xml(element: ET.Element, tag: str, default: str = "") -> str:
    node = element.find(tag)
    if node is None or node.text is None:
        return default
    value = node.text.strip()
    return value or default


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
            scorer=score_name_match,
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
