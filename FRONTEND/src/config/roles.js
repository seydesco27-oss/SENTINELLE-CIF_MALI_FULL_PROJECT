export const ROLE_IDS = Object.freeze({
  ADMIN: 1,
  COMPLIANCE_OFFICER: 2,
  SUPERVISOR: 3,
  AGENT: 4,
  ACCOUNT_MANAGER: 5,
});

export const PERMISSIONS_BY_ROLE = Object.freeze({
  [ROLE_IDS.ADMIN]: [
    "nav.dashboard", "nav.network", "nav.alerts", "nav.investigations",
    "nav.centif", "nav.clients", "nav.accounts", "nav.transactions",
    "nav.screening", "nav.analyse", "nav.ml", "nav.reports", "nav.audit",
    "nav.users", "nav.engines", "nav.onboarding_org", "nav.settings",
    "data.scope_platform", "alert.view", "alert.decide", "alert.escalate",
    "alert.signal", "investigation.view", "investigation.manage", "centif.view",
    "centif.manage", "client.view", "client.create", "client.status",
    "account.view", "account.create", "tx.view", "tx.create", "screening.view",
    "ml.use", "report.view", "audit.view", "user.manage", "org.register",
    "engine.configure", "demo.run",
  ],
  [ROLE_IDS.COMPLIANCE_OFFICER]: [
    "nav.dashboard", "nav.network", "nav.alerts", "nav.investigations",
    "nav.centif", "nav.clients", "nav.accounts", "nav.transactions",
    "nav.screening", "nav.analyse", "nav.ml", "nav.reports", "nav.audit",
    "nav.settings", "data.scope_caisse", "alert.view", "alert.decide",
    "alert.escalate", "alert.signal", "investigation.view", "investigation.manage",
    "centif.view", "centif.manage", "client.view", "client.create",
    "client.status", "account.view", "account.create", "tx.view", "tx.create",
    "screening.view", "ml.use", "report.view", "audit.view",
  ],
  [ROLE_IDS.SUPERVISOR]: [
    "nav.dashboard", "nav.network", "nav.alerts", "nav.investigations",
    "nav.clients", "nav.accounts", "nav.transactions", "nav.screening",
    "nav.reports", "nav.settings", "data.scope_agency", "alert.view",
    "alert.escalate", "alert.signal", "investigation.view", "investigation.manage",
    "client.view", "client.create", "account.view", "account.create", "tx.view",
    "tx.create", "screening.view", "report.view",
  ],
  [ROLE_IDS.AGENT]: [
    "nav.dashboard", "nav.alerts", "nav.clients", "nav.accounts",
    "nav.transactions", "nav.settings", "data.scope_agency", "alert.view",
    "alert.signal", "client.view", "account.view", "tx.view", "tx.create",
  ],
  [ROLE_IDS.ACCOUNT_MANAGER]: [
    "nav.dashboard", "nav.alerts", "nav.clients", "nav.accounts",
    "nav.transactions", "nav.settings", "data.scope_portfolio", "alert.view",
    "alert.signal", "client.view", "account.view", "tx.view", "tx.create",
  ],
});

export const ROUTE_ACCESS = Object.freeze({
  "/dashboard": "nav.dashboard",
  "/agences": "nav.network",
  "/reseau": "nav.network",
  "/alertes": "nav.alerts",
  "/investigations": "nav.investigations",
  "/centif": "nav.centif",
  "/clients": "nav.clients",
  "/comptes": "nav.accounts",
  "/transactions": "nav.transactions",
  "/screening": "nav.screening",
  "/analyse": "nav.analyse",
  "/analyse-risque": "nav.analyse",
  "/ml": "nav.ml",
  "/rapports": "nav.reports",
  "/audit": "nav.audit",
  "/parametres": "nav.settings",
  "/admin/utilisateurs": "nav.users",
});

const NAV_CODES = Object.freeze(Object.values(ROUTE_ACCESS));
export const NAV_SECTIONS_BY_ROLE = Object.freeze(
  Object.fromEntries(
    Object.entries(PERMISSIONS_BY_ROLE).map(([roleId, permissions]) => [
      roleId,
      [...new Set(permissions.filter((permission) => NAV_CODES.includes(permission)))],
    ])
  )
);

const LEGACY_PERMISSION_ALIASES = Object.freeze({
  dashboard: ["nav.dashboard"], network: ["nav.network"],
  alerts: ["nav.alerts", "alert.view"],
  investigations: ["nav.investigations", "investigation.view"],
  centif: ["nav.centif", "centif.view"], clients: ["nav.clients", "client.view"],
  accounts: ["nav.accounts", "account.view"],
  transactions: ["nav.transactions", "tx.view"],
  screening: ["nav.screening", "screening.view"], risk_analysis: ["nav.analyse"],
  ml: ["nav.ml", "ml.use"], reports: ["nav.reports", "report.view"],
  audit: ["nav.audit", "audit.view"], settings: ["nav.settings"], assist: ["ml.use"],
});

export function getRoleId(user) {
  const value = user?.role?.id ?? user?.role_id;
  const roleId = Number(value);
  return Number.isFinite(roleId) ? roleId : null;
}

export function getPermissions(user) {
  const source = Array.isArray(user?.access?.permissions)
    ? user.access.permissions
    : PERMISSIONS_BY_ROLE[getRoleId(user)] || ["nav.settings"];
  return [...new Set(source.flatMap((permission) => LEGACY_PERMISSION_ALIASES[permission] || [permission]))];
}

export function can(user, permissionCode) {
  return getPermissions(user).includes(permissionCode);
}

export const canAccess = can;

export function dataScope(user) {
  if (user?.access?.scope?.type) return user.access.scope;
  const roleId = getRoleId(user);
  const type = roleId === ROLE_IDS.ADMIN
    ? "PLATFORM"
    : roleId === ROLE_IDS.COMPLIANCE_OFFICER
      ? "CAISSE"
      : roleId === ROLE_IDS.ACCOUNT_MANAGER ? "PORTFOLIO" : "AGENCY";
  return {
    type,
    agency_id: user?.agency?.id ?? user?.agency_id ?? null,
    caisse_id: user?.caisse?.id ?? user?.agency?.caisse?.id ?? user?.caisse_id ?? null,
    user_id: user?.id ?? null,
  };
}

export function getDefaultRoute(user) {
  return user?.access?.default_path || (can(user, "nav.dashboard") ? "/dashboard" : "/parametres");
}

export function getWorkspaceLabel(user) {
  if (user?.access?.workspace_label) return user.access.workspace_label;
  return {
    [ROLE_IDS.ADMIN]: "Administration et gouvernance de la plateforme",
    [ROLE_IDS.COMPLIANCE_OFFICER]: "Conformité et décisions LBC-FT",
    [ROLE_IDS.SUPERVISOR]: "Supervision opérationnelle locale",
    [ROLE_IDS.AGENT]: "Opérations de l’agence",
    [ROLE_IDS.ACCOUNT_MANAGER]: "Portefeuille clients",
  }[getRoleId(user)] || "Espace utilisateur";
}

export function getUserDisplayName(user) {
  const composedName = [user?.profile?.first_name || user?.first_name, user?.profile?.last_name || user?.last_name]
    .filter(Boolean).join(" ").trim();
  return user?.profile?.full_name || user?.full_name || user?.name || composedName || user?.username || "—";
}

export function getUserInitials(user) {
  const parts = getUserDisplayName(user).split(/\s+/).filter(Boolean);
  if (!parts.length) return "—";
  return parts.length === 1
    ? parts[0].slice(0, 2).toUpperCase()
    : `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
}

export const PERMISSION_LABELS = Object.freeze({
  "nav.dashboard": "Tableau de bord", "nav.network": "Réseau caisses et agences",
  "nav.alerts": "Alertes", "nav.investigations": "Investigations",
  "nav.centif": "Déclarations CENTIF", "nav.clients": "Clients",
  "nav.accounts": "Comptes", "nav.transactions": "Transactions",
  "nav.screening": "Screening PEP et sanctions", "nav.analyse": "Analyse du risque",
  "nav.ml": "Intelligence ML", "nav.reports": "Rapports", "nav.audit": "Journal d’audit",
  "nav.users": "Gestion des utilisateurs", "nav.engines": "Configuration des moteurs",
  "nav.onboarding_org": "Inscription des structures", "nav.settings": "Mon compte",
  "alert.view": "Consulter les alertes", "alert.decide": "Décider une alerte",
  "alert.escalate": "Escalader une alerte", "alert.signal": "Signaler un fait anormal",
  "investigation.view": "Consulter les investigations",
  "investigation.manage": "Gérer les investigations", "centif.view": "Consulter CENTIF",
  "centif.manage": "Gérer les déclarations CENTIF", "client.view": "Consulter les clients",
  "client.create": "Créer un client", "client.status": "Changer le statut client",
  "account.view": "Consulter les comptes", "account.create": "Ouvrir un compte",
  "tx.view": "Consulter les transactions", "tx.create": "Saisir une transaction",
  "screening.view": "Consulter le screening", "ml.use": "Utiliser l’assistant ML",
  "report.view": "Consulter les rapports", "audit.view": "Consulter l’audit",
  "user.manage": "Gérer les utilisateurs", "org.register": "Inscrire une structure",
  "engine.configure": "Configurer les moteurs", "data.scope_platform": "Périmètre plateforme",
  "demo.run": "Lancer les scénarios de démonstration",
  "data.scope_caisse": "Périmètre caisse", "data.scope_agency": "Périmètre agence",
  "data.scope_portfolio": "Périmètre portefeuille",
});
