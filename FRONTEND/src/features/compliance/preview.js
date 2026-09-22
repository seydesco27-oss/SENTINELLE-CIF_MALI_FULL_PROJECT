// Jeu de présentation uniquement, chargé par la route d’aperçu en développement.
export function createCompliancePreview() {
  const now = new Date();
  const daysAgo = (days) => { const date = new Date(now); date.setDate(date.getDate() - days); return date.toISOString(); };
  return {
    summary: { clients: { total: 10189, risky: 847, risk_rate: 8.31 }, alerts: { open: 38, total: 426 } },
    alerts: [
      { id: 3002, reference: "ALT-3002", client_name: "Sahel Négoce SARL", title: "Fractionnement de dépôts en espèces", alert_type: "STRUCTURING", priority: "CRITICAL", final_score: 94, status: "OPEN", created_at: daysAgo(0) },
      { id: 3004, reference: "ALT-3004", client_name: "Aminata Traoré", title: "Correspondance sur une liste de sanctions", alert_type: "SANCTION_MATCH", priority: "CRITICAL", final_score: 91, status: "OPEN", created_at: daysAgo(0) },
      { id: 3006, reference: "ALT-3006", client_name: "Delta Import & Export", title: "Virement vers un corridor sensible", alert_type: "HIGH_RISK_CORRIDOR", priority: "CRITICAL", final_score: 86, status: "OPEN", created_at: daysAgo(1) },
      { id: 3008, reference: "ALT-3008", client_name: "Moussa Coulibaly", title: "Montant inhabituel au regard du profil", alert_type: "LARGE_AMOUNT", priority: "HIGH", final_score: 76, status: "OPEN", created_at: daysAgo(1) },
      { id: 3010, reference: "ALT-3010", client_name: "Coopérative du Mandé", title: "Succession de transferts rapprochés", alert_type: "RAPID_TRANSFER", priority: "HIGH", final_score: 68, status: "OPEN", created_at: daysAgo(2) },
    ],
    risk: [{ code: "LOW", label: "Faible", client_count: 7290 }, { code: "MEDIUM", label: "Modéré", client_count: 2052 }, { code: "HIGH", label: "Élevé", client_count: 723 }, { code: "CRITICAL", label: "Critique", client_count: 124 }],
    trend: Array.from({ length: 30 }, (_, index) => ({ date: daysAgo(29 - index).slice(0, 10), alerts: [8, 13, 10, 17, 12, 7, 15, 9, 11, 6][index % 10] })),
    investigations: [
      { id: 142, alert_id: 2901, client_name: "Bamako Distribution", alert_reference: "ALT-2901", assigned_username: "Hawa Diallo", investigation_status: "OPEN" },
      { id: 139, alert_id: 2894, client_name: "Oumar Diarra", alert_reference: "ALT-2894", assigned_username: "Hawa Diallo", investigation_status: "OPEN" },
      { id: 136, alert_id: 2880, client_name: "Sikasso Céréales", alert_reference: "ALT-2880", assigned_username: "M. Touré", investigation_status: "OPEN" },
    ],
    declarations: [{ id: 21, reference: "DOS-2026-021", transmission_status: "DRAFT" }, { id: 22, reference: "DOS-2026-022", transmission_status: "DRAFT" }],
  };
}
