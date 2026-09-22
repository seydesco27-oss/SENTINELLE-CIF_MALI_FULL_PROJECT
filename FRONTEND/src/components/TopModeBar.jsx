import "./TopModeBar.css";

export default function TopModeBar({
  step = 1,
  total = 10,
  label,
  instruction,
  badgeNum,
  badgeLabel,
  showNav = false,
  onPrevious,
  onNext,
  previousLabel = "Précédent",
  nextLabel = "Étape suivante",
}) {
  return (
    <div className="mode-demo-bar">
      <div className="mode-demo-text">
        <div className="mode-demo-step">
          MODE DÉMO {step} / {total} · {label}
        </div>
        <div className="mode-demo-instruction">{instruction}</div>
      </div>

      <div className="mode-demo-right">
        {showNav && (
          <div className="mode-demo-nav">
            <button className="mode-demo-nav-btn" onClick={onPrevious}>{previousLabel}</button>
            <button className="mode-demo-nav-btn mode-demo-nav-btn-primary" onClick={onNext}>{nextLabel}</button>
          </div>
        )}
        <button className="mode-demo-badge">
          <span className="mode-demo-badge-num">{badgeNum}</span>
          <span>{badgeLabel}</span>
        </button>
      </div>
    </div>
  );
}