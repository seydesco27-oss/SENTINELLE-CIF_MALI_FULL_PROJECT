import { useState } from "react";
import { Link, NavLink } from "react-router-dom";
import { getNavSectionsForUser } from "../../config/roles";
import Icon from "./Icon";

export default function ComplianceSidebar({ user, onLogout, preview = false, openAlerts }) {
  const [expanded, setExpanded] = useState(false);
  const [leaving, setLeaving] = useState(false);
  const sections = getNavSectionsForUser(user);
  const name = user?.username || "Responsable conformité";
  const initials = name.split(/[ ._-]+/).slice(0, 2).map((part) => part[0]).join("").toUpperCase();

  async function leave() {
    setLeaving(true);
    try { await onLogout?.(); } finally { setLeaving(false); }
  }

  return <>
    <div className="co-mobile-bar">
      <Link to={preview ? "/apercu/conformite" : "/dashboard"}>SENTINELLE<span> / CIF</span></Link>
      <button type="button" aria-label={expanded ? "Fermer la navigation" : "Ouvrir la navigation"} aria-expanded={expanded} aria-controls="compliance-navigation" onClick={() => setExpanded(!expanded)}><Icon name={expanded ? "close" : "menu"} /></button>
    </div>
    {expanded && <button type="button" className="co-nav-overlay" aria-label="Fermer la navigation" onClick={() => setExpanded(false)} />}
    <aside id="compliance-navigation" className={`co-sidebar${expanded ? " is-expanded" : ""}`} aria-label="Navigation conformité" onKeyDown={(event) => { if (event.key === "Escape") setExpanded(false); }}>
      <Link className="co-brand" to={preview ? "/apercu/conformite" : "/dashboard"}>
        <span className="co-brand-mark"><Icon name="screening" size={25} /></span>
        <span><strong>SENTINELLE<span> / CIF</span></strong><small>La vigilance en confiance.</small></span>
      </Link>
      <div className="co-workspace-label"><span className="co-workspace-dot" /><div>Bureau de conformité<small>LBC · FT · FP</small></div><Icon name="chevron" size={14} /></div>
      <nav>
        {sections.map((section) => <div className="co-nav-group" key={section.label}>
          <p>{section.label === "SUPERVISION" ? "ESPACE DE TRAVAIL" : section.label === "DONNÉES" ? "PORTEFEUILLE" : section.label}</p>
          {section.items.map((item) => <NavLink key={item.to} to={preview ? (item.to === "/dashboard" ? "/apercu/conformite" : `/login?from=${encodeURIComponent(item.to)}`) : item.to} onClick={() => setExpanded(false)} className={({ isActive }) => `co-nav-link${isActive ? " is-active" : ""}`}>
            <Icon name={item.icon} size={17} /><span>{item.label}</span>
            {item.badgeKey && openAlerts != null && <small className="co-nav-count">{openAlerts > 99 ? "99+" : openAlerts}</small>}
          </NavLink>)}
        </div>)}
      </nav>
      <div className="co-sidebar-footer">
        <div className="co-territory"><Icon name="network" size={16} /><span>Réseau CIF<small>Mali · Afrique de l’Ouest</small></span><span className="co-mali" aria-label="Mali"><i /><i /><i /></span></div>
        <div className="co-user"><span className="co-avatar">{initials}</span><span><strong>{name}</strong><small>Responsable conformité</small></span>{preview ? <Link to="/login" aria-label="Se connecter"><Icon name="logout" /></Link> : <button type="button" onClick={leave} disabled={leaving} aria-label="Se déconnecter"><Icon name="logout" /></button>}</div>
      </div>
    </aside>
  </>;
}
