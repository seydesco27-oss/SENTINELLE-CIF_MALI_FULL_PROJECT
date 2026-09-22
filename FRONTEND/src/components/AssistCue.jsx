/**
 * Bandeau / badge pour susciter l'usage de Sentinelle Assist
 * Props:
 *  - variant: "banner" | "chip"
 *  - title, text
 *  - onOpen: callback ouverture tiroir
 *  - ctaLabel
 */
export default function AssistCue({
  variant = "banner",
  title = "Sentinelle Assist",
  text = "L’agent de conformité peut résumer un dossier, expliquer les signaux et préparer un brouillon.",
  ctaLabel = "Ouvrir Assist",
  onOpen,
}) {
  if (variant === "chip") {
    return (
      <button type="button" className="assist-cue-chip" onClick={onOpen}>
        <span className="assist-cue-dot" />
        {title}
      </button>
    );
  }

  return (
    <div className="assist-cue-banner" role="region" aria-label="Sentinelle Assist">
      <div className="assist-cue-banner-main">
        <span className="assist-cue-kicker">IA · AIDE À L’ANALYSE</span>
        <strong>{title}</strong>
        <p>{text}</p>
      </div>
      <button type="button" className="assist-cue-cta" onClick={onOpen}>
        {ctaLabel}
      </button>
    </div>
  );
}
