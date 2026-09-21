import axios from "axios";

// ──────────────────────────────────────────────
// CONFIGURATION
// ──────────────────────────────────────────────
export const USE_MOCKS = false; // ← on passe en réel dès maintenant

const api = axios.create({
  baseURL: "http://127.0.0.1:8000/api/v1",
  headers: {
    Accept: "application/json",
    "Content-Type": "application/json",
  },
});

// Injecte automatiquement le token
api.interceptors.request.use((config) => {
  const token = localStorage.getItem("token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Gestion globale des 401
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem("token");
      localStorage.removeItem("user");
      // On ne redirige pas ici pour éviter les boucles ;
      // la protection de routes s'en charge.
    }
    return Promise.reject(error);
  }
);

// ──────────────────────────────────────────────
// AUTH
// ──────────────────────────────────────────────

/**
 * POST /api/v1/auth/login
 * Body : { username, password }
 */
export const login = async (username, password) => {
  const response = await api.post("/auth/login", { username, password });
  return response.data; // { success, message, data: { token, user } }
};

/**
 * POST /api/v1/auth/logout
 */
export const logout = async () => {
  try {
    await api.post("/auth/logout");
  } finally {
    localStorage.removeItem("token");
    localStorage.removeItem("user");
  }
};

/**
 * GET /api/v1/auth/me
 */
export const getMe = async () => {
  const response = await api.get("/auth/me");
  return response.data; // { success, data: user }
};

// Helpers locaux
export const getStoredUser = () => {
  try {
    const raw = localStorage.getItem("user");
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
};

export const isAuthenticated = () => {
  return !!localStorage.getItem("token");
};

export default api;

// ---------- DASHBOARD ----------
export const getDashboardSummary = async () => {
  const response = await api.get("/dashboard/summary");
  return response.data;
};

export const getPriorityAlerts = async (params = {}) => {
  const response = await api.get("/alerts/high-risk", {
    params: { limit: 10, status: "OPEN", ...params },
  });
  return response.data;
};

export const getRiskDistribution = async () => {
  const response = await api.get("/dashboard/risk-distribution");
  return response.data;
};

export const getAlertTrend = async (days = 7) => {
  const response = await api.get("/dashboard/alerts-trend", {
    params: { days },
  });
  return response.data;
};

// ---------- CLIENTS ----------
export const getClients = async (params = {}) => {
  const response = await api.get("/clients", { params });
  return response.data;
  // { success, message, data: [...], meta: { current_page, last_page, per_page, total } }
};

// ---------- ALERTES ----------
export const getAlerts = async (params = {}) => {
  const response = await api.get("/alerts", { params });
  return response.data;
  // { success, count, data: [...] }
};

export const getOpenAlerts = async (params = {}) => {
  const response = await api.get("/alerts/open", { params });
  return response.data;
};

export const getAlertDetail = async (id) => {
  const response = await api.get(`/alerts/${id}`);
  return response.data;
  // { success, data: { alert, actions, investigations, risk_assessments } }
};

// ---------- TRANSACTIONS ----------
export const getTransactions = async (params = {}) => {
  const response = await api.get("/transactions", { params });
  return response.data;
  // { success, count, filters, data: [...] }
};

export const getTransactionDetail = async (id) => {
  const response = await api.get(`/transactions/${id}`);
  return response.data;
};

// ---------- CLIENT DETAIL ----------
export const getClientDetail = async (id) => {
  const response = await api.get(`/clients/${id}`);
  return response.data;
};

// ---------- SCREENING ----------
export const getScreeningRegistry = async (params = {}) => {
  const response = await api.get("/screening", { params });
  return response.data;
};

export const getClientScreening = async (clientId) => {
  const response = await api.get(`/clients/${clientId}/screening`);
  return response.data;
};

export const getClientPep = async (clientId) => {
  const response = await api.get(`/clients/${clientId}/pep`);
  return response.data;
};

// ---------- INVESTIGATIONS ----------
export const getInvestigations = async (params = {}) => {
  const response = await api.get("/investigations", { params });
  return response.data;
};

// ---------- COMPTES ----------
export const getAccounts = async (params = {}) => {
  const response = await api.get("/accounts", { params });
  return response.data;
};

export const getNetwork = async () => {
  const response = await api.get("/network");
  return response.data;
};

export const getAuditLog = async (params = {}) => {
  const response = await api.get("/audit", { params });
  return response.data;
};

// ---------- ML ----------
export const getMlHealth = async () => {
  const response = await api.get("/ml/health");
  return response.data;
};

export const getMlSuspiciousTransactions = async (params = {}) => {
  const response = await api.get("/ml/suspicious-transactions", { params });
  return response.data;
};

export const getMlCustomerFeatures = async (params = {}) => {
  const response = await api.get("/ml/customer-features", { params });
  return response.data;
};

export const getReportsSummary = async () => {
  const response = await api.get("/reports/summary");
  return response.data;
};

// ══════════════════════════════════════════════
// ÉCRITURES — actions métier (PATCH / POST / PUT)
// ══════════════════════════════════════════════

// ---------- ALERTES : décision ----------
/**
 * PATCH /alerts/{id}/decision
 * decision: "CONFIRMED_SUSPICIOUS" | "DISMISSED"
 * comment: string, 10 caractères minimum (obligatoire côté API)
 */
export const decideAlert = async (id, decision, comment) => {
  const response = await api.patch(`/alerts/${id}/decision`, {
    decision,
    comment,
  });
  return response.data;
};

// ---------- INVESTIGATIONS ----------
/**
 * POST /investigations
 * alertId obligatoire, assignedUser optionnel (id utilisateur)
 */
export const createInvestigation = async (alertId, assignedUser = null) => {
  const response = await api.post("/investigations", {
    alert_id: alertId,
    assigned_user: assignedUser,
  });
  return response.data;
};

/**
 * PATCH /investigations/{id}/close
 * decision + comment (10 caractères minimum) obligatoires
 */
export const closeInvestigation = async (id, decision, comment) => {
  const response = await api.patch(`/investigations/${id}/close`, {
    decision,
    comment,
  });
  return response.data;
};

// ---------- CLIENTS : écritures ----------
/**
 * PATCH /clients/{id}/status
 * status: "ACTIVE" | "SUSPENDED" | "BLOCKED" | "CLOSED"
 * reason: string, 10 caractères minimum (obligatoire)
 */
export const updateClientStatus = async (id, status, reason) => {
  const response = await api.patch(`/clients/${id}/status`, {
    status,
    reason,
  });
  return response.data;
};

/**
 * POST /clients/{id}/report — signalement manuel, crée une alerte
 * reason: string, 20 caractères minimum (obligatoire)
 */
export const reportClient = async (id, reason) => {
  const response = await api.post(`/clients/${id}/report`, { reason });
  return response.data;
};

/**
 * POST /clients — création (KYC minimal)
 * Champs a minima : client_type, first_name/last_name OU company_name,
 * nationality, phone, agency_id, document_type, document_number
 */
export const createClient = async (payload) => {
  const response = await api.post("/clients", payload);
  return response.data;
};

/**
 * PUT /clients/{id} — mise à jour
 */
export const updateClient = async (id, payload) => {
  const response = await api.put(`/clients/${id}`, payload);
  return response.data;
};

// ---------- TRANSACTIONS : saisie manuelle ----------
/**
 * POST /transactions
 * account_id, agency_id, transaction_type (DEPOSIT|PAYMENT|TRANSFER_IN|
 * TRANSFER_OUT|WITHDRAWAL), amount obligatoires. currency (def. XOF),
 * channel, country/country_from/country_to optionnels.
 * Déclenche automatiquement le moteur AML côté serveur (triggers SQL).
 */
export const createTransaction = async (payload) => {
  const response = await api.post("/transactions", payload);
  return response.data;
};

// ---------- CENTIF : déclarations de soupçon ----------
export const getCentifDeclarations = async (params = {}) => {
  const response = await api.get("/centif/declarations", { params });
  return response.data;
};

export const getCentifDeclarationDetail = async (id) => {
  const response = await api.get(`/centif/declarations/${id}`);
  return response.data;
};

/**
 * POST /centif/declarations
 * alert_id obligatoire (l'alerte doit avoir un client_id),
 * content_summary : 30 caractères minimum (obligatoire)
 */
export const createCentifDeclaration = async (alertId, contentSummary) => {
  const response = await api.post("/centif/declarations", {
    alert_id: alertId,
    content_summary: contentSummary,
  });
  return response.data;
};

/**
 * PATCH /centif/declarations/{id}
 * transmission_status: TRANSMITTED | ACKNOWLEDGED | OPPOSED | CLOSED
 * centif_opposition_until obligatoire uniquement si OPPOSED
 */
export const updateCentifDeclarationStatus = async (
  id,
  transmissionStatus,
  oppositionUntil = null
) => {
  const payload = { transmission_status: transmissionStatus };
  if (oppositionUntil) payload.centif_opposition_until = oppositionUntil;
  const response = await api.patch(`/centif/declarations/${id}`, payload);
  return response.data;
};

// ---------- À ajouter dans src/services/api.js (section ML) ----------

export const getAssistAlertContext = async (alertId) => {
  const response = await api.get(`/ml/assist/alert/${alertId}`);
  return response.data;
};

export const getAssistClientContext = async (clientId) => {
  const response = await api.get(`/ml/assist/client/${clientId}`);
  return response.data;
};

/** action: summarize | explain | suggest_questions | draft_centif */
export const postAssist = async ({ object_type, object_id, action = "summarize" }) => {
  const response = await api.post("/ml/assist", {
    object_type,
    object_id,
    action,
  });
  return response.data;
};

export const postAssistChat = async ({ object_type, object_id, message }) => {
  const response = await api.post("/ml/chat", {
    object_type,
    object_id,
    message,
  });
  return response.data;
};

/** Score ML via Laravel -> microservice Python */
export const postMlScore = async ({ alert_id, transaction_id, client_id } = {}) => {
  const response = await api.post("/ml/score", {
    alert_id,
    transaction_id,
    client_id,
  });
  return response.data;
};
