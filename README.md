# IA4Sustainability

IA4Sustainability is an integrated sustainability reporting application with:

- a Next.js frontend in `app/frontend`;
- a Laravel backend/API in `app/web`;
- a Python/FastAPI AI service in `app/ai-service`;
- ESRS/AR16 mapping files in `app/contracts/mappings`.

This public repository contains source code, dependency manifests, tests,
contracts, and public setup notes only. Local operator notes, source snapshots,
deployment logs, working data, archives, and private configuration are not part
of the public tree.

## Prerequisites

- PHP 8.2+ and Composer 2.x
- Node.js 20+ with Corepack/pnpm
- Python 3.10+
- SQLite for local development

## Laravel Backend

```powershell
cd app/web
composer install
Copy-Item .env.example .env
New-Item -ItemType File -Path database/database.sqlite -Force
php artisan key:generate
php artisan migrate --seed
npm install
npm run dev
php artisan serve
```

The default backend configuration uses `CHARACTERIZATION_GATEWAY=mock`, so the
Laravel workflow can run locally without private model artifacts or external
services.

## Next.js Frontend

```powershell
cd app/frontend
corepack enable
corepack pnpm install --frozen-lockfile
Copy-Item .env.example .env.local
corepack pnpm dev
```

Set `LARAVEL_API_ORIGIN` in `app/frontend/.env.local` when the frontend should
proxy API requests to a running Laravel backend.

## Python AI Service

```powershell
cd app/ai-service
py -3.10 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
.\.venv\Scripts\python.exe -m pip check
cd src
..\.venv\Scripts\uvicorn.exe services.endpoints:app --host 127.0.0.1 --port 8001
```

Trained model artifacts are not included in this public repository. Keep
Laravel on the mock gateway for a source-only local run, or provide your own
model artifacts under `app/ai-service/data/` before using the Python-backed
prediction gateway.

## Verification

```powershell
cd app/web
php artisan test

cd ..\frontend
corepack pnpm build
node --test tests/integration-boundaries.test.mjs tests/p5-step1-laravel-boundary.test.mjs tests/p6-step2-laravel-boundary.test.mjs tests/p7-step3-laravel-boundary.test.mjs tests/p8-step4-laravel-boundary.test.mjs tests/p9-step5-laravel-boundary.test.mjs tests/p10-step6-laravel-boundary.test.mjs

cd ..\ai-service
$env:PYTHONPATH='src'
python -m unittest discover -s src/tests
```

## Configuration Rules

- Do not commit real `.env` files, API keys, credentials, local databases, logs,
  generated build output, model artifacts, source-report corpora, or private
  deployment notes.
- Use the checked-in `.env.example` files as templates.
- Keep local/private model artifacts outside commits unless a separate public
  model release process is defined.
