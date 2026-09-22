import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import {
  getPriorityAlerts,
  getClients,
  getMlSuspiciousTransactions,
} from "../services/api";
import "./Analyse.css";

function RiskPill({ level }) {
  const l = (level || "").toUpperCase();
  const label =
    { LOW: "Faible", MEDIUM: "Moyen", HIGH: "Élevé", CRITICAL: "Critique" }[l] ||
    level ||
    "—";
  return (
    <span className={`badge risk-${(l || "low").toLowerCase()}`}>{label}</span>
  );
}

function formatDateTime(v) {
  if (!v) return "—";
  try {
    return new Date(v).toLocaleString("fr-FR", {
      day: "2-digit",
      month: "short",
      hour: "2-digit",
      minute: "2-digit",
    });
  } catch {
    return String(v);
  }
}

export default function Analyse({ user, onLogout }) {
  const navigate = useNavigate();
  const [alerts, setAlerts] = useState([]);
  const [clients, setClients] = useState([]);
  const [tx, setTx] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError("");
      try {
        const [a, c, t] = await Promise.all([
          getPriorityAlerts({ limit: 20, status: "ALL" }),
          getClients({
            risk_level: "HIGH",
            per_page: 20,
            sort: "risk_score",
            direction: "desc",
          }),
          getMlSuspiciousTransactions({ limit: 20 }),
        ]);
        if (cancelled) return;
        if (a?.success) setAlerts(a.data || []);
        if (c?.success) setClients(c.data || []);
        if (t?.success) setTx(t.data || []);
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de charger l’analyse."
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

  const criticalAlerts = useMemo(
    () =>
      alerts.filter(
        (a) => String(a.priority || "").toUpperCase() === "CRITICAL"
      ),
    [alerts]
  );

  const highRiskClients = useMemo(
    () =>
      clients.filter((c) =>
        ["HIGH", "CRITICAL"].includes(String(c.risk_level || "").toUpperCase())
      ),
    [clients]
  );

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />
        <section className="page-frame an-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">ANALYSE & RISQUE</p>
              <h1>Analyse consolidée</h1>
              <p>
                Vue transversale risque · Clients prioritaires · Alertes ·
                Signaux ML
              </p>
            </div>
          </header>

          {error && <div className="an-error">{error}</div>}

          <div className="an-kpi-strip">
            {[
              {
                label: "ALERTES CRITIQUES",
                value: criticalAlerts.length,
                danger: true,
              },
              {
                label: "CLIENTS ÉLEVÉS+",
                value: highRiskClients.length,
                warn: true,
              },
              { label: "SIGNAUX ML", value: tx.length },
              {
                label: "ALERTES PRIORITAIRES",
                value: alerts.length,
              },
            ].map((k) => (
              <div
                key={k.label}
                className={
                  "an-kpi" +
                  (k.danger ? " is-danger" : "") +
                  (k.warn ? " is-warn" : "")
                }
              >
                <span>{k.label}</span>
                <strong>{loading ? "…" : k.value}</strong>
              </div>
            ))}
          </div>

          {loading ? (
            <div className="an-loading">Chargement de la synthèse…</div>
          ) : (
            <div className="an-grid">
              <article className="an-panel">
                <header>
                  <h2>Alertes prioritaires</h2>
                  <button
                    type="button"
                    className="text-link"
                    onClick={() => navigate("/alertes")}
                  >
                    Voir tout →
                  </button>
                </header>
                <div className="table-scroll">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>RÉF.</th>
                        <th>CLIENT</th>
                        <th>PRIORITÉ</th>
                        <th>DATE</th>
                      </tr>
                    </thead>
                    <tbody>
                      {alerts.slice(0, 8).map((a) => (
                        <tr
                          key={a.id}
                          onClick={() => navigate(`/alertes/${a.id}`)}
                        >
                          <td className="mono">
                            {a.reference || `ALT-${a.id}`}
                          </td>
                          <td>
                            {a.customer_name || a.client_name || "—"}
                          </td>
                          <td>
                            <RiskPill level={a.priority} />
                          </td>
                          <td className="cell-muted">
                            {formatDateTime(a.created_at)}
                          </td>
                        </tr>
                      ))}
                      {!alerts.length && (
                        <tr>
                          <td className="empty-cell" colSpan={4}>
                            Aucune alerte prioritaire
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </article>

              <article className="an-panel">
                <header>
                  <h2>Clients à risque</h2>
                  <button
                    type="button"
                    className="text-link"
                    onClick={() => navigate("/clients")}
                  >
                    Voir tout →
                  </button>
                </header>
                <div className="table-scroll">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>CLIENT</th>
                        <th>NIVEAU</th>
                        <th>SCORE</th>
                      </tr>
                    </thead>
                    <tbody>
                      {highRiskClients.slice(0, 8).map((c) => {
                        const id = c.client_id || c.id;
                        return (
                          <tr
                            key={id}
                            onClick={() => navigate(`/clients/${id}`)}
                          >
                            <td>
                              <strong>
                                {c.customer_name || c.primary_name || "—"}
                              </strong>
                            </td>
                            <td>
                              <RiskPill level={c.risk_level} />
                            </td>
                            <td className="mono">
                              {c.risk_score != null
                                ? Number(c.risk_score).toFixed(0)
                                : "—"}
                            </td>
                          </tr>
                        );
                      })}
                      {!highRiskClients.length && (
                        <tr>
                          <td className="empty-cell" colSpan={3}>
                            Aucun client élevé/critique dans le jeu
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </article>

              <article className="an-panel an-panel-wide">
                <header>
                  <h2>Signaux ML (transactions)</h2>
                  <button
                    type="button"
                    className="text-link"
                    onClick={() => navigate("/ml")}
                  >
                    Module ML →
                  </button>
                </header>
                <div className="table-scroll">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>RÉF.</th>
                        <th>SCORE</th>
                        <th>NIVEAU</th>
                        <th>TYPE</th>
                      </tr>
                    </thead>
                    <tbody>
                      {tx.slice(0, 8).map((r) => {
                        const id = r.transaction_id || r.id;
                        return (
                          <tr
                            key={id || r.transaction_reference}
                            onClick={() =>
                              id && navigate(`/transactions/${id}`)
                            }
                          >
                            <td className="mono">
                              {r.transaction_reference ||
                                r.reference ||
                                (id ? `TX-${id}` : "—")}
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
                          </tr>
                        );
                      })}
                      {!tx.length && (
                        <tr>
                          <td className="empty-cell" colSpan={4}>
                            Aucun signal ML
                          </td>
                        </tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </article>
            </div>
          )}
        </section>
      </div>
    </div>
  );
}
