import { useEffect, useState } from "react";
import { getDashboardSummary, getPriorityAlerts, getRiskDistribution, getAlertTrend, getInvestigations, getCentifDeclarations } from "../../services/api";

const EMPTY = { summary: null, alerts: null, risk: null, trend: null, investigations: null, declarations: null };
const SOURCES = ["indicateurs", "alertes prioritaires", "répartition des risques", "évolution des alertes", "investigations", "déclarations CENTIF"];

export default function useComplianceData(preview, days) {
  const [state, setState] = useState({ data: EMPTY, loading: true, failures: [], updatedAt: null });
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let active = true;
    async function load() {
      // Le report d’un microtask permet aussi d’annuler le premier effet en StrictMode.
      await Promise.resolve();
      if (!active) return;
      setState({ data: EMPTY, loading: true, failures: [], updatedAt: null });
      if (preview) {
        const { createCompliancePreview } = await import("./preview.js");
        if (!active) return;
        const data = createCompliancePreview();
        data.trend = data.trend.slice(-days);
        setState({ data, loading: false, failures: [], updatedAt: new Date() });
        return;
      }
      const results = await Promise.allSettled([
        getDashboardSummary(), getPriorityAlerts({ limit: 100 }), getRiskDistribution(),
        getAlertTrend(days), getInvestigations({ status: "OPEN", limit: 100 }),
        getCentifDeclarations({ transmission_status: "DRAFT", limit: 100 }),
      ]);
      if (!active) return;
      const failures = [];
      const values = results.map((result, index) => {
        if (result.status === "rejected" || !result.value?.success) {
          failures.push(SOURCES[index]);
          return null;
        }
        return result.value.data;
      });
      setState({
        data: { summary: values[0], alerts: values[1], risk: values[2]?.distribution ?? values[2], trend: values[3], investigations: values[4], declarations: values[5] },
        loading: false, failures, updatedAt: failures.length < SOURCES.length ? new Date() : null,
      });
    }
    load();
    return () => { active = false; };
  }, [preview, days, version]);

  return { ...state, refresh: () => setVersion((value) => value + 1) };
}
