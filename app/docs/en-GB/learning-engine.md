# Optional learning engine

## Scope

The module records reviewed materiality decisions and can prepare cases, train and evaluate model candidates, reload them and exercise explicit selection or rollback in a synthetic test environment. Candidates remain separate from the model serving application requests.

An unanswered topic is not a negative training label. Disclosure relevance and answer-selection decisions do not remove reporting obligations. The workflow preserves source revisions and case authorization; source changes, withdrawal and account deletion invalidate eligibility.

## Operational boundary

Learning is disabled by default. The included composition admits only synthetic test mode: it has no positive operational authorization issuer or complete business-data learning workflow. No environment variable turns that mechanism into a production feature. Do not set `APP_ENV=testing` on TEST or production to bypass this boundary.

Publishing, installing or updating this version does not activate learning or replace the serving model. Business use requires additional operational composition with verified identity and data rights, durable storage, applicable policies, authorized exports and candidate registration outside test profiles. Training and model promotion are separate operations.

## API and serving separation

The authenticated API includes `GET/PUT /api/learning-case/draft` and `POST /api/learning-case/close` and `/withdraw`. It preserves the existing session and CSRF boundaries. Exposed routes do not imply that operational closure is enabled.

Optional Python learning modules are independent of `public_runtime`, `public_model_app.py`, serving requirements and the four distributed model files. Do not replace the existing models with synthetic candidates. The package includes no training corpus, business data, trained candidate artifacts or private policies.

## Dependencies and tests

Install `app/ai-service/requirements-learning.txt` only in a separate optional environment. Keep `requirements.public.txt` for the existing service. Review the [dependency inventory](learning-dependencies.md) and accompanying lockfile before preparing a test environment.

Python learning tests use `PYTHONPATH=src`. Portable tests supply synthetic inputs and require no private receipts. Platform- and database-specific tests require explicitly provisioned disposable resources; a skipped case is not a passed check.

The optional native harness runs from `app/web`:

    php tests/scripts/t11-native.php --allow-native-fixture --profile=<operator-owned-disposable-profile> --engines=mysql,pgsql --require-positive-discovery

Prepare a disposable profile compatible with the harness driver, version, loopback and database-identity guards. Never run these tests against a real database. Machine-bound private harnesses are not part of this distribution.
