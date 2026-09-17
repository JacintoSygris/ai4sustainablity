# Extraccion documental

Volver al [manual tecnico](index.md).

## Estado actual

La carga de documentos esta preparada por rutas y controlador, pero la extraccion documental no es funcional con el codigo incluido.

Evidencia:

- Laravel acepta PDF/DOCX hasta 50 MB cuando `P6_DOCUMENT_UPLOAD_ENABLED=true`.
- El job `ExtractCharacterizationDocumentJob` llama `POST {CHARACTERIZATION_API_BASE_URL}/extract-document`.
- `app/ai-service/public_model_app.py` solo ofrece `/healthz`, `/model-profiles` y `/predict`.

Por tanto, mantener:

```dotenv
P6_DOCUMENT_UPLOAD_ENABLED=false
```

Ninguna variable, Compose, clave externa, token ni URL arregla una ruta que no existe.

## Contrato minimo pendiente

Antes de activar la funcion, debe existir un servicio privado compatible con:

```http
POST /extract-document
Content-Type: application/json
```

Peticion minima:

```json
{
  "document_id": "123",
  "document_path": "/var/www/html/storage/app/private/characterization-documents/1/example.pdf",
  "original_filename": "example.pdf",
  "sha256": "example-sha256-placeholder"
}
```

Respuesta minima:

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

Estados admitidos por Laravel:

- `ok` -> documento `extracted`;
- `no_usable_evidence` -> documento `no_usable_evidence`;
- cualquier otro valor -> documento `failed`.

## Criterios de aceptacion antes de activar

- Endpoint `POST /extract-document` implementado y accesible solo desde Laravel.
- Lectura segura del `document_path` local o contrato alternativo implementado en Laravel.
- Timeouts y limites alineados con `P6_DOCUMENT_EXTRACT_TIMEOUT` y `P6_DOCUMENT_EXTRACT_JOB_TIMEOUT`.
- Validaciones de seguridad de archivos verificadas y controles de seguridad de carga.
- Logs sin contenido documental completo.
- Pruebas funcionales con PDF/DOCX benignos y documento sin evidencia util.
- Documentacion actualizada para retirar el bloqueo.

Hasta cumplir todo lo anterior, no describir la extraccion como caracteristica disponible.
