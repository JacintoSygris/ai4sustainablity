# AI, Mapping And Reporting

Back to the [technical manual](index.md).

## Included FastAPI Service

The included AI service starts with Uvicorn and exposes only:

- `GET /healthz`;
- `GET /model-profiles`;
- `POST /predict`.

It does not expose `POST /extract-document`.

Prerequisite: `ai-service` on the private network.

```sh
# Directory: /srv/ia4sustainability
docker compose exec ai-service python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8001/healthz', timeout=5).read().decode())"
docker compose exec ai-service python -c "import urllib.request; print(urllib.request.urlopen('http://localhost:8001/model-profiles', timeout=5).read().decode())"
```

Expected result: `status` is `ok` and the active profile is `new_format_732_v1_gpt41`.

Check from Laravel: configure `CHARACTERIZATION_GATEWAY=api` and `CHARACTERIZATION_API_BASE_URL=http://ai-service:8001`; a submitted characterisation should produce a technical proposal or controlled error.

## ESRS Mapping

The matter to Disclosure Requirement mapping is enabled through an immutable path:

```dotenv
ESRS_MATTER_DR_MAPPING_PATH=/var/www/html/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json
```

Prerequisite: file present inside the Laravel container and with `source.status=approved`.

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan esrs:validate-matter-dr-mapping /var/www/html/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json
```

Expected result: the command completes successfully and lists matter and requirement coverage.

Check: if the path is missing, JSON is invalid or status is not `approved`, Laravel treats the mapping as unavailable.

## ESRS Information, Exports And Technical Candidate

ESRS information outputs and the package/technical candidate require real state and data prerequisites. An available download or ready state is not regulatory filing, assurance, content validation or third-party acceptance.

Typical technical prerequisites:

- authenticated user and, if required, verified email;
- characterisation created;
- materiality decision recorded;
- sufficient datapoint/fact responses;
- validated ESRS/DR mapping if the output needs it;
- reporting assets present and intact;
- executable Arelle for candidate iXBRL validation.

Validate assets:

```sh
# Directory: /srv/ia4sustainability
docker compose exec web php artisan report:validate-assets
```

Expected result: `report:validate-assets` reports `OK`.

## Arelle And External Taxonomy

The XHTML/iXBRL candidate does not use a taxonomy included in the image or repository. The installation provides the authorised EFRAG ZIP separately, a private manifest with its SHA-256, and an absolute Arelle path. Laravel runs Arelle without network access; if the manifest, package, checksum or executable is missing, it blocks the candidate rather than declaring a valid download.

Follow the [external EFRAG taxonomy installation](external-efrag-taxonomy.md). `php artisan report:validate-assets` checks versioned assets and that taxonomy bytes have not been reintroduced into the repository; external ZIP validation occurs when the authenticated candidate is requested.

## Strict Interpretation Of Candidate XHTML/iXBRL

The XHTML/iXBRL candidate is a technical output for review. `arelle_validation.status=passed` only means the configured technical validation did not fail. It does not prove the report is complete, correct, audited, filed or accepted. Any external use requires professional review and the operator's procedure.
