import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import AssistPanel from "../components/AssistPanel";
import { getMlSuspiciousTransactions } from "../services/api";
import "./MlAssistance.css";

function RiskPill({ level }) {
  const l = (level || "").toUpperCase();
  const label =
    { LOW: "Faible", MEDIUM: "Moyen", HIGH: "Élevé", CRITICAL: "Critique" }[l] ||
    level ||
    "—";
  return (
    <span className={`ml-pill ml-pill-${(l || "low").toLowerCase()}`}>
      {label}
    </span>
  );
}

export default function MlAssistance({ user, onLogout }) {
  const navigate = useNavigate();
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [selectedAlertId, setSelectedAlertId] = useState(null);
  const [selectedTxId, setSelectedTxId] = useState(null);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError("");
      try {
        const res = await getMlSuspiciousTransactions({ limit: 50 });
        if (cancelled) return;
        if (res?.success) {
          const data = res.data || [];
          setRows(data);
          // Préselection : première ligne avec alert liée si dispo
          const first = data[0];
          if (first?.alert_id) setSelectedAlertId(first.alert_id);
          else if (first?.transaction_id) setSelectedTxId(first.transaction_id);
        } else setError(res?.message || "Erreur ML.");
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de charger les signaux ML."
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

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />
        <section className="page-frame ml-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">ANALYSE / INTELLIGENCE</p>
              <h1>Assistance ML & Sentinelle Assist</h1>
              <p>
                Signaux AML issus des vues SQL + copilote de conformité. La
                décision reste humaine.
              </p>
            </div>
          </header>

          <div className="ml-gov-chain">
            <div>
              <span>1 · AML</span>
              <strong>Règles déterministes</strong>
            </div>
            <i>→</i>
            <div className="is-active">
              <span>2 · ML / ASSIST</span>
              <strong>Score & explication</strong>
            </div>
            <i>→</i>
            <div>
              <span>3 · ANALYSTE</span>
              <strong>Décision & CENTIF</strong>
            </div>
          </div>

          {error && <div className="ml-error">{error}</div>}

          <div className="ml-layout">
            <div className="ml-main">
              <article className="ml-panel">
                <header>
                  <h2>Transactions signalées</h2>
                  <span className="ml-count">
                    {rows.length} signal{rows.length !== 1 ? "x" : ""}
                  </span>
                </header>
                <p className="ml-source-note">
                  Source : vue AML SQL. Le scoring MLP temps réel est disponible
                  depuis Sentinelle Assist sur chaque dossier.
                </p>
                {loading ? (
                  <div className="ml-loading">Chargement…</div>
                ) : (
                  <div className="table-scroll">
                    <table className="data-table">
                      <thead>
                        <tr>
                          <th>RÉFÉRENCE</th>
                          <th>CLIENT</th>
                          <th>SCORE</th>
                          <th>NIVEAU</th>
                          <th>TYPE</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        {rows.length === 0 ? (
                          <tr>
                            <td className="empty-cell" colSpan={6}>
                              Aucun signal ML disponible.
                            </td>
                          </tr>
                        ) : (
                          rows.map((r) => {
                            const txId = r.transaction_id || r.id;
                            const alertId = r.alert_id;
                            const active =
                              (alertId && alertId === selectedAlertId) ||
                              (!alertId && txId === selectedTxId);
                            return (
                              <tr
                                key={txId || r.transaction_reference}
                                className={active ? "is-selected" : ""}
                                onClick={() => {
                                  if (alertId) setSelectedAlertId(alertId);
                                  setSelectedTxId(txId);
                                }}
                              >
                                <td className="mono">
                                  {r.transaction_reference ||
                                    (txId ? `TX-${txId}` : "—")}
                                </td>
                                <td>
                                  {r.customer_name ||
                                    r.client_number ||
                                    r.client_id ||
                                    "—"}
                                </td>
                                <td className="mono">
                                  {r.aml_risk_score != null
                                    ? Number(r.aml_risk_score).toFixed(0)
                                    : "—"}
                                </td>
                                <td>
                                  <RiskPill
                                    level={
                                      r.aml_risk_level ||
                                      r.risk_level ||
                                      r.ml_label
                                    }
                                  />
                                </td>
                                <td>{r.transaction_type || r.type || "—"}</td>
                                <td>
                                  <button
                                    type="button"
                                    className="text-link"
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      if (txId) navigate(`/transactions/${txId}`);
                                    }}
                                  >
                                    Voir →
                                  </button>
                                </td>
                              </tr>
                            );
                          })
                        )}
                      </tbody>
                    </table>
                  </div>
                )}
                <p className="ml-note">
                  Source : <code>/ml/suspicious-transactions</code> · Assist :{" "}
                  <code>POST /ml/assist</code>
                </p>
              </article>
            </div>

            </div>

            {selectedAlertId && (
              <AssistPanel
                objectType="alert"
                objectId={selectedAlertId}
                title="Assist — signal sélectionné"
              />
            )}
        </section>
      </div>
    </div>
  );
}
