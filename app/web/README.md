# IA4Sustainability Web App

## Overview / Descripción
- **Purpose / Propósito:** IA4Sustainability helps organisations capture ESG characterization data and orchestrate asynchronous assessments aligned with the ESRS framework.
- **Tech Stack / Tecnologías:** Laravel 12 (PHP 8.4), Tailwind CSS, Vite, Docker (Sail). Development database defaults to SQLite; production targets PostgreSQL.

## Prerequisites / Requisitos Previos
- PHP ≥ 8.3, Composer 2.8+
- Node.js ≥ 20, PNPM
- Docker Desktop (for Sail-based environment)

## Local Development (SQLite) / Desarrollo Local (SQLite)
1. `cp .env.example .env`
2. `composer install`
3. Ensure the SQLite file exists: `touch database/database.sqlite`
4. `php artisan key:generate`
5. Run migrations when available: `php artisan migrate`
6. Start the dev server: `php artisan serve`
7. Build assets: `npm install` then `npm run dev`

## Docker Workflow (Sail) / Flujo Docker (Sail)
1. `cp .env.example .env`
2. (Optional) switch DB connection to PostgreSQL inside `.env` when you want to use the Sail `pgsql` container.
3. Boot containers: `./vendor/bin/sail up -d`
4. Run migrations: `./vendor/bin/sail artisan migrate`
5. Frontend dev server: `./vendor/bin/sail npm install && ./vendor/bin/sail npm run dev`
6. Stop containers: `./vendor/bin/sail down`

## Data Seeds / Datos de Referencia
- ESRS topics JSON: `data/esrs_topics.json`
- NACE codes JSON: `data/nace_codes.json`
- Run `php artisan migrate --seed` (or `php artisan db:seed --class=Database\\Seeders\\NaceCodeSeeder` / `EsrsTopicSeeder`) to load both datasets; avoid manual edits to the JSON files.

## Async Mock Service / Servicio Simulado Asíncrono
- Submissions move through `draft → submitted → waiting → processing → completed/failed` via `SubmitCharacterizationJob` and status changes broadcast on `characterizations.{user_id}`.
- Configure the simulated gateway outcome with `CHARACTERIZATION_MOCK_OUTCOME` (`success` by default, set to `fail` for testing retries/errors).
- Results from the mock service are stored in `characterizations.result_data` once the job finishes.

## Project Assets / Recursos
- Legal & UX inspiration: https://sygris.com
- All UI copy must ship in English and Spanish.

## Characterization Flow / Flujo de caracterización
- Multi-step wizard (Company → Operations → ESRS → Review) with draft persistence and auto-save when navigating between steps.
- Real-time status badge powered by Laravel Echo (`characterizations.{user_id}`) plus retry metadata (retry count, next retry time, last attempt).
- Failed or timed-out submissions expose a manual retry button that re-dispatches the async job.
- Summary export available as HTML or downloadable PDF (`/characterization/summary?format=pdf`).

## Next Steps / Próximos Pasos
- Swap `CHARACTERIZATION_GATEWAY=api` and provide credentials once the real external service is reachable; add end-to-end tests against a sandbox environment.
- Enrich the wizard with conditional ESG questions, autosave feedback, and accessibility refinements (ARIA announcements per step).
- Build reporting dashboards and KPIs (e.g., average processing time, retry rates) once real responses are flowing.
