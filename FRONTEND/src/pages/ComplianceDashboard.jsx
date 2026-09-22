import { useEffect, useMemo, useRef, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import ComplianceSidebar from "../components/compliance/ComplianceSidebar";
import Icon from "../components/compliance/Icon";
import useComplianceData from "../features/compliance/useComplianceData";
import { ALERT_TYPES, PRIORITIES, alertsCsv, filterAlerts, number } from "../features/compliance/model";

const PREVIEW_USER = { username: "Hawa Diallo", role: { id: 2, name: "COMPLIANCE_OFFICER" } };
const RISK_COLORS = { LOW: "#497e6b", MEDIUM: "#c5aa71", HIGH: "#cb8053", CRITICAL: "#ac514c" };

function Empty({ children, icon = "dashboard" }) {
  return <div className="co-empty"><Icon name={icon} size={24} /><p>{children}</p></div>;
}

function RiskChart({ risks }) {
  const total = risks?.reduce((sum, risk) => sum + Number(risk.client_count || 0), 0) || 0;
  let offset = 0;
  return <>
    <div className="co-risk-visual">
      <svg viewBox="0 0 200 200" role="img" aria-label={`Répartition du risque sur ${number(total)} clients classés`}>
        <circle cx="100" cy="100" r="78" fill="none" stroke="#eeeae3" strokeWidth="18" />
        {risks?.map((risk) => {
          const length = total ? Number(risk.client_count) / total * 100 : 0;
          const start = offset;
          offset += length;
          return <circle key={risk.code} cx="100" cy="100" r="78" fill="none" stroke={RISK_COLORS[risk.code] || "#87928f"} strokeWidth="18" pathLength="100" strokeDasharray={`${Math.max(0, length - (length > 1 ? 1 : 0))} 100`} strokeDashoffset={-start} transform="rotate(-90 100 100)" />;
        })}
      </svg>
      <div><strong>{number(total)}</strong><span>clients classés</span></div>
    </div>
    <div className="co-risk-legend">{risks?.map((risk) => <div key={risk.code}><span><i style={{ background: RISK_COLORS[risk.code] || "#87928f" }} />{risk.label || PRIORITIES[risk.code] || risk.code}</span><strong>{number(risk.client_count)}</strong><small>{total ? (Number(risk.client_count) / total * 100).toLocaleString("fr-FR", { maximumFractionDigits: 1 }) : 0} %</small></div>)}</div>
  </>;
}

function TrendChart({ trend }) {
  const max = Math.max(1, ...trend.map((point) => Number(point.alerts) || 0));
  const total = trend.reduce((sum, point) => sum + Number(point.alerts || 0), 0);
  return <>
    <div className="co-trend-summary"><strong>{number(total)}</strong><span>alertes détectées sur la période</span><span className="co-chart-key"><i />Alertes créées</span></div>
    <div className="co-trend" role="img" aria-label={`${number(total)} alertes détectées. ${trend.map((point) => `${point.date} : ${point.alerts}`).join(" ; ")}`}>
      <div className="co-chart-axis"><span>{max}</span><span>{Math.round(max / 2)}</span><span>0</span></div>
      <div className="co-chart-bars">{trend.map((point, index) => <div className="co-chart-column" key={point.date}>
        <div className="co-bar-track"><div className={`co-chart-bar${index === trend.length - 1 ? " is-last" : ""}`} style={{ height: `${Number(point.alerts || 0) / max * 100}%` }} title={`${point.date} : ${point.alerts} alertes`}><span>{point.alerts}</span></div></div>
        <small>{trend.length <= 7 || index % 5 === 0 || index === trend.length - 1 ? new Date(`${point.date}T12:00:00`).toLocaleDateString("fr-FR", { day: "2-digit", month: "2-digit" }) : ""}</small>
      </div>)}</div>
    </div>
  </>;
}

function PreviewAlertDialog({ alert, onClose }) {
  const dialog = useRef(null);
  useEffect(() => { dialog.current.showModal(); }, []);
  return <dialog ref={dialog} className="co-dialog" onClose={onClose} onClick={(event) => { if (event.target === event.currentTarget) onClose(); }} aria-labelledby="preview-alert-title">
    <div className="co-dialog-top"><span className="co-overline">DOSSIER DE DÉMONSTRATION · {alert.reference}</span><button type="button" className="co-icon-button" aria-label="Fermer le dossier" onClick={onClose}><Icon name="close" /></button></div>
    <h2 id="preview-alert-title">{alert.client_name}</h2><p>{alert.title}</p>
    <div className="co-dialog-facts"><div><span>Priorité</span><strong>{PRIORITIES[alert.priority]}</strong></div><div><span>Score de risque</span><strong>{number(alert.final_score)} / 100</strong></div><div><span>Statut</span><strong>À examiner</strong></div></div>
    <div className="co-preview-note">Ce dossier est fictif. Connectez-vous avec votre compte conformité pour consulter les pièces, instruire les alertes et accéder aux investigations.</div>
    <div className="co-dialog-actions"><button type="button" className="co-button" onClick={onClose}>Revenir à la file</button><Link to="/login" className="co-button co-button-primary">Se connecter <Icon name="arrow" size={15} /></Link></div>
  </dialog>;
}

export default function ComplianceDashboard({ user, onLogout, preview = false }) {
  const navigate = useNavigate();
  const currentUser = preview ? PREVIEW_USER : user;
  const [days, setDays] = useState(7);
  const [priority, setPriority] = useState("ALL");
  const [search, setSearch] = useState("");
  const [selectedAlert, setSelectedAlert] = useState(null);
  const [exportNotice, setExportNotice] = useState("");
  const { data, loading, failures, updatedAt, refresh } = useComplianceData(preview, days);
  const filtered = useMemo(() => filterAlerts(data.alerts || [], { priority, search }), [data.alerts, priority, search]);
  const criticalCount = data.alerts?.filter((alert) => alert.priority === "CRITICAL").length;
  const firstName = currentUser?.username?.split(/[ ._-]/)[0] || "";
  const date = new Date().toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
  const href = (path) => preview ? `/login?from=${encodeURIComponent(path)}` : path;
  const openAlert = (alert) => preview ? setSelectedAlert(alert) : navigate(`/alertes/${alert.id}`);
  const count = (list) => list == null ? null : list.length >= 100 ? "100+" : list.length;

  function exportQueue() {
    const blob = new Blob([alertsCsv(filtered)], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `sentinelle-${preview ? "apercu-" : ""}alertes-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    setExportNotice(`${filtered.length} dossier${filtered.length > 1 ? "s" : ""} exporté${filtered.length > 1 ? "s" : ""}.`);
  }

  function focusCriticalQueue() {
    setPriority("CRITICAL");
    setSearch("");
    document.getElementById("compliance-queue")?.focus();
  }

  const metrics = [
    { label: "Alertes à traiter", value: data.summary?.alerts?.open, note: "Alertes ouvertes du portefeuille", icon: "alert", tone: "amber", to: "/alertes" },
    { label: "Priorité critique", value: criticalCount, note: "Dans la file prioritaire chargée", icon: "risk", tone: "red", action: focusCriticalQueue },
    { label: "Investigations ouvertes", value: count(data.investigations), note: "Dossiers récents · 100 au maximum", icon: "investigation", tone: "green", to: "/investigations" },
    { label: "Clients à risque élevé", value: data.summary?.clients?.risky, note: `${number(data.summary?.clients?.total)} clients sous supervision`, icon: "clients", tone: "neutral", to: "/clients" },
  ];

  return <div className="compliance-workspace">
    <div className="app-shell co-shell">
      <ComplianceSidebar user={currentUser} onLogout={onLogout} preview={preview} openAlerts={data.summary?.alerts?.open} />
      <div className="app-main co-main">
        <a className="co-skip" href="#compliance-content">Aller au contenu</a>
        {preview && <div className="co-preview-banner"><span><strong>Aperçu local</strong> · Données fictives, aucune opération réelle.</span><Link to="/login">Se connecter <Icon name="arrow" size={14} /></Link></div>}
        <header className="co-topbar"><div><Icon name="dashboard" size={16} /><span>Espace conformité</span><span className="co-breadcrumb-separator">/</span><strong>Vue d’ensemble</strong></div><div className="co-topbar-right"><span className="co-access-tag"><Icon name="screening" size={14} />{preview ? "Démonstration" : "Responsable conformité"}</span><Link to={href("/parametres")} className="co-top-avatar" aria-label="Mon compte">{currentUser?.username?.split(/[ ._-]/).slice(0, 2).map((part) => part[0]).join("").toUpperCase()}</Link></div></header>

        <main id="compliance-content" className="co-content">
          <div className="co-page-heading"><div><p className="co-overline">VIGILANCE FINANCIÈRE · RÉSEAU CIF MALI</p><h1>Votre bureau de conformité<span>.</span></h1><p>Bonjour{firstName ? `, ${firstName}` : ""}. Voici les points qui méritent votre attention.</p></div><div className="co-heading-side"><span><Icon name="calendar" size={15} />{date}</span><button type="button" className="co-button" disabled={loading} onClick={refresh}><Icon name="refresh" size={15} className={loading ? "co-spinning" : ""} />{loading ? "Actualisation…" : "Actualiser"}</button></div></div>

          {failures.length > 0 && <div className="co-error" role="alert"><Icon name="alert" /><div><strong>{failures.length === 6 ? "Les données sont momentanément indisponibles." : "Certaines données n’ont pas pu être actualisées."}</strong><span>À recharger : {failures.join(", ")}. Vous pouvez réessayer avec « Actualiser ».</span></div></div>}

          <section className="co-metrics" aria-label="Indicateurs de conformité" aria-busy={loading}>
            {metrics.map((metric) => <article className={`co-metric co-tone-${metric.tone}`} key={metric.label}><div><span>{metric.label}</span><span className="co-metric-icon"><Icon name={metric.icon} size={17} /></span></div><strong className={loading ? "co-skeleton" : ""}>{loading ? " " : typeof metric.value === "string" ? metric.value : number(metric.value)}</strong><p>{metric.note}</p>{metric.action ? <button type="button" className="co-metric-link" onClick={metric.action} aria-label="Filtrer les alertes critiques"><Icon name="arrowUp" size={16} /></button> : <Link className="co-metric-link" to={href(metric.to)} aria-label={`Consulter : ${metric.label}`}><Icon name="arrowUp" size={16} /></Link>}</article>)}
          </section>

          {!loading && criticalCount > 0 && <div className="co-attention"><span className="co-attention-icon"><Icon name="alert" size={18} /></span><p><strong>{criticalCount} dossier{criticalCount > 1 ? "s" : ""} critique{criticalCount > 1 ? "s" : ""} à examiner</strong><span>Commencez par les signaux les plus sensibles de votre file.</span></p><button type="button" onClick={focusCriticalQueue}>Examiner les dossiers <Icon name="arrow" size={16} /></button></div>}

          <div className="co-work-grid">
            <section id="compliance-queue" className="co-panel co-queue" tabIndex={-1} aria-labelledby="queue-title" aria-busy={loading}>
              <div className="co-panel-heading"><div><p className="co-overline">À VOTRE ATTENTION</p><h2 id="queue-title">File prioritaire <span className="co-count">{data.alerts ? data.alerts.length : "—"}</span></h2></div><Link to={href("/alertes")} className="co-text-link">Toutes les alertes <Icon name="arrow" size={14} /></Link></div>
              <div className="co-queue-toolbar"><div className="co-segments" role="group" aria-label="Filtrer par priorité">{[["ALL", "Toutes"], ["CRITICAL", "Critiques"], ["HIGH", "Élevées"]].map(([value, label]) => <button key={value} type="button" aria-pressed={priority === value} onClick={() => setPriority(value)}>{value === "CRITICAL" && <i />}{label}</button>)}</div><label className="co-search"><Icon name="search" size={15} /><input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Client, référence…" aria-label="Rechercher dans la file prioritaire" /></label></div>
              <div className="co-table-wrap"><table className="co-table"><thead><tr><th scope="col">Client / signal détecté</th><th scope="col">Priorité</th><th scope="col">Score</th><th scope="col"><span className="co-sr-only">Consulter</span></th></tr></thead><tbody>
                {loading ? <tr><td colSpan={4}><Empty>Chargement des dossiers…</Empty></td></tr> : data.alerts === null ? <tr><td colSpan={4}><Empty icon="alert">La file est indisponible. Réessayez l’actualisation.</Empty></td></tr> : filtered.length === 0 ? <tr><td colSpan={4}><Empty icon="check">{search || priority !== "ALL" ? "Aucun dossier ne correspond à ces filtres." : "Aucune alerte prioritaire ouverte."}</Empty>{(search || priority !== "ALL") && <button className="co-reset" type="button" onClick={() => { setSearch(""); setPriority("ALL"); }}>Réinitialiser les filtres</button>}</td></tr> : filtered.slice(0, 5).map((alert) => <tr key={alert.id}><td><div className="co-client-cell"><span className={`co-client-symbol${alert.priority === "CRITICAL" ? " is-critical" : ""}`}><Icon name={alert.alert_type === "SANCTION_MATCH" ? "screening" : "clients"} size={18} /></span><div><button type="button" className="co-client-name" onClick={() => openAlert(alert)}>{alert.client_name || alert.customer_name || `Client #${alert.client_id}`}</button><span>{alert.title || ALERT_TYPES[alert.alert_type] || alert.alert_type || "Signal à analyser"}</span><small>{alert.reference || `ALT-${alert.id}`}</small></div></div></td><td><span className={`co-priority co-priority-${String(alert.priority).toLowerCase()}`}><i />{PRIORITIES[alert.priority] || alert.priority || "Non classée"}</span></td><td><div className="co-score"><strong>{number(alert.final_score)}</strong><span>/100</span></div></td><td><button type="button" className="co-row-open" aria-label={`Examiner ${alert.reference || `ALT-${alert.id}`}`} onClick={() => openAlert(alert)}><Icon name="chevron" size={16} /></button></td></tr>)}
              </tbody></table></div>
              <div className="co-panel-footer"><span>{loading ? "Chargement…" : `${Math.min(filtered.length, 5)} sur ${filtered.length} dossier${filtered.length > 1 ? "s" : ""} affiché${filtered.length > 1 ? "s" : ""}`}<small>File ouverte · jusqu’à 100 alertes prioritaires</small></span><button type="button" className="co-text-link" disabled={!filtered.length || loading} onClick={exportQueue}><Icon name="download" size={14} />Exporter la sélection</button></div>
            </section>

            <section className="co-panel co-risk" aria-labelledby="risk-title"><div className="co-panel-heading"><div><p className="co-overline">PORTEFEUILLE</p><h2 id="risk-title">Exposition au risque</h2></div><span className="co-neutral-tag">Global</span></div>{loading ? <Empty>Chargement du portefeuille…</Empty> : !data.risk?.length ? <Empty>Aucune répartition disponible.</Empty> : <RiskChart risks={data.risk} />}<Link className="co-panel-bottom-link" to={href("/analyse-risque")}>Approfondir l’analyse <Icon name="arrow" size={15} /></Link></section>

            <section className="co-panel co-activity" aria-labelledby="trend-title"><div className="co-panel-heading"><div><p className="co-overline">SURVEILLANCE CONTINUE</p><h2 id="trend-title">Rythme des alertes</h2></div><label className="co-period"><span className="co-sr-only">Période du graphique</span><select value={days} onChange={(event) => setDays(Number(event.target.value))}><option value={7}>7 derniers jours</option><option value={30}>30 derniers jours</option></select></label></div>{loading ? <Empty>Chargement de l’activité…</Empty> : !data.trend?.length ? <Empty>Aucune activité disponible pour cette période.</Empty> : <TrendChart trend={data.trend} />}<div className="co-chart-footnote"><Icon name="clock" size={13} />Historique global · la période s’applique à ce graphique.</div></section>

            <section className="co-panel co-followup" aria-labelledby="followup-title"><div className="co-panel-heading"><div><p className="co-overline">CONTINUITÉ DU TRAITEMENT</p><h2 id="followup-title">Dossiers en cours</h2></div><Icon name="investigation" size={19} /></div><div className="co-investigations">{loading ? <Empty>Chargement des investigations…</Empty> : !data.investigations?.length ? <Empty>{data.investigations === null ? "Investigations indisponibles." : "Aucune investigation ouverte."}</Empty> : data.investigations.slice(0, 3).map((item) => <Link key={item.id} to={href(item.alert_id ? `/alertes/${item.alert_id}` : "/investigations")}><span className="co-case-line" /><span><strong>{item.client_name || `Dossier #${item.id}`}</strong><small>{item.alert_reference || `INV-${item.id}`}<span> · {item.assigned_username || "Non attribué"}</span></small></span><Icon name="chevron" size={14} /></Link>)}</div><Link to={href("/centif")} className="co-centif-link"><span className="co-centif-icon"><Icon name="centif" size={21} /></span><span><strong>Déclarations CENTIF</strong><small>{data.declarations == null ? "Registre à consulter" : `${count(data.declarations)} brouillon${data.declarations.length !== 1 ? "s" : ""} à préparer`}</small></span><Icon name="arrow" size={17} /></Link></section>
          </div>

          <div className="co-quick-links"><span>ACCÈS DIRECT</span><Link to={href("/screening")}><Icon name="screening" size={16} />Screening PPE & sanctions<Icon name="arrowUp" size={13} /></Link><Link to={href("/rapports")}><Icon name="reports" size={16} />Rapports de conformité<Icon name="arrowUp" size={13} /></Link><Link to={href("/audit")}><Icon name="audit" size={16} />Journal d’audit<Icon name="arrowUp" size={13} /></Link></div>
          <footer className="co-page-footer"><span>SENTINELLE / CIF <i />{preview ? "Environnement de présentation" : "Espace de travail · Conformité LBC-FT"}</span><span><span className={`co-sync-dot${failures.length ? " is-offline" : ""}`} />{loading ? "Actualisation en cours" : updatedAt ? `${failures.length ? "Actualisation partielle" : preview ? "Aperçu généré" : "Dernière actualisation"} à ${updatedAt.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" })}` : "Données indisponibles"}</span></footer>
          <p className="co-sr-only" role="status">{exportNotice}</p>
        </main>
      </div>
    </div>
    {selectedAlert && <PreviewAlertDialog alert={selectedAlert} onClose={() => setSelectedAlert(null)} />}
  </div>;
}
