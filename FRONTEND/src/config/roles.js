/**
 * RBAC front — SENTINELLE·CIF
 * Aligné sur SQL SEED_01_REFERENTIEL.roles
 *   1 ADMIN | 2 COMPLIANCE_OFFICER | 3 SUPERVISOR | 4 AGENT
 *
 * Principe : le front filtre navigation + routes.
 * La sécurité réelle reste côté API (RoleMiddleware) — à durcir en parallèle.
 */

export const ROLE = {
  ADMIN: "ADMIN",
  COMPLIANCE_OFFICER: "COMPLIANCE_OFFICER",
  SUPERVISOR: "SUPERVISOR",
  AGENT: "AGENT",
};

/** Libellés métier affichés (pas les codes techniques) */
export const ROLE_LABEL = {
  ADMIN: "Administrateur plateforme",
  COMPLIANCE_OFFICER: "Responsable conformité",
  SUPERVISOR: "Superviseur agence",
  AGENT: "Agent guichet",
};

/**
 * Matrice d'accès par chemin.
 * - true  : autorisé
 * - false : refusé (nav masquée + garde route)
 *
 * COMPLIANCE_OFFICER = cœur LBC-FT : supervision, données, analyse, CENTIF.
 * ADMIN              = tout + paramètres système.
 * SUPERVISOR         = validation / escalade, pas CENTIF ni audit global.
 * AGENT              = consultation limitée (clients / comptes / tx de son périmètre).
 */
export const ROUTE_ACCESS = {
  "/dashboard":       [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER, ROLE.SUPERVISOR, ROLE.AGENT],
  "/agences":         [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER, ROLE.SUPERVISOR],
  "/reseau":          [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER, ROLE.SUPERVISOR],
  "/alertes":         [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER, ROLE.SUPERVISOR, ROLE.AGENT],
  "/investigations":  [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER, ROLE.SUPERVISOR],
  "/centif":          [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER],
  "/clients":         [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER, ROLE.SUPERVISOR, ROLE.AGENT],
  "/comptes":         [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER, ROLE.SUPERVISOR, ROLE.AGENT],
  "/transactions":    [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER, ROLE.SUPERVISOR, ROLE.AGENT],
  "/screening":       [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER, ROLE.SUPERVISOR],
  "/analyse":         [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER],
  "/analyse-risque":  [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER],
  "/ml":              [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER],
  "/rapports":        [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER, ROLE.SUPERVISOR],
  "/audit":           [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER],
  "/parametres":      [ROLE.ADMIN, ROLE.COMPLIANCE_OFFICER, ROLE.SUPERVISOR, ROLE.AGENT],
};

/**
 * Navigation par rôle — ordre et regroupement métier.
 * Les items absents de ROUTE_ACCESS pour un rôle sont filtrés automatiquement.
 */
export const NAV_SECTIONS_BY_ROLE = {
  [ROLE.COMPLIANCE_OFFICER]: [
    {
      label: "SUPERVISION",
      items: [
        { to: "/dashboard", label: "Vue d’ensemble", icon: "dashboard" },
        { to: "/alertes", label: "Alertes", icon: "alert", badgeKey: "open_alerts" },
        { to: "/investigations", label: "Investigations", icon: "investigation" },
        { to: "/centif", label: "Déclarations CENTIF", icon: "centif" },
        { to: "/agences", label: "Réseau caisses", icon: "network" },
      ],
    },
    {
      label: "DONNÉES",
      items: [
        { to: "/clients", label: "Clients", icon: "clients" },
        { to: "/comptes", label: "Comptes", icon: "accounts" },
        { to: "/transactions", label: "Transactions", icon: "transactions" },
      ],
    },
    {
      label: "ANALYSE",
      items: [
        { to: "/screening", label: "Screening PEP / Sanctions", icon: "screening" },
        { to: "/analyse-risque", label: "Analyse & Risque", icon: "risk" },
        { to: "/ml", label: "Assistance ML", icon: "ml" },
        { to: "/rapports", label: "Rapports", icon: "reports" },
        { to: "/audit", label: "Journal d'audit", icon: "audit" },
      ],
    },
    {
      label: "COMPTE",
      items: [{ to: "/parametres", label: "Paramètres", icon: "settings" }],
    },
  ],

  [ROLE.ADMIN]: [
    {
      label: "SUPERVISION",
      items: [
        { to: "/dashboard", label: "Tableau de bord", icon: "dashboard" },
        { to: "/agences", label: "Réseau caisses/agences", icon: "network" },
        { to: "/alertes", label: "Alertes", icon: "alert", badgeKey: "open_alerts" },
        { to: "/investigations", label: "Investigations", icon: "investigation" },
        { to: "/centif", label: "Déclarations CENTIF", icon: "centif" },
      ],
    },
    {
      label: "DONNÉES",
      items: [
        { to: "/clients", label: "Clients", icon: "clients" },
        { to: "/comptes", label: "Comptes", icon: "accounts" },
        { to: "/transactions", label: "Transactions", icon: "transactions" },
      ],
    },
    {
      label: "ANALYSE",
      items: [
        { to: "/screening", label: "Screening PEP/Sanctions", icon: "screening" },
        { to: "/analyse-risque", label: "Analyse & Risque", icon: "risk" },
        { to: "/ml", label: "Intelligence ML", icon: "ml" },
        { to: "/rapports", label: "Rapports", icon: "reports" },
        { to: "/audit", label: "Journal d'audit", icon: "audit" },
      ],
    },
    {
      label: "SYSTÈME",
      items: [{ to: "/parametres", label: "Paramètres", icon: "settings" }],
    },
  ],

  [ROLE.SUPERVISOR]: [
    {
      label: "SUPERVISION",
      items: [
        { to: "/dashboard", label: "Tableau de bord", icon: "dashboard" },
        { to: "/alertes", label: "Alertes", icon: "alert", badgeKey: "open_alerts" },
        { to: "/investigations", label: "Investigations", icon: "investigation" },
        { to: "/agences", label: "Réseau agences", icon: "network" },
      ],
    },
    {
      label: "DONNÉES",
      items: [
        { to: "/clients", label: "Clients", icon: "clients" },
        { to: "/comptes", label: "Comptes", icon: "accounts" },
        { to: "/transactions", label: "Transactions", icon: "transactions" },
      ],
    },
    {
      label: "ANALYSE",
      items: [
        { to: "/screening", label: "Screening", icon: "screening" },
        { to: "/rapports", label: "Rapports", icon: "reports" },
      ],
    },
    {
      label: "COMPTE",
      items: [{ to: "/parametres", label: "Paramètres", icon: "settings" }],
    },
  ],

  [ROLE.AGENT]: [
    {
      label: "OPÉRATIONS",
      items: [
        { to: "/dashboard", label: "Tableau de bord", icon: "dashboard" },
        { to: "/alertes", label: "Alertes", icon: "alert", badgeKey: "open_alerts" },
      ],
    },
    {
      label: "DONNÉES",
      items: [
        { to: "/clients", label: "Clients", icon: "clients" },
        { to: "/comptes", label: "Comptes", icon: "accounts" },
        { to: "/transactions", label: "Transactions", icon: "transactions" },
      ],
    },
    {
      label: "COMPTE",
      items: [{ to: "/parametres", label: "Paramètres", icon: "settings" }],
    },
  ],
};

/** Fallback si rôle inconnu : navigation minimale */
const FALLBACK_NAV = [
  {
    label: "ACCÈS",
    items: [
      { to: "/dashboard", label: "Tableau de bord", icon: "dashboard" },
      { to: "/parametres", label: "Paramètres", icon: "settings" },
    ],
  },
];

/**
 * Extrait le code rôle normalisé depuis l'objet user stocké après login.
 * Supporte user.role.name | user.role (string) | user.role_id
 */
export function resolveRoleName(user) {
  if (!user) return null;
  const raw =
    user?.role?.name ||
    (typeof user.role === "string" ? user.role : null) ||
    null;
  if (raw) return String(raw).toUpperCase().trim();

  // Fallback par id si name absent
  const id = user?.role?.id ?? user?.role_id ?? null;
  const byId = { 1: ROLE.ADMIN, 2: ROLE.COMPLIANCE_OFFICER, 3: ROLE.SUPERVISOR, 4: ROLE.AGENT };
  return id != null ? byId[Number(id)] || null : null;
}

export function getRoleLabel(user) {
  const name = resolveRoleName(user);
  if (!name) return "Utilisateur";
  return (
    user?.role?.description ||
    ROLE_LABEL[name] ||
    name
  );
}

export function canAccessPath(user, path) {
  const role = resolveRoleName(user);
  if (!role) return false;

  // Match exact ou préfixe pour routes dynamiques (/alertes/12 → /alertes)
  const base = Object.keys(ROUTE_ACCESS).find(
    (key) => path === key || path.startsWith(key + "/")
  );
  if (!base) return false;
  return ROUTE_ACCESS[base].includes(role);
}

export function getNavSectionsForUser(user) {
  const role = resolveRoleName(user);
  const sections = NAV_SECTIONS_BY_ROLE[role] || FALLBACK_NAV;
  return sections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => canAccessPath(user, item.to)),
    }))
    .filter((section) => section.items.length > 0);
}
