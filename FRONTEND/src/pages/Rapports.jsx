import { useEffect, useState } from "react";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { getReportsSummary } from "../services/api";
import "./Rapports.css";

export default function Rapports({ user, onLogout }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError("");
      try {
        const res = await getReportsSummary();
        if (cancelled) return;
        if (res?.success) setData(res.data);
        else setError(res?.message || "Erreur rapports.");
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de charger la synthèse."
          );
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, []);

  const compliance = data?.compliance || {};
  const network = data?.network || {};
  const screening = data?.screening || {};
  const centif = data?.centif_declarations || data?.centif || {};
  const audit = data?.audit || {};

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />
        <section className="page-frame rp-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">ANALYSE / PILOTAGE</p>
              <h1>Rapports</h1>
              <p>
                Synthèse de supervision reconstituée depuis les données réelles
                de la plateforme.
              </p>
            </div>
          </header>

                    <li>
                      PEP : <strong>{screening.distinct_pep_clients ?? screening.pep_clients ?? "—"}</strong>
                    </li>
          {error && <div className="rp-error">{error}</div>}

          {loading ? (
            <div className="rp-loading">Chargement…</div>
          ) : (
            <>
                      <strong>{screening.distinct_sanction_clients ?? screening.sanction_clients ?? "—"}</strong>
              <div className="rp-hero">
                <div>
                  <p className="eyebrow">SYNTHÈSE API</p>
                  <h2>Supervision — données live</h2>
                  <p>
                    Généré le{" "}
                    {data?.generated_at
                      ? new Date(data.generated_at).toLocaleString("fr-FR")
                      : "—"}
                  </p>
                </div>
              </div>

              <div className="rp-cards">
                <article className="rp-card">
                  <div className="rp-card-top">
                    <span className="rp-tag">Live</span>
                  </div>
                  <h2>Conformité / AML</h2>
                  <ul>
                    <li>
                      Clients : <strong>{compliance.total_clients ?? "—"}</strong>
                    </li>
                    <li>
                      À risque :{" "}
                      <strong>{compliance.risky_clients ?? "—"}</strong>
                    </li>
                    <li>
                      Alertes ouvertes :{" "}
                      <strong>
                        {compliance.open_alerts ??
                          compliance.total_alerts ??
                          "—"}
                      </strong>
                    </li>
                  </ul>
                </article>

                <article className="rp-card">
                  <div className="rp-card-top">
                    <span className="rp-tag">Live</span>
                  </div>
                  <h2>Réseau</h2>
                  <ul>
                    <li>
                      Caisses :{" "}
                      <strong>
                        {network.caisses_count ?? network.caisse_count ?? network.caisses ?? "—"}
                      </strong>
                    </li>
                    <li>
                      Agences :{" "}
                      <strong>
                        {network.agencies_count ?? network.agency_count ?? network.agencies ?? "—"}
                      </strong>
                    </li>
                  </ul>
                </article>

                <article className="rp-card">
                  <div className="rp-card-top">
                    <span className="rp-tag">Live</span>
                  </div>
                  <h2>Screening</h2>
                  <ul>
                    <li>
                      Screenings :{" "}
                      <strong>{screening.total_screenings ?? "—"}</strong>
                    </li>
                    <li>
                      Matches :{" "}
                      <strong>{screening.matches_found ?? "—"}</strong>
                    </li>
                    <li>
                      PEP :{" "}
                      <strong>
                        {screening.distinct_pep_clients ?? screening.pep_clients ?? "—"}
                      </strong>
                    </li>
                    <li>
                      Sanctions :{" "}
                      <strong>
                        {screening.distinct_sanction_clients ?? screening.sanction_clients ?? "—"}
                      </strong>
                    </li>
                  </ul>
                </article>

                <article className="rp-card">
                  <div className="rp-card-top">
                    <span className="rp-tag">Live</span>
                  </div>
                  <h2>CENTIF / Audit</h2>
                  <ul>
                    <li>
                      Déclarations : <strong>{centif.total ?? "—"}</strong>
                    </li>
                    <li>
                      Transmises :{" "}
                      <strong>{centif.transmitted ?? "—"}</strong>
                    </li>
                    <li>
                      Événements audit :{" "}
                      <strong>
                        {audit.total_events ?? audit.count ?? "—"}
                      </strong>
                    </li>
                  </ul>
                </article>
              </div>

              <p className="rp-note">
                Source : <code>GET /reports/summary</code> — libellés adaptés aux
                clés réellement renvoyées.
              </p>
            </>
          )}
        </section>
      </div>
    </div>
  );
}
