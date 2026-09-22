import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import AssistPanel from "../components/AssistPanel";
import AssistCue from "../components/AssistCue";
import "../components/AssistCue.css";
import { getAlerts } from "../services/api";
import "./AlertsList.css";

const STATUS_OPTS = [
  { label: "Toutes", value: "ALL" },
  { label: "Ouvertes", value: "OPEN" },
  { label: "En analyse", value: "IN_REVIEW" },
  { label: "Clôturées", value: "CLOSED" },
  { label: "Écartées", value: "DISMISSED" },
];

const PRIORITY_OPTS = [
  { label: "Tous niveaux", value: "ALL" },
  { label: "Critique", value: "CRITICAL" },
  { label: "Élevée", value: "HIGH" },
  { label: "Moyenne", value: "MEDIUM" },
  { label: "Faible", value: "LOW" },
];

const TYPE_OPTS = [
  { label: "Tous types", value: "ALL" },
  { label: "Montant élevé", value: "LARGE_AMOUNT" },
  { label: "Structuration", value: "STRUCTURING" },
  { label: "Virement rapide", value: "RAPID_TRANSFER" },
  { label: "Volume inhabituel", value: "UNUSUAL_VOLUME" },
  { label: "Corridor à risque", value: "HIGH_RISK_CORRIDOR" },
];

const STATUS_LABEL = {
  OPEN: "Ouverte",
  OUVERTE: "Ouverte",
  IN_REVIEW: "En analyse",
  EN_ANALYSE: "En analyse",
  CLOSED: "Clôturée",
  DISMISSED: "Écartée",
  RESOLVED: "Résolue",
};

const PRIORITY_LABEL = {
  CRITICAL: "Critique",
  HIGH: "Élevée",
  MEDIUM: "Moyenne",
  LOW: "Faible",
};

function alertTypeLabel(type) {
  return (
    {
      LARGE_AMOUNT: "Montant élevé",
      STRUCTURING: "Structuration",
      RAPID_TRANSFER: "Virement rapide",
      UNUSUAL_VOLUME: "Volume inhabituel",
      HIGH_RISK_CORRIDOR: "Corridor à risque",
      AML_RULE_ENGINE: "Moteur de règles",
    }[type] || type || "—"
  );
}

function PriorityBadge({ priority }) {
  const p = (priority || "").toUpperCase();
  const cls =
    { CRITICAL: "risk-critical", HIGH: "risk-high", MEDIUM: "risk-medium", LOW: "risk-low" }[
      p
    ] || "status-muted";
  return (
    <span className={`badge ${cls}`}>{PRIORITY_LABEL[p] || priority || "—"}</span>
  );
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
  return (
    <span className={`badge ${cls}`}>{STATUS_LABEL[s] || status || "—"}</span>
  );
}

function formatDateTime(value) {
  if (!value) return "—";
  try {
    return new Date(value).toLocaleString("fr-FR", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  } catch {
    return String(value);
  }
}

export default function AlertsList({ user, onLogout }) {
  const [assistOpen, setAssistOpen] = useState(false);
  const [focusAlertId, setFocusAlertId] = useState(null);
  const navigate = useNavigate();
  const [alerts, setAlerts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("ALL");
  const [priorityFilter, setPriorityFilter] = useState("ALL");
  const [typeFilter, setTypeFilter] = useState("ALL");

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError("");
      try {
        const params = { limit: 100 };
        if (statusFilter !== "ALL") params.status = statusFilter;
        if (priorityFilter !== "ALL") params.priority = priorityFilter;
        if (typeFilter !== "ALL") params.alert_type = typeFilter;
        if (search.trim()) params.search = search.trim();

        const res = await getAlerts(params);
        if (cancelled) return;
        if (res?.success) setAlerts(res.data || []);
        else setError(res?.message || "Erreur lors du chargement des alertes.");
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de récupérer les alertes."
          );
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    const timer = setTimeout(load, search ? 350 : 0);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [search, statusFilter, priorityFilter, typeFilter]);

  const totalCount = alerts.length;
  const openCount = useMemo(
    () =>
      alerts.filter((a) =>
        ["OPEN", "OUVERTE"].includes((a.status || "").toUpperCase())
      ).length,
    [alerts]
  );
  const reviewCount = useMemo(
    () =>
      alerts.filter((a) =>
        ["IN_REVIEW", "EN_ANALYSE"].includes((a.status || "").toUpperCase())
      ).length,
    [alerts]
  );
  const criticalCount = useMemo(
    () =>
      alerts.filter((a) => (a.priority || "").toUpperCase() === "CRITICAL")
        .length,
    [alerts]
  );

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />

        <section className="page-frame alerts-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">SUPERVISION / ALERTES AML-CFT</p>
              <h1>Centre des alertes</h1>
              <p>
                Moteur de détection LBC/FT/FP · Données live API
              </p>
            </div>
            <button type="button" className="button secondary" disabled title="Export V1.1">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
                <polyline points="7 10 12 15 17 10" />
                <line x1="12" y1="15" x2="12" y2="3" />
              </svg>
              Exporter
            </button>
          </header>

          <AssistCue
            title="Priorisez avec Sentinelle Assist"
            text="Ouvrez une alerte du tableau pour un résumé contextualisé, ou lancez l’agent pour être guidé."
            ctaLabel="Ouvrir Assist"
            onOpen={() => setAssistOpen(true)}
          />

          {error && <div className="al-error">{error}</div>}

          {/* Bandeau résumé — contrat Figma */}
          <div className="al-summary-strip">
            {[
              { label: "TOTAL ALERTES", value: totalCount, note: "Jeu chargé" },
              {
                label: "OUVERTES",
                value: openCount,
                note: "Requiert action",
                accent: true,
              },
              {
                label: "EN ANALYSE",
                value: reviewCount,
                note: "En cours de traitement",
              },
              {
                label: "CRITIQUES",
                value: criticalCount,
                note: "Priorité maximale",
                danger: true,
              },
            ].map((item) => (
              <div
                key={item.label}
                className={
                  "al-summary-cell" +
                  (item.accent ? " is-accent" : "") +
                  (item.danger ? " is-danger" : "")
                }
              >
                <div className="al-summary-label">{item.label}</div>
                <div className="al-summary-value">{item.value}</div>
                <div className="al-summary-note">{item.note}</div>
              </div>
            ))}
          </div>

          {/* Toolbar filtres */}
          <div className="al-toolbar">
            <div className="al-search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="11" cy="11" r="8" />
                <path d="M21 21l-4.35-4.35" />
              </svg>
              <input
                type="text"
                placeholder="Rechercher ID, client, référence…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>

            <div className="al-toolbar-sep" />

            <div className="al-filter-inline">
              <span>STATUT</span>
              <select
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
              >
                {STATUS_OPTS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>

            <div className="al-filter-inline">
              <span>PRIORITÉ</span>
              <select
                value={priorityFilter}
                onChange={(e) => setPriorityFilter(e.target.value)}
              >
                {PRIORITY_OPTS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>

            <div className="al-filter-inline">
              <span>TYPE</span>
              <select
                value={typeFilter}
                onChange={(e) => setTypeFilter(e.target.value)}
              >
                {TYPE_OPTS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>

            <button
              type="button"
              className="al-reset"
              onClick={() => {
                setSearch("");
                setStatusFilter("ALL");
                setPriorityFilter("ALL");
                setTypeFilter("ALL");
              }}
            >
              Réinitialiser
            </button>
          </div>

          {/* Table */}
          <div className="al-table-panel">
            {loading ? (
              <div className="al-loading">Chargement des alertes…</div>
            ) : (
              <div className="table-scroll">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>RÉFÉRENCE</th>
                      <th>CLIENT</th>
                      <th>TYPE</th>
                      <th>PRIORITÉ</th>
                      <th>STATUT</th>
                      <th>DATE</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {alerts.length === 0 ? (
                      <tr>
                        <td className="empty-cell" colSpan={7}>
                          Aucune alerte pour ce périmètre.
                        </td>
                      </tr>
                    ) : (
                      alerts.map((a) => {
                        const isCritical =
                          (a.priority || "").toUpperCase() === "CRITICAL";
                        return (
                          <tr
                            key={a.id}
                            className={isCritical ? "row-critical" : undefined}
                            onClick={() => { setFocusAlertId(Number(a.id)); navigate(`/alertes/${a.id}`); }}
                          >
                            <td className="mono">
                              {a.reference || `ALT-${a.id}`}
                            </td>
                            <td>
                              <strong>
                                {a.client_name || a.customer_name || "—"}
                              </strong>
                              {a.client_number ? (
                                <small>{a.client_number}</small>
                              ) : null}
                            </td>
                            <td>{alertTypeLabel(a.alert_type)}</td>
                            <td>
                              <PriorityBadge priority={a.priority} />
                            </td>
                            <td>
                              <StatusBadge status={a.status} />
                            </td>
                            <td className="cell-muted">
                              {formatDateTime(a.created_at)}
                            </td>
                            <td>
                              <button
                                type="button"
                                className="al-open-btn"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  navigate(`/alertes/${a.id}`);
                                }}
                              >
                                Ouvrir
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
            <div className="al-footer-note">
              {alerts.length} résultat{alerts.length !== 1 ? "s" : ""} · Source
              API /alerts · Ligne rouge = priorité critique
            </div>
          </div>
        </section>

        <AssistPanel
          objectType="alert"
          objectId={focusAlertId}
          title="Assist — file d’alertes"
          open={assistOpen}
          onOpenChange={setAssistOpen}
        />

      </div>
    </div>
  );
}