# Interface SENTINELLE-CIF

Application React/Vite de la plateforme de supervision financière.

## Démarrer

Depuis la racine du projet, lancer `powershell -ExecutionPolicy Bypass -File .\start-sentinelle.ps1` pour démarrer les trois services. Pour lancer seulement l’interface :

```powershell
cd FRONTEND
npm install
npm run dev -- --host 127.0.0.1 --port 5173
```

L’interface utilise l’API locale `http://127.0.0.1:8000/api/v1`. Les contrôles d’accès affichés par React améliorent la navigation ; l’API applique elle-même les droits et les périmètres.

## Espace ADMIN SaaS

Après connexion, le rôle ADMIN ouvre directement `/admin/structures`.

- **Structures** (`/admin/structures`) : liste des caisses avec leur mode API, CSV ou SQL, périmètre et actions. Le wizard `/admin/structures/new` suit cinq étapes et crée la caisse, sa première agence et trois comptes initiaux. `/admin/structures/:id/access` remet le contrat adapté au mode choisi.
- **Listes & Screening** (`/admin/screening-lists`) : liens officiels ONU, UE, OFAC ; import du fichier téléchargé ; historique SUCCESS/FAILED ; screening par lots sur une caisse ou une agence. Après un import, l’interface recommande de lancer le screening.
- **Utilisateurs** (`/admin/users`) : création et édition du rôle, du périmètre, de la caisse et de l’agence. Un SUPERVISOR au périmètre CAISSE est l’admin de caisse ; un AGENT reste au périmètre AGENCY.

La navigation ADMIN présente ces trois rubriques, le journal d’audit et Mon compte. L’assistant ADMIN guide le déploiement, les accès, les listes et les comptes. L’aide à l’analyse de dossier appartient à la session Conformité habilitée.

Le thème de chaque rôle est défini dans `src/role-theme.css` : indigo ADMIN, bleu Conformité, ambre Superviseur, teal Agent. `src/config/roles.js` centralise les badges, les permissions d’affichage et le périmètre affiché. Les listes métier transmettent aussi le filtre caisse ou agence ; seul le filtre de l’API fait autorité.

## Vérifier

```powershell
cd FRONTEND
npm run lint
npm run build
```

Le flux complet et les limites du connecteur sont documentés dans le [README racine](../README.md) et le [README API](../SENTINELLE-CIF-API/README.md).
