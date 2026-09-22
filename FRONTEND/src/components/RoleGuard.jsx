import { Navigate, useLocation } from "react-router-dom";
import { canAccessPath, getRoleLabel, resolveRoleName } from "../config/roles";
import "./RoleGuard.css";

/**
 * Garde de route par rôle.
 * À placer sous ProtectedRoute (auth déjà vérifiée).
 * Si accès refusé → écran sobre "Accès restreint" (pas de redirect silencieux).
 */
export default function RoleGuard({ user, children }) {
  const location = useLocation();
  const path = location.pathname;

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  if (!canAccessPath(user, path)) {
    return (
      <main className="role-denied">
        <div className="role-denied-card">
          <div className="role-denied-icon" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" />
              <path d="M7 11V7a5 5 0 0110 0v4" />
            </svg>
          </div>
          <p className="role-denied-eyebrow">ACCÈS RESTREINT</p>
          <h1>Cette zone n’est pas ouverte à votre profil</h1>
          <p className="role-denied-body">
            Votre rôle <strong>{getRoleLabel(user)}</strong>
            {resolveRoleName(user) ? (
              <span className="role-denied-code"> ({resolveRoleName(user)})</span>
            ) : null}{" "}
            ne permet pas d’ouvrir <code>{path}</code>.
          </p>
          <p className="role-denied-hint">
            Si vous estimez que cet accès est nécessaire pour votre mission LBC-FT,
            contactez l’administrateur de la plateforme.
          </p>
          <a className="role-denied-cta" href="/dashboard">
            Retour au tableau de bord
          </a>
        </div>
      </main>
    );
  }

  return resolveRoleName(user) === "COMPLIANCE_OFFICER"
    ? <div className="compliance-workspace">{children}</div>
    : children;
}
