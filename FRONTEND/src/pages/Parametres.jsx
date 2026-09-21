import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import "./Parametres.css";

export default function Parametres({ user, onLogout }) {
  const role =
    user?.role ||
    user?.roles?.[0] ||
    (Array.isArray(user?.roles) ? user.roles.join(", ") : null) ||
    "—";

  const agencyName =
    user?.agency_name ||
    user?.agency?.name ||
    user?.agency_code ||
    (user?.agency_id != null ? `Agence #${user.agency_id}` : "—");

  const city =
    user?.city ||
    user?.agency?.city ||
    user?.agency_city ||
    "—";

  return (
    <div className="app-shell">
      <Sidebar user={user} onLogout={onLogout} />
      <div className="app-main">
        <DemoRail />

        <section className="page-frame param-page">
          <header className="page-heading">
            <div>
              <p className="eyebrow">SYSTÈME / COMPTE</p>
              <h1>Paramètres</h1>
              <p>
                Profil de session et rattachement opérationnel. Aucune
                modification de droits depuis cet écran en V1.
              </p>
            </div>
          </header>

          <div className="param-grid">
            <article className="param-card">
              <div className="param-card-title">INFORMATIONS UTILISATEUR</div>
              <div className="param-row">
                <span className="param-label">Identifiant</span>
                <span className="param-value">{user?.username || "—"}</span>
              </div>
              <div className="param-row">
                <span className="param-label">Nom affiché</span>
                <span className="param-value">
                  {user?.full_name || user?.name || user?.username || "—"}
                </span>
              </div>
              <div className="param-row">
                <span className="param-label">Rôle</span>
                <span className="param-value">{role}</span>
              </div>
              <div className="param-row">
                <span className="param-label">ID utilisateur</span>
                <span className="param-value param-mono">
                  {user?.id ?? "—"}
                </span>
              </div>
            </article>

            <article className="param-card">
              <div className="param-card-title">RATTACHEMENT</div>
              <div className="param-row">
                <span className="param-label">Agence</span>
                <span className="param-value">{agencyName}</span>
              </div>
              <div className="param-row">
                <span className="param-label">Ville</span>
                <span className="param-value">{city}</span>
              </div>
              <div className="param-row">
                <span className="param-label">Caisse / réseau</span>
                <span className="param-value">
                  {user?.caisse_name || user?.network || "CIF · Mali"}
                </span>
              </div>
            </article>

            <article className="param-card param-card-wide">
              <div className="param-card-title">SESSION</div>
              <div className="param-row">
                <span className="param-label">État</span>
                <span className="param-value param-ok">Connecté</span>
              </div>
              <div className="param-row">
                <span className="param-label">Authentification</span>
                <span className="param-value">Laravel Sanctum (token)</span>
              </div>
              <p className="param-hint">
                Les préférences avancées (seuils AML, notifications) seront
                exposées ici lorsqu’elles seront disponibles côté API — voir
                backlog innovation.
              </p>
              {typeof onLogout === "function" && (
                <button
                  type="button"
                  className="param-logout"
                  onClick={onLogout}
                >
                  Se déconnecter
                </button>
              )}
            </article>
          </div>
        </section>
      </div>
    </div>
  );
}
