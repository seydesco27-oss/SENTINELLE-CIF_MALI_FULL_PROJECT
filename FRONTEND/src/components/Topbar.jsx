import { useState } from "react";
import "./Topbar.css";

export default function Topbar({ breadcrumb = [], user, notifCount = 0 }) {
  const [showMenu, setShowMenu] = useState(false);

  return (
    <div className="topbar">
      <div className="topbar-breadcrumb">
        {breadcrumb.map((item, i) => (
          <span key={i} className="topbar-crumb">
            {i > 0 && <span className="topbar-crumb-sep">/</span>}
            <span className={i === breadcrumb.length - 1 ? "topbar-crumb-current" : ""}>
              {item}
            </span>
          </span>
        ))}
      </div>

      <div className="topbar-actions">
        <div className="topbar-search">
          <span className="topbar-search-icon">⌕</span>
          <input type="text" placeholder="Rechercher un client, une alerte, une transaction..." />
        </div>

        <button className="topbar-icon-btn" title="Notifications">
          🔔
          {notifCount > 0 && <span className="topbar-notif-dot">{notifCount}</span>}
        </button>

        <div className="topbar-user" onClick={() => setShowMenu((s) => !s)}>
          <div className="topbar-user-avatar">
            {(user?.username || "AC").slice(0, 2).toUpperCase()}
          </div>
          <span className="topbar-user-name">{user?.username || "a.coulibaly"}</span>
          <span className="topbar-user-caret">▾</span>

          {showMenu && (
            <div className="topbar-user-menu">
              <div className="topbar-user-menu-item">Mon profil</div>
              <div className="topbar-user-menu-item">Changer le mot de passe</div>
              <div className="topbar-user-menu-item topbar-user-menu-danger">Déconnexion</div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}