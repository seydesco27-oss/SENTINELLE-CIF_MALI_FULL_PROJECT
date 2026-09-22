"""
demo.py - Démonstration du moteur de filtrage PPE/sanctions
Hackathon CIF DigiCoop-WA+ - Thématique 01

Lance : python demo.py
"""

import pandas as pd
from sanctions_screening import SanctionsScreener, load_watchlist_csv

WATCHLIST_PATH = "sample_data/watchlist_exemple.csv"
CLIENTS_PATH = "sample_data/clients_test.csv"


def main():
    watchlist = load_watchlist_csv(WATCHLIST_PATH)
    screener = SanctionsScreener(watchlist, alert_threshold=92.0, review_threshold=80.0)

    clients = pd.read_csv(CLIENTS_PATH)

    print("=" * 78)
    print("SCREENING PPE / SANCTIONS - DEMO (donnees fictives)")
    print("=" * 78)

    results_summary = []

    for _, row in clients.iterrows():
        result = screener.screen(row["client_name"])
        best = result.matches[0] if result.matches else None

        print(f"\nClient        : {row['client_name']}")
        print(f"Cas teste     : {row['cas_teste']}")
        print(f"Niveau risque : {result.risk_level.upper()}")
        if best:
            print(f"Meilleure correspondance : {best.matched_name}  "
                  f"(score={best.score}, source={best.source}, programme={best.program})")
        else:
            print("Meilleure correspondance : aucune")

        results_summary.append({
            "client": row["client_name"],
            "cas_teste": row["cas_teste"],
            "risk_level": result.risk_level,
            "score": best.score if best else 0.0,
        })

    print("\n" + "=" * 78)
    print("TABLEAU RECAPITULATIF")
    print("=" * 78)
    summary_df = pd.DataFrame(results_summary)
    print(summary_df.to_string(index=False))

    # -- Vérifications rapides (pas un test unitaire formel, mais un
    #    garde-fou pour vérifier que le moteur se comporte comme attendu) --
    print("\n" + "=" * 78)
    print("VERIFICATIONS")
    print("=" * 78)

    checks = [
        ("Amadou Traore Diallo", "alerte", "correspondance exacte doit alerter"),
        ("Traore Diallo Amadou", "alerte", "ordre inverse doit quand meme alerter"),
        ("Seydou Diakite Ouattara", "aucun", "nom sans lien ne doit pas alerter"),
        ("Kadiatou Sanogo Fofana", "aucun", "nom sans lien ne doit pas alerter"),
    ]

    all_ok = True
    for name, expected, description in checks:
        actual = screener.screen(name).risk_level
        status = "OK" if actual == expected else "ECHEC"
        if actual != expected:
            all_ok = False
        print(f"[{status}] {description} -> attendu={expected}, obtenu={actual}")

    print("\n" + ("Toutes les verifications sont passees." if all_ok
                   else "Attention : certaines verifications ont echoue, ajuster les seuils."))


if __name__ == "__main__":
    main()
