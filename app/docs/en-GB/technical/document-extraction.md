# Document Extraction

Back to the [technical manual](index.md).

## Current State

Document upload is prepared by routes and controller, but document extraction is not functional with the included code.

Evidence:

- Laravel accepts PDF/DOCX up to 50 MB when `P6_DOCUMENT_UPLOAD_ENABLED=true`.
- `ExtractCharacterizationDocumentJob` calls `POST {CHARACTERIZATION_API_BASE_URL}/extract-document`.
- `app/ai-service/public_model_app.py` only provides `/healthz`, `/model-profiles` and `/predict`.

Therefore keep:

```dotenv
P6_DOCUMENT_UPLOAD_ENABLED=false
P6_DOCUMENT_SCAN_ENABLED=false
```

No variable, Compose file, external key, token or URL fixes a route that does not exist.

## Pending Minimum Contract

Before enabling the feature, a private compatible service must exist with:

```http
POST /extract-document
Content-Type: application/json
```

Minimum request:

```json
{
  "document_id": "123",
  "document_path": "/var/www/html/storage/app/private/characterization-documents/1/example.pdf",
  "original_filename": "example.pdf",
  "sha256": "example-sha256-placeholder"
}
```

Minimum response:

```json
{
  "status": "ok",
  "extractor_version": "example-version",
  "evidence": [
    {
      "topic_key": "example-topic",
      "standard": "ESRS E1",
      "kind": "candidate_evidence",
      "page": 1,
      "snippet": "short extracted snippet",
      "confidence": 0.75
    }
  ]
}
```

Statuses accepted by Laravel:

- `ok` -> document `extracted`;
- `no_usable_evidence` -> document `no_usable_evidence`;
- any other value -> document `failed`.

## Acceptance Criteria Before Enabling

- `POST /extract-document` implemented and reachable only from Laravel.
- Safe reading of the local `document_path` or an alternative contract implemented in Laravel.
- Timeouts and limits aligned with `P6_DOCUMENT_EXTRACT_TIMEOUT` and `P6_DOCUMENT_EXTRACT_JOB_TIMEOUT`.
- Operational ClamAV if `P6_DOCUMENT_SCAN_ENABLED=true`.
- Logs without full document content.
- Functional checks with benign PDF/DOCX, controlled infected test file and document with no usable evidence.
- Documentation updated to remove the block.

Until all of the above is met, do not describe extraction as an available feature.
