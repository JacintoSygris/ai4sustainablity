# Secrets And Parameters

Back to the [technical manual](index.md).

## Principles

- Do not store real `.env` files in Git.
- Do not paste secrets into tickets, documentation, screenshots or shared commands.
- Use a secret manager or host files with permissions `0640` or stricter.
- Separate non-secret configuration from secrets: domains, paths and flags may be versioned in templates; keys and passwords must not.
- Rotate credentials in a planned window and verify login, mail, queues and health checks afterwards.

## Secret Creation

Prerequisite: a deployment copy with PHP dependencies installed, or a built Laravel container.

```sh
# Directory: app/web
php artisan key:generate --show
```

Expected result: a `base64:...` key is printed.

Check: copy it only into the environment secret `APP_KEY`; do not write it in Markdown or in a versioned Compose file.

For database, Redis, SMTP and OAuth passwords, generate values with the corporate secret manager or a secure local generator. Do not reuse values across services.

## Storage And Rotation

Prerequisite: the deployment reads variables from the operator's chosen mechanism.

1. Create the new secret without deleting the old one.
2. Update the consuming service.
3. Restart only affected processes.
4. Verify health checks and functional flow.
5. Revoke the old secret in the provider.

Expected result: the service is operational with the new secret and the old one no longer authenticates.

Check: review provider authentication logs and Laravel/FastAPI errors without exposing the secret.

## Variable Table

| Variable | Service | Required | Non-secret example | Validation |
|---|---|---:|---|---|
| `APP_NAME` | Laravel | Yes | `IA4Sustainability` | Appears in mail and views. |
| `APP_ENV` | Laravel | Yes | `production` | `php artisan about` inside `web`. |
| `APP_KEY` | Laravel | Yes | `base64:EXAMPLE_NOT_A_SECRET=` | Encrypted sessions do not fail. |
| `APP_DEBUG` | Laravel | Yes | `false` | Errors do not show traces to users. |
| `APP_URL` | Laravel | Yes | `https://app.example.org` | Mail links and OAuth use the correct domain. |
| `APP_LOCALE` | Laravel | Recommended | `es` | Expected localised messages. |
| `LOG_CHANNEL` | Laravel | Yes | `stderr` or `stack` | Logs visible in platform. |
| `LOG_LEVEL` | Laravel | Yes | `info` | No debug-sensitive data. |
| `DB_CONNECTION` | Laravel | Yes | `pgsql` | `php artisan migrate:status`. |
| `DB_HOST` | Laravel | Yes | `postgres` | Laravel reaches PostgreSQL privately. |
| `DB_PORT` | Laravel | Yes | `5432` | Internal TCP connection. |
| `DB_DATABASE` | Laravel/PostgreSQL | Yes | `ia4sustainability` | Database exists. |
| `DB_USERNAME` | Laravel/PostgreSQL | Yes | `ia4_app` | Limited role. |
| `DB_PASSWORD` | Laravel/PostgreSQL | Yes | managed secret | Migrations authenticate. |
| `DB_SSLMODE` | Laravel/PostgreSQL | Depends | `prefer` | Review operator policy. |
| `REDIS_HOST` | Laravel/Redis | Yes | `redis` | `queue:work` connects. |
| `REDIS_PORT` | Laravel/Redis | Yes | `6379` | Internal connection. |
| `REDIS_USERNAME` | Laravel/Redis | Depends | `default` | If ACLs are used. |
| `REDIS_PASSWORD` | Laravel/Redis | Recommended | managed secret | Redis does not accept anonymous access if supported. |
| `REDIS_DB` | Laravel/Redis | Yes | `0` | Session/queue separation possible. |
| `REDIS_CACHE_DB` | Laravel/Redis | Yes | `1` | Separate cache. |
| `SESSION_DRIVER` | Laravel | Yes | `redis` | Login persists after reload. |
| `SESSION_STORE` | Laravel | Yes with Redis | `redis` | Session uses correct store. |
| `SESSION_SECURE_COOKIE` | Laravel | Yes | `true` | Cookies only over HTTPS. |
| `SESSION_SAME_SITE` | Laravel | Yes | `lax` | Normal login works. |
| `CACHE_STORE` | Laravel | Yes | `redis` | Cache does not use array in production. |
| `QUEUE_CONNECTION` | Laravel | Yes | `redis` | Jobs enter Redis. |
| `REDIS_QUEUE` | Laravel | Recommended | `default` | Worker listens to the same queue. |
| `REDIS_QUEUE_RETRY_AFTER` | Laravel | Recommended | `1800` | Longer than long job timeout; with learning, longer than 1200. |
| `DB_QUEUE_RETRY_AFTER` | Laravel | Yes with `database` queue | `1800` | Longer than long job timeout; with learning, longer than 1200. |
| `MAIL_MAILER` | Laravel | Yes for mail | `smtp` | Password reset sends. |
| `MAIL_HOST` | Laravel/SMTP | Yes for mail | `smtp.example.org` | Provider connection. |
| `MAIL_PORT` | Laravel/SMTP | Yes for mail | `587` | Typical STARTTLS. |
| `MAIL_USERNAME` | Laravel/SMTP | Yes for mail | provider user | Authentication works. |
| `MAIL_PASSWORD` | Laravel/SMTP | Yes for mail | managed secret | Not in logs. |
| `MAIL_ENCRYPTION` | Laravel/SMTP | Yes for mail | `tls` | Encrypted sending. |
| `MAIL_FROM_ADDRESS` | Laravel/SMTP | Yes for mail | `no-reply@app.example.org` | Authorised sender. |
| `MAIL_FROM_NAME` | Laravel/SMTP | Yes for mail | `IA4Sustainability` | Visible name. |
| `AUTH_REQUIRE_EMAIL_VERIFICATION` | Laravel | Recommended | `true` | Unverified users cannot use protected routes. |
| `TURNSTILE_SITE_KEY` | Laravel/frontend | Optional | example public key | Register page shows widget if configured. |
| `TURNSTILE_SECRET` | Laravel | Optional | managed secret | Anti-abuse verification. |
| `SOCIAL_LOGIN_ENABLED` | Laravel | Optional | `false` | OAuth disabled by default. |
| `GOOGLE_CLIENT_ID` | Laravel/Google | If Google | app id | Redirect starts. |
| `GOOGLE_CLIENT_SECRET` | Laravel/Google | If Google | managed secret | Callback authenticates. |
| `GOOGLE_REDIRECT_URI` | Laravel/Google | If Google | `https://app.example.org/auth/google/callback` | Exact match in Google Cloud. |
| `MICROSOFT_CLIENT_ID` | Laravel/Entra | If Microsoft | app id | Redirect starts. |
| `MICROSOFT_CLIENT_SECRET` | Laravel/Entra | If Microsoft | managed secret | Callback authenticates. |
| `MICROSOFT_REDIRECT_URI` | Laravel/Entra | If Microsoft | `https://app.example.org/auth/microsoft/callback` | Exact match in Entra. |
| `MICROSOFT_TENANT_ID` | Laravel/Entra | If Microsoft | `common` | Adjust if operator restricts tenants. |
| `CHARACTERIZATION_GATEWAY` | Laravel | Yes | `api` | Do not use `mock` for real operation. |
| `CHARACTERIZATION_API_BASE_URL` | Laravel/FastAPI | Yes | `http://ai-service:8001` (containers) or `http://127.0.0.1:8001` (native installation) | `/healthz` responds privately. |
| `CHARACTERIZATION_API_TIMEOUT` | Laravel | Yes | `60` | Sufficient prediction timeout. |
| `CHARACTERIZATION_AI_MODEL_PROFILE` | Laravel/FastAPI | Yes | `new_format_732_v1_gpt41` | `/model-profiles` lists it. |
| `CHARACTERIZATION_API_TOKEN` | Laravel/FastAPI | Not used by included FastAPI | empty | Do not assume authentication if service does not implement it. |
| `CHARACTERIZATION_PREDICTION_MAPPING_PATH` | Laravel | Optional | empty or internal path | Key mapping exists if customised. |
| `ESRS_MATTER_DR_MAPPING_PATH` | Laravel | Yes for full P9/P10 | `/var/www/html/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json` | `esrs:validate-matter-dr-mapping`. |
| `ESRS_EXTERNAL_TAXONOMY_MANIFEST_PATH` | Laravel | XHTML/iXBRL candidate only | absolute private manifest path | Manifest verifies profile, entrypoint, external ZIP and SHA-256. |
| `ESRS_ARELLE_COMMAND` | Laravel | XHTML/iXBRL candidate only | absolute `arelleCmdLine` path | Must be an absolute path to an executable, not a name resolved through `PATH`: Arelle runs offline with a minimal environment (`PATH=/usr/local/bin:/usr/bin:/bin`). A relative or missing path blocks the candidate. |
| `ESRS_ARELLE_CACHE_DIRECTORY` | Laravel | Optional, XHTML/iXBRL candidate | absolute external path | Provisioned cache of public XBRL/W3C dependencies for offline validation; absolute path, outside the repository. |
| `FILESYSTEM_DISK` | Laravel | Yes | `local` | Documents are forced to local disk by model. |
| `AWS_ACCESS_KEY_ID` and related | Laravel | Only other integrations | non-secret example | Do not enable P6 documents automatically. |
| `P6_DOCUMENT_UPLOAD_ENABLED` | Laravel | Yes | `false` | Must remain `false` until `/extract-document` exists. |
| `P6_DOCUMENT_EXTRACT_TIMEOUT` | Laravel | Future | `240` | Does not fix missing endpoint. |
| `P6_DOCUMENT_EXTRACT_JOB_TIMEOUT` | Laravel | Future | `300` | Does not fix missing endpoint. |
| `LARAVEL_INTERNAL_API_ORIGIN` | Next.js | Yes | `http://web:8000` | Private Laravel origin for SSR and rewrites; not exposed to browsers. |
| `LARAVEL_CANONICAL_ORIGIN` | Next.js | Yes | `https://app.example.org` | Browser-visible HTTPS origin forwarded to Laravel for links and cookies. |
| `LARAVEL_API_ORIGIN` | Next.js | Compatibility | empty | Optional legacy alias; when set it must match `LARAVEL_INTERNAL_API_ORIGIN`. |
| `NEXT_PUBLIC_LARAVEL_API_BASE_URL` | Next.js | Yes | `/api` | Browser uses public origin. |
| `NEXT_PUBLIC_APP_URL` | Next.js | Yes | `https://app.example.org` | Frontend links correct. |
| `NEXT_TELEMETRY_DISABLED` | Next.js | Recommended | `1` | Framework telemetry disabled. |
| CORS origins | Proxy/Laravel | Depends | `https://app.example.org` | Keep same origin where possible. |

## Safe Toggles

For initial production:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=pgsql
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
CHARACTERIZATION_GATEWAY=api
CHARACTERIZATION_API_BASE_URL=http://ai-service:8001
P6_DOCUMENT_UPLOAD_ENABLED=false
SOCIAL_LOGIN_ENABLED=false
LEARNING_DEPLOYED=false
```

OAuth and Turnstile are enabled only when their providers are configured and verified. Document upload remains disabled until a service compatible with `POST /extract-document` exists.

With `APP_ENV=production`, optional learning is enabled by configuration unless `LEARNING_DEPLOYED=false`. Keep `LEARNING_DEPLOYED=false` in containers (they do not support learning) and on native installations until the provisioning checklist in the [activation runbook](../../learning-activation-runbook.md) (Spanish) is complete.

## Optional Learning

Laravel variables and, where stated, proposal-service variables. Use the same names as `app/web/.env.production.example`. A defined variable, even an empty one, overrides its default: an empty assignment (`VAR=`) does not inherit the `LEARNING_DEPLOYED` default. Empty boolean flags read as `false`, an empty authority is not `consent`, an empty bandit mode means `off` and an empty path fails its check; comment out or remove the lines that should inherit the default. The defaults in the "Declared" column apply with `APP_ENV=production|staging` or `LEARNING_DEPLOYED=true`; locally and in testing everything stays off.

| Variable | Service | Declared | Non-secret example | Validation |
|---|---|---|---|---|
| `LEARNING_DEPLOYED` | Laravel and FastAPI | — | `false` until provisioned | Sets the activation defaults of the engine and feedback adjustment. |
| `LEARNING_ENGINE_ENABLED` | Laravel and FastAPI | `true` | `true` | Capture, export, training and candidate serving. |
| `LEARNING_ENGINE_AUTHORITY` | Laravel and FastAPI | `consent` | `consent` | With `deny` no data is used. |
| `LEARNING_ENGINE_SYNTHETIC_ONLY` | Laravel | `false` | `false` | `true` restricts the engine to synthetic accounts. |
| `LEARNING_ENGINE_SCHEDULED` | Laravel | `true` | `true` | `learning:tick` queues processing and training. |
| `LEARNING_ENGINE_SERVING_ENABLED` | Laravel and FastAPI | `true` | `true` | The service may use a promoted candidate. |
| `LEARNING_ENGINE_SHADOW_ENABLED` | FastAPI | `false` | `false` | Shadow logging; keep `false` unless explicitly evaluating. |
| `LEARNING_BANDIT_MODE` | Laravel and FastAPI | `active` | `active` | `off`, `shadow` or `active`. |
| `LEARNING_ENGINE_ROOT` | Laravel and FastAPI | `storage/app/private/learning-engine` (not suitable) | absolute persistent path outside the checkout | The panel requires an absolute, real, non-symlinked, writable path; same path and user in every process. |
| `LEARNING_ENGINE_SOURCE` | Laravel | `../ai-service/src` | absolute path to `app/ai-service/src` | Only if the layout differs from the checkout. |
| `LEARNING_ENGINE_PYTHON` | Laravel | `../ai-service/.venv-learning/bin/python` | absolute path to the `.venv-learning` Python 3.13 | The panel runs `status` with this interpreter. |
| `LEARNING_ENGINE_CODE_COMMIT` | Laravel | empty | 40-character SHA of the deployed commit | Required to train and promote. |
| `LEARNING_ENGINE_HELDOUT_PATH` and `_SHA256` | Laravel | empty | private file outside Git and its SHA-256 | Required to train and promote. |
| `LEARNING_ENGINE_GOLD_PATH` and `_SHA256` | Laravel | empty | optional; both or neither | Recorded as `unavailable` when missing. |
| `LEARNING_ADMIN_USER_IDS` | Laravel | empty | `12,34` | Numeric IDs with a verified email that use the panel. |
| `LEARNING_ENGINE_ADMIN_IDS` | Laravel | empty | same IDs | Panel fallback; read by `learning:promote` and `learning:rollback`. |
| `I4S_AI_NEW_FORMAT_SCORE_THRESHOLD` | FastAPI | `0.95` | unset | If set, same value in FastAPI and in the real PHP-FPM and worker environment. |
| `I4S_AI_ARTIFACT_DIR` | FastAPI | automatic resolution | unset | Same as the previous row. |

Learning also requires `CHARACTERIZATION_GATEWAY=api` with `CHARACTERIZATION_API_BASE_URL=http://127.0.0.1:8001`, a `database`, `redis` or `beanstalkd` queue with `*_QUEUE_RETRY_AFTER=1800` (the panel requires a value above 1200; Laravel's defaults are lower), a shared `CACHE_STORE` other than `array`, a `queue:work --queue=default,learning --timeout=1200 --tries=1` worker and `schedule:run` every minute. See [Services and deployment](services-deployment.md).

### Promotion Gate Metrics

These defaults are the same locally and in deployed environments; they do not inherit activation from `LEARNING_DEPLOYED`. Laravel passes them explicitly to the Python bridge. The policy is frozen before fitting and checked again on promotion. See [Learning gate metrics](../../learning-engine-production.md#métricas-del-control-de-promoción) (Spanish).

| Variable | Default | Validation and purpose |
|---|---|---|
| `LEARNING_GATE_METRICS_ENABLED` | `false` | Explicit opt-in; requires precision improvement and preservation of every known positive. |
| `LEARNING_GATE_MIN_COMPANIES` | `30` | Integer from 1 to 1,000,000; distinct companies in the heldout set. |
| `LEARNING_GATE_MIN_POSITIVES` | `30` | Integer from 1 to 1,000,000; minimum known positive support. |
| `LEARNING_GATE_MIN_NEGATIVES` | `30` | Integer from 1 to 1,000,000; minimum known negative support. |
| `LEARNING_GATE_PRECISION_DELTA` | `0.01` | Finite, 0 ≤ value < 1; the interval lower bound must strictly exceed it. |
| `LEARNING_GATE_REPLICATES` | `2000` | Integer from 2 to 10,000; paired company-level resampling. |
| `LEARNING_GATE_CONFIDENCE` | `0.95` | Finite, 0 < value < 1; two-sided percentile interval. |
| `LEARNING_GATE_SEED` | `17` | Integer from 0 to 2³²−1; reproducible draws without redrawing. |
| `LEARNING_SERVING_RUNTIME` | `public` | Only supported value; uses the public `services` alias, shared with FastAPI. |

Configured fixed sets are verified by SHA-256 and their companies are excluded before feedback export. The panel shows aggregates, intervals and failure reasons; undefined values appear as “Unavailable”. A tie fails the gate. The support minima are policy choices, not quality guarantees; historical receipts cannot support new promotions while metrics are enabled. Code and contract changes continue to invalidate candidates while the flag is off.

## Optional Modules

All are off by default. Runtime XBRL identification and sector comparison also require independent Python flags; the other modules are managed in Laravel. Laravel reports the capability to the frontend through the session (XBRL identification, iXBRL, study, EU Taxonomy) or through the response itself (DMA import, concept relations, sector comparison, report scope integrity); the XBRL overlay has no interface. Run the migrations before enabling them and refresh the configuration cache.

| Variable | Default | Purpose | Guide |
|---|---|---|---|
| `DMA_IMPORT_ENABLED` | `false` | CSV import of the double materiality assessment into P8 | [DMA import](../../dma-import.md) |
| `DMA_NLP_ENABLED` | `false` | Laravel: local IRO text recommendations; requires DMA import and reviewed IRO traceability | [Text analysis](../../dma-import.md#análisis-local-de-textos-iro) |
| `I4S_DMA_NLP_ENABLED` | `false` | Python: independent authenticated text-analysis route; one ASGI process | As above |
| `I4S_DMA_NLP_MODEL_DIR` | unset | Python: external absolute JSON package directory with `catalogues/`; public manifest pin required | As above |
| `CONCEPT_RELATIONS_ENABLED` | `false` | Informational related-topic and related-datapoint suggestions in P8 and P9 | [Concept relations](../../../ai-service/docs/concept-relations.md) |
| `SECTOR_COMPARISON_ENABLED` | `false` | Laravel: informational comparison during topic review and confirmation | [Sector comparison](../../sector-comparison.md) |
| `ESRS_SECTOR_COMPARISON_ENABLED` | `false` | Python: independent opt-in, unrelated to learning activation | As above |
| `ESRS_SECTOR_COMPARISON_PATH` | unset | Python: authorised external JSON snapshot, at most 5 MiB; loaded once at startup | As above |
| `ESRS_SECTOR_COMPARISON_SHA256` | unset | Python: exact SHA-256 of the snapshot; invalid configuration disables comparison | As above |
| `XBRL_SUGGESTION_OVERLAY_ENABLED` | `false` | Applies operator-accepted XBRL suggestions to the concept map | [XBRL concept suggestions](../../../ai-service/docs/xbrl-concept-mapping.md) |
| `XBRL_RUNTIME_IDENTIFICATION_ENABLED` | `false` in Laravel and Python | Concept identification with review and frozen evidence; independent of learning and overlay | [Runtime XBRL identification](../../../ai-service/docs/xbrl-concept-mapping.md#identificación-en-ejecución) |
| `XBRL_RUNTIME_MANIFEST_PATH` | unset | Laravel: external manifest for the served model | As above |
| `XBRL_RUNTIME_CATALOG_PATH` | unset | Laravel: optional external catalog bound to the authorised package | As above |
| `XBRL_RUNTIME_MODEL_DIR` | unset | Python: external absolute model directory, loaded once per process | As above |
| `IXBRL_PACKAGE_UI_ENABLED` | `false` | iXBRL candidate package in P10 and reporting-period dates in P5; when off, `GET /api/report/ixbrl-package` returns 404 | [iXBRL candidate package](../../ixbrl-candidate-package.md) |
| `IXBRL_TAXONOMY_PROFILE` | `esrs_set1_2023-12-22` | Taxonomy profile of the package | As above |
| `IXBRL_2026_DRAFT_ENABLED` | `false` | Provisional 2026 profile; still blocked without mappings | As above |
| `REPORT_SCOPE_INTEGRITY_ENABLED` | `false` | V2 snapshots bound to corpus/mapping and XHTML presentation download; v1 requires new approval, v2 checks persist when disabled | [Report scope integrity](../../report-scope-integrity.md) |
| `USABILITY_STUDY_ENABLED` | `false` | Consent-based usability study kit | [Usability protocol](../../usability-study-protocol.md) |
| `USABILITY_STUDY_ADMIN_USER_IDS` | empty | IDs allowed to export study results | As above |
| `EU_TAXONOMY_ELIGIBILITY_ENABLED` | `false` | Indicative EU Taxonomy eligibility screening | [EU Taxonomy eligibility](../../eu-taxonomy-eligibility.md) |

The linked guides are in Spanish.

Sector comparison reuses `CHARACTERIZATION_API_BASE_URL/TOKEN` in Laravel and `I4S_AI_API_TOKEN` in Python. Configure the Python variables in its service environment; the Laravel file does not configure FastAPI. Keep the service private, provision the snapshot outside Git and restart Python after changing it. Both flags are off independently of learning; no real sector corpus is distributed. Laravel requires the PHP `curl` extension.

Runtime XBRL identification reuses `CHARACTERIZATION_API_TOKEN` / `I4S_AI_API_TOKEN` and requires numeric HTTP loopback in `CHARACTERIZATION_API_BASE_URL`. Both processes must use byte-identical manifests/catalogs; replacing the model requires restarting Python. The included network topology between containers does not meet the loopback restriction. Without an authorised bilingual catalog, the model supports English descriptions only and abstains on Spanish input. Generated artifacts and taxonomy bytes are not distributed.

### XHTML/iXBRL Candidate

- `GET /api/report/xhtml-ixbrl-candidate` and `GET /api/report/ixbrl-package` require explicit reporting-period start and end dates and a valid (MOD-97) LEI in the approved factual version. P5 captures those dates only with `IXBRL_PACKAGE_UI_ENABLED=true`: with the defaults, even when the taxonomy and Arelle are provisioned, the candidate returns 409 with `xhtml_ixbrl_context_missing`. Versions approved before the dates were captured must be approved again.
- `ESRS_ARELLE_COMMAND` and `ESRS_ARELLE_CACHE_DIRECTORY` must be absolute paths outside the repository. Laravel runs Arelle without a shell and with a minimal environment (`PATH=/usr/local/bin:/usr/bin:/bin`, `LANG=C.UTF-8` and a private `TMPDIR`), without the application's variables.
- The validator passes `--validationExitCode`, `--disablePersistentConfig`, `--xdgConfigHome` (a temporary directory), `--logFile` with a JSON log, `--packages` and, when configured, `--cacheDirectory` to Arelle. These options match `arelle-release` 2.46.1, the version pinned in `app/ai-service/scripts/ixbrl/requirements-validation.txt`; `app/web/requirements-arelle.txt`, which the web image installs, pins 2.45.1. That combination has not been checked: verify that the installed Arelle accepts these options before enabling the candidate. If it does not, the candidate is blocked with `xhtml_ixbrl_arelle_validation_failed`.

Details: [External EFRAG taxonomy](external-efrag-taxonomy.md).
