"""HTTP service exposing the exported CIF risk model."""

from __future__ import annotations

import sys
from pathlib import Path
from typing import Any

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

DOSSIER_DIR = Path(__file__).resolve().parent
ML_ROOT = DOSSIER_DIR.parent
if str(DOSSIER_DIR) not in sys.path:
    sys.path.insert(0, str(DOSSIER_DIR))
if str(ML_ROOT) not in sys.path:
    sys.path.insert(0, str(ML_ROOT))

from fusion_et_modele.risk_predictor import DEFAULT_MODEL_PATH, load_risk_model
from integration_service import CIFRiskService
from niveau1.sanctions_screening import load_official_watchlist

DEFAULT_WATCHLIST_PATH = DOSSIER_DIR / "niveau1_screening" / "sample_data" / "watchlist_exemple.csv"
OFFICIAL_WATCHLIST_DIR = ML_ROOT / "niveau1"


def load_screening_service() -> tuple[CIFRiskService, str, str]:
    official_watchlist = load_official_watchlist(OFFICIAL_WATCHLIST_DIR)
    if not official_watchlist.empty:
        return CIFRiskService(watchlist=official_watchlist), "OFFICIAL_BUNDLED", str(OFFICIAL_WATCHLIST_DIR)
    return CIFRiskService(DEFAULT_WATCHLIST_PATH), "DEMO", str(DEFAULT_WATCHLIST_PATH)


class ScoreRequest(BaseModel):
    transaction: dict[str, Any] = Field(default_factory=dict)
    client_name: str | None = None


app = FastAPI(title="Sentinelle CIF ML Service", version="1.0.0")
risk_service, screening_source, screening_path = load_screening_service()


@app.get("/health")
def health() -> dict[str, object]:
    try:
        artefact = load_risk_model()
        return {
            "status": "healthy",
            "service": "sentinelle-cif-ml",
            "model": artefact.get("model_name", "unknown"),
            "feature_count": len(artefact.get("feature_columns", [])),
            "model_path": str(DEFAULT_MODEL_PATH),
            "screening": {
                "enabled": risk_service.screener is not None,
                "watchlist_path": screening_path,
                "watchlist_source": screening_source,
            },
        }
    except Exception as error:
        raise HTTPException(status_code=503, detail=str(error)) from error


@app.post("/score")
def score(request: ScoreRequest) -> dict[str, object]:
    if not request.transaction:
        raise HTTPException(status_code=422, detail="transaction est obligatoire")
    try:
        result = risk_service.assess(request.transaction, request.client_name)
    except ValueError as error:
        raise HTTPException(status_code=422, detail=str(error)) from error
    except Exception as error:
        raise HTTPException(status_code=503, detail=str(error)) from error

    return {
        **result,
        "engine": "cif-risk-mlp-v1",
    }


if __name__ == "__main__":
    import uvicorn

    uvicorn.run("ml_service:app", host="127.0.0.1", port=8100, reload=False)
