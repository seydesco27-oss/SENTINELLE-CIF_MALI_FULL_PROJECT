# SENTINELLE-CIF Mali

Plateforme de supervision financière dédiée à la **Thématique 1 du hackathon
CIF DigiCoop-WA+ : filtrage des clients LBC/FT/FP**.

## Architecture

- `FRONTEND/` : interface React/Vite.
- `SENTINELLE-CIF-API/` : API Laravel, authentification Sanctum, vues AML,
	alertes, screening, investigations et déclarations CENTIF.
- `ML_projet_cif-master/` : screening PEP/sanctions, modèle comportemental
	exporté et microservice Python de scoring.
- `digi_aml_*.sql` : dump de démonstration MySQL.

## Administration SaaS et raccordement des caisses

Après connexion avec le rôle **ADMIN SaaS**, la page d’accueil est
`/admin/structures`. Le parcours de déploiement comprend cinq étapes :
identité de la caisse et de sa première agence, mode d’intégration, périmètre
des données et moteurs, trois utilisateurs initiaux, puis confirmation.
La création de la caisse, de l’agence et des comptes est atomique.

Les trois modes proposés sont :

- **API** : clé `sk_sent_…`, endpoint, en-têtes et exemple JSON à remettre à la caisse.
- **CSV** : trois fichiers UTF-8 séparés par `;` et un guide d’import.
- **SQL** : instructions de remise d’un dump ou d’un accès en lecture seule et vues de mapping.

L’écran **Accès techniques** présente le contrat adapté au mode choisi. Le
point d’ingestion `/api/v1/ingest/transactions` est indiqué comme **prévu** :
la passerelle d’ingestion, l’import automatique CSV et le connecteur SQL ne
sont pas implémentés dans ce MVP. Les clés API sont visibles uniquement dans
la réponse administrateur de cet écran ; les listes de structures ne les exposent pas.

Dans `/admin/screening-lists`, l’ADMIN SaaS importe les exports ONU, UE ou
OFAC. Un import enregistre un lot `RUNNING` puis `SUCCESS` ou `FAILED`,
actualise `screening_list_entries` et `sanctions_entities` (la source lue par
les procédures existantes), et conserve les entrées précédentes en cas d’échec.
Après import, il faut choisir une caisse ou une agence et lancer le screening
par lots avec `sp_screen_client`. L’import seul ne contrôle pas les clients.

`/admin/users` permet de créer ou modifier les quatre rôles existants, avec
caisse, agence et périmètre. **SUPERVISOR + CAISSE** est l’administrateur de
caisse ; **AGENT** reste strictement limité à son agence. L’ADMIN SaaS voit
l’espace de déploiement ; les écrans métier sont destinés aux rôles de caisse.
Les couleurs d’accent distinguent ces quatre rôles.

Le contrat et les routes sont détaillés dans
[`SENTINELLE-CIF-API/README.md`](SENTINELLE-CIF-API/README.md) ; l’interface
dans [`FRONTEND/README.md`](FRONTEND/README.md).

## Démarrage local

WampServer doit fournir MySQL avec la base `digi_aml`.
Appliquer les migrations après une mise à jour du code :

```powershell
cd SENTINELLE-CIF-API
php artisan migrate --force
cd ..
```

Depuis la racine du projet, la commande recommandée démarre le modèle ML,
l'API et le frontend, puis vérifie leurs endpoints :

```powershell
powershell -ExecutionPolicy Bypass -File .\start-sentinelle.ps1
```

L'application est ensuite disponible sur `http://127.0.0.1:5173`.

Au premier démarrage du service ML, si son environnement existe mais ne
contient pas encore les dépendances :

```powershell
cd ML_projet_cif-master
.\.venv\Scripts\python.exe -m ensurepip --upgrade
.\.venv\Scripts\python.exe -m pip install -r dossier\requirements.txt
cd ..
```

Le démarrage manuel reste possible avec trois terminaux.

Terminal 1, service ML :

```powershell
cd ML_projet_cif-master
.\.venv\Scripts\Activate.ps1
python dossier\ml_service.py
```

Terminal 2, API Laravel :

```powershell
cd SENTINELLE-CIF-API
cd public
php -d upload_max_filesize=50M -d post_max_size=52M -d memory_limit=512M -d max_execution_time=120 -S 127.0.0.1:8000 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
```

Terminal 3, frontend :

```powershell
cd FRONTEND
npm run dev -- --host 127.0.0.1
```

Ouvrir `http://127.0.0.1:5173`.

Compte de démonstration : `admin.test` / `DigiAML@2026`.
Le script `start-sentinelle.ps1` démarre l’API avec une limite de fichier de
50 Mo pour les exports sanctions. Si le port 8000 est déjà occupé par un
ancien serveur PHP, arrêtez ce serveur puis relancez le script pour appliquer
ces limites.

## Activer le chatbot Groq

La clé reste uniquement dans `SENTINELLE-CIF-API/.env`. Elle ne doit jamais
être placée dans une variable `VITE_*`, le code React ou GitHub.

Le chatbot utilise l'API Groq compatible OpenAI :

```env
LLM_PROVIDER=groq
GROQ_API_KEY=ta_cle_groq
LLM_BASE_URL=https://api.groq.com/openai/v1
LLM_MODEL=openai/gpt-oss-120b
LLM_FALLBACK_MODELS=qwen/qwen3.8-27b,openai/gpt-oss-20b
```

Les modèles principal et de secours ont des quotas séparés : une limite de débit
ou un modèle inaccessible provoque une bascule automatique. Si le fournisseur
est injoignable, l’assistant active son moteur local déterministe et répond à
partir des données autorisées de l’application.

Après toute modification de `.env` :

```powershell
cd SENTINELLE-CIF-API
php artisan config:clear
```

Sans clé LLM, le chatbot conserve son mode local déterministe. Avec une clé,
`POST /api/v1/ml/chat` envoie la question et le contexte du dossier au LLM;
la clé n’est jamais envoyée au frontend.

## Vérifications

```powershell
curl.exe http://127.0.0.1:8100/health
curl.exe http://127.0.0.1:8000/api/health
```

Tests :

```powershell
cd FRONTEND; npm run lint; npm run build
cd ..\SENTINELLE-CIF-API; php artisan test
$env:RUN_SAAS_INTEGRATION='1'; $env:DB_CONNECTION='mysql'; $env:DB_DATABASE='digi_aml'; php artisan test --filter=SaasOnboardingTest
$env:RUN_RBAC_INTEGRATION='1'; php artisan test --filter=RbacMatrixTest
cd ..\ML_projet_cif-master; .\.venv\Scripts\python.exe -m unittest tests.test_official_sources dossier.test_ml_service -v
```

## Screening et IA

Le service Python charge le modèle `MLPClassifier` exporté et les listes
UE, OFAC et ONU embarquées. L’endpoint `/health` expose la source et le
nombre d’entrées chargées. Le score temps réel est disponible via
`POST /api/v1/ml/score`; les listes et les statistiques SQL de screening sont
des sources distinctes. Les imports administrateur mettent à jour le
référentiel MySQL utilisé par les procédures SQL de screening ; ils ne
rechargent pas automatiquement les listes embarquées dans le service Python.

Les données sont destinées à une démonstration et doivent être recalibrées
sur des données CIF anonymisées avant toute décision opérationnelle.
