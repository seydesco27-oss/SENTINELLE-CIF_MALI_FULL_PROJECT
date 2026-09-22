import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import AssistPanel from "../components/AssistPanel";
import AssistCue from "../components/AssistCue";
import "../components/AssistCue.css";
import {
  getDashboardSummary,
  getMlHealth,
  getPriorityAlerts,
  getRiskDistribution,
  getAlertTrend,
} from "../services/api";
import "./Dashboard.css";

const FILTER_OPTIONS = {
  period: [
    ["Aujourd’hui", "today"],
    ["7 derniers jours", "7d"],
    ["30 derniers jours", "30d"],
  ],
  caisse: [
    ["Toutes les caisses", "ALL"],
    ["CIF Bamako", "CIF Bamako"],
    ["CIF Sikasso", "CIF Sikasso"],
    ["CIF Kayes", "CIF Kayes"],
  ],
  agence: [
    ["Toutes les agences", "ALL"],
    ["Bamako Centre", "Bamako Centre"],
    ["Badalabougou", "Badalabougou"],
    ["Sikasso Ville", "Sikasso Ville"],
    ["Kayes Centre", "Kayes Centre"],
  ],
  risk: [
    ["Tous risques", "ALL"],
    ["Critique", "CRITICAL"],
    ["Élevé", "HIGH"],
    ["Modéré", "MEDIUM"],
    ["Faible", "LOW"],
  ],
  type: [
    ["Tous types", "ALL"],
    ["Montant élevé", "LARGE_AMOUNT"],
    ["Fractionnement", "STRUCTURING"],
    ["Mouvements rapides", "RAPID_TRANSFER"],
    ["Corridor sensible", "HIGH_RISK_CORRIDOR"],
  ],
  status: [
    ["Tous statuts", "ALL"],
    ["Ouvert", "OPEN"],
    ["En revue", "IN_REVIEW"],
    ["Clos", "CLOSED"],
    ["Écarté", "DISMISSED"],
  ],
};

const FILTER_LABELS = {
  period: "PÉRIODE",
  caisse: "CAISSE",
  agence: "AGENCE",
  risk: "RISQUE",
  type: "TYPE ALERTE",
  status: "STATUT",
};

const PRIORITY_LABEL = {
  CRITICAL: "Critique",
  HIGH: "Élevée",
  MEDIUM: "Moyenne",
  LOW: "Faible",
};

const STATUS_LABEL = {
  OPEN: "Ouverte",
  IN_REVIEW: "En analyse",
  CLOSED: "Clôturée",
  DISMISSED: "Écartée",
  OUVERTE: "Ouverte",
  EN_ANALYSE: "En analyse",
};

function PriorityBadge({ priority }) {
  const p = (priority || "").toUpperCase();
  const cls =
    { CRITICAL: "risk-critical", HIGH: "risk-high", MEDIUM: "risk-medium", LOW: "risk-low" }[
      p
    ] || "status-muted";
  return <span className={`badge ${cls}`}>{PRIORITY_LABEL[p] || priority || "—"}</span>;
}

function StatusBadge({ status }) {
  const s = (status || "").toUpperCase();
  const cls =
    {
      OPEN: "status-open",
      OUVERTE: "status-open",
      IN_REVIEW: "status-review",
      EN_ANALYSE: "status-review",
      CLOSED: "status-closed",
      DISMISSED: "status-muted",
    }[s] || "status-muted";
  return <span className={`badge ${cls}`}>{STATUS_LABEL[s] || status || "—"}</span>;
}

function formatDateTime(value) {
  if (!value) return "—";
  try {
    return new Date(value).toLocaleString("fr-FR", {
      day: "2-digit",
      month: "short",
      hour: "2-digit",
      minute: "2-digit",
    });
  } catch {
    return String(value);
  }
}

export default function Dashboard({ user, onLogout }) {
  const [assistOpen, setAssistOpen] = useState(false);
  const navigate = useNavigate();

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [summary, setSummary] = useState(null);
  const [priorityAlerts, setPriorityAlerts] = useState([]);
  const [riskDistribution, setRiskDistribution] = useState([]);
  const [alertTrend, setAlertTrend] = useState([]);
  const [highRiskSummary, setHighRiskSummary] = useState(null);
  const [mlHealth, setMlHealth] = useState(null);

  const [filters, setFilters] = useState({
    period: "today",
    caisse: "ALL",
    agence: "ALL",
    risk: "ALL",
    type: "ALL",
    status: "ALL",
  });

  const setFilter = (key, value) =>
    setFilters((current) => ({ ...current, [key]: value }));

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError("");
      try {
        const [sumRes, alertsRes, riskRes, trendRes, mlRes] = await Promise.all([
          getDashboardSummary(),
          getPriorityAlerts({ limit: 12 }),
          getRiskDistribution(),
          getAlertTrend(7),
          getMlHealth().catch(() => ({ success: false })),
        ]);
        if (cancelled) return;

        if (sumRes?.success) setSummary(sumRes.data);
        if (alertsRes?.success) {
          setPriorityAlerts(alertsRes.data || []);
          setHighRiskSummary(alertsRes.summary || null);
        }
        if (riskRes?.success) {
          setRiskDistribution(riskRes.data?.distribution || riskRes.data || []);
        }
        if (trendRes?.success) {
          setAlertTrend(trendRes.data || []);
        }
        setMlHealth(mlRes);
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de charger le tableau de bord."
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

  // Filtre local sur la file déjà chargée (UI Figma) — ne casse pas l'API
  const filteredAlerts = useMemo(() => {
    return priorityAlerts.filter((alert) => {
      if (filters.status !== "ALL") {
        const s = (alert.status || "").toUpperCase();
        if (s !== filters.status) return false;
      }
      if (filters.type !== "ALL") {
        if ((alert.alert_type || "") !== filters.type) return false;
      }
      if (filters.risk !== "ALL") {
        const p = (alert.priority || alert.risk_level || "").toUpperCase();
        if (p !== filters.risk) return false;
      }
      return true;
    });
  }, [priorityAlerts, filters]);

  const openAlertsCount =
    summary?.alerts?.open ??
    filteredAlerts.filter((a) =>
      ["OPEN", "OUVERTE", "IN_REVIEW", "EN_ANALYSE"].includes(
        (a.status || "").toUpperCase()
      )
    ).length;

  const criticalAlerts = useMemo(() => {
    return filteredAlerts.filter(
      (a) => (a.priority || "").toUpperCase() === "CRITICAL"
    );
  }, [filteredAlerts]);

  const criticalCount =
    highRiskSummary?.critical_total != null
      ? highRiskSummary.critical_total
      : criticalAlerts.length;

  const riskyClients = summary?.clients?.risky ?? "—";
  const clientsTotal = summary?.clients?.total ?? "—";

  const priorityQueue = filteredAlerts.slice(0, 10);

  const trendMax = useMemo(() => {
    if (!alertTrend.length) return 1;
    return Math.max(...alertTrend.map((d) => Number(d.alerts) || 0), 1);
  }, [alertTrend]);

  const risks = useMemo(() => {
    return (riskDistribution || []).map((item) => ({
      risk_level: item.label || item.code || item.risk_level || "—",
      code: (item.code || "").toUpperCase(),
      count: item.client_count ?? item.count ?? 0,
      percentage: item.percentage ?? 0,
    }));
  }, [riskDistribution]);

  const todayLabel = new Date().toLocaleDateString("fr-FR", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />

        <section className="page-frame dashboard-v2">
          <header className="page-heading dashboard-heading">
            <div>
              <p className="eyebrow">
                SUPERVISION OPÉRATIONNELLE · {todayLabel.toUpperCase()}
              </p>
              <h1>Tableau de bord</h1>
              <p>
                Alertes, risques et dossiers qui nécessitent une intervention de
                conformité.
              </p>
            </div>
            <div className="dashboard-heading-actions">
              <button
                type="button"
                className="demo-launch"
                onClick={() => navigate("/dashboard?demo=1&step=1")}
              >
                Lancer la démo guidée
              </button>
              <div className="system-live">
                <span className={mlHealth?.success ? "is-ml-online" : "is-ml-offline"} />
                {mlHealth?.success ? "Moteur AML opérationnel" : "Moteur ML indisponible"}
                <small>
                  {mlHealth?.success
                    ? `${mlHealth.data?.model || "Modèle chargé"} · ${mlHealth.data?.feature_count || 0} features`
                    : "Vérifiez le service Python sur le port 8100"}
                </small>
              </div>
            </div>
          </header>

          {error && <div className="dash-error">{error}</div>}

          <AssistCue
            title="Sentinelle Assist — copilote de conformité"
            text="Résumez une alerte prioritaire, expliquez les signaux AML et préparez un brouillon d’analyse. Aide non décisionnelle."
            ctaLabel="Ouvrir l’agent"
            onOpen={() => setAssistOpen(true)}
          />

          {/* Filtres — structure Figma ; filtrage local sur file chargée */}
          <section className="supervision-filters" aria-label="Filtres de supervision">
            <div className="filter-label">PÉRIMÈTRE</div>
            {Object.keys(FILTER_OPTIONS).map((key) => (
              <label key={key}>
                <span>{FILTER_LABELS[key]}</span>
                <select
                  value={filters[key]}
                  onChange={(e) => setFilter(key, e.target.value)}
                >
                  {FILTER_OPTIONS[key].map(([label, value]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </label>
            ))}
            <button
              type="button"
              onClick={() =>
                setFilters({
                  period: "today",
                  caisse: "ALL",
                  agence: "ALL",
                  risk: "ALL",
                  type: "ALL",
                  status: "ALL",
                })
              }
            >
              Réinitialiser
            </button>
          </section>

          {loading ? (
            <div className="dash-loading">Chargement du tableau de bord…</div>
          ) : (
            <>
              <div className="kpi-grid supervision-kpis">
                <article className="kpi-card">
                  <span>CLIENTS SUIVIS</span>
                  <strong>
                    {typeof clientsTotal === "number"
                      ? clientsTotal.toLocaleString("fr-FR")
                      : clientsTotal}
                  </strong>
                  <p>Portefeuille sous supervision</p>
                </article>
                <article className="kpi-card tone-red">
                  <span>ALERTES À TRAITER</span>
                  <strong>{openAlertsCount}</strong>
                  <p>Ouvertes (source dashboard API)</p>
                </article>
                <article className="kpi-card tone-red">
                  <span>ALERTES CRITIQUES</span>
                  <strong>{criticalCount}</strong>
                  <p>Intervention prioritaire requise</p>
                </article>
                <article className="kpi-card tone-amber">
                  <span>CLIENTS À RISQUE ÉLEVÉ</span>
                  <strong>
                    {typeof riskyClients === "number"
                      ? riskyClients.toLocaleString("fr-FR")
                      : riskyClients}
                  </strong>
                  <p>Indicateur portefeuille global</p>
                </article>
              </div>

              {criticalCount > 0 && (
                <div className="critical-work">
                  <strong>
                    {criticalCount} alerte{criticalCount > 1 ? "s" : ""} critique
                    {criticalCount > 1 ? "s" : ""} requiert une action
                  </strong>
                  <div>
                    {criticalAlerts.slice(0, 4).map((alert) => (
                      <button
                        key={alert.id}
                        type="button"
                        onClick={() => navigate(`/alertes/${alert.id}`)}
                      >
                        {alert.reference || `ALT-${alert.id}`} ·{" "}
                        {alert.client_name || alert.customer_name || "Client"}
                      </button>
                    ))}
                  </div>
                  <button type="button" onClick={() => navigate("/alertes")}>
                    Accéder à la file →
                  </button>
                </div>
              )}

              <div className="supervision-grid">
                <article className="dash-panel">
                  <header>
                    <div>
                      <p className="eyebrow">DOSSIERS PRIORITAIRES</p>
                      <h2>File d’intervention</h2>
                    </div>
                    <button type="button" onClick={() => navigate("/alertes")}>
                      Toutes les alertes →
                    </button>
                  </header>
                  <div className="table-scroll">
                    <table className="data-table queue-table">
                      <thead>
                        <tr>
                          <th>ALERTE</th>
                          <th>CLIENT / MOTIF</th>
                          <th>PRIORITÉ</th>
                          <th>STATUT</th>
                        </tr>
                      </thead>
                      <tbody>
                        {priorityQueue.length === 0 ? (
                          <tr>
                            <td className="empty-cell" colSpan={4}>
                              Aucun dossier ouvert dans ce périmètre.
                            </td>
                          </tr>
                        ) : (
                          priorityQueue.map((alert) => (
                            <tr
                              key={alert.id}
                              onClick={() => navigate(`/alertes/${alert.id}`)}
                            >
                              <td className="mono">
                                {alert.reference || `ALT-${alert.id}`}
                                <small>{formatDateTime(alert.created_at)}</small>
                              </td>
                              <td>
                                <strong>
                                  {alert.client_name ||
                                    alert.customer_name ||
                                    "—"}
                                </strong>
                                <small>
                                  {alert.alert_type || alert.title || "—"}
                                </small>
                              </td>
                              <td>
                                <PriorityBadge priority={alert.priority} />
                              </td>
                              <td>
                                <StatusBadge status={alert.status} />
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                  </div>
                </article>

                <article className="dash-panel risk-panel">
                  <header>
                    <div>
                      <p className="eyebrow">EXPOSITION AU RISQUE</p>
                      <h2>Répartition portefeuille</h2>
                    </div>
                    <span className="chart-key">Vue globale</span>
                  </header>
                  <div className="risk-bars">
                    {risks.length === 0 ? (
                      <div className="empty-inline">Aucune donnée disponible</div>
                    ) : (
                      risks.map((risk) => (
                        <div key={risk.code || risk.risk_level}>
                          <div>
                            <span>{risk.risk_level}</span>
                            <strong>{risk.count}</strong>
                          </div>
                          <span>
                            <i style={{ width: `${Math.min(risk.percentage, 100)}%` }} />
                          </span>
                        </div>
                      ))
                    )}
                  </div>
                  <footer>
                    <span>LOW / MEDIUM / HIGH / CRITICAL</span>
                    <button type="button" onClick={() => navigate("/clients")}>
                      Voir clients à risque →
                    </button>
                  </footer>
                </article>
              </div>

              <div className="supervision-grid bottom">
                <article className="dash-panel">
                  <header>
                    <div>
                      <p className="eyebrow">PORTEFEUILLE</p>
                      <h2>Indicateurs clients</h2>
                    </div>
                    <span className="chart-key">API summary</span>
                  </header>
                  <div className="portfolio-stats">
                    <div>
                      <span>Total clients</span>
                      <strong>
                        {typeof clientsTotal === "number"
                          ? clientsTotal.toLocaleString("fr-FR")
                          : clientsTotal}
                      </strong>
                    </div>
                    <div>
                      <span>À risque élevé+</span>
                      <strong>
                        {typeof riskyClients === "number"
                          ? riskyClients.toLocaleString("fr-FR")
                          : riskyClients}
                      </strong>
                    </div>
                    <div>
                      <span>Taux d’exposition</span>
                      <strong>
                        {summary?.clients?.risk_rate != null
                          ? `${summary.clients.risk_rate} %`
                          : "—"}
                      </strong>
                    </div>
                  </div>
                  <footer>
                    <span>Source : /dashboard/summary</span>
                    <button type="button" onClick={() => navigate("/clients")}>
                      Ouvrir les clients →
                    </button>
                  </footer>
                </article>

                <article className="dash-panel chart-panel">
                  <header>
                    <div>
                      <p className="eyebrow">TENDANCE</p>
                      <h2>Évolution des alertes</h2>
                    </div>
                    <span className="chart-key">7 derniers jours · agrégat global</span>
                  </header>
                  <div className="chart-wrap trend-bars-wrap">
                    {alertTrend.length === 0 ? (
                      <div className="empty-inline">Pas encore de données de tendance</div>
                    ) : (
                      <div className="trend-bars">
                        {alertTrend.map((d, i) => (
                          <div key={d.date || i} className="trend-bar-col">
                            <div
                              className="trend-bar"
                              style={{
                                height: `${((Number(d.alerts) || 0) / trendMax) * 100}%`,
                              }}
                              title={`${d.date}: ${d.alerts} alerte(s)`}
                            />
                            <small>
                              {d.date
                                ? String(d.date).slice(5, 10)
                                : String(i + 1)}
                            </small>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                  <footer className="trend-note">
                    Tendance issue de l’API · les filtres locaux n’agrègent pas
                    l’historique global.
                  </footer>
                </article>
              </div>
            </>
          )}
        </section>
      </div>

        <AssistPanel
          objectType="alert"
          objectId={criticalAlerts?.[0]?.id || priorityAlerts?.[0]?.id || null}
          title="Assist — supervision"
          open={assistOpen}
          onOpenChange={setAssistOpen}
        />

    </div>
  );
}
