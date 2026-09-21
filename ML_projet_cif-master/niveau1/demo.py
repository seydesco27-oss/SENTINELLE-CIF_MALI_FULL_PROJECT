"""
demo.py - Démonstration du moteur de filtrage PPE/sanctions
Hackathon CIF DigiCoop-WA+ - Thématique 01

Lance : python demo.py
"""

from pathlib import Path

from sanctions_screening import SanctionsScreener, load_official_watchlist

BASE_DIR = Path(__file__).resolve().parent

CLIENTS_TO_SCREEN = [
    "Amadou Traore Diallo",
    "Seydou Diakite Ouattara",
    "GTS SARL",
    "Ibrahim Ould Cheik",
]


def main():
    watchlist = load_official_watchlist(BASE_DIR)
    if watchlist.empty:
        raise FileNotFoundError("Aucune source officielle trouvée. Ajoute les fichiers UE/OFAC/ONU dans le dossier du projet.")

    screener = SanctionsScreener(watchlist, alert_threshold=92.0, review_threshold=80.0)

    print("=" * 78)
    print("SCREENING PPE / SANCTIONS - DEMO (sources officielles)")
    print("=" * 78)

    for client_name in CLIENTS_TO_SCREEN:
        result = screener.screen(client_name)
        best = result.matches[0] if result.matches else None

        print(f"\nClient        : {client_name}")
        print(f"Niveau risque : {result.risk_level.upper()}")
        if best:
            print(
                f"Meilleure correspondance : {best.matched_name}  "
                f"(score={best.score}, source={best.source}, programme={best.program})"
            )
        else:
            print("Meilleure correspondance : aucune")


if __name__ == "__main__":
    main()
