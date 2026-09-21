import { useEffect, useState } from "react";
import { postAssist, postAssistChat, postMlScore } from "../services/api";
import "./AssistPanel.css";

/**
 * Sentinelle Assist — tiroir droit repliable (agent de conformité)
 *
 * Props:
 *  - objectType: "alert" | "client"
 *  - objectId: number
 *  - transactionId?: number (pour score ML)
 *  - title?: string
 *  - defaultOpen?: boolean
 */
export default function AssistPanel({
  objectType = "alert",
  objectId = null,
  transactionId = null,
  title = "Sentinelle Assist",
  defaultOpen = false,
  open: openProp = null,
  onOpenChange = null,
}) {
  const [openInternal, setOpenInternal] = useState(defaultOpen);
  const open = openProp != null ? openProp : openInternal;
  const setOpen = (v) => {
    if (onOpenChange) onOpenChange(v);
    if (openProp == null) setOpenInternal(v);
  };
  const [loading, setLoading] = useState(false);
  const [mlLoading, setMlLoading] = useState(false);
  const [error, setError] = useState("");
  const [result, setResult] = useState(null);
  const [mlScore, setMlScore] = useState(null);
  const [question, setQuestion] = useState("");
  const [messages, setMessages] = useState([
    {
      role: "assistant",
      content: "Je peux résumer le dossier, expliquer les signaux, proposer des vérifications ou préparer un brouillon CENTIF.",
    },
  ]);

  // Reset when dossier change
  /* eslint-disable react-hooks/set-state-in-effect */
  useEffect(() => {
    setResult(null);
    setMlScore(null);
    setError("");
    setQuestion("");
    setMessages([
      {
        role: "assistant",
        content: "Je peux résumer le dossier, expliquer les signaux, proposer des vérifications ou préparer un brouillon CENTIF.",
      },
    ]);
  }, [objectType, objectId]);
  /* eslint-enable react-hooks/set-state-in-effect */

  async function run(act) {
    if (!objectId) return;
    setLoading(true);
    setError("");
    try {
      const res = await postAssist({
        object_type: objectType,
        object_id: Number(objectId),
        action: act,
      });
      if (res?.success) setResult(res.data);
      else setError(res?.message || "Assist indisponible.");
    } catch (err) {
      setError(
        err.response?.data?.message ||
          "Impossible de contacter Sentinelle Assist (API)."
      );
    } finally {
      setLoading(false);
    }
  }

  async function runMlScore() {
    setMlLoading(true);
    setError("");
    try {
      const body =
        transactionId != null
          ? { transaction_id: Number(transactionId) }
          : objectType === "alert"
            ? { alert_id: Number(objectId) }
          : objectType === "client"
            ? { client_id: Number(objectId) }
            : {};
      const res = await postMlScore(body);
      if (res?.success) {
        setMlScore(res.data);
        setMessages((current) => [
          ...current,
          {
            role: "assistant",
            content: `Score ML calculé : ${res.data?.label || "indisponible"}. Score final : ${res.data?.final_score != null ? Number(res.data.final_score).toFixed(2) : "—"}.`,
          },
        ]);
      }
      else setError(res?.message || "Score ML indisponible.");
    } catch (err) {
      setError(
        err.response?.data?.message ||
          "Service ML non joignable (port 8100)."
      );
    } finally {
      setMlLoading(false);
    }
  }

  async function runChat(message) {
    if (!objectId || !message) return;
    setLoading(true);
    setError("");
    setMessages((current) => [...current, { role: "user", content: message }]);
    try {
      const res = await postAssistChat({
        object_type: objectType,
        object_id: Number(objectId),
        message,
      });
      if (res?.success) {
        setResult(res.data?.payload || null);
        setMessages((current) => [
          ...current,
          {
            role: "assistant",
            content: res.data?.message || "Réponse contextuelle disponible.",
          },
        ]);
      } else {
        setError(res?.message || "Chatbot indisponible.");
      }
    } catch (err) {
      setError(
        err.response?.data?.message ||
          "Impossible de contacter le chatbot de conformité."
      );
    } finally {
      setLoading(false);
    }
  }

  function onAsk(e) {
    e.preventDefault();
    const text = question.trim();
    const q = text.toLowerCase();
    if (!q) return;
    if (q.includes("score") || q.includes("ml")) {
      setMessages((current) => [...current, { role: "user", content: text }]);
      runMlScore();
    } else runChat(text);
    setQuestion("");
  }

  function copyDraft() {
    if (result?.draft) navigator.clipboard?.writeText(result.draft);
  }

  return (
    <>
      {/* Bouton flottant d'ouverture */}
      {!open && (
        <button
          type="button"
          className="assist-fab"
          onClick={() => setOpen(true)}
          title="Ouvrir Sentinelle Assist"
        >
          <span className="assist-fab-icon">◈</span>
          <span className="assist-fab-label">Assist</span>
        </button>
      )}

      {/* Overlay léger */}
      {open && (
        <div className="assist-overlay" onClick={() => setOpen(false)} />
      )}

      {/* Tiroir droit */}
      <aside
        className={`assist-drawer ${open ? "is-open" : ""}`}
        aria-hidden={!open}
      >
        <header className="assist-drawer-header">
          <div>
            <p className="assist-eyebrow">AGENT DE CONFORMITÉ</p>
            <h3>{title}</h3>
          </div>
          <div className="assist-drawer-header-actions">
            <span className="assist-badge">Aide · non décisionnel</span>
            <button
              type="button"
              className="assist-close"
              onClick={() => setOpen(false)}
              aria-label="Fermer"
            >
              ✕
            </button>
          </div>
        </header>

        <div className="assist-drawer-body">
          <div className="assist-quick">
            <button
              type="button"
              className="assist-btn primary"
              disabled={loading || !objectId}
              onClick={() => run("summarize")}
            >
              {loading ? "Analyse…" : "Résumer"}
            </button>
            <button
              type="button"
              className="assist-btn"
              disabled={loading || !objectId}
              onClick={() => run("explain")}
            >
              Expliquer
            </button>
            <button
              type="button"
              className="assist-btn"
              disabled={loading || !objectId}
              onClick={() => run("suggest_questions")}
            >
              Questions
            </button>
            {objectType === "alert" && (
              <button
                type="button"
                className="assist-btn"
                disabled={loading || !objectId}
                onClick={() => run("draft_centif")}
              >
                Brouillon
              </button>
            )}
            <button
              type="button"
              className="assist-btn"
              disabled={mlLoading}
              onClick={runMlScore}
            >
              {mlLoading ? "Score…" : "Score ML"}
            </button>
          </div>

          {error && <div className="assist-error">{error}</div>}

          {objectId && (
            <div className="assist-conversation" aria-live="polite">
              {messages.map((message, index) => (
                <div
                  className={`assist-message is-${message.role}`}
                  key={`${message.role}-${index}`}
                >
                  <span>{message.role === "user" ? "Vous" : "Assist"}</span>
                  <p>{message.content}</p>
                </div>
              ))}
            </div>
          )}

          {mlScore && (
            <div className="assist-ml-box">
              <h4>Score ML (couche 2)</h4>
              <div className="assist-ml-grid">
                <div>
                  <span>Final</span>
                  <strong>
                    {mlScore.final_score != null
                      ? Number(mlScore.final_score).toFixed(2)
                      : "—"}
                  </strong>
                </div>
                <div>
                  <span>Modèle</span>
                  <strong>
                    {mlScore.model_score != null
                      ? Number(mlScore.model_score).toFixed(2)
                      : "—"}
                  </strong>
                </div>
                <div>
                  <span>Règles</span>
                  <strong>
                    {mlScore.rule_score != null
                      ? Number(mlScore.rule_score).toFixed(2)
                      : "—"}
                  </strong>
                </div>
                <div>
                  <span>Niveau</span>
                  <strong>{mlScore.label || "—"}</strong>
                </div>
              </div>
              <p className="assist-disclaimer">
                Signal de priorisation uniquement. Moteur :{" "}
                {mlScore.engine || "n/d"}
              </p>
            </div>
          )}

          {result && (
            <div className="assist-body">
              <section>
                <h4>Synthèse</h4>
                <pre className="assist-summary">{result.summary}</pre>
              </section>

              {Array.isArray(result.factors) && result.factors.length > 0 && (
                <section>
                  <h4>Facteurs</h4>
                  <ul className="assist-factors">
                    {result.factors.map((f, i) => (
                      <li key={i}>
                        <span>{f.label}</span>
                        <strong>{f.value}</strong>
                      </li>
                    ))}
                  </ul>
                </section>
              )}

              {Array.isArray(result.questions) && result.questions.length > 0 && (
                <section>
                  <h4>Pistes de diligence</h4>
                  <ol className="assist-questions">
                    {result.questions.map((q, i) => (
                      <li key={i}>{q}</li>
                    ))}
                  </ol>
                </section>
              )}

              {result.draft && (
                <section>
                  <div className="assist-draft-head">
                    <h4>Brouillon</h4>
                    <button
                      type="button"
                      className="assist-btn small"
                      onClick={copyDraft}
                    >
                      Copier
                    </button>
                  </div>
                  <pre className="assist-draft">{result.draft}</pre>
                </section>
              )}

              <p className="assist-disclaimer">
                {result.disclaimer ||
                  "Assistance analytique — ne constitue pas une décision de conformité."}
              </p>
            </div>
          )}

          
          {!objectId && (
            <div className="assist-body">
              <section>
                <h4>Comment utiliser Assist</h4>
                <ol className="assist-questions">
                  <li>Ouvrez une <strong>alerte</strong> ou un <strong>client</strong> pour un résumé contextualisé.</li>
                  <li>Sur un dossier, cliquez <strong>Résumer</strong> ou <strong>Expliquer les signaux</strong>.</li>
                  <li>Utilisez <strong>Score ML</strong> si le service Python est démarré.</li>
                  <li>Les réponses sont une <strong>aide</strong> — la décision reste humaine.</li>
                </ol>
              </section>
            </div>
          )}

          {!result && !loading && !error && !mlScore && objectId && (
            <p className="assist-hint">
              Ouvrez une action rapide ci-dessus, ou posez une question
              (résumer, expliquer, score ML, brouillon CENTIF…).
            </p>
          )}
        </div>

        <form className="assist-chatbar" onSubmit={onAsk}>
          <input
            type="text"
            placeholder="Ex. résumer cette alerte…"
            value={question}
            onChange={(e) => setQuestion(e.target.value)}
            disabled={!objectId}
          />
          <button type="submit" className="assist-btn primary" disabled={!objectId || loading}>
            Envoyer
          </button>
        </form>
      </aside>
    </>
  );
}
