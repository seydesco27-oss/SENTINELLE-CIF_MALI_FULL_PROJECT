# FRONTEND RBAC — COMPLIANCE_OFFICER (priorité) + matrice 4 rôles

## Fichiers à intégrer dans FRONTEND/
- src/config/roles.js          (nouveau)
- src/components/RoleGuard.jsx (nouveau)
- src/components/RoleGuard.css (nouveau)
- src/components/Sidebar.jsx   (remplace)
- src/components/Sidebar.css   (remplace)
- src/App.jsx                  (remplace)

## Test rapide
1. Login: analyste.demo / Admin@2026  → rôle COMPLIANCE_OFFICER
2. Sidebar: sections SUPERVISION / DONNÉES / ANALYSE / COMPTE + chip « Conformité LBC-FT »
3. Accès OK: /centif, /audit, /ml, /analyse-risque
4. Login: agent.bamako.01 → pas de CENTIF / ML / audit dans la nav
5. Forcer URL /centif en AGENT → écran Accès restreint

## Note API
Le middleware role côté Laravel est quasi non branché (sauf dashboard/summary avec role 5 fantôme).
Durcir les routes API en parallèle — le front seul ne sécurise pas.
