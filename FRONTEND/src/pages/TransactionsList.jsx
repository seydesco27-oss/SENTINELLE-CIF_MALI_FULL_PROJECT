import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { getTransactions } from "../services/api";
import { canAccess } from "../auth/access";
import "./TransactionsList.css";

const TYPE_OPTS = [
  { label: "Tous types", value: "ALL" },
  { label: "Virement", value: "VIREMENT" },
  { label: "Dépôt espèces", value: "DEPOT_ESPECES" },
  { label: "Retrait", value: "RETRAIT" },
  { label: "Remise chèque", value: "REMISE_CHEQUE" },
  { label: "Transfert", value: "TRANSFER" },
];

const RISK_OPTS = [
  { label: "Tous niveaux", value: "ALL" },
  { label: "Critique", value: "CRITICAL" },
  { label: "Élevé", value: "HIGH" },
  { label: "Modéré", value: "MEDIUM" },
  { label: "Faible", value: "LOW" },
];

const ALERT_OPTS = [
  { label: "Toutes", value: "ALL" },
  { label: "Avec alerte", value: "WITH" },
  { label: "Sans alerte", value: "WITHOUT" },
];

function riskLevelFromScore(score, level) {
  if (level) return String(level).toUpperCase();
  if (score == null) return null;
  const s = Number(score);
  if (s >= 80) return "CRITICAL";
  if (s >= 60) return "HIGH";
  if (s >= 40) return "MEDIUM";
  return "LOW";
}

function RiskBadge({ score, level }) {
  const key = riskLevelFromScore(score, level) || "LOW";
  const label =
    { CRITICAL: "Critique", HIGH: "Élevé", MEDIUM: "Modéré", LOW: "Faible" }[
      key
    ] || key;
  return (
    <span className={`badge risk-${key.toLowerCase()}`}>
      <i />
      {label}
      {score != null ? ` (${Number(score).toFixed(0)})` : ""}
    </span>
  );
}

function transactionTypeLabel(type) {
  return (
    {
      VIREMENT: "Virement",
      DEPOT_ESPECES: "Dépôt espèces",
      RETRAIT: "Retrait",
      REMISE_CHEQUE: "Remise chèque",
      TRANSFER: "Transfert",
      TRANSFER_IN: "Transfert entrant",
      TRANSFER_OUT: "Transfert sortant",
    }[type] || type || "—"
  );
}

function formatAmount(amount, currency = "XOF") {
  if (amount == null) return "—";
  const n = Number(amount);
  if (Number.isNaN(n)) return String(amount);
  return (
    n.toLocaleString("fr-FR", { maximumFractionDigits: 0 }) +
    " " +
    (currency === "XOF" || !currency ? "FCFA" : currency)
  );
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

export default function TransactionsList({ user, onLogout }) {
  const navigate = useNavigate();
  const canViewCompliance = canAccess(user, "risk_analysis");
  const [transactions, setTransactions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [typeFilter, setTypeFilter] = useState("ALL");
  // Filtres UI locaux (non envoyés si non supportés explicitement par l'API)
  const [riskFilter, setRiskFilter] = useState("ALL");
  const [alertFilter, setAlertFilter] = useState("ALL");

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError("");
      try {
        const params = { limit: 100 };
        if (search.trim()) params.search = search.trim();
        if (typeFilter !== "ALL") params.transaction_type = typeFilter;

        const res = await getTransactions(params);
        if (cancelled) return;
        if (res?.success) setTransactions(res.data || []);
        else
          setError(
            res?.message || "Erreur lors du chargement des transactions."
          );
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de récupérer les transactions."
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
  }, [search, typeFilter]);

  const displayed = useMemo(() => {
    return transactions.filter((t) => {
      if (canViewCompliance && riskFilter !== "ALL") {
        const lvl = riskLevelFromScore(t.risk_score, t.risk_level || t.aml_risk_level);
        if (lvl !== riskFilter) return false;
      }
      if (canViewCompliance && alertFilter === "WITH") {
        if (!(t.has_alert || t.alert_id || Number(t.alert_count) > 0))
          return false;
      }
      if (canViewCompliance && alertFilter === "WITHOUT") {
        if (t.has_alert || t.alert_id || Number(t.alert_count) > 0)
          return false;
      }
      return true;
    });
  }, [transactions, riskFilter, alertFilter, canViewCompliance]);

  const highRiskCount = useMemo(
    () =>
      displayed.filter((t) => {
        const lvl = riskLevelFromScore(
          t.risk_score,
          t.risk_level || t.aml_risk_level
        );
        return lvl === "HIGH" || lvl === "CRITICAL";
      }).length,
    [displayed]
  );

  const withAlertCount = useMemo(
    () =>
      displayed.filter(
        (t) => t.has_alert || t.alert_id || Number(t.alert_count) > 0
      ).length,
    [displayed]
  );

  const volume = useMemo(
    () => displayed.reduce((s, t) => s + (Number(t.amount) || 0), 0),
    [displayed]
  );

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />

        <section className="page-frame transactions-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">DONNÉES / FLUX TRANSACTIONNEL</p>
              <h1>Transactions</h1>
              <p>
                {canViewCompliance
                  ? "Historique des opérations évaluées par le moteur AML."
                  : `Historique des opérations de ${user?.agency?.name || "votre agence"}.`}
              </p>
            </div>
          </header>

          {error && <div className="tl-error">{error}</div>}

          <div className="tl-summary-strip">
            {[
              {
                label: "TRANSACTIONS",
                value: displayed.length,
                note: "Jeu filtré",
              },
              ...(canViewCompliance ? [{
                label: "RISQUE ÉLEVÉ+",
                value: highRiskCount,
                note: "HIGH / CRITICAL",
                danger: true,
              }, {
                label: "AVEC ALERTE",
                value: withAlertCount,
                note: "Signal AML lié",
                warn: true,
              }] : []),
              {
                label: "VOLUME AFFICHÉ",
                value: formatAmount(volume),
                note: "Somme montants",
              },
            ].map((item) => (
              <div
                key={item.label}
                className={
                  "tl-summary-cell" +
                  (item.danger ? " is-danger" : "") +
                  (item.warn ? " is-warn" : "")
                }
              >
                <div className="tl-summary-label">{item.label}</div>
                <div className="tl-summary-value">{item.value}</div>
                <div className="tl-summary-note">{item.note}</div>
              </div>
            ))}
          </div>

          <div className="tl-toolbar">
            <div className="tl-search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="11" cy="11" r="8" />
                <path d="M21 21l-4.35-4.35" />
              </svg>
              <input
                type="text"
                placeholder="Réf., client, compte…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>

            <div className="tl-filter-inline">
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

            {canViewCompliance && <div className="tl-filter-inline">
              <span>RISQUE</span>
              <select
                value={riskFilter}
                onChange={(e) => setRiskFilter(e.target.value)}
              >
                {RISK_OPTS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>}

            {canViewCompliance && <div className="tl-filter-inline">
              <span>ALERTE</span>
              <select
                value={alertFilter}
                onChange={(e) => setAlertFilter(e.target.value)}
              >
                {ALERT_OPTS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>}

            <button
              type="button"
              className="tl-reset"
              onClick={() => {
                setSearch("");
                setTypeFilter("ALL");
                setRiskFilter("ALL");
                setAlertFilter("ALL");
              }}
            >
              Réinitialiser
            </button>
          </div>

          <div className="tl-table-panel">
            {loading ? (
              <div className="tl-loading">Chargement des transactions…</div>
            ) : (
              <div className="table-scroll">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>RÉFÉRENCE</th>
                      <th>CLIENT</th>
                      <th>TYPE</th>
                      <th>MONTANT</th>
                      <th>CANAL</th>
                      <th>STATUT</th>
                      {canViewCompliance && <th>RISQUE</th>}
                      <th>DATE</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {displayed.length === 0 ? (
                      <tr>
                        <td className="empty-cell" colSpan={canViewCompliance ? 9 : 8}>
                          Aucune transaction pour ce périmètre.
                        </td>
                      </tr>
                    ) : (
                      displayed.map((t) => (
                        <tr
                          key={t.id}
                          onClick={() => navigate(`/transactions/${t.id}`)}
                        >
                          <td className="mono">
                            {t.transaction_reference ||
                              t.reference ||
                              `TX-${t.id}`}
                          </td>
                          <td>
                            <button
                              type="button"
                              className="client-link"
                              onClick={(e) => {
                                e.stopPropagation();
                                if (t.client_id)
                                  navigate(`/clients/${t.client_id}`);
                              }}
                            >
                              {t.client_name ||
                                t.customer_name ||
                                t.client_number ||
                                "—"}
                            </button>
                          </td>
                          <td>{transactionTypeLabel(t.transaction_type || t.type)}</td>
                          <td className="mono">
                            {formatAmount(t.amount, t.currency)}
                          </td>
                          <td className="cell-muted">{t.channel || "—"}</td>
                          <td>
                            <span className="status-pill">
                              {t.transaction_status || t.status || "—"}
                            </span>
                          </td>
                          {canViewCompliance && <td>
                            <RiskBadge
                              score={t.risk_score ?? t.aml_risk_score}
                              level={t.risk_level || t.aml_risk_level}
                            />
                          </td>}
                          <td className="cell-muted">
                            {formatDateTime(
                              t.transaction_date || t.created_at
                            )}
                          </td>
                          <td>
                            <button
                              type="button"
                              className="open-btn"
                              onClick={(e) => {
                                e.stopPropagation();
                                navigate(`/transactions/${t.id}`);
                              }}
                            >
                              Ouvrir
                            </button>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            )}
            <div className="tl-footer-note">
              {displayed.length} résultat{displayed.length !== 1 ? "s" : ""} affiché
              {displayed.length !== 1 ? "s" : ""}
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
