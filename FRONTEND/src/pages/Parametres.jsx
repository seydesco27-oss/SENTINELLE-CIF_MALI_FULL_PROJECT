import { useState } from "react";
import Sidebar from "../components/Sidebar";
import DemoRail from "../components/DemoRail";
import { storeUser, updateMyProfile } from "../services/api";
import {
  getPermissions,
  getUserDisplayName,
  getWorkspaceLabel,
  PERMISSION_LABELS,
} from "../auth/access";
import "./Parametres.css";

export default function Parametres({ user, onLogout }) {
  const permissions = getPermissions(user);
  const [savingProfile, setSavingProfile] = useState(false);
  const [profileMessage, setProfileMessage] = useState(null);
  const storedProfile = user?.profile || {};
  const profileRevision = `${user?.id || "anonymous"}:${
    storedProfile.updated_at || user?.profile_updated_at || user?.full_name || user?.username || "empty"
  }`;

  const saveProfile = async (event) => {
    event.preventDefault();
    setSavingProfile(true);
    setProfileMessage(null);

    try {
      const formData = new FormData(event.currentTarget);
      const response = await updateMyProfile({
        first_name: String(formData.get("first_name") || ""),
        last_name: String(formData.get("last_name") || ""),
        email: String(formData.get("email") || ""),
        phone: String(formData.get("phone") || ""),
        job_title: String(formData.get("job_title") || ""),
      });
      if (!response?.success || !response.data) {
        throw new Error(response?.message || "Impossible de mettre à jour le profil.");
      }

      storeUser(response.data);
      window.dispatchEvent(new Event("sentinelle-auth-changed"));
      setProfileMessage({ type: "success", text: response.message });
    } catch (error) {
      const errors = error.response?.data?.errors;
      const firstError = errors
        ? Object.values(errors).flat().find(Boolean)
        : null;
      setProfileMessage({
        type: "error",
        text:
          firstError ||
          error.response?.data?.message ||
          error.message ||
          "Impossible de mettre à jour le profil.",
      });
    } finally {
      setSavingProfile(false);
    }
  };
  const role =
    user?.role?.name ||
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
              <p className="eyebrow">SESSION / PROFIL</p>
              <h1>Mon compte</h1>
              <p>
                Identité professionnelle, rattachement et périmètre d’accès de
                la session active.
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
                <span className="param-label">Nom complet</span>
                <span className="param-value">{getUserDisplayName(user)}</span>
              </div>
              <div className="param-row">
                <span className="param-label">Fonction</span>
                <span className="param-value">
                  {user?.profile?.job_title || user?.job_title || "—"}
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
                  {user?.caisse_name ||
                    user?.agency?.caisse?.name ||
                    user?.network ||
                    "CIF · Mali"}
                </span>
              </div>
            </article>

            <form
              className="param-card param-card-wide"
              key={profileRevision}
              onSubmit={saveProfile}
            >
              <div className="param-card-title">PROFIL PROFESSIONNEL</div>
              <div className="param-profile-intro">
                Ces informations identifient nominativement la personne connectée
                dans l’interface, le journal et Sentinelle Assist.
              </div>
              <div className="param-form-grid">
                <label>
                  <span>Prénom</span>
                  <input
                    required
                    name="first_name"
                    minLength={2}
                    maxLength={100}
                    defaultValue={storedProfile.first_name || user?.first_name || ""}
                    onChange={() => setProfileMessage(null)}
                    autoComplete="given-name"
                  />
                </label>
                <label>
                  <span>Nom</span>
                  <input
                    required
                    name="last_name"
                    minLength={2}
                    maxLength={100}
                    defaultValue={storedProfile.last_name || user?.last_name || ""}
                    onChange={() => setProfileMessage(null)}
                    autoComplete="family-name"
                  />
                </label>
                <label>
                  <span>Courriel professionnel</span>
                  <input
                    type="email"
                    name="email"
                    maxLength={190}
                    defaultValue={storedProfile.email || user?.email || ""}
                    onChange={() => setProfileMessage(null)}
                    autoComplete="email"
                  />
                </label>
                <label>
                  <span>Téléphone</span>
                  <input
                    type="tel"
                    name="phone"
                    maxLength={30}
                    defaultValue={storedProfile.phone || user?.phone || ""}
                    onChange={() => setProfileMessage(null)}
                    autoComplete="tel"
                  />
                </label>
                <label className="param-form-wide">
                  <span>Fonction</span>
                  <input
                    name="job_title"
                    maxLength={150}
                    defaultValue={storedProfile.job_title || user?.job_title || ""}
                    onChange={() => setProfileMessage(null)}
                    placeholder="Ex. Analyste conformité LBC-FT"
                    autoComplete="organization-title"
                  />
                </label>
              </div>
              <div className="param-profile-actions">
                <button
                  type="submit"
                  className="param-save"
                  disabled={savingProfile}
                >
                  {savingProfile ? "Enregistrement…" : "Enregistrer le profil"}
                </button>
                {profileMessage ? (
                  <span className={`param-profile-message is-${profileMessage.type}`}>
                    {profileMessage.text}
                  </span>
                ) : null}
              </div>
            </form>

            <article className="param-card param-card-wide">
              <div className="param-card-title">PÉRIMÈTRE D’ACCÈS</div>
              <div className="param-access-heading">
                {getWorkspaceLabel(user)}
              </div>
              <p className="param-access-copy">
                Ces accès sont déterminés par le rôle associé à votre compte.
              </p>
              <div className="param-permissions" aria-label="Fonctions autorisées">
                {permissions.map((permission) => (
                  <span className="param-permission" key={permission}>
                    <span aria-hidden="true">✓</span>
                    {PERMISSION_LABELS[permission] || permission}
                  </span>
                ))}
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
                <span className="param-value">Jeton de session sécurisé</span>
              </div>
              <p className="param-hint">
                Pour modifier votre rôle ou votre rattachement, contactez un
                administrateur de la plateforme.
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
