// =============================================================================
// À coller en fin de FRONTEND/src/services/api.js
// =============================================================================

/** GET /clients/{id}/compliance/dual-risk */
export const getClientDualRisk = async (clientId) => {
  const response = await api.get(`/clients/${clientId}/compliance/dual-risk`);
  return response.data;
};

/** GET /clients/{id}/compliance/summary */
export const getClientComplianceSummary = async (clientId) => {
  const response = await api.get(`/clients/${clientId}/compliance/summary`);
  return response.data;
};

/** GET /clients/{id}/compliance/pep-rca */
export const getClientPepRca = async (clientId) => {
  const response = await api.get(`/clients/${clientId}/compliance/pep-rca`);
  return response.data;
};

/** GET /accounts/managers?role=AGENT|ACCOUNT_MANAGER */
export const getAccountManagers = async (params = {}) => {
  const response = await api.get("/accounts/managers", { params });
  return response.data;
};

/** POST /accounts — ouverture via procédure BD */
export const createAccount = async (payload) => {
  const response = await api.post("/accounts", payload);
  return response.data;
};
