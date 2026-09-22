import { useEffect, useMemo, useState } from "react";
import { NavLink } from "react-router-dom";
import { getDashboardSummary } from "../services/api";
import { getNavSectionsForUser, getRoleLabel, resolveRoleName } from "../config/roles";
import "./Sidebar.css";
import ComplianceSidebar from "./compliance/ComplianceSidebar";

/** Logo bouclier SENTINELLE — contrat Figma (protection + data) */
function SentinelleLogo({ size = 28 }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 40 44"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
    >
      <path
        d="M20 2L4 8.5V21C4 30.5 11 38.5 20 42C29 38.5 36 30.5 36 21V8.5L20 2Z"
        fill="#2563eb"
        fillOpacity="0.18"
        stroke="#2563eb"
        strokeWidth="1.5"
        strokeLinejoin="round"
      />
      <path
        d="M14 17.5C14 15.5 15.8 14 18 14H22C24.2 14 26 15.5 26 17.5C26 19.5 24.2 21 22 21H18C15.8 21 14 22.5 14 24.5C14 26.5 15.8 28 18 28H26"
        stroke="white"
        strokeWidth="2.2"
        strokeLinecap="round"
      />
      <circle cx="27" cy="14" r="2.5" fill="#f59e0b" />
    </svg>
  );
}

const NAV_ICONS = {
  dashboard: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <rect x="3" y="3" width="7" height="7" rx="1.5" />
      <rect x="14" y="3" width="7" height="7" rx="1.5" />
      <rect x="3" y="14" width="7" height="7" rx="1.5" />
      <rect x="14" y="14" width="7" height="7" rx="1.5" />
    </svg>
  ),
  network: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="5" r="2.5" />
      <circle cx="5" cy="19" r="2.5" />
      <circle cx="19" cy="19" r="2.5" />
      <path d="M12 7.5v5M7.1 17.4l3.4-3.2M16.9 17.4l-3.4-3.2" />
    </svg>
  ),
  alert: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
      <line x1="12" y1="9" x2="12" y2="13" />
      <line x1="12" y1="17" x2="12.01" y2="17" />
    </svg>
  ),
  investigation: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="11" cy="11" r="8" />
      <path d="M21 21l-4.35-4.35" />
      <line x1="8" y1="11" x2="14" y2="11" />
      <line x1="11" y1="8" x2="11" y2="14" />
    </svg>
  ),
  clients: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
      <circle cx="9" cy="7" r="4" />
      <path d="M23 21v-2a4 4 0 00-3-3.87" />
      <path d="M16 3.13a4 4 0 010 7.75" />
    </svg>
  ),
  accounts: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <rect x="2" y="5" width="20" height="14" rx="2" />
      <line x1="2" y1="10" x2="22" y2="10" />
    </svg>
  ),
  transactions: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="17 1 21 5 17 9" />
      <path d="M3 11V9a4 4 0 014-4h14" />
      <polyline points="7 23 3 19 7 15" />
      <path d="M21 13v2a4 4 0 01-4 4H3" />
    </svg>
  ),
  screening: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
    </svg>
  ),
  risk: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <line x1="18" y1="20" x2="18" y2="10" />
      <line x1="12" y1="20" x2="12" y2="4" />
      <line x1="6" y1="20" x2="6" y2="14" />
      <line x1="2" y1="20" x2="22" y2="20" />
    </svg>
  ),
  ml: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <polygon points="12 2 2 7 12 12 22 7 12 2" />
      <polyline points="2 17 12 22 22 17" />
      <polyline points="2 12 12 17 22 12" />
    </svg>
  ),
  reports: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
      <polyline points="14 2 14 8 20 8" />
      <line x1="16" y1="13" x2="8" y2="13" />
      <line x1="16" y1="17" x2="8" y2="17" />
    </svg>
  ),
  audit: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" />
      <rect x="9" y="3" width="6" height="4" rx="1" />
      <path d="M8 12h8M8 16h5" />
    </svg>
  ),
  centif: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 2l8 3.5V11c0 5-3.4 8.7-8 10-4.6-1.3-8-5-8-10V5.5L12 2z" />
      <path d="M9 12l2 2 4-4" />
    </svg>
  ),
  settings: (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="3" />
      <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" />
    </svg>
  ),
};

const STORAGE_KEY = "sentinelle_sidebar_collapsed";

export default function Sidebar(props) {
  return resolveRoleName(props.user) === "COMPLIANCE_OFFICER"
    ? <ComplianceSidebar {...props} />
    : <StandardSidebar {...props} />;
}

function StandardSidebar({ user, onLogout }) {
  const navSections = useMemo(() => getNavSectionsForUser(user), [user]);
  const roleName = resolveRoleName(user);
  const roleLabel = getRoleLabel(user);

  const [openAlertsCount, setOpenAlertsCount] = useState(null);
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem(STORAGE_KEY) === "1";
    } catch {
      return false;
    }
  });

  useEffect(() => {
    let cancelled = false;
    async function loadBadge() {
      try {
        const res = await getDashboardSummary();
        if (cancelled) return;
        const count = res?.data?.alerts?.open ?? null;
        if (count != null && !Number.isNaN(Number(count))) {
          setOpenAlertsCount(Number(count));
        } else {
          setOpenAlertsCount(null);
        }
      } catch {
        if (!cancelled) setOpenAlertsCount(null);
      }
    }
    loadBadge();
    return () => {
      cancelled = true;
    };
  }, []);

  const toggleCollapsed = () => {
    setCollapsed((prev) => {
      const next = !prev;
      try {
        localStorage.setItem(STORAGE_KEY, next ? "1" : "0");
      } catch {
        /* ignore */
      }
      return next;
    });
  };

  function resolveBadge(item) {
    if (item.badgeKey === "open_alerts") {
      if (openAlertsCount == null || openAlertsCount <= 0) return null;
      return openAlertsCount > 99 ? "99+" : String(openAlertsCount);
    }
    return null;
  }

  return (
    <aside
      className={"sidebar" + (collapsed ? " is-collapsed" : "")}
      aria-label="Navigation principale"
    >
      {/* Rangée 1 : logo (+ texte si ouvert) */}
      <div className="sidebar-brand-row">
        <div className="sidebar-brand">
          <div className="sidebar-logo-wrap" title="SENTINELLE·CIF">
            <SentinelleLogo size={collapsed ? 26 : 30} />
          </div>
          <div className="sidebar-brand-text">
            <div className="sidebar-brand-name">
              SENTINELLE<span>·</span>CIF
            </div>
            <div className="sidebar-brand-tagline">
              Supervision financière
            </div>
          </div>
        </div>
      </div>

      {/* Rangée 2 : bouton repli — jamais confondu avec le logo */}
      <div className="sidebar-toggle-row">
        <button
          type="button"
          className="sidebar-toggle"
          onClick={toggleCollapsed}
          title={collapsed ? "Ouvrir le menu" : "Replier le menu"}
          aria-expanded={!collapsed}
        >
          {collapsed ? (
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
              <line x1="3" y1="6" x2="21" y2="6" />
              <line x1="3" y1="12" x2="21" y2="12" />
              <line x1="3" y1="18" x2="21" y2="18" />
            </svg>
          ) : (
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
              <polyline points="15 18 9 12 15 6" />
            </svg>
          )}
        </button>
      </div>

      <nav className="sidebar-nav">
        {navSections.map((section) => (
          <div className="sidebar-section" key={section.label}>
            <div className="sidebar-section-label">{section.label}</div>
            {section.items.map((item) => {
              const badge = resolveBadge(item);
              return (
                <NavLink
                  key={item.to}
                  to={item.to}
                  title={item.label}
                  className={({ isActive }) =>
                    "sidebar-link" + (isActive ? " sidebar-link-active" : "")
                  }
                >
                  <span className="sidebar-link-icon">
                    {NAV_ICONS[item.icon] || null}
                  </span>
                  <span className="sidebar-link-label">{item.label}</span>
                  {badge ? (
                    <span className="sidebar-badge">{badge}</span>
                  ) : null}
                </NavLink>
              );
            })}
          </div>
        ))}
      </nav>

      <div className="sidebar-footer">
        <div className="sidebar-session">
          <span className="sidebar-session-dot" />
          <span className="sidebar-session-text">Session sécurisée</span>
          <span className="sidebar-session-sub">Sanctum</span>
        </div>
        <div className="sidebar-user">
          <div
            className={
              "sidebar-user-avatar" +
              (roleName === "COMPLIANCE_OFFICER" ? " is-compliance" : "")
            }
          >
            {(user?.username || "AC").slice(0, 2).toUpperCase()}
          </div>
          <div className="sidebar-user-meta">
            <div className="sidebar-user-name">{user?.username || "—"}</div>
            <div className="sidebar-user-role">
              {roleLabel}
              {user?.agency?.city ? ` · ${user.agency.city}` : ""}
            </div>
            {roleName ? (
              <div className="sidebar-user-role-chip" title={roleName}>
                {roleName === "COMPLIANCE_OFFICER"
                  ? "Conformité LBC-FT"
                  : roleName === "ADMIN"
                    ? "Admin"
                    : roleName === "SUPERVISOR"
                      ? "Superviseur"
                      : "Agent"}
              </div>
            ) : null}
          </div>
        </div>
        <button type="button" className="sidebar-logout" onClick={onLogout} title="Déconnexion">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4" />
            <polyline points="16 17 21 12 16 7" />
            <line x1="21" y1="12" x2="9" y2="12" />
          </svg>
          <span className="sidebar-logout-label">Déconnexion</span>
        </button>
      </div>
    </aside>
  );
}
