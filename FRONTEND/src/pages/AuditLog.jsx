import { useEffect, useState } from "react";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { getAuditLog } from "../services/api";
import "./AuditLog.css";

function formatDateTime(v) {
  if (!v) return "—";
  try {
    return new Date(v).toLocaleString("fr-FR", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  } catch {
    return String(v);
  }
}

export default function AuditLog({ user, onLogout }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError("");
      try {
        const params = { limit: 100 };
        if (search.trim()) params.search = search.trim();
        const res = await getAuditLog(params);
        if (cancelled) return;
        if (res?.success) setRows(res.data || []);
        else setError(res?.message || "Erreur audit.");
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de charger le journal d’audit."
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
  }, [search]);

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />
        <section className="page-frame audit-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">CONFORMITÉ / TRAÇABILITÉ</p>
              <h1>Journal d’audit</h1>
              <p>
                Preuve de diligence · actions utilisateurs exposées par l’API
                audit.
              </p>
            </div>
          </header>

          {error && <div className="au-error">{error}</div>}

          <div className="au-toolbar">
            <input
              type="text"
              placeholder="Utilisateur, action, objet…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            <span className="au-count">
              {rows.length} événement{rows.length !== 1 ? "s" : ""}
            </span>
          </div>

          <article className="data-panel">
            {loading ? (
              <div className="au-loading">Chargement du journal…</div>
            ) : (
              <div className="table-scroll">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>DATE</th>
                      <th>UTILISATEUR</th>
                      <th>ACTION</th>
                      <th>OBJET</th>
                      <th>RÉSULTAT</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.length === 0 ? (
                      <tr>
                        <td className="empty-cell" colSpan={5}>
                          Aucun événement d’audit.
                        </td>
                      </tr>
                    ) : (
                      rows.map((r, i) => (
                        <tr key={r.id || i}>
                          <td className="cell-muted">
                            {formatDateTime(
                              r.created_at || r.at || r.timestamp
                            )}
                          </td>
                          <td>
                            <strong>
                              {r.username ||
                                r.user ||
                                r.user_name ||
                                "—"}
                            </strong>
                            {(r.role || r.user_role) && (
                              <small className="sub">
                                {r.role || r.user_role}
                              </small>
                            )}
                          </td>
                          <td>{r.action || r.action_type || "—"}</td>
                          <td className="mono">
                            {r.object_ref ||
                              r.object_type ||
                              r.entity ||
                              "—"}
                          </td>
                          <td>{r.result || r.status || "—"}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            )}
            <footer className="table-note">
              Source API : /audit · mapping défensif des champs renvoyés
            </footer>
          </article>
        </section>
      </div>
    </div>
  );
}
