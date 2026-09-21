import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { getClientDetail } from "../services/api";
import AssistPanel from "../components/AssistPanel";
import "./ClientDetail.css";

const TABS = [
  "Dossier",
  "Comptes",
  "Activité",
  "Risque & screening",
  "Alertes & investigations",
];

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

function SectionHeader({ number, title }) {
  return (
    <div className="section-header-top">
      <span className="section-number">{number}</span>
      <h2>{title}</h2>
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

export default function ClientDetail({ user, onLogout }) {
  const { id } = useParams();
  const navigate = useNavigate();

  const [activeTab, setActiveTab] = useState("Dossier");
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

        <div className="client-detail-content">
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
                <div className="client-header-left">
                  <div className="client-header-eyebrow">
                    DOSSIER CLIENT 360° / CONFORMITÉ
                  </div>
                  <h1>{customerName}</h1>
                  <div className="client-header-meta">
                    <span className="client-number">
                      {profile.client_number || "—"}
                    </span>
                    {Number(profile.is_pep) === 1 && (
                      <span className="pep-pill">PEP</span>
                    )}
                    <RiskPill level={riskLevel} score={scoreValue} />
                  </div>
                </div>
                <div className="client-header-right">
                  <div className="client-score-label">SCORE DE RISQUE</div>
                  <div className="client-score-value">
                    {scoreValue != null ? scoreValue.toFixed(0) : "—"}
                    <span className="client-score-max"> / 100</span>
                  </div>
                </div>
              </div>

              {/* Tags résumé */}
              <div className="client-tags-row">
                <div className="client-tag">
                  <div className="client-tag-label">TYPE</div>
                  <div className="client-tag-value">
                    {profile.client_type === "INDIVIDUAL"
                      ? "Individu"
                      : profile.client_type === "ENTITY"
                      ? "Entité"
                      : profile.client_type || "—"}
                  </div>
                </div>
                <div className="client-tag">
                  <div className="client-tag-label">COMPTES</div>
                  <div className="client-tag-value">{accounts.length}</div>
                </div>
                <div className="client-tag">
                  <div className="client-tag-label">TRANSACTIONS</div>
                  <div className="client-tag-value">
                    {profile.transaction_count ?? transactions.length}
                  </div>
                </div>
                <div className="client-tag">
                  <div className="client-tag-label">ALERTES OUVERTES</div>
                  <div className="client-tag-value">{openAlertsCount}</div>
                </div>
              </div>

              {/* Onglets */}
              <div className="tabs-bar">
                {TABS.map((tab) => (
                  <button
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
                  <div className="two-col-grid">
                    <div>
                      <SectionHeader number="01" title="Identité / Profil" />
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
                              {profile.client_type || "—"}
                            </div>
                          </div>
                          <div className="info-item">
                            <div className="info-label">NOM / RAISON SOCIALE</div>
                            <div className="info-value">{customerName}</div>
                          </div>
                          <div className="info-item">
                            <div className="info-label">PEP</div>
                            <div className="info-value">
                              {Number(profile.is_pep) === 1 ? "Oui" : "Non"}
                            </div>
                          </div>
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
                                  {individual.date_of_birth ||
                                    individual.birth_date ||
                                    "—"}
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
                                <div className="info-label">RAISON SOCIALE</div>
                                <div className="info-value">
                                  {entity.legal_name || "—"}
                                </div>
                              </div>
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
                        </div>
                      </div>
                    </div>

                    <div>
                      <SectionHeader number="02" title="Synthèse AML" />
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
                    </div>
                  </div>

                  {/* Dernières opérations */}
                  <SectionHeader number="03" title="Dernières opérations" />
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
                                {t.transaction_date
                                  ? new Date(
                                      t.transaction_date
                                    ).toLocaleString("fr-FR", {
                                      day: "2-digit",
                                      month: "short",
                                      hour: "2-digit",
                                      minute: "2-digit",
                                    })
                                  : "—"}
                              </td>
                              <td className="cell-sub">
                                {t.transaction_type || "—"}
                              </td>
                              <td className="cell-strong">
                                {formatAmount(t.amount, t.currency)}
                              </td>
                              <td>{t.transaction_status || "—"}</td>
                              <td>
                                <button
                                  className="cl-open-btn"
                                  onClick={() =>
                                    navigate(`/transactions/${t.id}`)
                                  }
                                >
                                  Ouvrir
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
                  <SectionHeader number="03" title="Comptes rattachés" />
                  <p className="tab-section-subtitle">
                    Vue consolidée des comptes associés à ce client.
                  </p>
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
                                {acc.account_type || "—"}
                              </td>
                              <td>
                                <span className="status-pill-active">
                                  {acc.status || "—"}
                                </span>
                              </td>
                              <td className="cell-strong">
                                {acc.current_balance != null
                                  ? formatAmount(acc.current_balance)
                                  : "—"}
                              </td>
                              <td className="cell-sub">
                                {acc.opened_at ||
                                  acc.created_at ||
                                  "—"}
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
                  <SectionHeader number="04" title="Activité transactionnelle" />
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
                                {t.transaction_date
                                  ? new Date(
                                      t.transaction_date
                                    ).toLocaleString("fr-FR")
                                  : "—"}
                              </td>
                              <td>{t.transaction_type || "—"}</td>
                              <td className="cell-strong">
                                {formatAmount(t.amount, t.currency)}
                              </td>
                              <td className="cell-sub">
                                {t.channel || "—"}
                              </td>
                              <td>{t.transaction_status || "—"}</td>
                              <td>
                                <button
                                  className="cl-open-btn"
                                  onClick={() =>
                                    navigate(`/transactions/${t.id}`)
                                  }
                                >
                                  Ouvrir
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
                  <SectionHeader number="05" title="Risque & scoring" />
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
                    {riskScore && (
                      <pre
                        style={{
                          marginTop: 16,
                          fontSize: 13,
                          color: "#334155",
                          whiteSpace: "pre-wrap",
                        }}
                      >
                        {JSON.stringify(riskScore, null, 2)}
                      </pre>
                    )}
                    <p
                      style={{
                        marginTop: 16,
                        color: "#64748b",
                        fontSize: 13,
                      }}
                    >
                      Le screening PEP/Sanctions détaillé sera branché via les
                      endpoints dédiés (`/clients/{"{id}"}/screening`,
                      `/sanctions`, `/pep`) dans une prochaine itération.
                    </p>
                  </div>
                </>
              )}

              {/* ——— ONGLET ALERTES ——— */}
              {activeTab === "Alertes & investigations" && (
                <>
                  <SectionHeader number="06" title="Alertes liées au client" />
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
                              <td>{a.alert_type || a.title || "—"}</td>
                              <td>{a.priority || "—"}</td>
                              <td>{a.status || "—"}</td>
                              <td>
                                {a.final_score != null
                                  ? Number(a.final_score).toFixed(0)
                                  : "—"}
                              </td>
                              <td className="cell-sub">
                                {a.created_at
                                  ? new Date(a.created_at).toLocaleString(
                                      "fr-FR"
                                    )
                                  : "—"}
                              </td>
                              <td>
                                <button
                                  className="cl-open-btn"
                                  onClick={() =>
                                    navigate(`/alertes/${a.id}`)
                                  }
                                >
                                  Ouvrir
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
              <AssistPanel
                objectType="client"
                objectId={Number(id)}
                title="Assist — profil client"
              />

            </>
          )}
        </div>
      </div>
    </div>
  );
}
