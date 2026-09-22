export const ROLE_IDS = Object.freeze({
  ADMIN: 1,
  COMPLIANCE_OFFICER: 2,
  SUPERVISOR: 3,
  AGENT: 4,
});

const ALL_PERMISSIONS = [
  "dashboard",
  "network",
  "alerts",
  "investigations",
  "centif",
  "clients",
  "accounts",
  "transactions",
  "screening",
  "risk_analysis",
  "ml",
  "reports",
  "audit",
  "settings",
  "assist",
];

const FALLBACK_PERMISSIONS = {
  [ROLE_IDS.ADMIN]: ALL_PERMISSIONS,
  [ROLE_IDS.COMPLIANCE_OFFICER]: ALL_PERMISSIONS,
  [ROLE_IDS.SUPERVISOR]: [
    "dashboard",
    "network",
    "alerts",
    "investigations",
    "centif",
    "clients",
    "accounts",
    "transactions",
    "screening",
    "risk_analysis",
    "reports",
    "settings",
    "assist",
  ],
  [ROLE_IDS.AGENT]: ["clients", "accounts", "transactions", "settings"],
};

export function getRoleId(user) {
  const value = user?.role?.id ?? user?.role_id;
  const roleId = Number(value);
  return Number.isFinite(roleId) ? roleId : null;
}

export function getPermissions(user) {
  if (Array.isArray(user?.access?.permissions)) {
    return user.access.permissions;
  }

  return FALLBACK_PERMISSIONS[getRoleId(user)] || ["settings"];
}

export function canAccess(user, permission) {
  return getPermissions(user).includes(permission);
}

export function getDefaultRoute(user) {
  if (user?.access?.default_path) {
    return user.access.default_path;
  }

  return canAccess(user, "dashboard") ? "/dashboard" : "/clients";
}

export function getWorkspaceLabel(user) {
  if (user?.access?.workspace_label) {
    return user.access.workspace_label;
  }

  return {
    [ROLE_IDS.ADMIN]: "Administration et supervision globale",
    [ROLE_IDS.COMPLIANCE_OFFICER]: "Conformité et analyse LBC-FT",
    [ROLE_IDS.SUPERVISOR]: "Supervision opérationnelle",
    [ROLE_IDS.AGENT]: "Consultation opérationnelle",
  }[getRoleId(user)] || "Espace utilisateur";
}

export function getUserDisplayName(user) {
  const profileName = user?.profile?.full_name;
  const flatName = user?.full_name || user?.name;
  const composedName = [
    user?.profile?.first_name || user?.first_name,
    user?.profile?.last_name || user?.last_name,
  ]
    .filter(Boolean)
    .join(" ")
    .trim();

  return profileName || flatName || composedName || user?.username || "—";
}

export function getUserInitials(user) {
  const parts = getUserDisplayName(user)
    .split(/\s+/)
    .filter(Boolean);

  if (parts.length === 0) return "—";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();

  return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
}

export const PERMISSION_LABELS = Object.freeze({
  dashboard: "Tableau de bord",
  network: "Réseau caisses et agences",
  alerts: "Alertes",
  investigations: "Investigations",
  centif: "Déclarations CENTIF",
  clients: "Clients",
  accounts: "Comptes",
  transactions: "Transactions",
  screening: "Screening PEP et sanctions",
  risk_analysis: "Analyse du risque",
  ml: "Intelligence ML",
  reports: "Rapports",
  audit: "Journal d’audit",
  settings: "Mon compte",
  assist: "Sentinelle Assist",
});
