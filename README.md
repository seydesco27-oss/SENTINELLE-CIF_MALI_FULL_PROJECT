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

## Démarrage local

WampServer doit fournir MySQL avec la base `digi_aml`.

Terminal 1, service ML :

```powershell
cd ML_projet_cif-master
.\.venv\Scripts\Activate.ps1
python dossier\ml_service.py
```

Terminal 2, API Laravel :

```powershell
cd SENTINELLE-CIF-API
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal 3, frontend :

```powershell
cd FRONTEND
npm run dev -- --host 127.0.0.1
```

Ouvrir `http://127.0.0.1:5173`.

Compte de démonstration : `admin.test` / `DigiAML@2026`.

## Activer le LLM du chatbot

La clé reste uniquement dans `SENTINELLE-CIF-API/.env`.

Pour Grok :

```env
LLM_PROVIDER=grok
LLM_API_KEY=ta_cle_xai
LLM_BASE_URL=https://api.x.ai/v1
LLM_MODEL=grok-3-mini
```

Pour OpenAI :

```env
LLM_PROVIDER=openai
LLM_API_KEY=ta_cle_openai
LLM_BASE_URL=https://api.openai.com/v1
LLM_MODEL=gpt-4o-mini
```

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
cd ..\ML_projet_cif-master; .\.venv\Scripts\python.exe -m unittest tests.test_official_sources dossier.test_ml_service -v
```

## Screening et IA

Le service Python charge le modèle `MLPClassifier` exporté et les listes
UE, OFAC et ONU embarquées. L’endpoint `/health` expose la source et le
nombre d’entrées chargées. Le score temps réel est disponible via
`POST /api/v1/ml/score`; les listes et les statistiques SQL de screening sont
des sources distinctes.

Les données sont destinées à une démonstration et doivent être recalibrées
sur des données CIF anonymisées avant toute décision opérationnelle.
