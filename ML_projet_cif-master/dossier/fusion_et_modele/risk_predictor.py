"""Interface legere de scoring pour le backend CIF."""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import Mapping

import joblib
import pandas as pd


DEFAULT_MODEL_PATH = Path(__file__).resolve().parent / "data" / "modele_risque_mlp.joblib"


@lru_cache(maxsize=2)
def load_risk_model(model_path: str | Path = DEFAULT_MODEL_PATH) -> dict:
    """Charge l'artefact une seule fois par processus backend."""
    path = Path(model_path)
    if not path.exists():
        raise FileNotFoundError(
            f"Modele introuvable : {path}. Executez d'abord train_model.py."
        )
    return joblib.load(path)


def predire_risque(transaction: Mapping[str, object], model_path: str | Path = DEFAULT_MODEL_PATH) -> float:
    """Retourne un score de risque entre 0 et 1 pour une transaction preparee."""
    score, _ = predire_risque_explique(transaction, model_path)
    return score


def predire_risque_explique(
    transaction: Mapping[str, object],
    model_path: str | Path = DEFAULT_MODEL_PATH,
    limit: int = 8,
) -> tuple[float, list[dict[str, float | str]]]:
    """Retourne le score et une explication locale par neutralisation.

    Chaque variable est remplacée à tour de rôle par sa moyenne d'entraînement
    dans l'espace standardisé. La variation de probabilité donne une mesure
    locale, signée et reproductible de son influence sur cette prédiction.
    """
    artefact = load_risk_model(str(model_path))
    columns = artefact["feature_columns"]
    features = pd.DataFrame([{column: transaction.get(column, 0) for column in columns}])
    features = features.apply(pd.to_numeric, errors="coerce").fillna(0)
    scaled = artefact["scaler"].transform(features)
    model = artefact["model"]
    score = float(model.predict_proba(scaled)[0, 1])

    factors = []
    for index, column in enumerate(columns):
        neutral = scaled.copy()
        neutral[0, index] = 0.0
        neutral_score = float(model.predict_proba(neutral)[0, 1])
        contribution = score - neutral_score
        factors.append({
            "feature_name": column,
            "feature_value": float(features.iloc[0, index]),
            "contribution": contribution,
            "importance": abs(contribution),
            "direction": "hausse" if contribution > 0 else "baisse" if contribution < 0 else "neutre",
        })

    factors.sort(key=lambda factor: float(factor["importance"]), reverse=True)
    return score, factors[: max(1, limit)]
