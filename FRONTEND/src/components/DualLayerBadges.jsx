import { useEffect, useState } from "react";
import { getClientDualRisk } from "../services/api";
import "./DualLayerBadges.css";

/**
 * Badges dual AML (règles CIF) vs ML — jamais fusionnés.
 * Source : GET /clients/{id}/compliance/dual-risk
 */
export default function DualLayerBadges({ clientId, compact = false }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    async function load() {
      if (!clientId) return;
      try {
        const res = await getClientDualRisk(clientId);
        if (!cancelled && res?.success) setData(res);
        else if (!cancelled) setError(res?.message || "Dual-risk indisponible");
      } catch (e) {
        if (!cancelled) {
          setError(e.response?.data?.message || "Dual-risk indisponible");
        }
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, [clientId]);

  if (error && !data) {
    return (
      <div className={`dual-badges ${compact ? "dual-badges-compact" : ""}`}>
        <span className="dual-muted">{error}</span>
      </div>
    );
  }

  if (!data) {
    return (
      <div className={`dual-badges ${compact ? "dual-badges-compact" : ""}`}>
        <span className="dual-muted">Scores…</span>
      </div>
    );
  }

  const { flags = {}, aml = {}, ml = {} } = data;
  const amlScore = aml.score;
  const mlScore =
    ml.ai_score != null
      ? ml.ai_score
      : ml.latest_probability != null
      ? ml.latest_probability
      : null;

  return (
    <div className={`dual-badges ${compact ? "dual-badges-compact" : ""}`}>
      <div className="dual-row">
        <span className="dual-layer-tag dual-layer-aml">AML · règles</span>
        <span className={`dual-pill dual-pill-${(aml.level_code || "LOW").toLowerCase()}`}>
          {amlScore != null ? Number(amlScore).toFixed(0) : "—"}
          {aml.level_code ? ` · ${aml.level_code}` : ""}
        </span>
        {Number(aml.centif_hit_count) > 0 && (
          <span className="dual-pill dual-pill-centif" title="Hits CENTIF 15M">
            CENTIF ×{aml.centif_hit_count}
          </span>
        )}
      </div>

      <div className="dual-row">
        <span className="dual-layer-tag dual-layer-ml">ML · assist</span>
        {mlScore != null ? (
          <span className="dual-pill dual-pill-ml">
            {Number(mlScore).toFixed(2)}
            {ml.latest_predicted_risk ? ` · ${ml.latest_predicted_risk}` : ""}
          </span>
        ) : (
          <span className="dual-muted">Non scorifié</span>
        )}
      </div>

      {!compact && (
        <div className="dual-flags">
          <span className={`dual-flag ${Number(flags.is_pep) === 1 ? "on-pep" : ""}`}>
            PEP {Number(flags.is_pep) === 1 ? "oui" : "non"}
          </span>
          <span className={`dual-flag ${Number(flags.is_rca) === 1 ? "on-rca" : ""}`}>
            RCA {Number(flags.is_rca) === 1 ? "oui" : "non"}
          </span>
          {flags.kyc_status && (
            <span className="dual-flag">KYC {flags.kyc_status}</span>
          )}
        </div>
      )}
    </div>
  );
}
