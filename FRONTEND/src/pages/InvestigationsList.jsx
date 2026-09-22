import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { getInvestigations } from "../services/api";
import "./InvestigationsList.css";

const STATUS_OPTS = [
  { label: "Toutes", value: "ALL" },
  { label: "Ouvertes", value: "OPEN" },
  { label: "Clôturées", value: "CLOSED" },
];

const PRIORITY_OPTS = [
  { label: "Toutes priorités", value: "ALL" },
  { label: "Critique", value: "CRITICAL" },
  { label: "Élevée", value: "HIGH" },
  { label: "Moyenne", value: "MEDIUM" },
  { label: "Faible", value: "LOW" },
];

const STATUS_LABEL = {
  OPEN: "Ouverte",
  CLOSED: "Clôturée",
};

const PRIORITY_LABEL = {
  CRITICAL: "Critique",
  HIGH: "Élevée",
  MEDIUM: "Moyenne",
  LOW: "Faible",
};

function PriorityBadge({ priority }) {
  const p = (priority || "").toUpperCase();
  return (
    <span className={`badge risk-${(p || "low").toLowerCase()}`}>
      {PRIORITY_LABEL[p] || priority || "—"}
    </span>
  );
}

function StatusBadge({ status }) {
  const s = (status || "").toUpperCase();
  const cls =
    s === "OPEN" || s === "OUVERTE"
      ? "status-open"
      : s === "CLOSED" || s === "CLOTUREE"
        ? "status-closed"
        : "status-muted";
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

export default function InvestigationsList({ user, onLogout }) {
  const navigate = useNavigate();
  const [dossiers, setDossiers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [statusFilter, setStatusFilter] = useState("ALL");
  const [priorityFilter, setPriorityFilter] = useState("ALL");
  const [search, setSearch] = useState("");

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError("");
      try {
        const params = { limit: 80 };
        if (statusFilter !== "ALL") params.status = statusFilter;
        if (search.trim()) params.search = search.trim();

        const res = await getInvestigations(params);
        if (cancelled) return;
        if (res?.success) setDossiers(res.data || []);
        else setError(res?.message || "Erreur lors du chargement.");
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de récupérer les investigations."
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
  }, [statusFilter, search]);

  // Priorité = filtre local (non inventé côté API si non documenté)
  const displayed = useMemo(() => {
    return dossiers.filter((d) => {
      if (priorityFilter === "ALL") return true;
      const p = (
        d.priority ||
        d.alert_priority ||
        d.alert?.priority ||
        ""
      ).toUpperCase();
      return p === priorityFilter;
    });
  }, [dossiers, priorityFilter]);

  const openCount = useMemo(
    () =>
      displayed.filter((d) =>
        ["OPEN", "OUVERTE"].includes(String(d.status || "").toUpperCase())
      ).length,
    [displayed]
  );

  const closedCount = useMemo(
    () =>
      displayed.filter((d) =>
        ["CLOSED", "CLOTUREE"].includes(String(d.status || "").toUpperCase())
      ).length,
    [displayed]
  );

  const criticalCount = useMemo(
    () =>
      displayed.filter(
        (d) =>
          String(d.priority || d.alert_priority || "").toUpperCase() ===
          "CRITICAL"
      ).length,
    [displayed]
  );

  const unassignedCount = useMemo(
    () =>
      displayed.filter(
        (d) => !d.assigned_user && !d.assigned_username && !d.assignee
      ).length,
    [displayed]
  );

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />

        <section className="page-frame investigations-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">SUPERVISION / DOSSIERS D&apos;INVESTIGATION</p>
              <h1>File de travail — Investigations</h1>
              <p>
                Gestion des dossiers conformité · Traçabilité des décisions et
                assignation des analystes
              </p>
            </div>
          </header>

          {error && <div className="iv-error">{error}</div>}

          <div className="iv-summary-strip">
            {[
              {
                label: "DOSSIERS",
                value: displayed.length,
                note: "File chargée",
              },
              {
                label: "OUVERTES",
                value: openCount,
                note: "Actives",
                accent: true,
              },
              {
                label: "À ATTRIBUER",
                value: unassignedCount,
                note: "Sans analyste",
                warn: true,
              },
              {
                label: "CRITIQUES",
                value: criticalCount,
                note: "Priorité max",
                danger: true,
              },
            ].map((item) => (
              <div
                key={item.label}
                className={
                  "iv-summary-cell" +
                  (item.accent ? " is-accent" : "") +
                  (item.warn ? " is-warn" : "") +
                  (item.danger ? " is-danger" : "")
                }
              >
                <div className="iv-summary-label">{item.label}</div>
                <div className="iv-summary-value">{item.value}</div>
                <div className="iv-summary-note">{item.note}</div>
              </div>
            ))}
          </div>

          <div className="iv-toolbar">
            <div className="iv-search-wrap">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="11" cy="11" r="8" />
                <path d="M21 21l-4.35-4.35" />
              </svg>
              <input
                type="text"
                placeholder="Client, dossier, analyste…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>

            <div className="iv-filter-inline">
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

            <div className="iv-filter-inline">
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

            <button
              type="button"
              className="iv-reset"
              onClick={() => {
                setSearch("");
                setStatusFilter("ALL");
                setPriorityFilter("ALL");
              }}
            >
              Réinitialiser
            </button>
          </div>

          <div className="iv-table-panel">
            {loading ? (
              <div className="iv-loading">Chargement des dossiers…</div>
            ) : (
              <div className="table-scroll">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>DOSSIER</th>
                      <th>ALERTE LIÉE</th>
                      <th>CLIENT</th>
                      <th>PRIORITÉ</th>
                      <th>STATUT</th>
                      <th>ANALYSTE</th>
                      <th>OUVERT LE</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {displayed.length === 0 ? (
                      <tr>
                        <td className="empty-cell" colSpan={8}>
                          Aucun dossier pour ce périmètre.
                        </td>
                      </tr>
                    ) : (
                      displayed.map((d) => {
                        const alertId = d.alert_id || d.alert?.id;
                        return (
                          <tr key={d.id}>
                            <td className="mono">
                              {d.reference || `INV-${d.id}`}
                            </td>
                            <td className="mono">
                              {alertId ? (
                                <button
                                  type="button"
                                  className="link-btn"
                                  onClick={() =>
                                    navigate(`/alertes/${alertId}`)
                                  }
                                >
                                  {d.alert_reference || `ALT-${alertId}`}
                                </button>
                              ) : (
                                "—"
                              )}
                            </td>
                            <td>
                              <strong>
                                {d.customer_name ||
                                  d.client_name ||
                                  d.alert?.customer_name ||
                                  "—"}
                              </strong>
                            </td>
                            <td>
                              <PriorityBadge
                                priority={
                                  d.priority ||
                                  d.alert_priority ||
                                  d.alert?.priority
                                }
                              />
                            </td>
                            <td>
                              <StatusBadge status={d.status} />
                            </td>
                            <td>
                              {d.assigned_username ||
                                d.assignee ||
                                (d.assigned_user
                                  ? `User #${d.assigned_user}`
                                  : "—")}
                            </td>
                            <td className="cell-muted">
                              {formatDateTime(d.opened_at || d.created_at)}
                            </td>
                            <td>
                              {alertId ? (
                                <button
                                  type="button"
                                  className="open-btn"
                                  onClick={() =>
                                    navigate(`/alertes/${alertId}`)
                                  }
                                >
                                  Ouvrir
                                </button>
                              ) : null}
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>
            )}
            <div className="iv-footer-note">
              {displayed.length} dossier{displayed.length !== 1 ? "s" : ""} ·
              API /investigations · priorité = filtre local · clôturées :{" "}
              {closedCount}
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
