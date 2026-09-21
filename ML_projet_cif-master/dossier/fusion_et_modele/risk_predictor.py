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
    artefact = load_risk_model(str(model_path))
    columns = artefact["feature_columns"]
    features = pd.DataFrame([{column: transaction.get(column, 0) for column in columns}])
    features = features.apply(pd.to_numeric, errors="coerce").fillna(0)
    scaled = artefact["scaler"].transform(features)
    return float(artefact["model"].predict_proba(scaled)[0, 1])
