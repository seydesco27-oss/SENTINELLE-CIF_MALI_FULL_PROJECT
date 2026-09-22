"""Adaptation du contrat backend CIF vers les features du modele AML.

Le contrat de reference est ``dataset/dataset_C_hybrid_aml.csv``. Les
colonnes d'historique doivent etre calculees par le backend avant appel; elles
ne sont jamais deduites de donnees futures.
"""

from __future__ import annotations

from math import log1p
from typing import Mapping


SEUIL_DECLARATION_FCFA = 10_000_000

TRANSACTION_TYPE_FEATURES = {
    "CREDIT_DISBURSEMENT": "type_decaissement_credit",
    "DEPOSIT": "type_depot",
    "LOAN_REPAYMENT": "type_remboursement_credit",
    "WITHDRAWAL": "type_retrait",
    "TRANSFER_IN": "type_transfert_entrant",
    "TRANSFER_OUT": "type_transfert_sortant",
}


def _number(value: object, default: float = 0.0) -> float:
    try:
        return float(value) if value is not None else default
    except (TypeError, ValueError):
        return default


def _integer(value: object, default: int = 0) -> int:
    return int(_number(value, default))


def backend_to_model_features(transaction: Mapping[str, object]) -> dict[str, float]:
    """Construit les variables attendues par ``modele_risque_mlp.joblib``.

    Champs backend utilises : ``amount``, ``transaction_type``,
    ``transaction_day_of_week``, ``previous_transaction_*_30d``,
    ``previous_transaction_count_24h``, ``previous_volume_24h`` et
    ``country_count_30d``. Les champs optionnels ``in_degree`` et
    ``out_degree`` doivent provenir du graphe de comptes du backend.
    """
    amount = max(_number(transaction.get("amount")), 0.0)
    average_30d = _number(
        transaction.get("average_transaction_amount_30d",
                        transaction.get("previous_transaction_average_30d"))
    )
    count_30d = _integer(transaction.get("previous_transaction_count_30d"))
    count_24h = _integer(transaction.get("previous_transaction_count_24h"))
    volume_24h = _number(
        transaction.get("previous_volume_24h",
                        transaction.get("previous_transaction_volume_24h"))
    )
    tx_type = str(transaction.get("transaction_type", "")).upper()
    recipient = transaction.get("country_to") or transaction.get("account_number")

    features = {
        "montant_log": log1p(amount),
        "ratio_seuil": amount / SEUIL_DECLARATION_FCFA,
        "proche_seuil": int(0.85 * SEUIL_DECLARATION_FCFA <= amount < SEUIL_DECLARATION_FCFA),
        "a_destinataire": int(bool(recipient)),
        "jour_semaine": _integer(transaction.get("transaction_day_of_week")),
        "montant_moyen_client": average_30d,
        "montant_std_client": _number(transaction.get("transaction_amount_std_30d")),
        "nb_transactions_client": count_30d + 1,
        "nb_destinataires_distincts_client": _integer(
            transaction.get("beneficiary_count_30d", transaction.get("country_count_30d"))
        ),
        "montant_zscore": (amount - average_30d) / (_number(transaction.get("transaction_amount_std_30d")) + 1.0),
        "nb_transactions_7j": count_24h + 1,
        "montant_cumule_7j": volume_24h + amount,
        "in_degree": _number(transaction.get("in_degree")),
        "out_degree": _number(transaction.get("out_degree")),
    }
    features.update({feature: 0 for feature in TRANSACTION_TYPE_FEATURES.values()})
    if tx_type in TRANSACTION_TYPE_FEATURES:
        features[TRANSACTION_TYPE_FEATURES[tx_type]] = 1
    return features


def validate_backend_transaction(transaction: Mapping[str, object]) -> list[str]:
    """Retourne les champs contractuels indispensables absents ou invalides."""
    errors = []
    if _number(transaction.get("amount"), -1) < 0:
        errors.append("amount doit etre un montant positif ou nul")
    if not str(transaction.get("transaction_type", "")).strip():
        errors.append("transaction_type est obligatoire")
    if transaction.get("transaction_day_of_week") is None:
        errors.append("transaction_day_of_week est obligatoire")
    return errors
