import unittest

from fastapi.testclient import TestClient

from dossier.ml_service import app


class MlServiceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.client = TestClient(app)

    def test_health_reports_loaded_model(self):
        response = self.client.get("/health")

        self.assertEqual(response.status_code, 200)
        payload = response.json()
        self.assertEqual(payload["status"], "healthy")
        self.assertEqual(payload["model"], "MLPClassifier")
        self.assertEqual(payload["feature_count"], 20)
        self.assertTrue(payload["screening"]["enabled"])
        self.assertIn(payload["screening"]["watchlist_source"], {"OFFICIAL_BUNDLED", "DEMO"})

    def test_score_uses_exported_model_and_returns_combined_scores(self):
        response = self.client.post(
            "/score",
            json={
                "transaction": {
                    "transaction_id": "TEST-001",
                    "amount": 7_500_000,
                    "transaction_type": "TRANSFER_OUT",
                    "transaction_day_of_week": 1,
                    "previous_transaction_count_30d": 2,
                    "previous_volume_30d": 7_541_267,
                    "previous_transaction_count_24h": 1,
                    "previous_volume_24h": 7_500_000,
                    "country_count_30d": 1,
                    "aml_rule_score": 75,
                    "max_sanction_match_score": 0,
                },
                "client_name": "Client Test",
            },
        )

        self.assertEqual(response.status_code, 200)
        payload = response.json()
        self.assertEqual(payload["transaction_id"], "TEST-001")
        self.assertEqual(payload["engine"], "cif-risk-mlp-v1")
        self.assertGreaterEqual(payload["model_score"], 0)
        self.assertLessEqual(payload["model_score"], 1)
        self.assertGreaterEqual(payload["final_score"], 0)
        self.assertLessEqual(payload["final_score"], 1)
        self.assertIn(payload["screening_risk_level"], {"aucun", "a_verifier", "alerte"})

    def test_score_rejects_missing_transaction(self):
        response = self.client.post("/score", json={})

        self.assertEqual(response.status_code, 422)


if __name__ == "__main__":
    unittest.main()
