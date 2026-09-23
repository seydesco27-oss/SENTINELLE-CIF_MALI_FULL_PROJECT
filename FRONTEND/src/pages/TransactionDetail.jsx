import { useEffect, useState } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { getTransactionDetail } from "../services/api";
import { canAccess } from "../auth/access";
import "./TransactionDetail.css";

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
    { HIGH: "Élevé", CRITICAL: "Critique", MEDIUM: "Moyen", LOW: "Faible" }[lvl] ||
    lvl ||
    "—";
  return (
    <span className={`risk-pill risk-pill-${(lvl || "low").toLowerCase()}`}>
      <span className="risk-pill-dot" />
      {label}
      {score != null ? ` (${Number(score).toFixed(0)})` : ""}
    </span>
  );
}

function StatusPill({ status }) {
  const s = (status || "").toUpperCase();
  return (
    <span className={`status-pill status-${s.toLowerCase() || "unknown"}`}>
      {status || "—"}
    </span>
  );
}

function Title({ number, title, note }) {
  return (
    <header className="dossier-section-title">
      <span>{number}</span>
      <div>
        <h2>{title}</h2>
        {note ? <p>{note}</p> : null}
      </div>
    </header>
  );
}

function Field({ label, value }) {
  return (
    <div className="profile-field">
      <span>{label}</span>
      <strong>{value ?? "—"}</strong>
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
    (currency === "XOF" ? "FCFA" : currency || "FCFA")
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

function transactionTypeLabel(type) {
  if (!type) return "—";
  return (
    {
      VIREMENT: "Virement",
      DEPOT_ESPECES: "Dépôt espèces",
      RETRAIT: "Retrait",
      TRANSFER_IN: "Transfert entrant",
      TRANSFER_OUT: "Transfert sortant",
      TRANSFER: "Transfert",
    }[type] || String(type).replaceAll("_", " ")
  );
}

function alertTypeLabel(type) {
  return (
    {
      LARGE_AMOUNT: "Montant élevé",
      STRUCTURING: "Structuration",
      RAPID_TRANSFER: "Virement rapide",
      UNUSUAL_VOLUME: "Volume inhabituel",
      HIGH_RISK_CORRIDOR: "Corridor à risque",
      AML_RULE_ENGINE: "Moteur de règles",
    }[type] || type || "—"
  );
}

export default function TransactionDetail({ user, onLogout }) {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [payload, setPayload] = useState(null);
  const [decision, setDecision] = useState("");
  const [comment, setComment] = useState("");
  const canViewAnalysis = canAccess(user, "tx.view");

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError("");
      setPayload(null);
      try {
        const res = await getTransactionDetail(id);
        if (cancelled) return;
        if (res?.success && res.data) setPayload(res.data);
        else setError(res?.message || "Transaction introuvable.");
      } catch (err) {
        if (!cancelled) {
          setError(
            err.response?.data?.message ||
              "Impossible de charger le détail de la transaction."
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

  // Ancres démo Figma : ?focus=aml | ?focus=decision
  useEffect(() => {
    const focus = searchParams.get("focus");
    if (!focus || loading) return;
    const t = requestAnimationFrame(() => {
      document.getElementById(focus)?.scrollIntoView({
        behavior: "smooth",
        block: "start",
      });
    });
    return () => cancelAnimationFrame(t);
  }, [searchParams, loading, payload]);

  const txn = payload?.transaction || null;
  const aml = payload?.aml || null;
  const alerts = payload?.alerts || [];
  const riskAssessments = payload?.risk_assessments || [];
  const ruleExecutions = payload?.rule_executions || [];
  const mlLabel = payload?.ml_label || null;

  const score =
    aml?.risk_score ??
    txn?.aml_risk_score ??
    txn?.risk_score ??
    null;
  const riskLevel =
    aml?.risk_level ?? txn?.aml_risk_level ?? txn?.risk_level ?? null;

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />

        <section className="page-frame profile-page transaction-analysis">
          <nav className="breadcrumb">
            <button type="button" onClick={() => navigate("/transactions")}>
              Transactions
            </button>
            <span>›</span>
            <strong>
              {txn?.transaction_reference || txn?.reference || `#${id}`}
            </strong>
          </nav>

          {loading && (
            <div className="txn-loading">Chargement du dossier transactionnel…</div>
          )}

          {error && !loading && (
            <div className="txn-error">
              {error}
              <div style={{ marginTop: 12 }}>
                <button
                  type="button"
                  className="btn-secondary"
                  onClick={() => navigate("/transactions")}
                >
                  Retour à la liste
                </button>
              </div>
            </div>
          )}

          {!loading && !error && txn && (
            <>
              <header className="profile-header">
                <div>
                  <p className="eyebrow">
                    {canViewAnalysis
                      ? "DOSSIER D’ANALYSE TRANSACTIONNELLE / AML"
                      : "CONSULTATION TRANSACTIONNELLE"}
                  </p>
                  <h1>
                    {txn.transaction_reference || txn.reference || `TX-${txn.id}`}
                  </h1>
                  <div className="header-meta">
                    <span className="mono">
                      {formatAmount(txn.amount, txn.currency)}
                    </span>
                    {canViewAnalysis && <RiskPill level={riskLevel} score={score} />}
                    <StatusPill status={txn.transaction_status || txn.status} />
                  </div>
                </div>
                {canViewAnalysis && <div className="profile-score">
                  <span>SCORE AML DÉTERMINISTE</span>
                  <strong>
                    {score != null ? Number(score).toFixed(0) : "—"}
                  </strong>
                  <small>/ 100</small>
                </div>}
              </header>

              {/* Chemin d'analyse Figma */}
              {canViewAnalysis && <div className="analysis-path">
                <div>
                  <span>TRANSACTION</span>
                  <strong>{transactionTypeLabel(txn.transaction_type || txn.type)}</strong>
                </div>
                <i>→</i>
                <div>
                  <span>CONTEXTE</span>
                  <strong>
                    {txn.customer_name || txn.client_name || txn.client_number || "—"}
                  </strong>
                </div>
                <i>→</i>
                <div>
                  <span>SIGNAUX</span>
                  <strong>
                    {ruleExecutions.length
                      ? `${ruleExecutions.length} règle(s)`
                      : riskAssessments.length
                        ? `${riskAssessments.length} évaluation(s)`
                        : "Voir analyse AML"}
                  </strong>
                </div>
                <i>→</i>
                <div>
                  <span>ALERTE</span>
                  <strong>
                    {alerts.length
                      ? alerts[0].reference || `ALT-${alerts[0].id}`
                      : "Aucune"}
                  </strong>
                </div>
                <i>→</i>
                <div className="human-step">
                  <span>DÉCISION</span>
                  <strong>Analyste humain</strong>
                </div>
              </div>}

              {/* 01 */}
              <section className="dossier-section">
                <Title number="01" title="Identification de l'opération" />
                <div className="transaction-ledger">
                  <Field
                    label="DATE / HEURE"
                    value={formatDateTime(txn.transaction_date || txn.created_at)}
                  />
                  <Field
                    label="MONTANT"
                    value={formatAmount(txn.amount, txn.currency)}
                  />
                  <Field
                    label="TYPE"
                    value={transactionTypeLabel(txn.transaction_type || txn.type)}
                  />
                  <Field
                    label="COMPTE CONCERNÉ"
                    value={
                      txn.account_number ||
                      (txn.account_id != null ? `Compte ${txn.account_id}` : "—")
                    }
                  />
                  <Field label="CANAL" value={txn.channel || "—"} />
                  <Field
                    label="STATUT"
                    value={txn.transaction_status || txn.status || "—"}
                  />
                </div>
              </section>

              {/* 02 */}
              <section className="dossier-section">
                <Title
                  number="02"
                  title="Contexte client et institutionnel"
                  note="Rattachement du dossier selon les données API disponibles."
                />
                <div className="analysis-context">
                  <article className="profile-card">
                    <div className="field-grid">
                      <Field
                        label="CLIENT"
                        value={txn.customer_name || txn.client_name || "—"}
                      />
                      <Field label="N° CLIENT" value={txn.client_number || "—"} />
                      <Field label="TYPE CLIENT" value={txn.client_type || "—"} />
                      <Field
                        label="SOLDE COMPTE"
                        value={
                          txn.account_current_balance != null
                            ? formatAmount(
                                txn.account_current_balance,
                                txn.currency
                              )
                            : "—"
                        }
                      />
                    </div>
                    {txn.client_id && (
                      <button
                        type="button"
                        className="link-btn"
                        onClick={() => navigate(`/clients/${txn.client_id}`)}
                      >
                        Voir le profil client →
                      </button>
                    )}
                  </article>
                </div>
              </section>

              {canViewAnalysis && <>
              {/* 03 AML — ancre démo */}
              <section id="aml" className="dossier-section">
                <Title
                  number="03"
                  title="Analyse AML déterministe"
                  note="Score et signaux issus du moteur de règles / évaluations liées."
                />
                <div className="aml-grid">
                  <article className="profile-card">
                    <div className="field-grid">
                      <Field
                        label="SCORE AML"
                        value={
                          score != null ? Number(score).toFixed(0) : "—"
                        }
                      />
                      <Field
                        label="NIVEAU"
                        value={riskLevel || "—"}
                      />
                      <Field
                        label="ÉVALUATIONS"
                        value={String(riskAssessments.length)}
                      />
                      <Field
                        label="RÈGLES EXÉCUTÉES"
                        value={String(ruleExecutions.length)}
                      />
                    </div>
                  </article>
                </div>

                {ruleExecutions.length > 0 && (
                  <article className="data-panel">
                    <div className="panel-label">RÈGLES DÉCLENCHÉES / EXÉCUTÉES</div>
                    <div className="table-scroll">
                      <table className="data-table">
                        <thead>
                          <tr>
                            <th>RÈGLE</th>
                            <th>RÉSULTAT</th>
                            <th>SCORE</th>
                            <th>DATE</th>
                          </tr>
                        </thead>
                        <tbody>
                          {ruleExecutions.map((r, i) => (
                            <tr key={r.id || i}>
                              <td className="mono">
                                {r.rule_code || r.rule_name || r.rule_id || "—"}
                              </td>
                              <td>{r.execution_result || r.result || r.status || "—"}</td>
                              <td className="mono">
                                {r.rule_score != null
                                  ? Number(r.rule_score).toFixed(0)
                                  : r.score != null
                                    ? Number(r.score).toFixed(0)
                                    : "—"}
                              </td>
                              <td>
                                {formatDateTime(r.executed_at || r.created_at)}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </article>
                )}

                {riskAssessments.length > 0 && (
                  <article className="data-panel">
                    <div className="panel-label">ÉVALUATIONS DE RISQUE</div>
                    <div className="table-scroll">
                      <table className="data-table">
                        <thead>
                          <tr>
                            <th>TYPE</th>
                            <th>SCORE</th>
                            <th>NIVEAU</th>
                            <th>DATE</th>
                          </tr>
                        </thead>
                        <tbody>
                          {riskAssessments.map((ra, i) => (
                            <tr key={ra.id || i}>
                              <td>{ra.risk_type || "—"}</td>
                              <td className="mono">
                                {ra.score != null ? Number(ra.score).toFixed(0) : "—"}
                              </td>
                              <td>
                                <RiskPill level={ra.risk_level} />
                              </td>
                              <td>{formatDateTime(ra.created_at)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </article>
                )}
              </section>

              {/* 04 ML */}
              <section className="dossier-section">
                <Title
                  number="04"
                  title="Analyse comportementale / ML"
                  note="Signal d’aide à l’analyse — ne constitue pas une décision réglementaire."
                />
                <article className="profile-card ml-card">
                  {mlLabel ? (
                    <div className="field-grid">
                      <Field
                        label="LABEL ML"
                        value={mlLabel.label || mlLabel.prediction || "—"}
                      />
                      <Field
                        label="CONFIANCE"
                        value={
                          mlLabel.confidence != null
                            ? `${Number(mlLabel.confidence).toFixed(2)}`
                            : "—"
                        }
                      />
                      <Field
                        label="MODÈLE"
                        value={mlLabel.model_name || mlLabel.model || "—"}
                      />
                    </div>
                  ) : (
                    <p className="muted">
                      Aucun label ML exposé pour cette transaction dans l’API
                      actuelle.
                    </p>
                  )}
                  <small>
                    Modèles expérimentaux : signaux comportementaux uniquement.
                  </small>
                </article>
              </section>

              {/* 05 Alertes */}
              <section className="dossier-section">
                <Title number="05" title="Alerte et historique de traitement" />
                {alerts.length === 0 ? (
                  <div className="investigation-note">
                    <span>ALERTE</span>
                    <strong>
                      Aucune alerte associée à cette opération dans les données
                      disponibles.
                    </strong>
                  </div>
                ) : (
                  <article className="data-panel">
                    <div className="table-scroll">
                      <table className="data-table">
                        <thead>
                          <tr>
                            <th>ALERTE</th>
                            <th>TYPE</th>
                            <th>PRIORITÉ</th>
                            <th>STATUT</th>
                            <th>CRÉÉE LE</th>
                          </tr>
                        </thead>
                        <tbody>
                          {alerts.map((a) => (
                            <tr
                              key={a.id}
                              onClick={() => navigate(`/alertes/${a.id}`)}
                            >
                              <td className="mono">
                                {a.reference || `ALT-${a.id}`}
                              </td>
                              <td>{alertTypeLabel(a.alert_type)}</td>
                              <td>{a.priority || "—"}</td>
                              <td>{a.status || "—"}</td>
                              <td>{formatDateTime(a.created_at)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </article>
                )}
              </section>

              {/* 06 Décision — ancre démo */}
              <section id="decision" className="dossier-section">
                <Title
                  number="06"
                  title="Investigation et décision"
                  note="La décision finale est imputable à l’analyste. Saisie locale de démonstration — pas d’écriture API dans cette V1."
                />
                <div className="investigation-workbench">
                  <div>
                    <p className="eyebrow">CONTEXTE</p>
                    <Field
                      label="TRANSACTION"
                      value={
                        txn.transaction_reference || txn.reference || String(txn.id)
                      }
                    />
                    <Field
                      label="ALERTES LIÉES"
                      value={String(alerts.length)}
                    />
                  </div>
                  <div>
                    <label>
                      Décision de l’analyste
                      <select
                        value={decision}
                        onChange={(e) => setDecision(e.target.value)}
                      >
                        <option value="">À renseigner</option>
                        <option value="review">Poursuivre la revue</option>
                        <option value="close">Clore après justification</option>
                        <option value="investigation">
                          Ouvrir une investigation
                        </option>
                      </select>
                    </label>
                    <label>
                      Commentaire de décision
                      <textarea
                        value={comment}
                        onChange={(e) => setComment(e.target.value)}
                        placeholder="Motif et éléments examinés…"
                      />
                    </label>
                    <small>
                      {decision || comment
                        ? "Brouillon local de démonstration — aucune écriture API effectuée."
                        : "Aucune décision saisie."}
                    </small>
                  </div>
                </div>
              </section>
              </>}
            </>
          )}
        </section>
      </div>
    </div>
  );
}
