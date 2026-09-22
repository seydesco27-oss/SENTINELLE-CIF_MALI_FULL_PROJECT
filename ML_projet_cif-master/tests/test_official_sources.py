import unittest
import sys
from pathlib import Path

import pandas as pd

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "niveau1"))

from sanctions_screening import adapt_eu_source, load_official_watchlist, score_name_match


class OfficialSourcesTests(unittest.TestCase):
    def test_adapt_eu_source_builds_name_from_parts(self):
        raw_df = pd.DataFrame(
            [{
                "NameAlias_LastName": "Diallo",
                "NameAlias_FirstName": "Amadou",
                "NameAlias_MiddleName": "",
                "NameAlias_WholeName": "",
                "Entity_SubjectType": "P",
                "Entity_Regulation_Programme": "PPE",
            }]
        )

        watchlist = adapt_eu_source(raw_df)

        self.assertEqual(watchlist.iloc[0]["full_name"], "Amadou Diallo")
        self.assertEqual(watchlist.iloc[0]["source"], "EU")
        self.assertEqual(watchlist.iloc[0]["entry_type"], "person")

    def test_score_name_match_penalizes_different_surnames(self):
        score = score_name_match("Amadou Traore Diallo", "Ahmad Ali Taher")
        self.assertLess(score, 60)

    def test_score_name_match_keeps_exact_matches_high(self):
        score = score_name_match("Amadou Traore Diallo", "Amadou Traore Diallo")
        self.assertGreaterEqual(score, 95)

    def test_load_official_watchlist(self):
        base_dir = Path(__file__).resolve().parents[1] / "niveau1"
        watchlist = load_official_watchlist(base_dir)

        self.assertFalse(watchlist.empty)
        self.assertIn("source", watchlist.columns)
        self.assertIn("full_name", watchlist.columns)
        self.assertTrue(watchlist["source"].isin(["EU", "OFAC", "ONU"]).any())


if __name__ == "__main__":
    unittest.main()
