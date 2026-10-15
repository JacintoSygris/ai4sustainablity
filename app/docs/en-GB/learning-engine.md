# Optional learning engine

## Scope

Optional learning has two loops that share configuration, consent and an administration panel:

- **Supervised learning engine**: retains the reviewed materiality decisions (P8) and structured P9 evidence of consenting accounts, trains candidate models, evaluates them against held-out sets and serves them only after manual promotion.
- **Feedback-based adjustment**: a bounded contextual bandit that learns incrementally from consented P8 confirmations and may change only a few candidate topics close to the threshold. Each version also requires manual promotion.

The `/admin/learning` panel has one section per loop with these same names: "Supervised learning engine" and "Feedback-based adjustment" (in English) or "Motor de aprendizaje supervisado" and "Ajuste por retroalimentación" (in Spanish).

Candidates remain separate from the base model, whose artefacts are never replaced. An unanswered topic is not a negative training label. Disclosure relevance and answer-selection decisions do not remove reporting obligations. The workflow preserves source revisions and case authorisation; source changes, withdrawal and account deletion invalidate eligibility.

## Activation

- **Off locally and in the included composition.** `compose.public.yml` and local or test environments keep learning off and synthetic.
- **On by configuration in deployed environments.** With `APP_ENV=production` or `staging`, or with `LEARNING_DEPLOYED=true`, the Laravel and proposal-service defaults enable both loops. `LEARNING_DEPLOYED=false` keeps them off even with `APP_ENV=production`; the `app/web/.env.production.example` template sets it that way until the provisioning checklist is complete.
- **Consent only.** The authority is `consent`. Only accounts with a verified email and current consent for this purpose take part; consent is granted and withdrawn in `/profile`. The supervised engine additionally requires a verified company membership.
- **Manual promotion and rollback.** They take place in `/admin/learning`, restricted to the IDs configured in `LEARNING_ADMIN_USER_IDS` and `LEARNING_ENGINE_ADMIN_IDS`. Nothing is promoted automatically.
- **Prerequisites fail closed and visibly.** The panel lists migrations, state directory, Python environment, scheduler, queue, current authorisation, held-out set and declared commit. A missing prerequisite blocks the actions that depend on it, and any integrity, compatibility, consent or expiry failure makes the service fall back to the base model.

Do not set `APP_ENV=testing` in a real environment to bypass these limits. The [activation runbook](../learning-activation-runbook.md) details variables, panel checks, Jenkins deployment, switching off and rollback; the [supervised engine operations note](../learning-engine-production.md) covers held-out sets, the promotion gate and data protection. Both are in Spanish.

## Components and serving separation

Laravel keeps authentication, sessions, consent, capture, export, queued jobs, the panel and the scheduled tasks `learning:tick` and `learning:maintain-privacy`. It calls the Python modules in `app/ai-service/src` through a local subprocess with fixed arguments and a scrubbed environment; it exposes no administrative endpoints on the proposal service.

The proposal service keeps serving `public_runtime`. `public_model_app.py` adds `src` after `public_runtime`, so the learning hooks load when present and stay inactive without configuration. Without `src`, the base model is served. Inside `src`, the `services` package is an alias of `public_runtime/services`: the Laravel bridge, the tests and FastAPI use the same serving files.

Two readbacks show whether the running service matches the trainer environment:

- `GET /healthz` includes a `learning` block with `hooks_available`, `engine_enabled`, `serving_enabled`, `bandit_mode` and `runtime_sha256` (Python and dependency versions plus fingerprints of the learning and serving code).
- `GET /model-profiles` includes `serving_identity_sha256`, the identity of that process's base model and effective policy.

Neither contains paths or personal identifiers. If `runtime_sha256` differs from the value computed in the learning environment, or `serving_identity_sha256` differs from the baseline identity shown in the panel, promoted candidates are not served.

Learning is supported only on a native installation, with the proposal service running from the `.venv-learning` environment. The `Dockerfile.public` images include neither the learning environment nor a shared state volume, and keep learning off. The package includes no training corpus, business data, held-out sets, candidate models or private policies.

## API

The authenticated API includes `GET /api/admin/learning` and `POST /api/admin/learning/actions` for the panel, `PUT /profile/learning-consent` for consent, and the synthetic routes `GET/PUT /api/learning-case/draft` and `POST /api/learning-case/close` and `/withdraw`. All keep the existing session and CSRF boundaries. The panel and consent routes also have rate limits; the synthetic routes do not. The panel and synthetic routes require a verified email when `AUTH_REQUIRE_EMAIL_VERIFICATION=true`, and granting consent always requires one. Panel actions require a reason, are audited before they are queued and accept no paths, commands or files.

## Dependencies and tests

Build `app/ai-service/.venv-learning` with Python 3.13 from `requirements-learning.lock.txt` (`pip install --require-hashes`). The same environment runs the trainer and, when learning is deployed, the proposal service. `requirements.public.txt` remains the set for the service image without learning. Review the [dependency inventory](learning-dependencies.md) before preparing the environment.

Python learning tests use `PYTHONPATH=src`. Public-runtime tests in `tests/` use pytest, which is not part of the locks and is installed separately:

    cd app/ai-service
    PYTHONPATH=src python -B -m unittest discover -s src/tests
    python -B -m pytest -q -p no:cacheprovider tests

Portable tests supply synthetic inputs and require no private receipts. Platform- and database-specific tests require explicitly provisioned disposable resources; a skipped case is not a passed check.

The optional native harness runs from `app/web`:

    php tests/scripts/t11-native.php --allow-native-fixture --profile=<operator-owned-disposable-profile> --engines=mysql,pgsql --require-positive-discovery

Prepare a disposable profile compatible with the harness driver, version, loopback and database-identity guards. Never run these tests against a real database. Machine-bound private harnesses are not part of this distribution.
