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
| `REDIS_QUEUE_RETRY_AFTER` | Laravel | Recommended | `360` | Longer than long job timeout. |
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
| `CHARACTERIZATION_API_BASE_URL` | Laravel/FastAPI | Yes | `http://ai-service:8001` | `/healthz` responds privately. |
| `CHARACTERIZATION_API_TIMEOUT` | Laravel | Yes | `60` | Sufficient prediction timeout. |
| `CHARACTERIZATION_AI_MODEL_PROFILE` | Laravel/FastAPI | Yes | `new_format_732_v1_gpt41` | `/model-profiles` lists it. |
| `CHARACTERIZATION_API_TOKEN` | Laravel/FastAPI | Not used by included FastAPI | empty | Do not assume authentication if service does not implement it. |
| `CHARACTERIZATION_PREDICTION_MAPPING_PATH` | Laravel | Optional | empty or internal path | Key mapping exists if customised. |
| `ESRS_MATTER_DR_MAPPING_PATH` | Laravel | Yes for full P9/P10 | `/var/www/html/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json` | `esrs:validate-matter-dr-mapping`. |
| `ESRS_EXTERNAL_TAXONOMY_MANIFEST_PATH` | Laravel | XHTML/iXBRL candidate only | absolute private manifest path | Manifest verifies profile, entrypoint, external ZIP and SHA-256. |
| `ESRS_ARELLE_COMMAND` | Laravel | XHTML/iXBRL candidate only | absolute `arelleCmdLine` path | Arelle runs offline; its absence blocks the candidate. |
| `FILESYSTEM_DISK` | Laravel | Yes | `local` | Documents are forced to local disk by model. |
| `AWS_ACCESS_KEY_ID` and related | Laravel | Only other integrations | non-secret example | Do not enable P6 documents automatically. |
| `P6_DOCUMENT_UPLOAD_ENABLED` | Laravel | Yes | `false` | Must remain `false` until `/extract-document` exists. |
| `P6_DOCUMENT_EXTRACT_TIMEOUT` | Laravel | Future | `240` | Does not fix missing endpoint. |
| `P6_DOCUMENT_EXTRACT_JOB_TIMEOUT` | Laravel | Future | `300` | Does not fix missing endpoint. |
| `LARAVEL_API_ORIGIN` | Next.js | Yes | `http://web:8000` | Rewrites work. |
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
```

OAuth and Turnstile are enabled only when their providers are configured and verified. Document upload remains disabled until a service compatible with `POST /extract-document` exists.
