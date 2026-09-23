import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { getAccounts } from "../services/api";
import { canAccess } from "../auth/access";
import "./AccountsList.css";

const STATUS_OPTS = [
  { label: "Tous statuts", value: "ALL" },
  { label: "Actif", value: "ACTIVE" },
  { label: "Inactif", value: "INACTIVE" },
  { label: "Fermé", value: "CLOSED" },
];

const TYPE_OPTS = [
  { label: "Tous types", value: "ALL" },
  { label: "Courant", value: "CURRENT" },
  { label: "Épargne", value: "SAVINGS" },
  { label: "Crédit", value: "CREDIT" },
];

function RiskBadge({ level }) {
  if (!level) return <span className="cell-muted">—</span>;
  const key = String(level).toUpperCase();
  const label =
    { CRITICAL: "Critique", HIGH: "Élevé", MEDIUM: "Modéré", LOW: "Faible" }[
      key
    ] || level;
  return (
    <span className={`badge risk-${key.toLowerCase()}`}>
      <i />
      {label}
    </span>
  );
}

function StatusBadge({ status }) {
  const s = (status || "").toUpperCase();
  return (
    <span
      className={
        "badge " +
        (s === "ACTIVE" || s === "ACTIF"
          ? "status-open"
          : s === "CLOSED" || s === "FERME"
            ? "status-closed"
            : "status-muted")
      }
    >
      {status || "—"}
    </span>
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

function formatDate(value) {
  if (!value) return "—";
  try {
    return new Date(value).toLocaleDateString("fr-FR", {
      day: "2-digit",
      month: "short",
      year: "numeric",
    });
  } catch {
    return String(value);
  }
}

export default function AccountsList({ user, onLogout }) {
  const navigate = useNavigate();
  const canViewCompliance = canAccess(user, "account.view");
  const [accounts, setAccounts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("ALL");
  const [typeFilter, setTypeFilter] = useState("ALL");

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError("");
      try {
        const params = { limit: 80 };
        if (search.trim()) params.search = search.trim();
        if (statusFilter !== "ALL") params.status = statusFilter;
        if (typeFilter !== "ALL") params.account_type = typeFilter;

        const res = await getAccounts(params);
        if (cancelled) return;
        if (res?.success) setAccounts(res.data || []);
        else setError(res?.message || "Erreur lors du chargement des comptes.");
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de récupérer les comptes."
          );
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    const t = setTimeout(load, search ? 350 : 0);
    return () => {
      cancelled = true;
      clearTimeout(t);
    };
  }, [search, statusFilter, typeFilter]);

  const activeCount = useMemo(
    () =>
      accounts.filter((a) =>
        ["ACTIVE", "ACTIF"].includes(String(a.status || "").toUpperCase())
      ).length,
    [accounts]
  );

  const highRiskCount = useMemo(
    () =>
      accounts.filter((a) =>
        ["HIGH", "CRITICAL"].includes(
          String(a.client_risk_level || a.risk_level || "").toUpperCase()
        )
      ).length,
    [accounts]
  );
  const totalBalance = useMemo(
    () => accounts.reduce((sum, account) => sum + (Number(account.current_balance) || 0), 0),
    [accounts]
  );

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />

        <section className="page-frame accounts-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">DONNÉES / COMPTES</p>
              <h1>Registre des comptes</h1>
              <p>
                {canViewCompliance
                  ? "Comptes rattachés au portefeuille · soldes et statut de tenue."
                  : `Comptes rattachés à ${user?.agency?.name || "votre agence"}.`}
              </p>
            </div>
          </header>

          {error && <div className="ac-error">{error}</div>}

          <div className="ac-summary-strip">
            {[
              {
                label: "COMPTES",
                value: accounts.length,
                note: "Jeu chargé",
              },
              {
                label: "ACTIFS",
                value: activeCount,
                note: "Tenue ouverte",
                accent: true,
              },
              ...(canViewCompliance ? [{
                label: "CLIENT RISQUE ÉLEVÉ+",
                value: highRiskCount,
                note: "HIGH / CRITICAL",
                danger: true,
              }] : [{
                label: "SOLDE CUMULÉ",
                value: formatAmount(totalBalance),
                note: "Comptes affichés",
              }]),
            ].map((item) => (
              <div
                key={item.label}
                className={
                  "ac-summary-cell" +
                  (item.accent ? " is-accent" : "") +
                  (item.danger ? " is-danger" : "")
                }
              >
                <div className="ac-summary-label">{item.label}</div>
                <div className="ac-summary-value">{item.value}</div>
                <div className="ac-summary-note">{item.note}</div>
              </div>
            ))}
          </div>

          <div className="ac-toolbar">
            <div className="ac-search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="11" cy="11" r="8" />
                <path d="M21 21l-4.35-4.35" />
              </svg>
              <input
                type="text"
                placeholder="N° compte, client…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>

            <div className="ac-filter-inline">
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

            <div className="ac-filter-inline">
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
              className="ac-reset"
              onClick={() => {
                setSearch("");
                setStatusFilter("ALL");
                setTypeFilter("ALL");
              }}
            >
              Réinitialiser
            </button>
          </div>

          <div className="ac-table-panel">
            {loading ? (
              <div className="ac-loading">Chargement des comptes…</div>
            ) : (
              <div className="table-scroll">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>COMPTE</th>
                      <th>CLIENT</th>
                      <th>TYPE</th>
                      <th>STATUT</th>
                      {canViewCompliance && <th>RISQUE CLIENT</th>}
                      <th>SOLDE</th>
                      <th>OUVERT LE</th>
                    </tr>
                  </thead>
                  <tbody>
                    {accounts.length === 0 ? (
                      <tr>
                        <td className="empty-cell" colSpan={canViewCompliance ? 7 : 6}>
                          Aucun compte pour ce périmètre.
                        </td>
                      </tr>
                    ) : (
                      accounts.map((a) => {
                        const clientId = a.client_id || a.client?.client_id;
                        return (
                          <tr
                            key={a.id || a.account_id}
                            onClick={() => {
                              if (clientId) navigate(`/clients/${clientId}`);
                            }}
                          >
                            <td className="mono">
                              {a.account_number || a.number || "—"}
                            </td>
                            <td>
                              <strong>
                                {a.customer_name ||
                                  a.client_name ||
                                  a.client?.customer_name ||
                                  "—"}
                              </strong>
                              {a.client_number ? (
                                <small className="sub">
                                  {a.client_number}
                                </small>
                              ) : null}
                            </td>
                            <td>{a.account_type || a.type || "—"}</td>
                            <td>
                              <StatusBadge status={a.status} />
                            </td>
                            {canViewCompliance && <td>
                              <RiskBadge
                                level={
                                  a.client_risk_level ||
                                  a.risk_level ||
                                  a.client?.risk_level
                                }
                              />
                            </td>}
                            <td className="mono">
                              {formatAmount(
                                a.current_balance ?? a.balance,
                                a.currency
                              )}
                            </td>
                            <td className="cell-muted">
                              {formatDate(a.opened_at || a.created_at)}
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>
            )}
            <div className="ac-footer-note">
              {accounts.length} compte{accounts.length !== 1 ? "s" : ""} affiché
              {accounts.length !== 1 ? "s" : ""} · soldes selon la dernière synchronisation
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
