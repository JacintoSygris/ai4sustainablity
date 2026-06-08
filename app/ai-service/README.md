# IA4Sustainability AI Service

Python/FastAPI service for IA4Sustainability prediction and extraction
workflows.

This public repository ships service source code and dependency manifests. It
does not ship private trained model artifacts, report corpora, local extraction
outputs, or real provider credentials.

## Running the service endpoints

Use the AI-service virtual environment from this folder:

```powershell
cd app/ai-service
py -3.10 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
.\.venv\Scripts\python.exe -m pip check
cd src
..\.venv\Scripts\uvicorn.exe services.endpoints:app --host 127.0.0.1 --port 8001
```

The `/predict` runtime expects model artifacts under `data/`. If those files are
absent, use Laravel's mock characterization gateway for a full local app smoke,
or provide your own compatible artifacts locally.

To inspect runtime profile metadata while the service is running:

```powershell
Invoke-RestMethod http://127.0.0.1:8001/model-profiles
```

## Offline Extraction Helpers

The manual extraction scripts can validate AR16/YAML/Python key alignment before
calling an external LLM:

```powershell
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\ar16_reconcile.py --strict
.\.venv\Scripts\python.exe src\extract.py config --tasks extraction_models --path <pdf-or-folder> --save <output.csv> --require-ar16-aligned
```

Provider credentials must be supplied through environment variables or a local
uncommitted `keys.properties` file.

After a manual extraction run, an output CSV can be post-processed into approved
AR16/Python keys without calling an LLM:

```powershell
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\ar16_align_extraction.py --input <extracted.csv> --output <aligned-values.csv> --trace-output <alignment-trace.csv>
```

`src/requirements.txt` delegates to the canonical root `requirements.txt`; do
not maintain a second dependency list under `src/`.

Keep `keys.properties`, model artifacts, report corpora, generated extraction
CSVs, and training outputs local and uncommitted.
