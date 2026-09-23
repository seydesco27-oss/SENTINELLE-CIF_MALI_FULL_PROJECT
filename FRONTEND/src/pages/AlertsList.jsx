import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
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
  return ({
    LARGE_AMOUNT: "Montant élevé",
    STRUCTURING: "Structuration",
    RAPID_TRANSFER: "Virement rapide",
    UNUSUAL_VOLUME: "Volume inhabituel",
    HIGH_RISK_CORRIDOR: "Corridor à risque",
    AML_RULE_ENGINE: "Moteur de règles",
  }[type] || type || "—");
}

function PriorityBadge({ priority }) {
  const normalized = (priority || "").toUpperCase();
  const tone = {
    CRITICAL: "risk-critical",
    HIGH: "risk-high",
    MEDIUM: "risk-medium",
    LOW: "risk-low",
  }[normalized] || "status-muted";
  return <span className={`badge ${tone}`}>{PRIORITY_LABEL[normalized] || priority || "—"}</span>;
}

function StatusBadge({ status }) {
  const normalized = (status || "").toUpperCase();
  const tone = {
    OPEN: "status-open",
    OUVERTE: "status-open",
    IN_REVIEW: "status-review",
    EN_ANALYSE: "status-review",
    CLOSED: "status-closed",
    DISMISSED: "status-muted",
  }[normalized] || "status-muted";
  return <span className={`badge ${tone}`}>{STATUS_LABEL[normalized] || status || "—"}</span>;
}

function DualScore({ aml, ml }) {
  const value = (score) => score == null ? "—" : Number(score).toFixed(0);
  return (
    <div className="al-dual-score" aria-label="Scores AML et ML séparés">
      <span><small>AML</small><strong>{value(aml)}</strong></span>
      <span className="is-ml"><small>ML</small><strong>{value(ml)}</strong></span>
    </div>
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
  const navigate = useNavigate();
  const [alerts, setAlerts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("ALL");
  const [priorityFilter, setPriorityFilter] = useState("ALL");
  const [typeFilter, setTypeFilter] = useState("ALL");
  const [showAdvancedFilters, setShowAdvancedFilters] = useState(false);

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
        const response = await getAlerts(params);
        if (cancelled) return;
        if (response?.success) setAlerts(response.data || []);
        else setError(response?.message || "Erreur lors du chargement des alertes.");
      } catch (requestError) {
        if (!cancelled) {
          setError(requestError.response?.data?.message || "Impossible de récupérer les alertes.");
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
  const openCount = useMemo(() => alerts.filter((alert) => ["OPEN", "OUVERTE"].includes((alert.status || "").toUpperCase())).length, [alerts]);
  const reviewCount = useMemo(() => alerts.filter((alert) => ["IN_REVIEW", "EN_ANALYSE"].includes((alert.status || "").toUpperCase())).length, [alerts]);
  const criticalCount = useMemo(() => alerts.filter((alert) => (alert.priority || "").toUpperCase() === "CRITICAL").length, [alerts]);

  const resetFilters = () => {
    setSearch("");
    setStatusFilter("ALL");
    setPriorityFilter("ALL");
    setTypeFilter("ALL");
  };

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail user={user} />

        <section className="page-frame alerts-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">SUPERVISION / ALERTES AML-CFT</p>
              <h1>Centre des alertes</h1>
              <p>Priorités, signaux et traitement humain dans votre périmètre.</p>
            </div>
            <span className="al-heading-count">{totalCount} dossier{totalCount !== 1 ? "s" : ""}</span>
          </header>

          {error && <div className="al-error">{error}</div>}

          <div className="al-summary-strip">
            {[
              { label: "OUVERTES", value: openCount, note: "Requiert action", accent: true },
              { label: "EN ANALYSE", value: reviewCount, note: "Traitement en cours" },
              { label: "CRITIQUES", value: criticalCount, note: "Priorité maximale", danger: true },
            ].map((item) => (
              <div key={item.label} className={`al-summary-cell${item.accent ? " is-accent" : ""}${item.danger ? " is-danger" : ""}`}>
                <div className="al-summary-label">{item.label}</div>
                <div className="al-summary-value">{item.value}</div>
                <div className="al-summary-note">{item.note}</div>
              </div>
            ))}
          </div>

          <div className="al-toolbar">
            <div className="al-search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="11" cy="11" r="8" /><path d="M21 21l-4.35-4.35" /></svg>
              <input type="text" placeholder="Référence, client, transaction…" value={search} onChange={(event) => setSearch(event.target.value)} />
            </div>
            <div className="al-filter-inline">
              <span>STATUT</span>
              <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
                {STATUS_OPTS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
              </select>
            </div>
            <button type="button" className="al-more" aria-expanded={showAdvancedFilters} onClick={() => setShowAdvancedFilters((current) => !current)}>
              {showAdvancedFilters ? "Masquer" : "Filtres avancés"}
            </button>
            {showAdvancedFilters && <>
              <div className="al-filter-inline">
                <span>PRIORITÉ</span>
                <select value={priorityFilter} onChange={(event) => setPriorityFilter(event.target.value)}>
                  {PRIORITY_OPTS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </select>
              </div>
              <div className="al-filter-inline">
                <span>TYPE</span>
                <select value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)}>
                  {TYPE_OPTS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </select>
              </div>
            </>}
            <button type="button" className="al-reset" onClick={resetFilters}>Réinitialiser</button>
          </div>

          <div className="al-table-panel">
            {loading ? <div className="al-loading">Chargement des alertes…</div> : (
              <div className="table-scroll">
                <table className="data-table">
                  <thead><tr><th>RÉFÉRENCE</th><th>CLIENT / SIGNAL</th><th>SCORES DISTINCTS</th><th>TRAITEMENT</th><th>DATE</th><th /></tr></thead>
                  <tbody>
                    {alerts.length === 0 ? <tr><td className="empty-cell" colSpan={6}>Aucune alerte pour ce périmètre.</td></tr> : alerts.map((alert) => {
                      const isCritical = (alert.priority || "").toUpperCase() === "CRITICAL";
                      return (
                        <tr key={alert.id} className={isCritical ? "row-critical" : undefined} onClick={() => navigate(`/alertes/${alert.id}`)}>
                          <td className="mono">{alert.reference || `ALT-${alert.id}`}<small>{alert.transaction_reference || "Transaction non liée"}</small></td>
                          <td><strong>{alert.client_name || alert.customer_name || "—"}</strong><small>{alert.client_number || "Référence indisponible"}</small><small>{alertTypeLabel(alert.alert_type)}</small></td>
                          <td><DualScore aml={alert.aml_score ?? alert.final_score} ml={alert.ml_score} /></td>
                          <td className="al-treatment"><PriorityBadge priority={alert.priority} /><StatusBadge status={alert.status} /></td>
                          <td className="cell-muted">{formatDateTime(alert.created_at)}</td>
                          <td><button type="button" className="al-open-btn" onClick={(event) => { event.stopPropagation(); navigate(`/alertes/${alert.id}`); }}>Ouvrir</button></td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
            <div className="al-footer-note">{alerts.length} résultat{alerts.length !== 1 ? "s" : ""} · AML et ML sont présentés séparément.</div>
          </div>
        </section>
      </div>
    </div>
  );
}
