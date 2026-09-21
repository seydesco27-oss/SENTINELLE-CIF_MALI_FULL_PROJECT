"""Point d'integration unique des niveaux 1, 2 et 3 pour le backend."""

from __future__ import annotations

from pathlib import Path
from typing import Mapping

import pandas as pd

from fusion_et_modele.backend_adapter import backend_to_model_features, validate_backend_transaction
from fusion_et_modele.risk_predictor import predire_risque
from niveau1_screening.sanctions_screening import SanctionsScreener, load_watchlist_csv


class CIFRiskService:
    """Combine screening PPE/sanctions, regles backend et modele comportemental."""

    def __init__(self, watchlist_path: str | Path | None = None, watchlist: pd.DataFrame | None = None):
        self.screener = None
        if watchlist is not None:
            self.screener = SanctionsScreener(watchlist)
        elif watchlist_path is not None:
            self.screener = SanctionsScreener(load_watchlist_csv(watchlist_path))

    def assess(self, transaction: Mapping[str, object], client_name: str | None = None) -> dict:
        errors = validate_backend_transaction(transaction)
        if errors:
            raise ValueError("; ".join(errors))

        model_score = predire_risque(backend_to_model_features(transaction))
        screening = self.screener.screen(client_name) if self.screener and client_name else None
        sanctions_score = float(transaction.get("max_sanction_match_score") or 0) / 100
        screening_score = max(sanctions_score, (screening.matches[0].score / 100 if screening and screening.matches else 0))
        rule_score = min(float(transaction.get("aml_rule_score") or 0) / 100, 1.0)

        # Le score ML reste le signal principal; les regles et le screening
        # augmentent prioritairement les cas deja confirmes par le backend.
        final_score = min(1.0, 0.65 * model_score + 0.20 * rule_score + 0.15 * screening_score)
        return {
            "transaction_id": transaction.get("transaction_id"),
            "model_score": model_score,
            "rule_score": rule_score,
            "screening_score": screening_score,
            "final_score": final_score,
            "screening_risk_level": screening.risk_level if screening else "non_evalue",
        }
