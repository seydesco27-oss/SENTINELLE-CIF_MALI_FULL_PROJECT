"""Scenarios de demonstration du service CIFRiskService.

Depuis le dossier ``dossier`` : ``python demo_scenarios.py``.
Les scores du modele sont informatifs : une transaction est toujours revue par
un agent de conformite avant toute decision.
"""

from pathlib import Path

from integration_service import CIFRiskService


WATCHLIST = Path(__file__).resolve().parent / "niveau1_screening" / "sample_data" / "watchlist_exemple.csv"


def transaction_base(transaction_id: str, **overrides: object) -> dict[str, object]:
    transaction = {
        "transaction_id": transaction_id,
        "amount": 60_000,
        "transaction_type": "DEPOSIT",
        "transaction_day_of_week": 2,
        "previous_transaction_count_30d": 12,
        "previous_volume_30d": 720_000,
        "previous_transaction_count_24h": 0,
        "previous_volume_24h": 0,
        "average_transaction_amount_30d": 60_000,
        "transaction_amount_std_30d": 20_000,
        "beneficiary_count_30d": 1,
        "country_count_30d": 1,
        "in_degree": 1,
        "out_degree": 0,
        "aml_rule_score": 0,
        "max_sanction_match_score": 0,
    }
    transaction.update(overrides)
    return transaction


SCENARIOS = [
    (
        "1. Transaction habituelle, client non liste",
        transaction_base("DEMO-NORMAL"),
        "Seydou Diakite Ouattara",
    ),
    (
        "2. Structuring : montant proche du seuil et activite inhabituelle",
        transaction_base(
            "DEMO-STRUCTURING",
            amount=9_400_000,
            transaction_type="TRANSFER_OUT",
            previous_transaction_count_30d=3,
            previous_volume_30d=600_000,
            previous_transaction_count_24h=4,
            previous_volume_24h=28_000_000,
            average_transaction_amount_30d=200_000,
            transaction_amount_std_30d=80_000,
            beneficiary_count_30d=5,
            out_degree=5,
            aml_rule_score=85,
        ),
        "Kadiatou Sanogo Fofana",
    ),
    (
        "3. Fan-out : dispersion vers de nombreux beneficiaires",
        transaction_base(
            "DEMO-FANOUT",
            amount=2_500_000,
            transaction_type="TRANSFER_OUT",
            previous_transaction_count_30d=4,
            previous_transaction_count_24h=8,
            previous_volume_24h=16_000_000,
            average_transaction_amount_30d=100_000,
            transaction_amount_std_30d=40_000,
            beneficiary_count_30d=25,
            in_degree=1,
            out_degree=25,
            aml_rule_score=70,
        ),
        "Kadiatou Sanogo Fofana",
    ),
    (
        "4. Correspondance sanctions/PPE sans anomalie comportementale",
        transaction_base("DEMO-SANCTION"),
        "Amadou Traore Diallo",
    ),
    (
        "5. Cas combine : comportement suspect + sanctions",
        transaction_base(
            "DEMO-COMBINE",
            amount=9_600_000,
            transaction_type="TRANSFER_OUT",
            previous_transaction_count_24h=5,
            previous_volume_24h=35_000_000,
            average_transaction_amount_30d=150_000,
            transaction_amount_std_30d=50_000,
            beneficiary_count_30d=18,
            in_degree=10,
            out_degree=18,
            aml_rule_score=95,
        ),
        "Fatoumata Kone Sangare",
    ),
]


def main() -> None:
    service = CIFRiskService(WATCHLIST)
    for title, transaction, client_name in SCENARIOS:
        result = service.assess(transaction, client_name)
        print(f"\n{title}")
        print(f"Client : {client_name}")
        print(
            "Scores : "
            f"modele={result['model_score']:.3f} | "
            f"regles={result['rule_score']:.3f} | "
            f"screening={result['screening_score']:.3f} | "
            f"final={result['final_score']:.3f} | "
            f"niveau_screening={result['screening_risk_level']}"
        )


if __name__ == "__main__":
    main()
