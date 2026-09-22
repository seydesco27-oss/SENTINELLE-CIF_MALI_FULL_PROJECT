// Données fictives — structures identiques au contrat API V1
export const mockLoginResponse = {
  success: true,
  message: "Connexion réussie.",
  data: {
    token: "fake-sanctum-token-123",
    user: {
      id: 1,
      username: "Hawa Thiama",
      agency: {
        id: 1,
        code: "AG001",
        name: "Bamako Centre",
        city: "Bamako",
      },
      role: {
        id: 1,
        name: "ADMIN",
        description: "Administrateur de la plateforme",
      },
      created_at: "2026-08-14T16:55:11.000000Z",
    },
  },
};

export const mockDashboardSummary = {
  success: true,
  data: {
    total_clients: 10189,
    risky_clients: 124,
    alerts: 18,
  },
};

export const mockClientsList = {
  success: true,
  message: "Clients récupérés avec succès.",
  data: [
    {
      client_id: 10307,
      client_number: "SAN-ENTITY-8",
      client_type: "ENTITY",
      customer_name: "CIMEX IBERICA",
      is_pep: 0,
      risk_level: null,
      risk_score: "0.00",
      alert_count: 0,
      transaction_count: 0,
      total_volume: "0.00",
    },
    {
      client_id: 10412,
      client_number: "BKO-IND-42",
      client_type: "INDIVIDUAL",
      customer_name: "Lala Doucouré",
      is_pep: 1,
      risk_level: "HIGH",
      risk_score: "87.00",
      alert_count: 3,
      transaction_count: 56,
      total_volume: "2450000.00",
    },
    {
      client_id: 10521,
      client_number: "BKO-IND-77",
      client_type: "INDIVIDUAL",
      customer_name: "Seydou Coulibaly",
      is_pep: 0,
      risk_level: "MEDIUM",
      risk_score: "42.00",
      alert_count: 1,
      transaction_count: 12,
      total_volume: "320000.00",
    },
  ],
  meta: {
    current_page: 1,
    last_page: 510,
    per_page: 20,
    total: 10189,
  },
};

export const mockAlertsOpen = {
  success: true,
  data: [
    {
      id: 4521,
      client_id: 10412,
      transaction_id: 98213,
      alert_type: "LARGE_AMOUNT",
      priority: "HIGH",
      status: "OPEN",
      created_at: "2026-08-14T09:14:00.000000Z",
    },
    {
      id: 4488,
      client_id: 10412,
      transaction_id: 98150,
      alert_type: "STRUCTURING",
      priority: "MEDIUM",
      status: "OPEN",
      created_at: "2026-08-13T15:02:00.000000Z",
    },
  ],
};

export const mockAlertDetail = {
  success: true,
  data: {
    alert: {
      id: 4521,
      client_id: 10412,
      transaction_id: 98213,
      alert_type: "LARGE_AMOUNT",
      priority: "HIGH",
      status: "OPEN",
      created_at: "2026-08-14T09:14:00.000000Z",
    },
    actions: [],
    investigations: [],
    risk_assessments: [
      {
        id: 1,
        client_id: 10412,
        transaction_id: 98213,
        risk_type: "TRANSACTION",
        score: 87,
        risk_level: "HIGH",
        reason: "Montant inhabituel détecté",
        source: "aml_engine",
        created_at: "2026-08-14T09:14:00.000000Z",
      },
    ],
  },
};

// ⚠️ MOCK_V1_GAP : ces champs enrichis (transactions_analyzed, alerts_critical,
// priority_alerts, risk_distribution) ne sont pas garantis tels quels par
// /dashboard/summary dans le contrat V1. À vérifier/ajuster avec le vrai
// endpoint une fois le backend branché.
export const mockDashboardSummaryV2 = {
  success: true,
  data: {
    transactions_analyzed_today: 312,
    alerts_to_review: 8,
    alerts_critical: 3,
    high_risk_clients: 847,
    engine_status: "operational",
    last_sync: "09:42",
  },
};

export const mockPriorityAlerts = {
  success: true,
  data: [
    {
      id: "ALT-3002",
      created_at: "2026-08-14T09:13:00.000000Z",
      client_name: "SARL DELTA TRADING",
      reason: "Activité cash élevée",
      agency: "CIF Bamako",
      agency_zone: "Bamako Centre",
      priority: "CRITICAL",
      status: "EN_ANALYSE",
    },
    {
      id: "ALT-3004",
      created_at: "2026-08-14T11:21:00.000000Z",
      client_name: "BTP GUINEE SA",
      reason: "Montant élevé",
      agency: "CIF Sikasso",
      agency_zone: "Sikasso Ville",
      priority: "CRITICAL",
      status: "OUVERTE",
    },
    {
      id: "ALT-3006",
      created_at: "2026-08-14T15:48:00.000000Z",
      client_name: "Boubacar Touré",
      reason: "Transfert rapide",
      agency: "CIF Bamako",
      agency_zone: "Bamako Centre",
      priority: "CRITICAL",
      status: "EN_ANALYSE",
    },
    {
      id: "ALT-3001",
      created_at: "2026-08-14T08:48:00.000000Z",
      client_name: "Mamadou Konaté",
      reason: "Montant élevé",
      agency: "CIF Bamako",
      agency_zone: "Badalabougou",
      priority: "HIGH",
      status: "OUVERTE",
    },
    {
      id: "ALT-3003",
      created_at: "2026-08-14T10:56:00.000000Z",
      client_name: "Mamadou Konaté",
      reason: "Corridor à risque",
      agency: "CIF Bamako",
      agency_zone: "Badalabougou",
      priority: "HIGH",
      status: "OUVERTE",
    },
    {
      id: "ALT-3005",
      created_at: "2026-08-14T14:31:00.000000Z",
      client_name: "Oumou Diarra",
      reason: "Structuration",
      agency: "CIF Bamako",
      agency_zone: "Bamako Centre",
      priority: "HIGH",
      status: "OUVERTE",
    },
  ],
};

// ⚠️ MOCK_V1_GAP : à recouper avec /dashboard/risk-distribution
export const mockRiskDistribution = {
  success: true,
  data: {
    LOW: 7842,
    MEDIUM: 1624,
    HIGH: 612,
    CRITICAL: 111,
  },
};

// ⚠️ MOCK_V1_GAP : tendance et concentration par point d'opération
// non garanties telles quelles par le contrat V1 — à recouper avec
// /dashboard/alerts et /dashboard/transactions une fois branché.
export const mockAlertTrend = {
  success: true,
  data: [12, 18, 15, 22, 16, 19, 24],
};

export const mockRiskConcentration = {
  success: true,
  data: [
    { caisse: "CIF Bamako", agency: "Bamako Centre", alerts: 34, critical: 5 },
    { caisse: "CIF Bamako", agency: "Badalabougou", alerts: 21, critical: 2 },
    { caisse: "CIF Sikasso", agency: "Sikasso Ville", alerts: 14, critical: 3 },
    { caisse: "CIF Kayes", agency: "Kayes Centre", alerts: 9, critical: 1 },
  ],
};
// ⚠️ MOCK_V1_GAP : cette structure combine plusieurs endpoints du contrat V1
// (transaction, compte, client, screening, analyse AML, alertes liées).
// À vérifier/ajuster une fois les endpoints réels branchés.
export const mockTransactionDetail = {
  success: true,
  data: {
    breadcrumb: ["Transactions", "CIF Bamako", "Badalabougou", "TRX-2026-0814-001"],

    transaction: {
      reference: "TRX-2026-0814-001",
      amount_display: "2.45 M FCFA",
      risk_level: "HIGH",
      status: "Terminée",
      date_display: "14 août 2026, 08:47",
      type: "VIREMENT",
      account_number: "ACC-DAK-501-001",
      channel: "AGENCE",
      operation_agency: "CIF Bamako",
      operation_zone: "Badalabougou",
    },

    score: {
      value: 72,
      max: 100,
      label: "SCORE AML DÉTERMINISTE",
    },

    chain: [
      { label: "TRANSACTION", value: "VIREMENT" },
      { label: "CONTEXTE", value: "Mamadou Konaté" },
      { label: "SIGNAUX", value: "3 règles" },
      { label: "ALERTE", value: "ALT-3001" },
      { label: "DÉCISION", value: "Analyste humain" },
    ],

    client_context: {
      client_name: "Mamadou Konaté",
      caisse: "CIF Bamako",
      agence_rattachement: "Badalabougou",
      corridor: "SN → ML",
      autres_comptes: [
        { account_number: "ACC-DAK-501-001", type: "COURANT", note: "Compte de l'opération" },
        { account_number: "ACC-DAK-501-002", type: "EPARGNE", note: "Compte associé au même client" },
      ],
    },

    aml_analysis: {
      score_display: "72.00 / 100",
      niveau: "HIGH",
      source: "AML_ENGINE",
      regles_declenchees_display: "3 / 7 catégories",
      narrative: "Virement international vers zone à risque + PEP émetteur + montant supérieur au seuil",
      historique: [
        {
          date_display: "14 août 2026, 08:47",
          title: "EVALUATION",
          description: "Score déterministe calculé avant la création de l'alerte.",
        },
        {
          date_display: "14 août 2026, 08:48",
          title: "ALERTE",
          description: "Générée après le franchissement des règles applicables.",
        },
      ],
      rules_triggered: [
        {
          code: "LARGE_AMOUNT",
          detail: "Montant supérieur au seuil réglementaire (2 000 000 FCFA)",
          contribution: "+35",
        },
        {
          code: "HIGH_RISK_CORRIDOR",
          detail: "Corridor SN→ML classé zone à risque élevé",
          contribution: "+28",
        },
      ],
    },
  },
};
// ⚠️ MOCK_V1_GAP : vue combinée (identité KYC, rattachement, synthèse,
// dernières opérations) — à répartir sur les vrais endpoints
// /clients/{id}, /clients/{id}/accounts, /clients/{id}/transactions,
// /clients/{id}/alerts une fois le backend branché.
export const mockClientDetail = {
  success: true,
  data: {
    breadcrumb: ["Clients", "CIF Bamako", "Badalabougou", "CLI-IND-1001"],

    client: {
      client_number: "CLI-IND-1001",
      customer_name: "Mamadou Konaté",
      is_pep: true,
      risk_level: "HIGH",
      risk_score: 75,
      caisse: "CIF Bamako",
      agence_rattachement: "Badalabougou",
      accounts_count: 2,
      open_alerts_count: 2,
    },

    kyc: {
      client_number: "CLI-IND-1001",
      client_type: "Individu",
      nationalite: "SN",
      profession: "Homme politique",
      statut_kyc: "Non fourni par l'API",
      statut_kyc_note: "Emplacement préparé",
      statut_ppe: "PPE détectée",
      piece_identite: "PASSPORT",
      numero_piece: "SN-PP-2847391",
    },

    rattachement: {
      caisse: "CIF Bamako",
      agence_rattachement: "Badalabougou",
      reference_client: "CLI-IND-1001",
      note: "Ce rattachement définit le dossier client. Les opérations peuvent provenir d'autres agences du réseau.",
    },

    synthese: {
      comptes: 2,
      transactions: 47,
      alertes_liees: 3,
      volume_consolide_display: "12.45 M FCFA",
    },

    operations_attention: [
      {
        reference: "TRX-2026-0814-004",
        compte: "ACC-DAK-501-002",
        date_display: "14 août 2026, 10:55",
        montant_display: "1.80 M FCFA",
        agence: "CIF Bamako",
        zone: "Badalabougou",
        risque: "HIGH",
      },
      {
        reference: "TRX-2026-0814-001",
        compte: "ACC-DAK-501-001",
        date_display: "14 août 2026, 08:47",
        montant_display: "2.45 M FCFA",
        agence: "CIF Bamako",
        zone: "Badalabougou",
        risque: "HIGH",
      },
    ],

    operations_footer_note: "Dernières opérations disponibles dans ce prototype. Ouvrir une ligne pour accéder à la traçabilité AML complète.",
  },
};
// ⚠️ MOCK_V1_GAP : à vérifier avec l'endpoint réel GET /clients/{id}/accounts
export const mockClientAccounts = {
  success: true,
  data: [
    {
      account_number: "ACC-DAK-501-001",
      type: "COURANT",
      opened_display: "15 juil. 2019",
      statut: "Actif",
      caisse: "CIF Bamako",
      agence: "Badalabougou",
    },
    {
      account_number: "ACC-DAK-501-002",
      type: "EPARGNE",
      opened_display: "03 févr. 2021",
      statut: "Actif",
      caisse: "CIF Bamako",
      agence: "Badalabougou",
    },
  ],
  footer_note: "2 comptes reliés au dossier client · l'agence de rattachement ne limite pas les agences d'opération.",
};
// ⚠️ MOCK_V1_GAP : vue combinée screening PEP/sanctions par client.
// À vérifier avec les endpoints réels /clients/{id}/screening, /clients/{id}/pep,
// /clients/{id}/sanctions du contrat V1.
export const mockScreeningRegistry = {
  success: true,
  data: [
    { client_id: "ID-1001", customer_name: "Mamadou Konaté", statut: "À vérifier" },
    { client_id: "ID-1003", customer_name: "SARL DELTA TRADING", statut: "À vérifier" },
    { client_id: "ID-1008", customer_name: "Oumar Diarra", statut: "À vérifier" },
    { client_id: "ID-1004", customer_name: "Ibrahim Traoré", statut: "Absence de correspondance" },
    { client_id: "ID-1011", customer_name: "Boubacar Touré", statut: "À vérifier" },
  ],
};

export const mockScreeningDetail = {
  success: true,
  data: {
    client_id: "ID-1001",
    customer_name: "Mamadou Konaté",
    statut_controle: "À vérifier",
    dernier_screening_display: "14 août 2026, 10:20",
    pep_result: "Correspondance potentielle",
    sanctions_result: "Absence de correspondance",

    pep_matches: [
      {
        statut: "Correspondance potentielle",
        categorie: "DOMESTIC_PEP",
        fonction: "Député, Assemblée Nationale",
        pays: "SN",
        indication_pct: "96 %",
        indication_note: "À comparer avec l'identité déclarée.",
      },
    ],

    sanctions_note: "Absence de correspondance sanctions dans les sources consultées.",

    elements_a_controler: [
      "Nom, alias et orthographe de l'entrée de liste",
      "Nationalité, pays et fonction déclarés",
      "Identifiants et pièce du dossier client",
      "Source consultée et date du contrôle",
    ],

    conclusion_options: ["À renseigner", "Correspondance confirmée", "Faux positif / Écarté"],
    conclusion_note: "Aucune qualification saisie.",
  },
};
// ⚠️ MOCK_V1_GAP : les signaux comportementaux ML ne sont pas encore exposés
// par l'API V1. À vérifier avec les endpoints ML du contrat une fois disponibles.
export const mockMlSignals = {
  success: true,
  data: [
    {
      transaction_reference: "TRX-2026-0814-001",
      score_aml: 72,
      score_max: 100,
      niveau: "HIGH",
      regles_display: "3 / 7 exécutées",
      facteurs_disponibles: "Montant 3.2× moyenne · international · corridor surveillé",
      signal_ml_status: "en_attente_api",
    },
    {
      transaction_reference: "TRX-2026-0814-008",
      score_aml: 93,
      score_max: 100,
      niveau: "CRITICAL",
      regles_display: "4 / 7 exécutées",
      facteurs_disponibles: "Montant 7.8× moyenne · international · corridor surveillé",
      signal_ml_status: "en_attente_api",
    },
  ],
  footer_note: "Les champs « signal ML » et « confiance » sont réservés à l'API ML. Aucun score de performance n'est simulé.",
};
// ⚠️ MOCK_V1_GAP : le journal d'audit n'est pas encore exposé par l'API V1.
// À vérifier avec l'endpoint réel une fois disponible dans le contrat.
export const mockAuditLog = {
  success: true,
  data: [
    {
      reference: "AUD-000812",
      date_display: "14 août 2026, 10:58",
      utilisateur: "a.coulibaly",
      role: "Analyste conformité",
      action: "Consultation alerte",
      objet_type: "Alerte",
      objet_ref: "ALT-3003",
      resultat: "Consultée",
      commentaire: "Revue du corridor géographique requise.",
    },
    {
      reference: "AUD-000811",
      date_display: "14 août 2026, 10:56",
      utilisateur: "system.aml",
      role: "Moteur AML",
      action: "Création alerte",
      objet_type: "Alerte",
      objet_ref: "ALT-3003",
      resultat: "Créée",
      commentaire: "Règle HIGH_RISK_CORRIDOR déclenchée.",
    },
    {
      reference: "AUD-000810",
      date_display: "14 août 2026, 10:55",
      utilisateur: "system.aml",
      role: "Moteur AML",
      action: "Évaluation transaction",
      objet_type: "Transaction",
      objet_ref: "TRX-2026-0814-004",
      resultat: "Score calculé",
      commentaire: "Évaluation déterministe enregistrée.",
    },
    {
      reference: "AUD-000809",
      date_display: "14 août 2026, 10:20",
      utilisateur: "system.screening",
      role: "Service screening",
      action: "Contrôle PEP",
      objet_type: "Client",
      objet_ref: "CLI-IND-1001",
      resultat: "À vérifier",
      commentaire: "Correspondance potentielle PEP soumise à revue humaine.",
    },
    {
      reference: "AUD-000808",
      date_display: "14 août 2026, 09:13",
      utilisateur: "m.traore",
      role: "Responsable conformité",
      action: "Attribution investigation",
      objet_type: "Alerte",
      objet_ref: "ALT-3002",
      resultat: "Attribuée",
      commentaire: "Affectée à l'équipe conformité pour analyse renforcée.",
    },
    {
      reference: "AUD-000807",
      date_display: "14 août 2026, 08:48",
      utilisateur: "system.aml",
      role: "Moteur AML",
      action: "Création alerte",
      objet_type: "Alerte",
      objet_ref: "ALT-3001",
      resultat: "Créée",
      commentaire: "Dépassement de seuil enregistré.",
    },
  ],
  footer_note: "Les commentaires et résultats sont restitués lorsqu'ils sont disponibles ; aucune action d'audit n'est modifiable depuis cette vue.",
};
// ⚠️ MOCK_V1_GAP : l'agrégation Caisse/Agence n'est pas encore exposée
// telle quelle par l'API réseau V1. À vérifier avec l'endpoint réel une
// fois disponible dans le contrat.
export const mockNetworkOverview = {
  success: true,
  data: {
    reseau: "CIF",
    caisses_count: 3,
    agences_count: 4,
    alertes_count: 8,
    critiques_count: 3,

    agences: [
      {
        caisse: "CIF Bamako",
        agence: "Bamako Centre",
        clients_rattaches: 3,
        transactions_disponibles: 5,
        volume_display: "21.73 M FCFA",
        alertes: 3,
        critiques: 2,
      },
      {
        caisse: "CIF Bamako",
        agence: "Badalabougou",
        clients_rattaches: 3,
        transactions_disponibles: 2,
        volume_display: "4.25 M FCFA",
        alertes: 2,
        critiques: null,
      },
      {
        caisse: "CIF Kayes",
        agence: "Kayes Centre",
        clients_rattaches: 3,
        transactions_disponibles: 2,
        volume_display: "8.10 M FCFA",
        alertes: 2,
        critiques: null,
      },
      {
        caisse: "CIF Sikasso",
        agence: "Sikasso Ville",
        clients_rattaches: 3,
        transactions_disponibles: 1,
        volume_display: "47.00 M FCFA",
        alertes: 1,
        critiques: 1,
      },
    ],

    footer_note: "L'activité est rattachée au point d'opération ; les clients restent rattachés à leur agence d'origine.",

    point_attention: {
      titre: "Concentration de vigilance",
      caisse_agence: "CIF Bamako · Bamako Centre",
      description: "3 alertes disponibles, dont 2 critiques. Examiner ce point d'opération dans la file d'alertes, sans conclure à un risque de l'agence elle-même.",
    },
  },
};
// ⚠️ MOCK_V1_GAP : liste complète pour la page /alertes — à vérifier avec
// GET /alerts du contrat V1 (filtres, tri, pagination réels).
export const mockAlertsList = {
  success: true,
  data: [
    { id: 3001, alert_type: "LARGE_AMOUNT", client_id: 10744, customer_name: "Mamadou Konaté", transaction_reference: "TRX-2026-0814-001", priority: "HIGH", status: "OPEN", risk_score: "72", created_at: "2026-08-14T08:48:00.000000Z" },
    { id: 3002, alert_type: "UNUSUAL_VOLUME", client_id: 10521, customer_name: "SARL DELTA TRADING", transaction_reference: "TRX-2026-0814-002", priority: "CRITICAL", status: "IN_REVIEW", risk_score: "91", created_at: "2026-08-14T09:13:00.000000Z" },
    { id: 3003, alert_type: "HIGH_RISK_CORRIDOR", client_id: 10744, customer_name: "Mamadou Konaté", transaction_reference: "TRX-2026-0814-003", priority: "HIGH", status: "OPEN", risk_score: "78", created_at: "2026-08-14T10:56:00.000000Z" },
    { id: 3004, alert_type: "LARGE_AMOUNT", client_id: 10412, customer_name: "BTP GUINEE SA", transaction_reference: "TRX-2026-0814-004", priority: "CRITICAL", status: "OPEN", risk_score: "88", created_at: "2026-08-14T11:21:00.000000Z" },
    { id: 3005, alert_type: "STRUCTURING", client_id: 10633, customer_name: "Oumar Diarra", transaction_reference: "TRX-2026-0814-005", priority: "HIGH", status: "OPEN", risk_score: "65", created_at: "2026-08-14T14:31:00.000000Z" },
    { id: 3006, alert_type: "RAPID_TRANSFER", client_id: 10744, customer_name: "Boubacar Touré", transaction_reference: "TRX-2026-0814-006", priority: "CRITICAL", status: "IN_REVIEW", risk_score: "93", created_at: "2026-08-14T15:48:00.000000Z" },
    { id: 2998, alert_type: "STRUCTURING", client_id: 10521, customer_name: "Ibrahim Traoré", transaction_reference: "TRX-2026-0813-014", priority: "MEDIUM", status: "CLOSED", risk_score: "44", created_at: "2026-08-13T09:02:00.000000Z" },
    { id: 2991, alert_type: "UNUSUAL_VOLUME", client_id: 10412, customer_name: "SARL DELTA TRADING", transaction_reference: "TRX-2026-0812-021", priority: "MEDIUM", status: "DISMISSED", risk_score: "38", created_at: "2026-08-12T16:20:00.000000Z" },
  ],
};

export function alertTypeLabel(type) {
  return {
    LARGE_AMOUNT: "Montant élevé",
    STRUCTURING: "Structuration",
    RAPID_TRANSFER: "Virement rapide",
    UNUSUAL_VOLUME: "Volume inhabituel",
    HIGH_RISK_CORRIDOR: "Corridor à risque",
  }[type] || type;
}
// ⚠️ MOCK_V1_GAP : liste complète pour la page /clients — à vérifier avec
// GET /clients du contrat V1 (filtres, tri, pagination réels).
export const mockClientsFullList = {
  success: true,
  data: [
    { client_id: 10744, client_number: "CLI-IND-1001", customer_name: "Mamadou Konaté", client_type: "INDIVIDUAL", is_pep: 1, risk_level: "HIGH", risk_score: "75", alert_count: 2, agence: "Badalabougou" },
    { client_id: 10521, client_number: "CLI-ENT-1003", customer_name: "SARL DELTA TRADING", client_type: "ENTITY", is_pep: 0, risk_level: "CRITICAL", risk_score: "91", alert_count: 1, agence: "Bamako Centre" },
    { client_id: 10412, client_number: "CLI-ENT-1004", customer_name: "BTP GUINEE SA", client_type: "ENTITY", is_pep: 0, risk_level: "CRITICAL", risk_score: "88", alert_count: 1, agence: "Sikasso Ville" },
    { client_id: 10633, client_number: "CLI-IND-1008", customer_name: "Oumar Diarra", client_type: "INDIVIDUAL", is_pep: 0, risk_level: "HIGH", risk_score: "65", alert_count: 1, agence: "Bamako Centre" },
    { client_id: 10855, client_number: "CLI-IND-1011", customer_name: "Boubacar Touré", client_type: "INDIVIDUAL", is_pep: 0, risk_level: "MEDIUM", risk_score: "52", alert_count: 1, agence: "Bamako Centre" },
    { client_id: 10966, client_number: "CLI-IND-1004", customer_name: "Ibrahim Traoré", client_type: "INDIVIDUAL", is_pep: 0, risk_level: "LOW", risk_score: "18", alert_count: 0, agence: "Kayes Centre" },
    { client_id: 11077, client_number: "CLI-ENT-1015", customer_name: "CIMEX IBERICA", client_type: "ENTITY", is_pep: 0, risk_level: "LOW", risk_score: "12", alert_count: 0, agence: "Bamako Centre" },
  ],
};
// ⚠️ MOCK_V1_GAP : liste complète pour la page /transactions — à vérifier
// avec GET /transactions du contrat V1 (filtres, tri, pagination réels).
export const mockTransactionsFullList = {
  success: true,
  data: [
    { id: 2001, reference: "TRX-2026-0814-001", client_id: 10744, customer_name: "Mamadou Konaté", type: "VIREMENT", amount_display: "2.45 M FCFA", channel: "AGENCE", status: "Terminée", risk_level: "HIGH", date_display: "14 août 2026, 08:47" },
    { id: 2002, reference: "TRX-2026-0814-002", client_id: 10521, customer_name: "SARL DELTA TRADING", type: "DEPOT_ESPECES", amount_display: "6.10 M FCFA", channel: "AGENCE", status: "Terminée", risk_level: "CRITICAL", date_display: "14 août 2026, 09:13" },
    { id: 2003, reference: "TRX-2026-0814-003", client_id: 10744, customer_name: "Mamadou Konaté", type: "VIREMENT", amount_display: "1.20 M FCFA", channel: "MOBILE", status: "Terminée", risk_level: "HIGH", date_display: "14 août 2026, 10:56" },
    { id: 2004, reference: "TRX-2026-0814-004", client_id: 10412, customer_name: "BTP GUINEE SA", type: "VIREMENT", amount_display: "1.80 M FCFA", channel: "AGENCE", status: "Terminée", risk_level: "CRITICAL", date_display: "14 août 2026, 10:55" },
    { id: 2005, reference: "TRX-2026-0814-005", client_id: 10633, customer_name: "Oumar Diarra", type: "RETRAIT", amount_display: "0.85 M FCFA", channel: "AGENCE", status: "Terminée", risk_level: "HIGH", date_display: "14 août 2026, 14:31" },
    { id: 2006, reference: "TRX-2026-0814-006", client_id: 10855, customer_name: "Boubacar Touré", type: "VIREMENT", amount_display: "3.30 M FCFA", channel: "MOBILE", status: "Terminée", risk_level: "CRITICAL", date_display: "14 août 2026, 15:48" },
    { id: 1998, reference: "TRX-2026-0813-014", client_id: 10966, customer_name: "Ibrahim Traoré", type: "DEPOT_ESPECES", amount_display: "0.42 M FCFA", channel: "AGENCE", status: "Terminée", risk_level: "LOW", date_display: "13 août 2026, 09:02" },
    { id: 1991, reference: "TRX-2026-0812-021", client_id: 11077, customer_name: "CIMEX IBERICA", type: "VIREMENT", amount_display: "0.30 M FCFA", channel: "AGENCE", status: "Terminée", risk_level: "LOW", date_display: "12 août 2026, 16:20" },
  ],
};

export function transactionTypeLabel(type) {
  return {
    VIREMENT: "Virement",
    DEPOT_ESPECES: "Dépôt espèces",
    RETRAIT: "Retrait",
  }[type] || type;
}
// ⚠️ MOCK_V1_GAP : liste complète pour la page /comptes — à vérifier avec
// GET /accounts du contrat V1 (données indicatives, soldes non confirmés).
export const mockAccountsFullList = {
  success: true,
  data: [
    { id: 501, number: "ML-00100234", client_id: 10744, customer_name: "Mamadou Konaté", client_number: "CLI-IND-1001", type: "COURANT", caisse: "CIF Bamako", agence: "Badalabougou", risk_level: "HIGH", balance_display: "11.25 M FCFA", last_op_display: "14 août 2026", opened_display: "12 mars 2019", status: "ACTIVE" },
    { id: 502, number: "ML-00100331", client_id: 10521, customer_name: "SARL DELTA TRADING", client_number: "CLI-ENT-1003", type: "PROFESSIONNEL", caisse: "CIF Bamako", agence: "Bamako Centre", risk_level: "CRITICAL", balance_display: "42.60 M FCFA", last_op_display: "14 août 2026", opened_display: "04 juil. 2020", status: "ACTIVE" },
    { id: 503, number: "ML-00100428", client_id: 10412, customer_name: "BTP GUINEE SA", client_number: "CLI-ENT-1004", type: "PROFESSIONNEL", caisse: "CIF Sikasso", agence: "Sikasso Ville", risk_level: "CRITICAL", balance_display: "18.90 M FCFA", last_op_display: "12 août 2026", opened_display: "22 janv. 2021", status: "ACTIVE" },
    { id: 504, number: "ML-00100525", client_id: 10633, customer_name: "Oumar Diarra", client_number: "CLI-IND-1008", type: "EPARGNE", caisse: "CIF Bamako", agence: "Bamako Centre", risk_level: "HIGH", balance_display: "3.40 M FCFA", last_op_display: "14 août 2026", opened_display: "08 sept. 2018", status: "ACTIVE" },
    { id: 505, number: "ML-00100622", client_id: 10855, customer_name: "Boubacar Touré", client_number: "CLI-IND-1011", type: "COURANT", caisse: "CIF Bamako", agence: "Bamako Centre", risk_level: "MEDIUM", balance_display: "2.10 M FCFA", last_op_display: "14 août 2026", opened_display: "15 mai 2022", status: "ACTIVE" },
    { id: 506, number: "ML-00100719", client_id: 10966, customer_name: "Ibrahim Traoré", client_number: "CLI-IND-1004", type: "EPARGNE", caisse: "CIF Kayes", agence: "Kayes Centre", risk_level: "LOW", balance_display: "0.95 M FCFA", last_op_display: "02 juil. 2026", opened_display: "19 nov. 2017", status: "INACTIVE" },
    { id: 507, number: "ML-00100816", client_id: 11077, customer_name: "CIMEX IBERICA", client_number: "CLI-ENT-1015", type: "PROFESSIONNEL", caisse: "CIF Bamako", agence: "Bamako Centre", risk_level: "LOW", balance_display: "6.30 M FCFA", last_op_display: "10 août 2026", opened_display: "27 févr. 2023", status: "ACTIVE" },
  ],
};

export const accountTypeLabel = {
  COURANT: "Compte courant",
  EPARGNE: "Épargne",
  PROFESSIONNEL: "Professionnel",
};
// ⚠️ MOCK_V1_GAP : file de travail investigations — simulée à partir des
// alertes non clôturées, en attendant un endpoint /investigations dédié
// dans le contrat V1 (assignation, échéance).
export const mockInvestigationsList = {
  success: true,
  data: [
    { alert_id: 3001, alert_type: "LARGE_AMOUNT", client_id: 10744, customer_name: "Mamadou Konaté", priority: "HIGH", status: "OPEN", risk_score: "72", assignee: "A. Coulibaly", echeance_display: "18/08/2026", is_overdue: false, created_at: "2026-08-14T08:48:00.000000Z" },
    { alert_id: 3002, alert_type: "UNUSUAL_VOLUME", client_id: 10521, customer_name: "SARL DELTA TRADING", priority: "CRITICAL", status: "IN_REVIEW", risk_score: "91", assignee: "F. Diallo", echeance_display: "15/08/2026", is_overdue: true, created_at: "2026-08-14T09:13:00.000000Z" },
    { alert_id: 3003, alert_type: "HIGH_RISK_CORRIDOR", client_id: 10744, customer_name: "Mamadou Konaté", priority: "HIGH", status: "OPEN", risk_score: "78", assignee: "—", echeance_display: "20/08/2026", is_overdue: false, created_at: "2026-08-14T10:56:00.000000Z" },
    { alert_id: 3004, alert_type: "LARGE_AMOUNT", client_id: 10412, customer_name: "BTP GUINEE SA", priority: "CRITICAL", status: "OPEN", risk_score: "88", assignee: "M. Traoré", echeance_display: "16/08/2026", is_overdue: true, created_at: "2026-08-14T11:21:00.000000Z" },
    { alert_id: 3005, alert_type: "STRUCTURING", client_id: 10633, customer_name: "Oumar Diarra", priority: "HIGH", status: "IN_REVIEW", risk_score: "65", assignee: "A. Coulibaly", echeance_display: "21/08/2026", is_overdue: false, created_at: "2026-08-14T14:31:00.000000Z" },
    { alert_id: 3006, alert_type: "RAPID_TRANSFER", client_id: 10855, customer_name: "Boubacar Touré", priority: "CRITICAL", status: "OPEN", risk_score: "93", assignee: "—", echeance_display: "17/08/2026", is_overdue: false, created_at: "2026-08-14T15:48:00.000000Z" },
  ],
};