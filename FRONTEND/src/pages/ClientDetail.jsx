import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { getClientDetail } from "../services/api";
import AssistPanel from "../components/AssistPanel";
import { canAccess } from "../auth/access";
import "./ClientDetail.css";

function RiskPill({ level, score }) {
  let lvl = (level || "").toUpperCase();
  if (!lvl && score != null) {
    const s = Number(score);
    if (s >= 80) lvl = "CRITICAL";
    else if (s >= 60) lvl = "HIGH";
    else if (s >= 40) lvl = "MEDIUM";
    else lvl = "LOW";
  }
  const label =
    { HIGH: "Élevé", CRITICAL: "Critique", MEDIUM: "Moyen", LOW: "Faible" }[
      lvl
    ] || lvl || "—";
  return (
    <span className={`risk-pill risk-pill-${(lvl || "low").toLowerCase()}`}>
      <span className="risk-pill-dot" />
      {label}
    </span>
  );
}

function SectionHeader({ number, title, note }) {
  return (
    <div className="section-header-top">
      <span className="section-number">{number}</span>
      <div>
        <h2>{title}</h2>
        {note ? <p>{note}</p> : null}
      </div>
    </div>
  );
}

function formatAmount(amount, currency = "XOF") {
  if (amount == null) return "—";
  const n = Number(amount);
  if (Number.isNaN(n)) return String(amount);
  return (
    n.toLocaleString("fr-FR", { maximumFractionDigits: 0 }) +
    " " +
    (currency || "FCFA")
  );
}

function formatDateTime(value) {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleString("fr-FR", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function formatDate(value) {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString("fr-FR", {
    day: "2-digit",
    month: "long",
    year: "numeric",
  });
}

function labelFromCode(value, labels = {}) {
  if (!value) return "—";
  const code = String(value).toUpperCase();
  return labels[code] || code.replaceAll("_", " ").toLowerCase().replace(/^./, (c) => c.toUpperCase());
}

const transactionTypeLabels = {
  DEPOSIT: "Dépôt",
  DEPOT: "Dépôt",
  DEPOT_ESPECES: "Dépôt d’espèces",
  WITHDRAWAL: "Retrait",
  RETRAIT: "Retrait",
  TRANSFER: "Virement",
  VIREMENT: "Virement",
  TRANSFER_IN: "Virement entrant",
  TRANSFER_OUT: "Virement sortant",
  PAYMENT: "Paiement",
  CASH_IN: "Versement d’espèces",
  CASH_OUT: "Retrait d’espèces",
};

const statusLabels = {
  ACTIVE: "Actif",
  INACTIVE: "Inactif",
  OPEN: "Ouverte",
  CLOSED: "Clôturée",
  COMPLETED: "Terminée",
  PENDING: "En attente",
  FAILED: "Échouée",
  BLOCKED: "Bloqué",
  SUSPENDED: "Suspendu",
  IN_REVIEW: "En analyse",
  EN_ANALYSE: "En analyse",
};

const alertPriorityLabels = {
  LOW: "Faible",
  MEDIUM: "Moyenne",
  HIGH: "Élevée",
  CRITICAL: "Critique",
};

function clientTypeLabel(type) {
  return labelFromCode(type, {
    INDIVIDUAL: "Particulier",
    ENTITY: "Entreprise",
    UNKNOWN: "Non renseigné",
  });
}

function getInitials(name) {
  return String(name || "CL")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join("")
    .toUpperCase();
}

export default function ClientDetail({ user, onLogout }) {
  const { id } = useParams();
  const navigate = useNavigate();

  const [activeTab, setActiveTab] = useState("Dossier");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [payload, setPayload] = useState(null);
  const canViewRisk = canAccess(user, "risk_analysis");
  const canViewAlerts = canAccess(user, "alerts");
  const canUseAssist = canAccess(user, "assist");
  const tabs = [
    "Dossier",
    "Comptes",
    "Activité",
    ...(canViewRisk ? ["Risque & screening"] : []),
    ...(canViewAlerts ? ["Alertes & investigations"] : []),
  ];

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError("");
      setPayload(null);

      try {
        const res = await getClientDetail(id);
        if (cancelled) return;

        if (res?.success && res.data) {
          setPayload(res.data);
        } else {
          setError(res?.message || "Client introuvable.");
        }
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de charger le dossier client."
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

  const profile = payload?.profile || null;
  const individual = payload?.individual || null;
  const entity = payload?.entity || null;
  const accounts = payload?.accounts || [];
  const transactions = payload?.transactions || [];
  const alerts = payload?.alerts || [];
  const riskScore = payload?.risk_score || null;

  const customerName =
    profile?.customer_name ||
    (individual
      ? `${individual.first_name || ""} ${individual.last_name || ""}`.trim()
      : null) ||
    entity?.legal_name ||
    profile?.client_number ||
    "—";

  const riskLevel = profile?.risk_level || null;
  const scoreValue =
    profile?.risk_score != null
      ? Number(profile.risk_score)
      : riskScore?.score != null
      ? Number(riskScore.score)
      : null;

  const openAlertsCount = alerts.filter((a) =>
    ["OPEN", "OUVERTE", "IN_REVIEW", "EN_ANALYSE"].includes(
      String(a.status || "").toUpperCase()
    )
  ).length;

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />

      <div className="app-main">
        <DemoRail />

        <section className="page-frame client-360">
          {/* Fil d'ariane */}
          <div className="breadcrumb">
            <a
              href="/clients"
              onClick={(e) => {
                e.preventDefault();
                navigate("/clients");
              }}
            >
              Clients
            </a>
            <span className="breadcrumb-sep">›</span>
            <span className="breadcrumb-current">
              {profile?.client_number || `#${id}`}
            </span>
          </div>

          {loading && (
            <div style={{ padding: "40px", textAlign: "center", color: "#64748b" }}>
              Chargement du dossier client…
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
                <button
                  className="btn btn-secondary"
                  onClick={() => navigate("/clients")}
                >
                  Retour à la liste
                </button>
              </div>
            </div>
          )}

          {!loading && !error && profile && (
            <>
              {/* En-tête client */}
              <div className="client-header-card">
                <div className="client-header-identity">
                  <div className="client-avatar" aria-hidden="true">
                    {getInitials(customerName)}
                  </div>
                  <div className="client-header-left">
                    <div className="client-header-eyebrow">
                      DOSSIER CLIENT 360° / CONFORMITÉ
                    </div>
                    <h1>{customerName}</h1>
                    <div className="client-header-meta">
                      <span className="client-number">
                        {profile.client_number || "—"}
                      </span>
                      <span>{clientTypeLabel(profile.client_type)}</span>
                      {canViewRisk && Number(profile.is_pep) === 1 && (
                        <span className="pep-pill">PEP</span>
                      )}
                      {canViewRisk && <RiskPill level={riskLevel} score={scoreValue} />}
                    </div>
                    {(profile.client_agency_name || profile.client_caisse_name) && (
                      <div className="client-location">
                        {[profile.client_caisse_name, profile.client_agency_name]
                          .filter(Boolean)
                          .join(" · ")}
                      </div>
                    )}
                  </div>
                </div>
                {canViewRisk && <div className="client-header-right">
                  <div className="client-score-label">SCORE DE RISQUE</div>
                  <div className="client-score-value">
                    {scoreValue != null ? scoreValue.toFixed(0) : "—"}
                    <span className="client-score-max"> / 100</span>
                  </div>
                </div>}
              </div>

              {/* Tags résumé */}
              <div className="client-tags-row">
                <div className="client-tag">
                  <div className="client-tag-label">TYPE</div>
                  <div className="client-tag-value">
                    {clientTypeLabel(profile.client_type)}
                  </div>
                  <div className="client-tag-note">Catégorie KYC</div>
                </div>
                <div className="client-tag">
                  <div className="client-tag-label">COMPTES</div>
                  <div className="client-tag-value">{accounts.length}</div>
                  <div className="client-tag-note">Rattachés au client</div>
                </div>
                <div className="client-tag">
                  <div className="client-tag-label">TRANSACTIONS</div>
                  <div className="client-tag-value">
                    {profile.transaction_count ?? transactions.length}
                  </div>
                  <div className="client-tag-note">Opérations enregistrées</div>
                </div>
                {canViewAlerts && <div className="client-tag">
                  <div className="client-tag-label">ALERTES OUVERTES</div>
                  <div className="client-tag-value">{openAlertsCount}</div>
                  <div className="client-tag-note">À examiner</div>
                </div>}
              </div>

              {/* Onglets */}
              <div className="tabs-bar" role="tablist" aria-label="Sections du dossier client">
                {tabs.map((tab) => (
                  <button
                    type="button"
                    role="tab"
                    aria-selected={activeTab === tab}
                    key={tab}
                    className={`tab-btn ${
                      activeTab === tab ? "tab-btn-active" : ""
                    }`}
                    onClick={() => setActiveTab(tab)}
                  >
                    {tab}
                  </button>
                ))}
              </div>

              {/* ——— ONGLET DOSSIER ——— */}
              {activeTab === "Dossier" && (
                <>
                  <div className={canViewRisk ? "two-col-grid" : "two-col-grid one-column"}>
                    <div>
                      <SectionHeader
                        number="01"
                        title="Identité et profil"
                        note="Informations de connaissance client et rattachement opérationnel."
                      />
                      <div className="panel">
                        <div className="info-grid-2">
                          <div className="info-item">
                            <div className="info-label">N° CLIENT</div>
                            <div className="info-value">
                              {profile.client_number || "—"}
                            </div>
                          </div>
                          <div className="info-item">
                            <div className="info-label">TYPE</div>
                            <div className="info-value">
                              {clientTypeLabel(profile.client_type)}
                            </div>
                          </div>
                          <div className="info-item">
                            <div className="info-label">NOM / RAISON SOCIALE</div>
                            <div className="info-value">{customerName}</div>
                          </div>
                          {canViewRisk && (
                            <div className="info-item">
                              <div className="info-label">STATUT PEP</div>
                              <div className="info-value">
                                {Number(profile.is_pep) === 1 ? "Oui" : "Non"}
                              </div>
                            </div>
                          )}
                          {individual && (
                            <>
                              <div className="info-item">
                                <div className="info-label">PRÉNOM</div>
                                <div className="info-value">
                                  {individual.first_name || "—"}
                                </div>
                              </div>
                              <div className="info-item">
                                <div className="info-label">NOM</div>
                                <div className="info-value">
                                  {individual.last_name || "—"}
                                </div>
                              </div>
                              <div className="info-item">
                                <div className="info-label">DATE DE NAISSANCE</div>
                                <div className="info-value">
                                  {formatDate(
                                    individual.date_of_birth || individual.birth_date
                                  )}
                                </div>
                              </div>
                              <div className="info-item">
                                <div className="info-label">NATIONALITÉ</div>
                                <div className="info-value">
                                  {individual.nationality || "—"}
                                </div>
                              </div>
                            </>
                          )}
                          {entity && (
                            <>
                              <div className="info-item">
                                <div className="info-label">FORME JURIDIQUE</div>
                                <div className="info-value">
                                  {entity.legal_form || "—"}
                                </div>
                              </div>
                              <div className="info-item">
                                <div className="info-label">N° REGISTRE</div>
                                <div className="info-value">
                                  {entity.registration_number || "—"}
                                </div>
                              </div>
                            </>
                          )}
                          <div className="info-item">
                            <div className="info-label">CAISSE</div>
                            <div className="info-value">
                              {profile.client_caisse_name || profile.client_caisse_code || "—"}
                            </div>
                          </div>
                          <div className="info-item">
                            <div className="info-label">AGENCE</div>
                            <div className="info-value">
                              {profile.client_agency_name || profile.client_agency_code || "—"}
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>

                    {canViewRisk && <div>
                      <SectionHeader
                        number="02"
                        title="Synthèse AML"
                        note="Vue consolidée du niveau de vigilance et de l’exposition."
                      />
                      <div className="panel">
                        <div className="info-grid-2">
                          <div className="info-item">
                            <div className="info-label">NIVEAU DE RISQUE</div>
                            <div className="info-value">
                              <RiskPill level={riskLevel} score={scoreValue} />
                            </div>
                          </div>
                          <div className="info-item">
                            <div className="info-label">SCORE</div>
                            <div className="info-value">
                              {scoreValue != null
                                ? scoreValue.toFixed(0)
                                : "—"}
                            </div>
                          </div>
                          <div className="info-item">
                            <div className="info-label">NB ALERTES</div>
                            <div className="info-value">
                              {profile.alert_count ?? alerts.length}
                            </div>
                          </div>
                          <div className="info-item">
                            <div className="info-label">NB TRANSACTIONS</div>
                            <div className="info-value">
                              {profile.transaction_count ??
                                transactions.length}
                            </div>
                          </div>
                          <div className="info-item">
                            <div className="info-label">VOLUME TOTAL</div>
                            <div className="info-value">
                              {profile.total_volume != null
                                ? formatAmount(profile.total_volume)
                                : "—"}
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>}
                  </div>

                  {/* Dernières opérations */}
                  <SectionHeader
                    number="03"
                    title="Dernières opérations"
                    note="Les dix mouvements les plus récents du dossier."
                  />
                  <div className="panel operations-panel">
                    {transactions.length === 0 ? (
                      <p style={{ color: "#64748b", padding: 16 }}>
                        Aucune transaction disponible pour ce client.
                      </p>
                    ) : (
                      <table className="operations-table">
                        <thead>
                          <tr>
                            <th>RÉFÉRENCE</th>
                            <th>DATE</th>
                            <th>TYPE</th>
                            <th>MONTANT</th>
                            <th>STATUT</th>
                            <th></th>
                          </tr>
                        </thead>
                        <tbody>
                          {transactions.slice(0, 10).map((t) => (
                            <tr key={t.id}>
                              <td className="cell-strong">
                                {t.transaction_reference || `TRX-${t.id}`}
                              </td>
                              <td className="cell-sub">
                                {formatDateTime(t.transaction_date)}
                              </td>
                              <td className="cell-sub">
                                {labelFromCode(t.transaction_type, transactionTypeLabels)}
                              </td>
                              <td className="cell-strong">
                                {formatAmount(t.amount, t.currency)}
                              </td>
                              <td>
                                <span className="status-pill-active">
                                  {labelFromCode(t.transaction_status, statusLabels)}
                                </span>
                              </td>
                              <td>
                                <button
                                  className="cl-open-btn"
                                  type="button"
                                  onClick={() =>
                                    navigate(`/transactions/${t.id}`)
                                  }
                                >
                                    Consulter <span aria-hidden="true">→</span>
                                </button>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}
                  </div>
                </>
              )}

              {/* ——— ONGLET COMPTES ——— */}
              {activeTab === "Comptes" && (
                <>
                  <SectionHeader
                    number="03"
                    title="Comptes rattachés"
                    note="Vue consolidée des comptes associés à ce client."
                  />
                  <div className="panel operations-panel">
                    {accounts.length === 0 ? (
                      <p style={{ color: "#64748b", padding: 16 }}>
                        Aucun compte rattaché.
                      </p>
                    ) : (
                      <table className="operations-table">
                        <thead>
                          <tr>
                            <th>COMPTE</th>
                            <th>TYPE</th>
                            <th>STATUT</th>
                            <th>SOLDE</th>
                            <th>OUVERT LE</th>
                          </tr>
                        </thead>
                        <tbody>
                          {accounts.map((acc) => (
                            <tr key={acc.id || acc.account_number}>
                              <td className="cell-strong">
                                {acc.account_number || "—"}
                              </td>
                              <td className="cell-sub">
                                {labelFromCode(acc.account_type)}
                              </td>
                              <td>
                                <span className="status-pill-active">
                                  {labelFromCode(acc.status, statusLabels)}
                                </span>
                              </td>
                              <td className="cell-strong">
                                {acc.current_balance != null
                                  ? formatAmount(acc.current_balance)
                                  : "—"}
                              </td>
                              <td className="cell-sub">
                                {formatDate(acc.opened_at || acc.created_at)}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}
                    <div className="operations-footer-note">
                      {accounts.length} compte
                      {accounts.length !== 1 ? "s" : ""} relié
                      {accounts.length !== 1 ? "s" : ""} au dossier client.
                    </div>
                  </div>
                </>
              )}

              {/* ——— ONGLET ACTIVITÉ ——— */}
              {activeTab === "Activité" && (
                <>
                  <SectionHeader
                    number="04"
                    title="Activité transactionnelle"
                    note="Historique détaillé des opérations disponibles."
                  />
                  <div className="panel operations-panel">
                    {transactions.length === 0 ? (
                      <p style={{ color: "#64748b", padding: 16 }}>
                        Aucune transaction.
                      </p>
                    ) : (
                      <table className="operations-table">
                        <thead>
                          <tr>
                            <th>RÉFÉRENCE</th>
                            <th>DATE</th>
                            <th>TYPE</th>
                            <th>MONTANT</th>
                            <th>CANAL</th>
                            <th>STATUT</th>
                            <th></th>
                          </tr>
                        </thead>
                        <tbody>
                          {transactions.map((t) => (
                            <tr key={t.id}>
                              <td className="cell-strong">
                                {t.transaction_reference || `TRX-${t.id}`}
                              </td>
                              <td className="cell-sub">
                                {formatDateTime(t.transaction_date)}
                              </td>
                              <td>{labelFromCode(t.transaction_type, transactionTypeLabels)}</td>
                              <td className="cell-strong">
                                {formatAmount(t.amount, t.currency)}
                              </td>
                              <td className="cell-sub">
                                {labelFromCode(t.channel)}
                              </td>
                              <td>
                                <span className="status-pill-active">
                                  {labelFromCode(t.transaction_status, statusLabels)}
                                </span>
                              </td>
                              <td>
                                <button
                                  className="cl-open-btn"
                                  type="button"
                                  onClick={() =>
                                    navigate(`/transactions/${t.id}`)
                                  }
                                >
                                  Consulter <span aria-hidden="true">→</span>
                                </button>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}
                  </div>
                </>
              )}

              {/* ——— ONGLET RISQUE ——— */}
              {activeTab === "Risque & screening" && (
                <>
                  <SectionHeader
                    number="05"
                    title="Risque et scoring"
                    note="Résultat du moteur de risque et statut de screening."
                  />
                  <div className="panel">
                    <div className="info-grid-2">
                      <div className="info-item">
                        <div className="info-label">NIVEAU</div>
                        <div className="info-value">
                          <RiskPill level={riskLevel} score={scoreValue} />
                        </div>
                      </div>
                      <div className="info-item">
                        <div className="info-label">SCORE</div>
                        <div className="info-value">
                          {scoreValue != null ? scoreValue.toFixed(0) : "—"}
                        </div>
                      </div>
                      <div className="info-item">
                        <div className="info-label">PEP</div>
                        <div className="info-value">
                          {Number(profile.is_pep) === 1 ? "Oui" : "Non"}
                        </div>
                      </div>
                    </div>
                    <div className="screening-callout">
                      <span className="screening-callout-icon" aria-hidden="true">✓</span>
                      <div>
                        <strong>Données de risque vérifiées</strong>
                        <p>
                          Les indicateurs affichés proviennent du profil AML et du dernier
                          score calculé pour ce client.
                        </p>
                      </div>
                    </div>
                  </div>
                </>
              )}

              {/* ——— ONGLET ALERTES ——— */}
              {activeTab === "Alertes & investigations" && (
                <>
                  <SectionHeader
                    number="06"
                    title="Alertes liées au client"
                    note="Signaux de conformité associés à ce dossier."
                  />
                  <div className="panel operations-panel">
                    {alerts.length === 0 ? (
                      <p style={{ color: "#64748b", padding: 16 }}>
                        Aucune alerte pour ce client.
                      </p>
                    ) : (
                      <table className="operations-table">
                        <thead>
                          <tr>
                            <th>RÉFÉRENCE</th>
                            <th>TYPE</th>
                            <th>PRIORITÉ</th>
                            <th>STATUT</th>
                            <th>SCORE</th>
                            <th>DATE</th>
                            <th></th>
                          </tr>
                        </thead>
                        <tbody>
                          {alerts.map((a) => (
                            <tr key={a.id}>
                              <td className="cell-strong">
                                {a.reference || `ALT-${a.id}`}
                              </td>
                              <td>{labelFromCode(a.alert_type || a.title)}</td>
                              <td>
                                <span className={`priority-pill priority-${String(a.priority || "").toLowerCase()}`}>
                                  {labelFromCode(a.priority, alertPriorityLabels)}
                                </span>
                              </td>
                              <td>{labelFromCode(a.status, statusLabels)}</td>
                              <td>
                                {a.final_score != null
                                  ? Number(a.final_score).toFixed(0)
                                  : "—"}
                              </td>
                              <td className="cell-sub">
                                {formatDateTime(a.created_at)}
                              </td>
                              <td>
                                <button
                                  className="cl-open-btn"
                                  type="button"
                                  onClick={() =>
                                    navigate(`/alertes/${a.id}`)
                                  }
                                >
                                  Consulter <span aria-hidden="true">→</span>
                                </button>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    )}
                  </div>
                </>
                
              )}

              {/* Sentinelle Assist — tiroir droit */}
              {canUseAssist && <AssistPanel
                objectType="client"
                objectId={Number(id)}
                title="Assist — profil client"
              />}

            </>
          )}
        </section>
      </div>
    </div>
  );
}
