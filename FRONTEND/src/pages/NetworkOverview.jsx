import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { getNetwork } from "../services/api";
import "./NetworkOverview.css";

function formatAmount(amount) {
  if (amount == null) return "—";
  const n = Number(amount);
  if (Number.isNaN(n)) return String(amount);
  return n.toLocaleString("fr-FR", { maximumFractionDigits: 0 }) + " FCFA";
}

export default function NetworkOverview({ user, onLogout }) {
  const navigate = useNavigate();
  const [payload, setPayload] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [caisseFilter, setCaisseFilter] = useState("ALL");
  const [agenceFilter, setAgenceFilter] = useState("ALL");

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError("");
      try {
        const res = await getNetwork();
        if (cancelled) return;
        if (res?.success) setPayload(res.data);
        else setError(res?.message || "Erreur réseau.");
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de charger le réseau."
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

  const summary = payload?.summary || {};
  const caisses = useMemo(() => payload?.caisses || [], [payload]);
  const agencies = useMemo(() => payload?.agencies || [], [payload]);

  const caisseOptions = useMemo(() => {
    const names = caisses
      .map((c) => c.name || c.code)
      .filter(Boolean);
    return ["ALL", ...new Set(names)];
  }, [caisses]);

  const filtered = useMemo(() => {
    return agencies.filter((a) => {
      const caisseName = a.caisse_name || a.caisse_code || "";
      const agenceName = a.name || a.code || "";
      if (caisseFilter !== "ALL" && caisseName !== caisseFilter) return false;
      if (agenceFilter !== "ALL" && agenceName !== agenceFilter) return false;
      return true;
    });
  }, [agencies, caisseFilter, agenceFilter]);

  const agenceOptions = useMemo(() => {
    const base =
      caisseFilter === "ALL"
        ? agencies
        : agencies.filter(
            (a) => (a.caisse_name || a.caisse_code) === caisseFilter
          );
    return ["ALL", ...new Set(base.map((a) => a.name || a.code).filter(Boolean))];
  }, [agencies, caisseFilter]);

  const totalAlerts = useMemo(
    () => filtered.reduce((s, a) => s + (Number(a.alert_count) || 0), 0),
    [filtered]
  );
  const totalCritical = useMemo(
    () =>
      filtered.reduce((s, a) => s + (Number(a.critical_alert_count) || 0), 0),
    [filtered]
  );

  const top = useMemo(() => {
    if (!filtered.length) return null;
    return [...filtered].sort(
      (a, b) =>
        (Number(b.alert_count) || 0) - (Number(a.alert_count) || 0) ||
        (Number(b.critical_alert_count) || 0) -
          (Number(a.critical_alert_count) || 0)
    )[0];
  }, [filtered]);

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />

        <section className="page-frame network-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">SUPERVISION / RÉSEAU CIF</p>
              <h1>Réseau caisses et agences</h1>
              <p>
                Vue consolidée des points d’opération · concentration de
                vigilance par agence.
              </p>
            </div>
          </header>

          {error && <div className="nw-error">{error}</div>}

          <div className="nw-toolbar">
            <label>
              <span>CAISSE</span>
              <select
                value={caisseFilter}
                onChange={(e) => {
                  setCaisseFilter(e.target.value);
                  setAgenceFilter("ALL");
                }}
              >
                <option value="ALL">Toutes les caisses</option>
                {caisseOptions
                  .filter((o) => o !== "ALL")
                  .map((o) => (
                    <option key={o} value={o}>
                      {o}
                    </option>
                  ))}
              </select>
            </label>
            <label>
              <span>AGENCE</span>
              <select
                value={agenceFilter}
                onChange={(e) => setAgenceFilter(e.target.value)}
              >
                <option value="ALL">Toutes les agences</option>
                {agenceOptions
                  .filter((o) => o !== "ALL")
                  .map((o) => (
                    <option key={o} value={o}>
                      {o}
                    </option>
                  ))}
              </select>
            </label>
            <span className="nw-count">
              {filtered.length} agence{filtered.length !== 1 ? "s" : ""} dans le
              périmètre
            </span>
          </div>

          <div className="network-chain">
            <div>
              <span>RÉSEAU</span>
              <strong>CIF</strong>
            </div>
            <i>→</i>
            <div>
              <span>CAISSES</span>
              <strong>
                {summary.caisse_count ?? caisses.length ?? "—"}
              </strong>
            </div>
            <i>→</i>
            <div>
              <span>AGENCES</span>
              <strong>
                {summary.agency_count ?? filtered.length ?? "—"}
              </strong>
            </div>
            <i>→</i>
            <div>
              <span>ALERTES</span>
              <strong>{totalAlerts}</strong>
            </div>
            <i>→</i>
            <div className="network-critical">
              <span>CRITIQUES</span>
              <strong>{totalCritical}</strong>
            </div>
          </div>

          <article className="data-panel">
            {loading ? (
              <div className="nw-loading">Chargement du réseau…</div>
            ) : (
              <div className="table-scroll">
                <table className="data-table network-table">
                  <thead>
                    <tr>
                      <th>CAISSE</th>
                      <th>AGENCE</th>
                      <th>CLIENTS</th>
                      <th>TRANSACTIONS</th>
                      <th>VOLUME</th>
                      <th>ALERTES</th>
                      <th>CRITIQUES</th>
                      <th>ACCÈS</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filtered.length === 0 ? (
                      <tr>
                        <td className="empty-cell" colSpan={8}>
                          Aucune agence dans ce périmètre.
                        </td>
                      </tr>
                    ) : (
                      filtered.map((a) => (
                        <tr key={a.id || `${a.caisse_code}-${a.code}`}>
                          <td>
                            <strong>
                              {a.caisse_name || a.caisse_code || "—"}
                            </strong>
                          </td>
                          <td>{a.name || a.code || "—"}</td>
                          <td className="mono">
                            {a.client_count != null ? a.client_count : "—"}
                          </td>
                          <td className="mono">
                            {a.transaction_count != null
                              ? a.transaction_count
                              : "—"}
                          </td>
                          <td className="mono">
                            {formatAmount(
                              a.volume ?? a.total_volume ?? a.transaction_volume
                            )}
                          </td>
                          <td className="mono">
                            {a.alert_count != null ? a.alert_count : "—"}
                          </td>
                          <td className="mono network-critical-text">
                            {a.critical_alert_count != null
                              ? a.critical_alert_count
                              : "—"}
                          </td>
                          <td>
                            <button
                              type="button"
                              className="text-link"
                              onClick={() => navigate("/alertes")}
                            >
                              Superviser →
                            </button>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            )}
            <footer className="table-note">
              L’activité est rattachée au point d’opération ; les clients restent
              rattachés à leur agence d’origine. Source API : /network
            </footer>
          </article>

          <section className="network-focus">
            <header>
              <div>
                <p className="eyebrow">POINT D’ATTENTION</p>
                <h2>Concentration de vigilance</h2>
              </div>
            </header>
            {top ? (
              <div>
                <strong>
                  {top.caisse_name || top.caisse_code} · {top.name || top.code}
                </strong>
                <p>
                  {Number(top.alert_count) || 0} alerte
                  {(Number(top.alert_count) || 0) !== 1 ? "s" : ""} disponible
                  {(Number(top.alert_count) || 0) !== 1 ? "s" : ""}, dont{" "}
                  {Number(top.critical_alert_count) || 0} critique
                  {(Number(top.critical_alert_count) || 0) !== 1 ? "s" : ""}.
                  Examiner ce point d’opération dans la file d’alertes, sans
                  conclure à un risque de l’agence elle-même.
                </p>
                <button
                  type="button"
                  className="text-link"
                  onClick={() => navigate("/alertes")}
                >
                  Ouvrir les alertes →
                </button>
              </div>
            ) : (
              <p>Aucune concentration disponible dans ce périmètre.</p>
            )}
          </section>
        </section>
      </div>
    </div>
  );
}
