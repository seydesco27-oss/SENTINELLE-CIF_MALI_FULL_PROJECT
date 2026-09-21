import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { login } from "../services/api";
import "./Login.css";

export default function Login() {
  const navigate = useNavigate();
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [visible, setVisible] = useState(false);
  const [keepSession, setKeepSession] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const res = await login(username.trim(), password);

      if (res.success && res.data?.token) {
        localStorage.setItem("token", res.data.token);
        localStorage.setItem("user", JSON.stringify(res.data.user));
        if (keepSession) {
          localStorage.setItem("keepSession", "1");
        } else {
          localStorage.removeItem("keepSession");
        }
        navigate("/dashboard", { replace: true });
      } else {
        setError(res.message || "Connexion impossible.");
      }
    } catch (err) {
      const msg =
        err.response?.data?.message ||
        err.response?.data?.errors?.username?.[0] ||
        "Identifiants invalides ou serveur indisponible.";
      setError(msg);
    } finally {
      setLoading(false);
    }
  };

  return (
    <main className="login-v2">
      {/* === ASIDE (contrat Figma) === */}
      <section className="login-aside">
        <div className="login-brand">
          <div className="login-shield">S</div>
          <div>
            <strong>
              SENTINELLE<span>·</span>CIF
            </strong>
            <small>Plateforme de supervision financière</small>
          </div>
        </div>

        <div className="login-statement">
          <p className="eyebrow">CONFORMITÉ · VIGILANCE · CONFIANCE</p>
          <h1>La vigilance financière, avec discernement.</h1>
          <p>
            Un espace sécurisé pour les équipes en charge de la lutte contre le
            blanchiment de capitaux et le financement du terrorisme.
          </p>
        </div>

        <footer>
          République du Mali <span>·</span> Environnement de démonstration
          sécurisé
        </footer>
      </section>

      {/* === FORMULAIRE === */}
      <section className="login-form-zone">
        <form onSubmit={handleSubmit}>
          <div className="form-logo">S</div>
          <p className="eyebrow">ACCÈS CONTRÔLÉ</p>
          <h2>Connexion à votre espace</h2>
          <p className="form-intro">
            Utilisez vos identifiants professionnels pour poursuivre.
          </p>

          {error && <div className="login-error">{error}</div>}

          <label>
            Identifiant
            <input
              type="text"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              autoComplete="username"
              disabled={loading}
              required
            />
          </label>

          <label>
            Mot de passe
            <div className="password-wrap">
              <input
                type={visible ? "text" : "password"}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="current-password"
                disabled={loading}
                required
              />
              <button
                type="button"
                onClick={() => setVisible((v) => !v)}
                disabled={loading}
              >
                {visible ? "Masquer" : "Afficher"}
              </button>
            </div>
          </label>

          <div className="form-options">
            <label className="remember">
              <input
                type="checkbox"
                checked={keepSession}
                onChange={(e) => setKeepSession(e.target.checked)}
                disabled={loading}
              />
              Maintenir la session
            </label>
            <button type="button" className="forgot-btn">
              Identifiants oubliés ?
            </button>
          </div>

          <button type="submit" className="login-submit" disabled={loading}>
            {loading ? "Connexion…" : "Se connecter"} <span>→</span>
          </button>

          <button
            type="button"
            className="demo-login"
            disabled={loading}
            onClick={() => navigate("/dashboard?demo=1&step=1")}
          >
            Accéder au parcours démo
          </button>

          <div className="secure-note">
            <span>✓</span> Session chiffrée · Accès journalisé
          </div>
        </form>
      </section>
    </main>
  );
}
