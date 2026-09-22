import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { getAlertDetail } from "../services/api";
import AssistPanel from "../components/AssistPanel";
import "./AlertDetail.css";

const PRIORITY_LABEL = {
  CRITICAL: "Critique",
  HIGH: "Élevée",
  MEDIUM: "Moyenne",
  LOW: "Faible",
};

const STATUS_LABEL = {
  OPEN: "Ouverte",
  OUVERTE: "Ouverte",
  IN_REVIEW: "En analyse",
  EN_ANALYSE: "En analyse",
  CLOSED: "Clôturée",
  CLOTUREE: "Clôturée",
  DISMISSED: "Écartée",
  RESOLVED: "Résolue",
};

const RISK_LABEL = {
  CRITICAL: "Critique",
  HIGH: "Élevé",
  MEDIUM: "Moyen",
  LOW: "Faible",
};

const TABS = ["Résumé", "Client", "Transaction", "Risque", "Investigation", "Actions"];

function alertTypeLabel(type) {
  return (
    {
      LARGE_AMOUNT: "Montant élevé",
      STRUCTURING: "Structuration",
      RAPID_TRANSFER: "Virement rapide",
      UNUSUAL_VOLUME: "Volume inhabituel",
      HIGH_RISK_CORRIDOR: "Corridor à risque",
      AML_RULE_ENGINE: "Moteur de règles",
    }[type] || type || "Alerte"
  );
}

function PriorityBadge({ priority }) {
  const p = (priority || "").toUpperCase();
  return (
    <span className={`badge badge-priority-${p.toLowerCase()}`}>
      {PRIORITY_LABEL[p] || priority || "—"}
    </span>
  );
}

function StatusBadge({ status }) {
  const s = (status || "").toUpperCase();
  return (
    <span className={`badge badge-status-${s.toLowerCase()}`}>
      {STATUS_LABEL[s] || status || "—"}
    </span>
  );
}

function RiskDot({ level }) {
  const l = (level || "").toUpperCase();
  return (
    <span className={`risk-dot risk-dot-${l.toLowerCase()}`}>
      <span className="risk-dot-circle" />
      {RISK_LABEL[l] || level || "—"}
    </span>
  );
}

export default function AlertDetail({ user, onLogout }) {
  const { id } = useParams();
  const navigate = useNavigate();

  const [activeTab, setActiveTab] = useState("Résumé");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [payload, setPayload] = useState(null);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError("");
      setPayload(null);

      try {
        const res = await getAlertDetail(id);
        if (cancelled) return;

        if (res?.success && res.data) {
          setPayload(res.data);
        } else {
          setError(res?.message || "Alerte introuvable.");
        }
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de charger le détail de l’alerte."
          );
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    if (id) load();
    return () => {
      cancelled = true;
    };
  }, [id]);

  const alert = payload?.alert || null;
  const actions = payload?.actions || [];
  const investigations = payload?.investigations || [];
  const riskAssessments = payload?.risk_assessments || [];
  const mainRisk = riskAssessments[0] || null;

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />

      <div className="app-main">
        <DemoRail />
        <div className="alert-detail-content">
          {/* Fil d'ariane */}
          <div className="breadcrumb">
            <a
              href="/alertes"
              onClick={(e) => {
                e.preventDefault();
                navigate("/alertes");
              }}
            >
              Alertes
            </a>
            <span className="breadcrumb-sep">›</span>
            <span className="breadcrumb-current">
              Alerte {alert?.reference || `#${id}`}
            </span>
          </div>

          {loading && (
            <div style={{ padding: "40px", textAlign: "center", color: "#64748b" }}>
              Chargement du détail de l’alerte…
            </div>
          )}

          {error && !loading && (
            <div
              style={{
                background: "#fef2f2",
                border: "1px solid #fecaca",
                color: "#b91c1c",
                padding: "16px",
                borderRadius: "8px",
                marginTop: "16px",
              }}
            >
              {error}
              <div style={{ marginTop: 12 }}>
                <button className="btn btn-secondary" onClick={() => navigate("/alertes")}>
                  Retour à la liste
                </button>
              </div>
            </div>
          )}

          {!loading && !error && alert && (
            <>
              {/* En-tête carte alerte */}
              <div className="alert-header-card">
                <div className="alert-header-top">
                  <span className="alert-code">
                    {alert.reference || `ALT-${alert.id}`}
                  </span>
                  <div className="alert-header-badges">
                    <PriorityBadge priority={alert.priority} />
                    <StatusBadge status={alert.status} />
                  </div>
                </div>
                <h1>{alert.title || alertTypeLabel(alert.alert_type)}</h1>
                <div className="alert-header-meta">
                  Créée le{" "}
                  {alert.created_at
                    ? new Date(alert.created_at).toLocaleDateString("fr-FR", {
                        day: "2-digit",
                        month: "long",
                        year: "numeric",
                      })
                    : "—"}
                  {alert.created_at &&
                    `, ${new Date(alert.created_at).toLocaleTimeString("fr-FR", {
                      hour: "2-digit",
                      minute: "2-digit",
                    })}`}
                  {alert.final_score != null && (
                    <> · Score final : <strong>{Number(alert.final_score).toFixed(0)}</strong></>
                  )}
                </div>
                {alert.description && (
                  <p style={{ marginTop: 12, color: "#475569", lineHeight: 1.5 }}>
                    {alert.description}
                  </p>
                )}
              </div>

              {/* Onglets */}
              <div className="tabs-bar">
                {TABS.map((tab) => (
                  <button
                    key={tab}
                    className={`tab-btn ${activeTab === tab ? "tab-active" : ""}`}
                    onClick={() => setActiveTab(tab)}
                  >
                    {tab}
                  </button>
                ))}
              </div>

              {/* Onglet Résumé */}
              {activeTab === "Résumé" && (
                <>
                  <div className="detail-grid">
                    <div className="panel">
                      <div className="panel-eyebrow">SYNTHÈSE</div>
                      <div className="summary-row">
                        <span className="summary-label">Type d’alerte</span>
                        <span className="summary-value">
                          {alertTypeLabel(alert.alert_type)}
                        </span>
                      </div>
                      <div className="summary-row">
                        <span className="summary-label">Priorité</span>
                        <PriorityBadge priority={alert.priority} />
                      </div>
                      <div className="summary-row">
                        <span className="summary-label">Statut</span>
                        <StatusBadge status={alert.status} />
                      </div>
                      <div className="summary-row">
                        <span className="summary-label">Score final</span>
                        <span className="summary-value summary-value-score">
                          {alert.final_score != null
                            ? Number(alert.final_score).toFixed(0)
                            : "—"}
                        </span>
                      </div>
                      {mainRisk && (
                        <>
                          <div className="summary-row">
                            <span className="summary-label">Niveau de risque</span>
                            <RiskDot level={mainRisk.risk_level} />
                          </div>
                          <div className="summary-row">
                            <span className="summary-label">Score évaluation</span>
                            <span className="summary-value summary-value-score">
                              {mainRisk.score}
                            </span>
                          </div>
                        </>
                      )}
                    </div>

                    <div className="panel">
                      <div className="panel-eyebrow">RAISON DOCUMENTÉE</div>
                      <p className="reason-text">
                        {mainRisk?.reason ||
                          alert.description ||
                          "Aucune raison détaillée disponible."}
                      </p>
                      {mainRisk?.source && (
                        <div className="source-box">
                          <div className="source-label">SOURCE</div>
                          <div className="source-value">
                            {String(mainRisk.source).toUpperCase()}
                          </div>
                        </div>
                      )}
                    </div>
                  </div>

                  {riskAssessments.length > 0 && (
                    <div className="panel">
                      <div className="panel-eyebrow">
                        ÉVALUATIONS DE RISQUE ASSOCIÉES
                      </div>
                      <table className="risk-table">
                        <thead>
                          <tr>
                            <th>TYPE</th>
                            <th>SCORE</th>
                            <th>NIVEAU</th>
                            <th>DATE</th>
                          </tr>
                        </thead>
                        <tbody>
                          {riskAssessments.map((r) => (
                            <tr key={r.id}>
                              <td className="cell-strong">
                                {r.risk_type || "RULE_BASED"}
                              </td>
                              <td className="cell-strong">{r.score}</td>
                              <td>
                                <RiskDot level={r.risk_level} />
                              </td>
                              <td className="cell-sub">
                                {r.created_at
                                  ? new Date(r.created_at).toLocaleString(
                                      "fr-FR",
                                      {
                                        day: "2-digit",
                                        month: "long",
                                        year: "numeric",
                                        hour: "2-digit",
                                        minute: "2-digit",
                                      }
                                    )
                                  : "—"}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                    
                </>
              )}

              {/* Onglet Client */}
              {activeTab === "Client" && (
                <div className="panel">
                  <div className="panel-eyebrow">CLIENT LIÉ</div>
                  <div className="summary-row">
                    <span className="summary-label">Nom</span>
                    <span className="summary-value">
                      {alert.client_name || "—"}
                    </span>
                  </div>
                  <div className="summary-row">
                    <span className="summary-label">N° client</span>
                    <span className="summary-value">
                      {alert.client_number || "—"}
                    </span>
                  </div>
                  <div className="summary-row">
                    <span className="summary-label">Type</span>
                    <span className="summary-value">
                      {alert.client_type || "—"}
                    </span>
                  </div>
                  <div className="summary-row">
                    <span className="summary-label">PEP</span>
                    <span className="summary-value">
                      {Number(alert.is_pep) === 1 ? "Oui" : "Non"}
                    </span>
                  </div>
                  <div className="summary-row">
                    <span className="summary-label">Score risque client</span>
                    <span className="summary-value">
                      {alert.risk_score != null
                        ? Number(alert.risk_score).toFixed(0)
                        : "—"}
                    </span>
                  </div>
                  {alert.client_id && (
                    <div style={{ marginTop: 16 }}>
                      <button
                        className="btn btn-primary"
                        onClick={() => navigate(`/clients/${alert.client_id}`)}
                      >
                        Voir la fiche client →
                      </button>
                    </div>
                  )}
                </div>
              )}

              {/* Onglet Transaction */}
              {activeTab === "Transaction" && (
                <div className="panel">
                  <div className="panel-eyebrow">TRANSACTION LIÉE</div>
                  <div className="summary-row">
                    <span className="summary-label">Référence</span>
                    <span className="summary-value">
                      {alert.transaction_reference || "—"}
                    </span>
                  </div>
                  <div className="summary-row">
                    <span className="summary-label">Type</span>
                    <span className="summary-value">
                      {alert.transaction_type || "—"}
                    </span>
                  </div>
                  <div className="summary-row">
                    <span className="summary-label">Montant</span>
                    <span className="summary-value">
                      {alert.amount != null
                        ? `${Number(alert.amount).toLocaleString("fr-FR")} ${
                            alert.currency || "FCFA"
                          }`
                        : "—"}
                    </span>
                  </div>
                  <div className="summary-row">
                    <span className="summary-label">Canal</span>
                    <span className="summary-value">
                      {alert.channel || "—"}
                    </span>
                  </div>
                  <div className="summary-row">
                    <span className="summary-label">Date</span>
                    <span className="summary-value">
                      {alert.transaction_date
                        ? new Date(alert.transaction_date).toLocaleString(
                            "fr-FR"
                          )
                        : "—"}
                    </span>
                  </div>
                  {alert.transaction_id && (
                    <div style={{ marginTop: 16 }}>
                      <button
                        className="btn btn-primary"
                        onClick={() =>
                          navigate(`/transactions/${alert.transaction_id}`)
                        }
                      >
                        Voir la transaction →
                      </button>
                    </div>
                  )}
                </div>
              )}

              {/* Onglet Risque */}
              {activeTab === "Risque" && (
                <div className="panel">
                  <div className="panel-eyebrow">ÉVALUATIONS DE RISQUE</div>
                  {riskAssessments.length === 0 ? (
                    <p style={{ color: "#64748b" }}>
                      Aucune évaluation de risque associée.
                    </p>
                  ) : (
                    <table className="risk-table">
                      <thead>
                        <tr>
                          <th>TYPE</th>
                          <th>SCORE</th>
                          <th>NIVEAU</th>
                          <th>RAISON</th>
                          <th>SOURCE</th>
                          <th>DATE</th>
                        </tr>
                      </thead>
                      <tbody>
                        {riskAssessments.map((r) => (
                          <tr key={r.id}>
                            <td className="cell-strong">
                              {r.risk_type || "—"}
                            </td>
                            <td className="cell-strong">{r.score}</td>
                            <td>
                              <RiskDot level={r.risk_level} />
                            </td>
                            <td className="cell-sub">{r.reason || "—"}</td>
                            <td className="cell-sub">
                              {r.source || "—"}
                            </td>
                            <td className="cell-sub">
                              {r.created_at
                                ? new Date(r.created_at).toLocaleString(
                                    "fr-FR"
                                  )
                                : "—"}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              )}

              {/* Onglet Investigation */}
              {activeTab === "Investigation" && (
                <div className="panel">
                  <div className="panel-eyebrow">INVESTIGATIONS</div>
                  {investigations.length === 0 ? (
                    <p style={{ color: "#64748b" }}>
                      Aucune investigation ouverte sur cette alerte.
                    </p>
                  ) : (
                    <table className="risk-table">
                      <thead>
                        <tr>
                          <th>ID</th>
                          <th>ASSIGNÉ</th>
                          <th>DÉCISION</th>
                          <th>COMMENTAIRE</th>
                          <th>DÉBUT</th>
                          <th>CLÔTURE</th>
                        </tr>
                      </thead>
                      <tbody>
                        {investigations.map((inv) => (
                          <tr key={inv.id}>
                            <td className="cell-strong">{inv.id}</td>
                            <td>
                              {inv.assigned_username || "—"}
                            </td>
                            <td>{inv.decision || "—"}</td>
                            <td className="cell-sub">
                              {inv.comment || "—"}
                            </td>
                            <td className="cell-sub">
                              {inv.started_at
                                ? new Date(inv.started_at).toLocaleString(
                                    "fr-FR"
                                  )
                                : "—"}
                            </td>
                            <td className="cell-sub">
                              {inv.closed_at
                                ? new Date(inv.closed_at).toLocaleString(
                                    "fr-FR"
                                  )
                                : "—"}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              )}

              {/* Onglet Actions */}
              {activeTab === "Actions" && (
                <div className="panel">
                  <div className="panel-eyebrow">HISTORIQUE DES ACTIONS</div>
                  {actions.length === 0 ? (
                    <p style={{ color: "#64748b" }}>
                      Aucune action enregistrée sur cette alerte.
                    </p>
                  ) : (
                    <table className="risk-table">
                      <thead>
                        <tr>
                          <th>DATE</th>
                          <th>UTILISATEUR</th>
                          <th>TYPE</th>
                          <th>COMMENTAIRE</th>
                        </tr>
                      </thead>
                      <tbody>
                        {actions.map((act) => (
                          <tr key={act.id}>
                            <td className="cell-sub">
                              {act.created_at
                                ? new Date(act.created_at).toLocaleString(
                                    "fr-FR"
                                  )
                                : "—"}
                            </td>
                            <td>{act.username || "—"}</td>
                            <td className="cell-strong">
                              {act.action_type || "—"}
                            </td>
                            <td className="cell-sub">
                              {act.comment || "—"}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
                
              )}

              {/* Sentinelle Assist — tiroir droit (hors onglets) */}
              <AssistPanel
                objectType="alert"
                objectId={Number(id)}
                transactionId={alert?.transaction_id ? Number(alert.transaction_id) : null}
                title="Assist — cette alerte"
              />

            </>
          )}
        </div>
      </div>
    </div>
  );
}
