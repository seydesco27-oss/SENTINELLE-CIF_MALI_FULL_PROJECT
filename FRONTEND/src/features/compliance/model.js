export const PRIORITIES = { CRITICAL: "Critique", HIGH: "Élevée", MEDIUM: "Modérée", LOW: "Faible" };
export const ALERT_TYPES = {
  LARGE_AMOUNT: "Montant inhabituel", STRUCTURING: "Fractionnement d’opérations",
  RAPID_TRANSFER: "Mouvements rapides", HIGH_RISK_CORRIDOR: "Corridor sensible",
  PEP_MATCH: "Correspondance PPE", SANCTION_MATCH: "Correspondance sanctions",
  CASH_ACTIVITY: "Activité espèces inhabituelle",
};

export function number(value) {
  return value == null || value === "" || !Number.isFinite(Number(value)) ? "—" : Number(value).toLocaleString("fr-FR");
}

export function filterAlerts(alerts, { priority = "ALL", search = "" } = {}) {
  const query = search.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();
  const rank = { CRITICAL: 0, HIGH: 1, MEDIUM: 2, LOW: 3 };
  return alerts.filter((alert) => {
    if (priority !== "ALL" && alert.priority !== priority) return false;
    const text = [alert.client_name, alert.customer_name, alert.reference, alert.id, alert.title, ALERT_TYPES[alert.alert_type], alert.alert_type].filter(Boolean).join(" ").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    return text.includes(query);
  }).sort((a, b) => (rank[a.priority] ?? 4) - (rank[b.priority] ?? 4) || Number(b.final_score || 0) - Number(a.final_score || 0));
}

// Excel interprète les cellules commençant par ces caractères comme des formules.
export function csvCell(value) {
  const text = String(value ?? "");
  const safe = /^[\s]*[=+\-@]/.test(text) ? `'${text}` : text;
  return `"${safe.replaceAll('"', '""')}"`;
}

export function alertsCsv(alerts) {
  const rows = [["Référence", "Client", "Motif", "Priorité", "Score", "Statut", "Créée le"], ...alerts.map((alert) => [alert.reference || `ALT-${alert.id}`, alert.client_name || alert.customer_name, alert.title || ALERT_TYPES[alert.alert_type] || alert.alert_type, PRIORITIES[alert.priority] || alert.priority, alert.final_score, alert.status, alert.created_at])];
  return "\uFEFF" + rows.map((row) => row.map(csvCell).join(";")).join("\r\n");
}
