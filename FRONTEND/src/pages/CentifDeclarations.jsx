import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import {
  getCentifDeclarations,
  updateCentifDeclarationStatus,
} from "../services/api";
import "./CentifDeclarations.css";

const STATUS_OPTS = [
  { label: "Tous statuts", value: "ALL" },
  { label: "Brouillon", value: "DRAFT" },
  { label: "Transmise", value: "TRANSMITTED" },
  { label: "Accusé reçu", value: "ACKNOWLEDGED" },
  { label: "Opposition CENTIF", value: "OPPOSED" },
  { label: "Clôturée", value: "CLOSED" },
];

const STATUS_LABEL = {
  DRAFT: "Brouillon",
  TRANSMITTED: "Transmise",
  ACKNOWLEDGED: "Accusé reçu",
  OPPOSED: "Opposition",
  CLOSED: "Clôturée",
};

function StatusBadge({ status }) {
  const s = (status || "").toUpperCase();
  return (
    <span className={`cd-badge cd-status-${s.toLowerCase()}`}>
      {STATUS_LABEL[s] || status || "—"}
    </span>
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

export default function CentifDeclarations({ user, onLogout }) {
  const navigate = useNavigate();

  const [declarations, setDeclarations] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [statusFilter, setStatusFilter] = useState("ALL");

  // Ligne en cours d'action (transition de statut)
  const [actingId, setActingId] = useState(null);
  const [actingBusy, setActingBusy] = useState(false);
  const [actingError, setActingError] = useState("");
  const [oppositionDate, setOppositionDate] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const params = { limit: 100 };
      if (statusFilter !== "ALL") params.transmission_status = statusFilter;
      const res = await getCentifDeclarations(params);
      if (res?.success) {
        setDeclarations(res.data || []);
      } else {
        setError(res?.message || "Erreur lors du chargement des déclarations.");
      }
    } catch (err) {
      setError(
        err.response?.data?.message ||
          "Impossible de récupérer les déclarations CENTIF."
      );
    } finally {
      setLoading(false);
    }
  }, [statusFilter]);

  /* eslint-disable react-hooks/set-state-in-effect */
  useEffect(() => {
    load();
  }, [load]);
  /* eslint-enable react-hooks/set-state-in-effect */

  const counts = useMemo(() => {
    const c = { DRAFT: 0, TRANSMITTED: 0, OPPOSED: 0, CLOSED: 0 };
    declarations.forEach((d) => {
      const s = (d.transmission_status || "").toUpperCase();
      if (c[s] != null) c[s] += 1;
    });
    return c;
  }, [declarations]);

  async function applyTransition(id, newStatus) {
    if (newStatus === "OPPOSED" && !oppositionDate) {
      setActingError("Indique la date jusqu’à laquelle la CENTIF fait opposition.");
      return;
    }
    setActingBusy(true);
    setActingError("");
    try {
      const res = await updateCentifDeclarationStatus(
        id,
        newStatus,
        newStatus === "OPPOSED" ? oppositionDate : null
      );
      if (res?.success) {
        setActingId(null);
        setOppositionDate("");
        await load();
      } else {
        setActingError(res?.message || "Échec de la mise à jour du statut.");
      }
    } catch (err) {
      setActingError(
        err.response?.data?.message || "Échec de la mise à jour du statut."
      );
    } finally {
      setActingBusy(false);
    }
  }

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />

        <section className="page-frame cd-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">CONFORMITÉ / CENTIF</p>
              <h1>Déclarations de soupçon (DOS)</h1>
              <p>
                Registre des déclarations transmises à la CENTIF — Loi n°2016-008
                du 17 mars 2016 (Mali, LBC/FT/FP).
              </p>
            </div>
          </header>

          {error && <div className="cd-error">{error}</div>}

          <div className="cd-summary-strip">
            {[
              { label: "TOTAL CHARGÉ", value: declarations.length, note: "Registre" },
              { label: "BROUILLONS", value: counts.DRAFT, note: "À transmettre" },
              {
                label: "TRANSMISES",
                value: counts.TRANSMITTED,
                note: "En attente d’accusé",
              },
              {
                label: "OPPOSITION CENTIF",
                value: counts.OPPOSED,
                note: "Art. 15/67",
                danger: true,
              },
              { label: "CLÔTURÉES", value: counts.CLOSED, note: "Cycle terminé" },
            ].map((item) => (
              <div
                key={item.label}
                className={"cd-summary-cell" + (item.danger ? " is-danger" : "")}
              >
                <div className="cd-summary-label">{item.label}</div>
                <div className="cd-summary-value">{item.value}</div>
                <div className="cd-summary-note">{item.note}</div>
              </div>
            ))}
          </div>

          <div className="cd-toolbar">
            <div className="cd-filter-inline">
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
            <p className="cd-hint">
              Une déclaration se crée depuis l’onglet <strong>Actions</strong> d’une
              alerte confirmée (soupçon confirmé). Ce registre gère le cycle de
              transmission.
            </p>
          </div>

          <div className="cd-table-panel">
            {loading ? (
              <div className="cd-loading">Chargement du registre…</div>
            ) : (
              <div className="table-scroll">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>RÉFÉRENCE</th>
                      <th>ALERTE</th>
                      <th>CLIENT</th>
                      <th>DÉCLARANT</th>
                      <th>DATE TRANSMISSION</th>
                      <th>STATUT</th>
                      <th>OPPOSITION JUSQU’AU</th>
                      <th>ACTION</th>
                    </tr>
                  </thead>
                  <tbody>
                    {declarations.length === 0 ? (
                      <tr>
                        <td className="empty-cell" colSpan={8}>
                          Aucune déclaration pour ce périmètre.
                        </td>
                      </tr>
                    ) : (
                      declarations.map((d) => {
                        const status = (d.transmission_status || "").toUpperCase();
                        return (
                          <tr key={d.id}>
                            <td className="mono">{d.reference}</td>
                            <td>
                              {d.alert_id ? (
                                <button
                                  type="button"
                                  className="cd-link"
                                  onClick={() => navigate(`/alertes/${d.alert_id}`)}
                                >
                                  {d.alert_reference || `ALT-${d.alert_id}`}
                                </button>
                              ) : (
                                "—"
                              )}
                            </td>
                            <td>
                              {d.client_id ? (
                                <button
                                  type="button"
                                  className="cd-link"
                                  onClick={() => navigate(`/clients/${d.client_id}`)}
                                >
                                  {d.client_number || `#${d.client_id}`}
                                </button>
                              ) : (
                                "—"
                              )}
                            </td>
                            <td className="cell-muted">
                              {d.declared_by_username || "—"}
                            </td>
                            <td className="cell-muted">
                              {formatDate(d.declaration_date)}
                            </td>
                            <td>
                              <StatusBadge status={d.transmission_status} />
                            </td>
                            <td className="cell-muted">
                              {formatDate(d.centif_opposition_until)}
                            </td>
                            <td>
                              {actingId === d.id ? (
                                <div className="cd-action-cell">
                                  {actingError && (
                                    <div className="cd-action-error">
                                      {actingError}
                                    </div>
                                  )}
                                  {status === "DRAFT" && (
                                    <button
                                      className="cd-btn cd-btn-primary"
                                      disabled={actingBusy}
                                      onClick={() => applyTransition(d.id, "TRANSMITTED")}
                                    >
                                      {actingBusy ? "…" : "Confirmer transmission"}
                                    </button>
                                  )}
                                  {status === "TRANSMITTED" && (
                                    <>
                                      <button
                                        className="cd-btn cd-btn-primary"
                                        disabled={actingBusy}
                                        onClick={() =>
                                          applyTransition(d.id, "ACKNOWLEDGED")
                                        }
                                      >
                                        Accusé reçu
                                      </button>
                                      <input
                                        type="date"
                                        className="cd-date-input"
                                        value={oppositionDate}
                                        onChange={(e) =>
                                          setOppositionDate(e.target.value)
                                        }
                                      />
                                      <button
                                        className="cd-btn cd-btn-secondary"
                                        disabled={actingBusy}
                                        onClick={() => applyTransition(d.id, "OPPOSED")}
                                      >
                                        Opposition
                                      </button>
                                    </>
                                  )}
                                  {(status === "ACKNOWLEDGED" || status === "OPPOSED") && (
                                    <button
                                      className="cd-btn cd-btn-primary"
                                      disabled={actingBusy}
                                      onClick={() => applyTransition(d.id, "CLOSED")}
                                    >
                                      Clôturer
                                    </button>
                                  )}
                                  <button
                                    className="cd-btn cd-btn-ghost"
                                    onClick={() => {
                                      setActingId(null);
                                      setActingError("");
                                    }}
                                  >
                                    Fermer
                                  </button>
                                </div>
                              ) : status === "CLOSED" ? (
                                <span className="cell-muted">—</span>
                              ) : (
                                <button
                                  className="cd-btn cd-btn-secondary"
                                  onClick={() => {
                                    setActingId(d.id);
                                    setActingError("");
                                    setOppositionDate("");
                                  }}
                                >
                                  Faire évoluer
                                </button>
                              )}
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>
            )}
            <div className="cd-footer-note">
              {declarations.length} déclaration{declarations.length !== 1 ? "s" : ""} ·
              API /centif/declarations
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
