"""HTTP service exposing the exported CIF risk model."""

from __future__ import annotations

import sys
from pathlib import Path
from typing import Any

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

DOSSIER_DIR = Path(__file__).resolve().parent
if str(DOSSIER_DIR) not in sys.path:
    sys.path.insert(0, str(DOSSIER_DIR))

from fusion_et_modele.risk_predictor import DEFAULT_MODEL_PATH, load_risk_model
from integration_service import CIFRiskService


class ScoreRequest(BaseModel):
    transaction: dict[str, Any] = Field(default_factory=dict)
    client_name: str | None = None


app = FastAPI(title="Sentinelle CIF ML Service", version="1.0.0")
risk_service = CIFRiskService()


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
