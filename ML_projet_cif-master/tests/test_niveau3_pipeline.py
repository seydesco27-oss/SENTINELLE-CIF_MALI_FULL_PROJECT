import sys
import unittest
from pathlib import Path

import pandas as pd

ROOT = Path(__file__).resolve().parents[1]
sys.path.append(str(ROOT / "niveau3"))

from train_model import build_training_frame, combine_scores


class Niveau3PipelineTests(unittest.TestCase):
    def test_build_training_frame_creates_features_and_target(self):
        transactions = pd.DataFrame(
            [
                {
                    "client_id": "CLI-00001",
                    "date_transaction": "2026-08-01",
                    "montant_fcfa": 50000,
                    "type_transaction": "depot",
                    "corridor": "local",
                    "is_risky": False,
                },
                {
                    "client_id": "CLI-00002",
                    "date_transaction": "2026-08-02",
                    "montant_fcfa": 12000000,
                    "type_transaction": "transfert_sortant",
                    "corridor": "transfrontalier_sensible",
                    "is_risky": True,
                },
            ]
        )
        clients = pd.DataFrame(
            [
                {"client_id": "CLI-00001", "profil": "rural", "anciennete_compte_jours": 300},
                {"client_id": "CLI-00002", "profil": "urbain", "anciennete_compte_jours": 50},
            ]
        )

        features, target, client_ids = build_training_frame(transactions, clients)

        self.assertFalse(features.empty)
        self.assertIn("log_amount", features.columns)
        self.assertIn("client_age_days", features.columns)
        self.assertEqual(target.name, "is_risky")
        self.assertEqual(len(features), len(transactions))

    def test_combine_scores_uses_behaviour_and_ppe_weights(self):
        combined = combine_scores(behavior_score=0.8, ppe_score=0.2)
        self.assertAlmostEqual(combined, 0.62)


if __name__ == "__main__":
    unittest.main()
