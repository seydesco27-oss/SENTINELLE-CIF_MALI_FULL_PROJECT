import { useEffect, useRef, useState } from "react";
import { postAssistChat, postMlScore } from "../services/api";
import "./AssistPanel.css";

function renderInline(text) {
  return String(text)
    .split(/(\*\*[^*]+\*\*|`[^`]+`)/g)
    .filter(Boolean)
    .map((part, index) => {
      if (part.startsWith("**") && part.endsWith("**")) {
        return <strong key={index}>{part.slice(2, -2)}</strong>;
      }
      if (part.startsWith("`") && part.endsWith("`")) {
        return <code key={index}>{part.slice(1, -1)}</code>;
      }
      return part;
    });
}

function AssistantContent({ content }) {
  const lines = String(content || "").replace(/\r/g, "").split("\n");
  const blocks = [];
  const isTitle = (line) =>
    /^#{1,4}\s+/.test(line) || /^\*\*[^*]+\*\*$/.test(line);
  const isBullet = (line) => /^[-•]\s+/.test(line);
  const isNumbered = (line) => /^\d+[.)]\s+/.test(line);

  for (let index = 0; index < lines.length;) {
    const line = lines[index].trim();
    if (!line) {
      index += 1;
      continue;
    }
    if (/^(---+|___+|\*\*\*+)$/.test(line)) {
      index += 1;
      continue;
    }

    if (isTitle(line)) {
      blocks.push({
        type: "title",
        text: line.replace(/^#{1,4}\s+/, "").replace(/^\*\*|\*\*$/g, ""),
      });
      index += 1;
      continue;
    }

    if (isBullet(line) || isNumbered(line)) {
      const numbered = isNumbered(line);
      const items = [];
      while (index < lines.length) {
        const item = lines[index].trim();
        if (numbered ? !isNumbered(item) : !isBullet(item)) break;
        items.push(item.replace(numbered ? /^\d+[.)]\s+/ : /^[-•]\s+/, ""));
        index += 1;
      }
      blocks.push({ type: numbered ? "ordered" : "list", items });
      continue;
    }

    const paragraph = [line];
    index += 1;
    while (index < lines.length) {
      const next = lines[index].trim();
      if (!next || isTitle(next) || isBullet(next) || isNumbered(next)) break;
      paragraph.push(next);
      index += 1;
    }
    blocks.push({ type: "paragraph", text: paragraph.join(" ") });
  }

  return (
    <div className="assist-rich-answer">
      {blocks.map((block, index) => {
        if (block.type === "title") {
          return <h4 key={index}>{renderInline(block.text)}</h4>;
        }
        if (block.type === "list" || block.type === "ordered") {
          const List = block.type === "ordered" ? "ol" : "ul";
          return (
            <List key={index}>
              {block.items.map((item, itemIndex) => (
                <li key={itemIndex}>{renderInline(item)}</li>
              ))}
            </List>
          );
        }
        return <p key={index}>{renderInline(block.text)}</p>;
      })}
    </div>
  );
}

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
  const conversationEndRef = useRef(null);
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

  useEffect(() => {
    conversationEndRef.current?.scrollIntoView({
      behavior: "smooth",
      block: "nearest",
    });
  }, [messages, loading]);

  async function run(act) {
    const prompts = {
      summarize: "Résume le dossier en cours à partir des données vérifiées.",
      explain: "Explique les signaux et les règles déclenchées dans le dossier en cours.",
      suggest_questions: "Propose les prochaines vérifications de diligence pour le dossier en cours.",
      draft_centif: "Prépare un brouillon CENTIF factuel à partir du dossier en cours.",
    };
    if (!objectId || !prompts[act]) return;
    await runChat(prompts[act]);
  }

  async function runMlScore() {
    setMlLoading(true);
    setError("");
    try {
      const body =
        objectType === "alert"
          ? { alert_id: Number(objectId) }
          : transactionId != null
            ? { transaction_id: Number(transactionId) }
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
            content: `Analyse ML enregistrée. Modèle : ${res.data?.model_score != null ? Number(res.data.model_score).toFixed(1) : "—"}/100. Score opérationnel : ${res.data?.operational_score != null ? Number(res.data.operational_score).toFixed(1) : "—"}/100 (${res.data?.risk_level || "niveau indisponible"}).`,
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
    if (loading || !message) return;
    setLoading(true);
    setError("");
    // Le message d'accueil est une aide UI, pas un tour réellement produit
    // par le modèle. L'historique commence toujours par l'utilisateur.
    const nextHistory = [
      ...messages.slice(1),
      { role: "user", content: message },
    ].slice(-12);
    setMessages((current) => [
      ...current,
      { role: "user", content: message },
    ]);
    try {
      const res = await postAssistChat({
        object_type: objectId ? objectType : "general",
        object_id: objectId ? Number(objectId) : null,
        message,
        history: nextHistory,
      });
      if (res?.success) {
        // Une discussion affiche uniquement les tours du chat : elle ne doit
        // pas être suivie d'une synthèse générée par les anciens templates.
        setResult(null);
        setMessages((current) => [
          ...current,
          {
            role: "assistant",
            content: res.data?.message || "Réponse contextuelle disponible.",
            sources: res.data?.sources || [],
            model: res.data?.model || null,
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
    if (!text || loading) return;
    // Le modèle choisit lui-même les recherches à exécuter, y compris pour
    // les questions de score ML. Le bouton « Score ML » reste un raccourci.
    runChat(text);
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
            <div className="assist-suggestions" aria-label="Suggestions de recherche">
              <span>Explorer</span>
              <button type="button" onClick={() => runChat("Fais la liste des alertes critiques ouvertes.")} disabled={loading}>Alertes critiques</button>
              <button type="button" onClick={() => runChat("Analyse les risques du dossier en cours.")} disabled={loading}>Analyser ce dossier</button>
              <button type="button" onClick={() => setQuestion("Le client ")} disabled={loading}>Rechercher un client</button>
            </div>
          )}

          {objectId && (
            <div className="assist-conversation" aria-live="polite">
              {messages.map((message, index) => (
                <div
                  className={`assist-message is-${message.role}`}
                  key={`${message.role}-${index}`}
                >
                  <span>{message.role === "user" ? "Vous" : "Assist"}</span>
                  {message.role === "assistant" ? (
                    <>
                      <AssistantContent content={message.content} />
                      {Array.isArray(message.sources) && message.sources.length > 0 && (
                        <div className="assist-answer-meta">
                          <span aria-hidden="true">✓</span>
                          {message.sources.includes("session_authentifiee")
                            ? "Identité de session vérifiée"
                            : `Base interrogée · ${message.sources.length} vérification${message.sources.length > 1 ? "s" : ""}`}
                        </div>
                      )}
                    </>
                  ) : (
                    <p>{message.content}</p>
                  )}
                </div>
              ))}
              {loading && (
                <div className="assist-message is-assistant is-loading" aria-label="Analyse en cours">
                  <span>Assist</span>
                  <div className="assist-typing" aria-hidden="true">
                    <i />
                    <i />
                    <i />
                  </div>
                </div>
              )}
              <div ref={conversationEndRef} />
            </div>
          )}

          {mlScore && (
            <div className="assist-ml-box">
              <h4>Score ML (couche 2)</h4>
              <div className="assist-ml-grid">
                <div>
                  <span>Opérationnel</span>
                  <strong>
                    {mlScore.operational_score != null
                      ? Number(mlScore.operational_score).toFixed(1)
                      : "—"}
                  </strong>
                </div>
                <div>
                  <span>Modèle</span>
                  <strong>
                    {mlScore.model_score != null
                      ? Number(mlScore.model_score).toFixed(1)
                      : "—"}
                  </strong>
                </div>
                <div>
                  <span>Règles</span>
                  <strong>
                    {mlScore.rule_score != null
                      ? Number(mlScore.rule_score).toFixed(1)
                      : "—"}
                  </strong>
                </div>
                <div>
                  <span>Fusion</span>
                  <strong>
                    {mlScore.fused_score != null
                      ? Number(mlScore.fused_score).toFixed(1)
                      : "—"}
                  </strong>
                </div>
              </div>
              <p className="assist-disclaimer">
                Niveau retenu : {mlScore.risk_level || "—"}. Signal de
                priorisation uniquement. Moteur : {mlScore.engine || "n/d"}
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
            disabled={loading}
          />
          <button type="submit" className="assist-btn primary" disabled={loading}>
            Envoyer
          </button>
        </form>
      </aside>
    </>
  );
}
