# Configuration

Back to the [documentation index](../index.md).

Environment templates are guidance only. They do not contain real secrets and must not be copied to production without review.

## Profiles

| Profile | Use | Features |
|---|---|---|
| Public Compose | Local evaluation | Temporary SQLite, mail to log, OAuth disabled, documents disabled, internal API proposal service |
| Manual development | Local technical work | May use simulated prediction and local persistence if configured by the operator |
| Own environment | Operation outside the repository | Requires secrets, HTTPS, persistent database, mail, queues, monitoring and data policy |

## Key Variables

- `APP_URL`: public Laravel origin. It must match proxy, cookies and redirects.
- `NEXT_PUBLIC_LARAVEL_API_BASE_URL`: browser-visible route to the API.
- `LARAVEL_API_ORIGIN`: server-to-server origin used by the frontend.
- `CHARACTERIZATION_GATEWAY`: selects mock or API service for proposals.
- `CHARACTERIZATION_API_BASE_URL`: address of the proposal service when API is used.
- `CHARACTERIZATION_AI_MODEL_PROFILE`: technical profile of the included model.
- `CHARACTERIZATION_PREDICTION_MAPPING_PATH`: optional mapping for model keys.
- `ESRS_MATTER_DR_MAPPING_PATH`: optional mapping between topics and Disclosure Requirements.
- `AUTH_REQUIRE_EMAIL_VERIFICATION`, `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET`: registration controls dependent on the operator.

A configured variable does not turn a capability into a business guarantee. It only changes the technical behaviour described.
