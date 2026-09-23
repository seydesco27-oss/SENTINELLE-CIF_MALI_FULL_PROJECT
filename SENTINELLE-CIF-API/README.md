# API SENTINELLE-CIF

API Laravel, authentification Sanctum et MySQL `digi_aml`.

## Installation et démarrage

Depuis la racine du projet :

```powershell
cd SENTINELLE-CIF-API
composer install
php artisan migrate --force
cd ..
powershell -ExecutionPolicy Bypass -File .\start-sentinelle.ps1
```

Le script lance PHP sur `127.0.0.1:8000` avec `upload_max_filesize=50M`, `post_max_size=52M`, `memory_limit=512M` et `max_execution_time=120`, nécessaires aux exports sanctions. MySQL/WampServer doit être démarré séparément. Les routes acceptent un jeton Sanctum dans `Authorization: Bearer …`.

## Rôles et périmètres

Les quatre rôles de la base sont conservés : ADMIN (plateforme SaaS), COMPLIANCE_OFFICER, SUPERVISOR et AGENT. `users.scope_level` et `users.caisse_id` définissent le rattachement. SUPERVISOR peut être CAISSE (admin caisse) ou AGENCY ; AGENT est toujours AGENCY. L’API calcule le périmètre à partir de l’utilisateur authentifié et ignore toute tentative de l’élargir par les paramètres de requête. Les routes d’administration exigent le rôle ADMIN en plus de la permission correspondante.

La migration `2026_09_23_100000_add_caisse_onboarding.php` ajoute uniquement les colonnes d’intégration absentes de `caisses` : `integration_mode`, `api_key`, `data_perimeter`, `engines_json`, `sql_connection_hint`, `onboarded_at`, `onboarded_by`. Elle ajoute les permissions ADMIN `org.register`, `user.manage`, `list.import`, `list.publish` et `screening.run_batch`. Aucune nouvelle table métier ni nouveau rôle n’est créé.

## Contrat ADMIN SaaS

Les routes existent sous `/api/admin` et `/api/v1/admin` ; l’interface utilise la seconde forme.

| Méthode | Route | Fonction |
| --- | --- | --- |
| GET, POST | `/caisses` | Lister ou inscrire une caisse avec première agence et comptes initiaux |
| GET | `/caisses/{id}/access` | Contrat technique API, CSV ou SQL de la caisse |
| POST | `/caisses/{id}/access-key` | Générer une fois la clé API manquante d’une caisse existante |
| GET | `/caisses/{id}/csv-examples` | Trois exemples CSV si mode CSV |
| GET, POST | `/users` | Lister ou créer un utilisateur |
| PATCH | `/users/{id}` | Modifier rôle, périmètre, rattachement ou statut |
| GET | `/screening-lists` | Listes ONU, UE, OFAC et nombre d’entrées actives |
| POST | `/screening-lists/{id}/import` | Import XML/CSV/TXT (`multipart/form-data`, champ `file`, 50 Mo max) |
| GET | `/screening-lists/{id}/batches` | 30 derniers lots de cette liste |
| POST | `/screening/run-batch` | Contrôler les clients d’une caisse ou agence par lots |

`POST /caisses` exige notamment `code`, `name`, `city`, `country`, `agency` (`code`, `name`, `city`), `integration_mode` (`API`, `CSV`, `SQL`), `data_perimeter` (`REALTIME`, `BOUNDED`, `FULL`, `REFUSED`), les cinq booléens `engines` (`aml`, `screening`, `centif`, `network`, `ml`) et `users` avec au moins un SUPERVISOR/CAISSE, un COMPLIANCE_OFFICER et un AGENT/AGENCY. Le mot de passe initial fait au moins 12 caractères. La transaction annule toute la création en cas d’erreur. Seul le mode API génère une clé `sk_sent_` suivie de 48 caractères hexadécimaux.

L’écran `/access` est réservé à l’ADMIN et sa réponse est marquée `Cache-Control: no-store`. La liste des caisses et le modèle `Caisse` ne retournent pas la clé. Le endpoint `/api/v1/ingest/transactions` présent dans le contrat API est **prévu** ; il ne reçoit pas encore de transactions dans ce MVP. Les exemples CSV et les vues SQL sont des spécifications de raccordement à adapter au système de la caisse.
Une caisse déjà présente avant cette migration peut avoir `integration_mode=API` et une clé vide : l’ADMIN la provisionne alors explicitement depuis son écran d’accès. L’action est idempotente et ne régénère pas une clé existante.

`POST /screening-lists/{id}/import` crée d’abord un lot `RUNNING`, lit et normalise le fichier, puis met à jour `screening_list_entries`, `sanctions_entities` et `sanctions_aliases`. Cette seconde table est nécessaire parce que les procédures de screening existantes la consultent. Un succès date `screening_lists.last_update` et marque le lot `SUCCESS` ; une erreur marque `FAILED` et conserve les données précédentes. Les fichiers officiels présents dans le projet ont été vérifiés pour les formats ONU XML, UE CSV et OFAC CSV. Le nombre de lignes UE peut dépasser le nombre d’entités parce que les alias sont regroupés.

`POST /screening/run-batch` exige `batch_size` (1 à 500), `force` et `caisse_id` ou `agency_id` ; accepte `after_id` pour le lot suivant. Les identifiants de clients sont filtrés sur cette caisse/agence **avant** l’appel à `sp_screen_client(client_id, force)` ; la procédure globale n’a pas de paramètre de périmètre. La réponse contient `processed`, `eligible`, `has_more` et `next_after_id`. Un import ne lance pas automatiquement ce screening.

## Tests

```powershell
cd SENTINELLE-CIF-API
php artisan test
$env:RUN_SAAS_INTEGRATION='1'; $env:DB_CONNECTION='mysql'; $env:DB_DATABASE='digi_aml'; php artisan test --filter=SaasOnboardingTest
$env:RUN_RBAC_INTEGRATION='1'; php artisan test --filter=RbacMatrixTest
```

Les tests d’intégration utilisent la base locale et sont explicitement activés. Leurs données de test sont annulées en transaction. La documentation de l’interface se trouve dans le [README frontend](../FRONTEND/README.md).
