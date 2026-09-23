import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import AssistPanel from "../components/AssistPanel";
import {
  createInvestigation,
  decideAlert,
  escalateAlert,
  getAlertDetail,
  scoreAlertWithMl,
} from "../services/api";
import { canAccess } from "../auth/access";
import "./AlertDetail.css";

const PRIORITY_LABEL = { CRITICAL: "Critique", HIGH: "Élevée", MEDIUM: "Moyenne", LOW: "Faible" };
const STATUS_LABEL = {
  OPEN: "Ouverte", OUVERTE: "Ouverte", IN_REVIEW: "En analyse", EN_ANALYSE: "En analyse",
  CLOSED: "Clôturée", CLOTUREE: "Clôturée", DISMISSED: "Écartée", RESOLVED: "Résolue",
};
const RISK_LABEL = { CRITICAL: "Critique", HIGH: "Élevé", MEDIUM: "Moyen", LOW: "Faible" };
const TABS = ["Résumé", "Client", "Transaction", "Risque", "Investigation", "Actions"];
const FEATURE_LABELS = {
  montant_log: "Montant de l’opération",
  ratio_seuil: "Rapport au seuil réglementaire",
  proche_seuil: "Proximité du seuil",
  a_destinataire: "Présence d’un destinataire",
  jour_semaine: "Jour de la semaine",
  montant_moyen_client: "Montant moyen habituel",
  montant_std_client: "Dispersion des montants",
  nb_transactions_client: "Historique du client",
  nb_destinataires_distincts_client: "Destinataires distincts",
  montant_zscore: "Écart au comportement habituel",
  nb_transactions_7j: "Fréquence récente",
  montant_cumule_7j: "Volume récent",
  in_degree: "Diversité des flux entrants",
  out_degree: "Diversité des flux sortants",
  type_cash_in: "Opération d’encaissement",
  type_cash_out: "Opération de décaissement",
  type_transfer: "Opération de transfert",
  type_other: "Nature de l’opération",
  type_decaissement_credit: "Décaissement de crédit",
  type_depot: "Dépôt",
  type_remboursement_credit: "Remboursement de crédit",
  type_retrait: "Retrait",
  type_transfert_entrant: "Transfert entrant",
  type_transfert_sortant: "Transfert sortant",
};

function alertTypeLabel(type) {
  return ({
    LARGE_AMOUNT: "Montant élevé", STRUCTURING: "Structuration", RAPID_TRANSFER: "Virement rapide",
    UNUSUAL_VOLUME: "Volume inhabituel", HIGH_RISK_CORRIDOR: "Corridor à risque",
    HIGH_CASH_ACTIVITY: "Forte activité espèces", AML_RULE_ENGINE: "Moteur de règles AML",
  }[type] || type?.replaceAll("_", " ") || "Alerte AML");
}

function riskTypeLabel(type) {
  return ({
    ML_TRANSACTION_RISK: "Prédiction comportementale ML", HIGH_CASH_ACTIVITY: "Forte activité espèces",
    UNUSUAL_VOLUME: "Volume inhabituel", STRUCTURING: "Structuration", LARGE_AMOUNT: "Montant élevé",
  }[type] || type?.replaceAll("_", " ") || "Évaluation AML");
}

function actionTypeLabel(type) {
  return ({
    ESCALATED_TO_COMPLIANCE: "Escalade conformité",
    CONFIRMED_SUSPICIOUS: "Soupçon confirmé",
    DISMISSED: "Alerte écartée",
  }[type] || type?.replaceAll("_", " ") || "Action");
}

function formatDateTime(value) {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return new Intl.DateTimeFormat("fr-FR", {
    day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit",
  }).format(date);
}

function formatAmount(value, currency = "XOF") {
  if (value === null || value === undefined || value === "") return "—";
  return `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 }).format(Number(value))} ${currency || "XOF"}`;
}

function score(value) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : null;
}

function scoreText(value) {
  const parsed = score(value);
  return parsed === null ? "—" : parsed.toLocaleString("fr-FR", { maximumFractionDigits: 1 });
}

function PriorityBadge({ priority }) {
  const normalized = (priority || "").toUpperCase();
  return <span className={`alert-badge alert-priority-${normalized.toLowerCase()}`}>{PRIORITY_LABEL[normalized] || priority || "—"}</span>;
}

function StatusBadge({ status }) {
  const normalized = (status || "").toUpperCase();
  return <span className={`alert-badge alert-status-${normalized.toLowerCase()}`}>{STATUS_LABEL[normalized] || status || "—"}</span>;
}

function RiskBadge({ level }) {
  const normalized = (level || "").toUpperCase();
  return <span className={`risk-badge risk-${normalized.toLowerCase()}`}><span aria-hidden="true" />{RISK_LABEL[normalized] || level || "—"}</span>;
}

function DetailRow({ label, value, children }) {
  return <div className="alert-detail-row"><span>{label}</span><strong>{children ?? value ?? "—"}</strong></div>;
}

function EmptyState({ children }) {
  return <div className="alert-empty-state">{children}</div>;
}

function RiskTable({ assessments }) {
  if (!assessments.length) return <EmptyState>Aucune évaluation de risque associée.</EmptyState>;
  return (
    <div className="alert-table-scroll">
      <table className="alert-data-table alert-risk-table">
        <thead><tr><th>Évaluation</th><th>Score</th><th>Niveau</th><th>Motif</th><th>Source</th><th>Date</th></tr></thead>
        <tbody>{assessments.map((assessment) => (
          <tr key={assessment.id}>
            <td className="alert-cell-strong">{riskTypeLabel(assessment.risk_type)}</td>
            <td className="alert-score-cell">{scoreText(assessment.score)}<small>/100</small></td>
            <td><RiskBadge level={assessment.risk_level} /></td>
            <td className="alert-reason-cell">{assessment.reason || "—"}</td>
            <td><span className="alert-source-tag">{assessment.source || "—"}</span></td>
            <td className="alert-date-cell">{formatDateTime(assessment.created_at)}</td>
          </tr>
        ))}</tbody>
      </table>
    </div>
  );
}

function MlAnalysisPanel({ analysis, status, error, onRefresh, canRun }) {
  const factors = analysis?.factors || [];
  const maxImportance = Math.max(...factors.map((factor) => Number(factor.importance) || 0), 0);
  const isRunning = status === "scoring";
  return (
    <section className="alert-panel ml-panel">
      <div className="alert-section-heading">
        <div>
          <span className="alert-eyebrow">INTELLIGENCE ML</span>
          <h2>Lecture comportementale distincte</h2>
          <p>Le modèle ML complète l’analyse. Son score reste séparé du score AML déterministe.</p>
        </div>
        <div className="ml-heading-actions">
          <span className={`ml-status ${analysis ? "ml-ready" : isRunning ? "ml-running" : "ml-missing"}`}>
            <span aria-hidden="true" />{analysis ? "Modèle exécuté" : isRunning ? "Analyse en cours" : "À analyser"}
          </span>
          {canRun && <button className="alert-btn alert-btn-secondary" onClick={onRefresh} disabled={isRunning}>
            {isRunning ? "Calcul…" : analysis ? "Recalculer" : "Lancer le score ML"}
          </button>}
        </div>
      </div>
      {error && <div className="ml-error">{error}</div>}
      {analysis ? (
        <>
          <div className="ml-score-grid">
            <div className="ml-score-card ml-aml"><span>AML · règles</span><strong>{scoreText(analysis.rule_score)}<small>/100</small></strong><em>Scénarios déterministes</em></div>
            <div className="ml-score-card ml-model"><span>ML · modèle</span><strong>{scoreText(analysis.model_score)}<small>/100</small></strong><em>Signal statistique d’assistance</em></div>
          </div>
          <div className="ml-explanation-grid">
            <div>
              <h3>Facteurs analysés par le modèle</h3>
              {factors.length ? <div className="ml-factors">{factors.slice(0, 6).map((factor) => {
                const importance = Number(factor.importance) || 0;
                const width = maxImportance > 0 ? Math.max(5, (importance / maxImportance) * 100) : 5;
                return <div className="ml-factor" key={factor.feature_name}>
                  <div><span>{FEATURE_LABELS[factor.feature_name] || factor.feature_name?.replaceAll("_", " ")}</span><strong>{factor.feature_value ?? "—"}</strong></div>
                  <div className="ml-factor-track" aria-label={`Importance relative ${Math.round(width)} %`}><span style={{ width: `${width}%` }} /></div>
                </div>;
              })}</div> : <EmptyState>Le score est enregistré, mais aucun facteur explicatif n’a été fourni.</EmptyState>}
            </div>
            <aside className="ml-method-card">
              <span className="alert-eyebrow">MÉTHODE</span><h3>Une décision traçable</h3>
              <p>Le score AML explique les règles déclenchées. Le score ML apporte un second regard comportemental. L’analyste les examine séparément avant toute décision.</p>
              <small>Dernière exécution : {formatDateTime(analysis.scored_at)}</small>
            </aside>
          </div>
        </>
      ) : !isRunning && !error ? <EmptyState>{canRun
        ? "Le modèle sera exécuté automatiquement sur la transaction liée."
        : "Aucun score ML n’est encore enregistré pour cette opération."}</EmptyState> : null}
    </section>
  );
}

export default function AlertDetail({ user, onLogout }) {
  const { id } = useParams();
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState("Résumé");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [payload, setPayload] = useState(null);
  const [mlStatus, setMlStatus] = useState("idle");
  const [mlError, setMlError] = useState("");
  const [actionMode, setActionMode] = useState("");
  const [actionComment, setActionComment] = useState("");
  const [actionDecision, setActionDecision] = useState("CONFIRMED_SUSPICIOUS");
  const [actionStatus, setActionStatus] = useState("idle");
  const [actionMessage, setActionMessage] = useState(null);
  const canUseMl = canAccess(user, "ml.use");
  const canEscalate = canAccess(user, "alert.escalate");
  const canDecide = canAccess(user, "alert.decide");
  const canManageInvestigation = canAccess(user, "investigation.manage");
  const tabs = TABS.filter((tab) => tab !== "Investigation" || canAccess(user, "investigation.view"));

  useEffect(() => {
    let active = true;
    async function load() {
      setLoading(true); setError(""); setPayload(null); setMlError("");
      try {
        const response = await getAlertDetail(id);
        if (!active) return;
        if (!response?.success || !response.data) throw new Error(response?.message || "Alerte introuvable.");
        setPayload(response.data);
        const needsScore = canUseMl && response.data.alert?.transaction_id && !response.data.ml_analysis;
        if (needsScore) {
          setMlStatus("scoring");
          try {
            await scoreAlertWithMl(id);
            const refreshed = await getAlertDetail(id);
            if (active && refreshed?.success) { setPayload(refreshed.data); setMlStatus("ready"); }
          } catch (mlFailure) {
            if (active) {
              setMlStatus("error");
              setMlError(mlFailure.response?.data?.error || mlFailure.response?.data?.message || "Le service ML est momentanément indisponible.");
            }
          }
        } else setMlStatus(response.data.ml_analysis ? "ready" : "idle");
      } catch (loadError) {
        if (active) setError(loadError.response?.data?.message || loadError.message || "Impossible de charger le détail de l’alerte.");
      } finally { if (active) setLoading(false); }
    }
    if (id) load();
    return () => { active = false; };
  }, [id, canUseMl]);

  async function refreshMlScore() {
    if (!canUseMl) return;
    setMlStatus("scoring"); setMlError("");
    try {
      const result = await scoreAlertWithMl(id);
      if (!result?.success) throw new Error(result?.message || "Le scoring ML a échoué.");
      const refreshed = await getAlertDetail(id);
      if (refreshed?.success) setPayload(refreshed.data);
      setMlStatus("ready");
    } catch (mlFailure) {
      setMlStatus("error");
      setMlError(mlFailure.response?.data?.error || mlFailure.response?.data?.message || mlFailure.message || "Le service ML est momentanément indisponible.");
    }
  }

  function prepareAction(mode) {
    setActionMode(mode);
    setActionComment("");
    setActionMessage(null);
  }

  async function submitOperationalAction(event) {
    event.preventDefault();

    if (actionMode !== "investigation" && actionComment.trim().length < 10) {
      setActionMessage({ type: "error", text: "Décrivez le motif en au moins 10 caractères." });
      return;
    }

    setActionStatus("saving");
    setActionMessage(null);

    try {
      let response;
      let successText;

      if (actionMode === "escalate") {
        response = await escalateAlert(id, actionComment.trim());
        successText = "L’alerte a été transmise à la file de conformité.";
      } else if (actionMode === "decision") {
        response = await decideAlert(id, actionDecision, actionComment.trim());
        successText = actionDecision === "DISMISSED"
          ? "L’alerte a été écartée et clôturée."
          : "Le soupçon a été confirmé et l’alerte clôturée.";
      } else if (actionMode === "investigation") {
        response = await createInvestigation(Number(id));
        successText = "Une investigation a été ouverte sur cette alerte.";
      }

      if (!response?.success) throw new Error(response?.message || "L’action n’a pas pu être enregistrée.");

      const refreshed = await getAlertDetail(id);
      if (refreshed?.success) setPayload(refreshed.data);
      setActionMode("");
      setActionComment("");
      setActionMessage({ type: "success", text: successText });
      setActiveTab(actionMode === "investigation" ? "Investigation" : "Actions");
    } catch (actionError) {
      setActionMessage({
        type: "error",
        text: actionError.response?.data?.error
          || actionError.response?.data?.message
          || actionError.message
          || "L’action n’a pas pu être enregistrée.",
      });
    } finally {
      setActionStatus("idle");
    }
  }

  const alert = payload?.alert || null;
  const actions = payload?.actions || [];
  const investigations = payload?.investigations || [];
  const riskAssessments = payload?.risk_assessments || [];
  const mlAnalysis = payload?.ml_analysis || null;
  const mainRisk = riskAssessments.find((item) => item.source !== "ML_MODEL") || riskAssessments[0] || null;
  const isClosed = ["CLOSED", "DISMISSED", "RESOLVED"].includes(String(alert?.status || "").toUpperCase());
  const canOperate = canEscalate || canDecide || canManageInvestigation;

  return (
    <div className="app-shell"><Sidebar user={user} onLogout={onLogout} /><div className="app-main"><DemoRail user={user} />
      <main className="alert-detail-content">
        <nav className="alert-breadcrumb" aria-label="Fil d’Ariane"><button type="button" onClick={() => navigate("/alertes")}>Alertes</button><span>›</span><strong>{alert?.reference || `Alerte #${id}`}</strong></nav>
        {loading && <div className="alert-loading"><span />Chargement du dossier d’alerte…</div>}
        {error && !loading && <div className="alert-error-state"><strong>Le dossier n’a pas pu être chargé.</strong><p>{error}</p><button className="alert-btn alert-btn-secondary" onClick={() => navigate("/alertes")}>Retour aux alertes</button></div>}
        {!loading && !error && alert && <>
          <header className={`alert-hero alert-hero-${String(alert.priority || "low").toLowerCase()}`}>
            <div className="alert-hero-main">
              <div className="alert-hero-topline"><span className="alert-reference">{alert.reference || `ALT-${alert.id}`}</span><span className={`alert-live-indicator${isClosed ? " is-closed" : ""}`}><i /> {isClosed ? "Traitement terminé" : "Surveillance active"}</span></div>
              <h1>{alert.title || alertTypeLabel(alert.alert_type)}</h1>
              <p>{alert.description || mainRisk?.reason || "Alerte générée par le dispositif de surveillance AML."}</p>
              <div className="alert-hero-meta"><span>Créée le {formatDateTime(alert.created_at)}</span><span>Transaction {alert.transaction_reference || "non renseignée"}</span><span>Client {alert.client_number || "non renseigné"}</span></div>
            </div>
            <div className="alert-hero-decision">
              <div className="alert-header-badges"><PriorityBadge priority={alert.priority} /><StatusBadge status={alert.status} /></div>
              <div className="alert-hero-scores">
                <div><span>AML · règles</span><strong>{scoreText(mainRisk?.score ?? alert.aml_score ?? alert.final_score)}<small>/100</small></strong></div>
                <div className="is-ml"><span>ML · modèle</span><strong>{mlAnalysis ? scoreText(mlAnalysis.model_score) : "—"}<small>/100</small></strong></div>
              </div>
            </div>
          </header>

          <section className="alert-kpis" aria-label="Indicateurs clés">
            <div className="alert-kpi"><span>Client concerné</span><strong>{alert.client_name || "—"}</strong><small>{alert.client_number || "Référence indisponible"}</small></div>
            <div className="alert-kpi"><span>Opération analysée</span><strong>{formatAmount(alert.amount, alert.currency)}</strong><small>{alertTypeLabel(alert.transaction_type)}</small></div>
            <div className="alert-kpi"><span>Signal principal</span><strong>{riskTypeLabel(mainRisk?.risk_type || alert.alert_type)}</strong><small>{mainRisk ? `${scoreText(mainRisk.score)}/100 par ${mainRisk.source || "moteur AML"}` : "À qualifier"}</small></div>
            <div className="alert-kpi"><span>Modèle ML</span><strong>{mlAnalysis ? `${scoreText(mlAnalysis.model_score)}/100` : mlStatus === "scoring" ? "Calcul…" : canUseMl ? "À lancer" : "Non disponible"}</strong><small>{mlAnalysis ? `Exécuté le ${formatDateTime(mlAnalysis.scored_at)}` : "Aucun score enregistré"}</small></div>
          </section>

          {canOperate && <section className="alert-action-center" aria-label="Actions sur l’alerte">
            <div className="alert-action-summary">
              <span className="alert-eyebrow">TRAITEMENT DU DOSSIER</span>
              <h2>{isClosed ? "Alerte clôturée" : "Prochaine action"}</h2>
              <p>{isClosed
                ? "La décision finale et sa justification sont conservées dans le journal ci-dessous."
                : "Choisissez une action autorisée pour votre rôle. Chaque intervention est horodatée et tracée."}</p>
            </div>
            {!isClosed && <div className="alert-action-buttons">
              {canEscalate && <button type="button" className="alert-action-button alert-action-escalate" onClick={() => prepareAction("escalate")}><span>↗</span><strong>Escalader</strong><small>Passer en analyse</small></button>}
              {canManageInvestigation && <button type="button" className="alert-action-button alert-action-investigate" onClick={() => prepareAction("investigation")}><span>⌕</span><strong>Ouvrir une investigation</strong><small>Créer un dossier de traitement</small></button>}
              {canDecide && <button type="button" className="alert-action-button alert-action-decide" onClick={() => prepareAction("decision")}><span>✓</span><strong>Prendre une décision</strong><small>Clôturer avec justification</small></button>}
            </div>}

            {actionMode && !isClosed && <form className="alert-action-form" onSubmit={submitOperationalAction}>
              <div className="alert-action-form-heading">
                <div>
                  <strong>{actionMode === "escalate" ? "Escalader l’alerte" : actionMode === "investigation" ? "Ouvrir une investigation" : "Décision finale"}</strong>
                  <small>{actionMode === "investigation" ? "Le dossier sera créé sans assignation et pourra être attribué ensuite." : "La justification sera enregistrée dans le journal de l’alerte."}</small>
                </div>
                <button type="button" className="alert-action-close" aria-label="Fermer" onClick={() => setActionMode("")}>×</button>
              </div>
              {actionMode === "decision" && <label className="alert-action-field"><span>Décision</span><select value={actionDecision} onChange={(event) => setActionDecision(event.target.value)}><option value="CONFIRMED_SUSPICIOUS">Confirmer le soupçon</option><option value="DISMISSED">Écarter l’alerte</option></select></label>}
              {actionMode !== "investigation" && <label className="alert-action-field"><span>Motif détaillé</span><textarea rows="3" value={actionComment} onChange={(event) => setActionComment(event.target.value)} placeholder="Expliquez les éléments factuels qui motivent cette action…" /><small>{actionComment.trim().length}/10 caractères minimum</small></label>}
              <div className="alert-action-form-footer"><button type="button" className="alert-btn alert-btn-secondary" onClick={() => setActionMode("")}>Annuler</button><button type="submit" className="alert-btn alert-btn-primary" disabled={actionStatus === "saving"}>{actionStatus === "saving" ? "Enregistrement…" : "Confirmer l’action"}</button></div>
            </form>}

            {actionMessage && <div className={`alert-action-message is-${actionMessage.type}`} role="status">{actionMessage.text}</div>}
          </section>}

          <nav className="alert-tabs" role="tablist" aria-label="Sections du dossier">{tabs.map((tab) => <button key={tab} type="button" role="tab" aria-selected={activeTab === tab} className={activeTab === tab ? "is-active" : ""} onClick={() => setActiveTab(tab)}>{tab}</button>)}</nav>

          {activeTab === "Résumé" && <div className="alert-tab-content">
            <div className="alert-summary-grid">
              <section className="alert-panel"><span className="alert-eyebrow">SYNTHÈSE OPÉRATIONNELLE</span><h2>Qualification de l’alerte</h2>
                <DetailRow label="Scénario" value={alertTypeLabel(alert.alert_type)} /><DetailRow label="Priorité"><PriorityBadge priority={alert.priority} /></DetailRow><DetailRow label="Statut"><StatusBadge status={alert.status} /></DetailRow><DetailRow label="Niveau de risque"><RiskBadge level={mainRisk?.risk_level || alert.priority} /></DetailRow><DetailRow label="Score initial" value={`${scoreText(mainRisk?.score ?? alert.final_score)}/100`} />
              </section>
              <section className="alert-panel alert-reason-panel"><span className="alert-eyebrow">SIGNAL DOCUMENTÉ</span><h2>Pourquoi cette alerte ?</h2><p>{mainRisk?.reason || alert.description || "Aucun motif détaillé n’est disponible."}</p>
                <div className="alert-context-grid"><div><span>Source</span><strong>{mainRisk?.source || "—"}</strong></div><div><span>Canal</span><strong>{alert.channel || "—"}</strong></div><div><span>Origine</span><strong>{alert.country_from || alert.country || "—"}</strong></div><div><span>Destination</span><strong>{alert.country_to || "—"}</strong></div></div>
              </section>
            </div>
            <MlAnalysisPanel analysis={mlAnalysis} status={mlStatus} error={mlError} onRefresh={refreshMlScore} canRun={canUseMl} />
            <section className="alert-panel"><div className="alert-section-heading compact"><div><span className="alert-eyebrow">TRAÇABILITÉ</span><h2>Évaluations de risque</h2></div><span className="alert-count">{riskAssessments.length} évaluation{riskAssessments.length > 1 ? "s" : ""}</span></div><RiskTable assessments={riskAssessments} /></section>
          </div>}

          {activeTab === "Client" && <div className="alert-tab-content alert-two-columns">
            <section className="alert-panel"><span className="alert-eyebrow">IDENTITÉ</span><h2>{alert.client_name || "Client lié"}</h2><DetailRow label="N° client" value={alert.client_number} /><DetailRow label="Type" value={alert.client_type} /><DetailRow label="Statut" value={alert.client_status} /><DetailRow label="Nationalité" value={alert.nationality || alert.entity_nationality} /><DetailRow label="Profession / activité" value={alert.profession || alert.activity_sector} /></section>
            <section className="alert-panel"><span className="alert-eyebrow">CONFORMITÉ</span><h2>Profil de risque</h2><DetailRow label="Statut PEP" value={Number(alert.is_pep) === 1 ? "Oui" : "Non"} /><DetailRow label="Score client" value={`${scoreText(alert.risk_score)}/100`} /><DetailRow label="Téléphone" value={alert.phone} /><DetailRow label="E-mail" value={alert.email} />{alert.client_id && <button className="alert-btn alert-btn-primary" onClick={() => navigate(`/clients/${alert.client_id}`)}>Ouvrir le dossier client</button>}</section>
          </div>}

          {activeTab === "Transaction" && <div className="alert-tab-content alert-two-columns">
            <section className="alert-panel"><span className="alert-eyebrow">OPÉRATION</span><h2>{alert.transaction_reference || "Transaction liée"}</h2><DetailRow label="Montant" value={formatAmount(alert.amount, alert.currency)} /><DetailRow label="Type" value={alert.transaction_type} /><DetailRow label="Canal" value={alert.channel} /><DetailRow label="Statut" value={alert.transaction_status} /><DetailRow label="Date" value={formatDateTime(alert.transaction_date)} /></section>
            <section className="alert-panel"><span className="alert-eyebrow">CONTEXTE</span><h2>Compte et localisation</h2><DetailRow label="Compte" value={alert.account_number} /><DetailRow label="Type de compte" value={alert.account_type} /><DetailRow label="Agence" value={alert.agency_name || alert.agency_code} /><DetailRow label="Caisse" value={alert.caisse_name || alert.caisse_code} /><DetailRow label="Itinéraire" value={[alert.country_from, alert.country_to].filter(Boolean).join(" → ") || alert.country} />{alert.transaction_id && <button className="alert-btn alert-btn-primary" onClick={() => navigate(`/transactions/${alert.transaction_id}`)}>Ouvrir la transaction</button>}</section>
          </div>}

          {activeTab === "Risque" && <div className="alert-tab-content"><MlAnalysisPanel analysis={mlAnalysis} status={mlStatus} error={mlError} onRefresh={refreshMlScore} canRun={canUseMl} /><section className="alert-panel"><span className="alert-eyebrow">ÉVALUATIONS</span><h2>Historique des moteurs de risque</h2><RiskTable assessments={riskAssessments} /></section></div>}

          {activeTab === "Investigation" && <section className="alert-panel alert-tab-content"><span className="alert-eyebrow">TRAITEMENT</span><h2>Investigations associées</h2>
            {investigations.length ? <div className="alert-table-scroll"><table className="alert-data-table"><thead><tr><th>Dossier</th><th>Assigné à</th><th>Décision</th><th>Commentaire</th><th>Début</th><th>Clôture</th></tr></thead><tbody>{investigations.map((item) => <tr key={item.id}><td className="alert-cell-strong">INV-{item.id}</td><td>{item.assigned_name || item.assigned_username || "—"}</td><td>{item.decision || "En cours"}</td><td className="alert-reason-cell">{item.comment || "—"}</td><td className="alert-date-cell">{formatDateTime(item.started_at)}</td><td className="alert-date-cell">{formatDateTime(item.closed_at)}</td></tr>)}</tbody></table></div> : <EmptyState>Aucune investigation ouverte sur cette alerte.</EmptyState>}
          </section>}

          {activeTab === "Actions" && <section className="alert-panel alert-tab-content"><span className="alert-eyebrow">JOURNAL</span><h2>Historique des actions</h2>
            {actions.length ? <div className="alert-table-scroll"><table className="alert-data-table"><thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Commentaire</th></tr></thead><tbody>{actions.map((item) => <tr key={item.id}><td className="alert-date-cell">{formatDateTime(item.created_at)}</td><td>{item.user_name || item.username || "—"}</td><td className="alert-cell-strong">{actionTypeLabel(item.action_type)}</td><td className="alert-reason-cell">{item.comment || "—"}</td></tr>)}</tbody></table></div> : <EmptyState>Aucune action enregistrée sur cette alerte.</EmptyState>}
          </section>}

          {canUseMl && (
            <AssistPanel user={user} objectType="alert" objectId={Number(id)} transactionId={alert.transaction_id ? Number(alert.transaction_id) : null} title="Assist — cette alerte" />
          )}
        </>}
      </main>
    </div></div>
  );
}
