# IASR-code
Python code for the Airis AI assistant.
The project includes:
- Scripts for data extraction from sustainability reports
- Data processing utilities, including data cleaning, merging, filtering and transformation
- Scripts for training different classifiers based on random forests, and hyperparameter optimisation
- Service endpoints (with fastapi) for prediction and re-training
- Last versions of data-sets and serialized classifiers

# Running the service endpoints
Use the AI-service virtual environment from this folder. Do not rely on the
global Python interpreter: the persisted classifier in `data/esrs_classifier.pkl`
requires the runtime dependencies from `requirements.txt`, including
`scikit-learn==1.7.0` and `lightgbm>=4.0`.

```
cd app/ai-service
py -3.10 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
.\.venv\Scripts\python.exe -m pip check
```

To start the services, execute:
```
cd src
..\.venv\Scripts\uvicorn.exe services.endpoints:app --host 127.0.0.1 --port 8001
```
Make sure you are in directory `src`. In PowerShell, you can also activate the
virtual environment first:

```
.\.venv\Scripts\Activate.ps1
Set-Location src
uvicorn services.endpoints:app --host 127.0.0.1 --port 8001
```

By default this uses the legacy classifier currently stored at `data/`. Runtime
profiles can select classifiers stored under `trained_classifier/new_format/`.
To test the endpoints, you can use the simple client_endpoints.py script.

The active runtime model profile defaults to `legacy_v0` unless the request
payload or `I4S_AI_MODEL_PROFILE` selects another runtime-enabled profile.
`new_format_732_v1_gpt41` is runtime-enabled and uses a high-confidence
score filter before emitting binary ESRS keys:

```
I4S_AI_NEW_FORMAT_SCORE_THRESHOLD=0.95
```

The filter does not cap the number of emitted candidate keys. If a profile
returns many high-confidence candidates, that is analysis evidence for P6/P8
rather than something the AI service silently truncates. `new_format_732_v1_gemini`
remains inventoried but not runtime-enabled; `/predict` returns 422 for it.
`new_format_732_v1_gpt41_materiality_gold_v4` is also runtime-enabled, but only
as an experimental controlled-test profile. It uses the 732 GPT-4.1 baseline plus
the conservative materiality-gold-v4 overlay and is not the default profile.

As of 2026-06-09, `new_format_732_v1_gpt41` has been retrained offline from
`training_data/new_format/gpt41/companies_gpt41_clean.csv` and
`training_data/new_format/gpt41/esrs_gpt41.csv` with the new-format feature
pipeline (`RF`, `chain`, `iterative`, `optimise=none`). The previous runtime
artifacts are archived under
`trained_classifier/new_format/gpt41/archive/2026-06-09-before-fallback-reference-retrain/`.
The FY2025 PDF refresh folders and the evidence/review queue are source
material only until their labels are reviewed and approved into a training CSV.

The materiality-gold-v4 overlay artifacts are stored in
`trained_classifier/new_format/gpt41_materiality_gold_v4/`. A full
heterogeneous shadow comparison against `new_format_732_v1_gpt41` on 2026-06-09
kept the average candidate count similar (22.50 vs 22.81) and produced no
review-required mapping keys, but individual morphology deltas were large
(-11 to +9 topics). Therefore the overlay profile remains selectable for
controlled validation and must not replace the stable GPT-4.1 732 profile by
default.

The corrected reviewed-materiality v5 route is stored under
`training_data/materiality_approved/reviewed-materiality-v5-20260609/`. It does
not repeat the v4 overlay mistake of treating every missing child topic as a
negative. The v5 builder separates:

- `child-labels.jsonl`: exact or unique child-level labels that can eventually
  feed the flat 102-key classifier.
- `parent-materiality-labels.jsonl`: report-level theme/subtheme materiality
  such as `Working conditions`, `Pollution`, or `Business conduct`.
- `review-queue.jsonl`: parent/ambiguous rows that need a reviewer decision
  before they may become child labels.
- `training-readiness.json`: fail-closed readiness result for flat training.

On the 2024/FY2025 evidence set, v5 found 1,258 child labels across 576 reports
and 4,812 parent/ambiguous review rows across 796 reports. The training
readiness gate correctly blocks flat retraining: 565 of the child-label reports
also have unresolved parent/ambiguous materiality, so exporting now would turn
unknown child topics into false negatives. Resolve/promote the review queue
first, then rerun the readiness gate and retrain only when it passes.

To build the evidence/review queue from the connected 2024 and FY2025 report
sources:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\evidence_extraction.py build-targets --include-2024 --include-2025 --output training_data\evidence\targets-2024-2025.json
.\.venv\Scripts\python.exe src\evidence_extraction.py extract --targets training_data\evidence\targets-2024-2025.json --output-jsonl training_data\evidence\report-evidence-2024-2025.jsonl --review-csv training_data\evidence\review-queue-2024-2025.csv --max-pages 0 --max-hits-per-key 2
```

The review CSV and JSONL use `report_url` as the website-facing report
reference. It is a public `http(s)` URL when one has been validated; otherwise
it is a non-clickable `company:<company name>` or `file:<pdf filename>`
reference. `local_pdf_path` is internal provenance only. The extractor writes
`report-evidence-2024-2025.progress.json` while running and
`report-evidence-2024-2025.failures.json` at the end.

The evidence/review queue does not mutate training labels automatically. A
model retrain still consumes the approved training CSVs until reviewed evidence
is promoted into those CSVs.

To regenerate the corrected v5 reviewed-materiality split:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\materiality_reviewed_dataset.py --zones training_data\evidence\materiality-zones-2024-2025.jsonl --evidence training_data\evidence\report-evidence-2024-2025.jsonl --mapping ..\web\data\ar16_to_python_esrs_mapping_new_format_732_v1.json --output-dir training_data\materiality_approved\reviewed-materiality-v5-20260609 --reviewer-id deterministic-v5 --reviewed-at 2026-06-09T18:00:00+00:00 --run-id reviewed-materiality-v5-20260609
.\.venv\Scripts\python.exe src\materiality_training_readiness.py --child-labels training_data\materiality_approved\reviewed-materiality-v5-20260609\child-labels.jsonl --review-queue training_data\materiality_approved\reviewed-materiality-v5-20260609\review-queue.jsonl --output training_data\materiality_approved\reviewed-materiality-v5-20260609\training-readiness.json
```

To prepare reviewer assistance without mutating approved labels, generate
bounded child-topic suggestions from the review queue:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\materiality_resolution_suggestions.py --review-queue training_data\materiality_approved\reviewed-materiality-v5-20260609\review-queue.jsonl --mapping ..\web\data\ar16_to_python_esrs_mapping_new_format_732_v1.json --output training_data\materiality_approved\reviewed-materiality-v5-20260609\resolution-suggestions.jsonl --summary training_data\materiality_approved\reviewed-materiality-v5-20260609\resolution-suggestions-summary.json
```

This helper applies an Atomizer-style scope lock: suggested keys must already be
candidate keys for that review row, and only exact child-label evidence can
produce a `unique_child_match` template. It is not an approval path. On the v5
review queue it produced 425 unique child matches, 1,098 multiple-child matches
that still need review, and 3,289 parent-only/needs-review rows.

When reviewer or bounded-LLM decisions are available, import them with:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\materiality_review_resolution.py --review-queue training_data\materiality_approved\reviewed-materiality-v5-20260609\review-queue.jsonl --decisions <review-decisions.jsonl> --mapping ..\web\data\ar16_to_python_esrs_mapping_new_format_732_v1.json --output-labels <resolved-child-labels.jsonl> --outcomes <review-outcomes.jsonl> --blocked <blocked-review-decisions.jsonl>
```

For the 2026-06-09 machine-reviewed v5 pass, the end-to-end flow is:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\materiality_review_pipeline.py decisions --suggestions training_data\materiality_approved\reviewed-materiality-v5-20260609\resolution-suggestions.jsonl --output training_data\materiality_approved\reviewed-materiality-v5-20260609\machine-review-decisions.jsonl --summary training_data\materiality_approved\reviewed-materiality-v5-20260609\machine-review-decisions-summary.json --reviewer-id machine-scope-lock-v1 --reviewed-at 2026-06-09T20:30:00+00:00 --approve-multiple-exact-matches --resolve-parent-only
.\.venv\Scripts\python.exe src\materiality_review_resolution.py --review-queue training_data\materiality_approved\reviewed-materiality-v5-20260609\review-queue.jsonl --decisions training_data\materiality_approved\reviewed-materiality-v5-20260609\machine-review-decisions.jsonl --mapping ..\web\data\ar16_to_python_esrs_mapping_new_format_732_v1.json --output-labels training_data\materiality_approved\reviewed-materiality-v5-20260609\resolved-child-labels.jsonl --outcomes training_data\materiality_approved\reviewed-materiality-v5-20260609\machine-review-outcomes.jsonl --blocked training_data\materiality_approved\reviewed-materiality-v5-20260609\blocked-machine-review-decisions.jsonl
.\.venv\Scripts\python.exe src\materiality_review_pipeline.py assemble --base-child-labels training_data\materiality_approved\reviewed-materiality-v5-20260609\child-labels.jsonl --resolved-child-labels training_data\materiality_approved\reviewed-materiality-v5-20260609\resolved-child-labels.jsonl --review-queue training_data\materiality_approved\reviewed-materiality-v5-20260609\review-queue.jsonl --review-outcomes training_data\materiality_approved\reviewed-materiality-v5-20260609\machine-review-outcomes.jsonl --output-child-labels training_data\materiality_approved\reviewed-materiality-v5-20260609\training-child-labels-machine-reviewed.jsonl --residual-review-queue training_data\materiality_approved\reviewed-materiality-v5-20260609\residual-review-queue-machine-reviewed.jsonl --summary training_data\materiality_approved\reviewed-materiality-v5-20260609\training-inputs-machine-reviewed-summary.json
.\.venv\Scripts\python.exe src\materiality_training_readiness.py --child-labels training_data\materiality_approved\reviewed-materiality-v5-20260609\training-child-labels-machine-reviewed.jsonl --review-queue training_data\materiality_approved\reviewed-materiality-v5-20260609\residual-review-queue-machine-reviewed.jsonl --output training_data\materiality_approved\reviewed-materiality-v5-20260609\training-readiness-machine-reviewed.json
```

That pass generated 4,812 decisions, 2,970 resolved child-label rows, and a
merged training label set of 4,003 labels across 735 report rows, with 0
residual review rows and readiness `ready=true`.

The first CSV export blocked 46 FY2025 reports because they had no company
profile row in the GPT-4.1 732 profile CSV. To keep those reports in the
training set without inventing profile data, generate an augmented company CSV:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\materiality_company_profiles.py --source-companies training_data\new_format\gpt41\companies_gpt41_clean.csv --missing-reports training_data\materiality_approved\reviewed-materiality-v5-20260609\training\training-csv-blocked-machine-reviewed.jsonl --output-companies training_data\materiality_approved\reviewed-materiality-v5-20260609\training\companies_gpt41_plus_missing_profiles.csv --summary training_data\materiality_approved\reviewed-materiality-v5-20260609\training\companies_gpt41_plus_missing_profiles_summary.json
.\.venv\Scripts\python.exe src\materiality_label_promotion.py csv --labels training_data\materiality_approved\reviewed-materiality-v5-20260609\training-child-labels-machine-reviewed.jsonl --targets training_data\evidence\targets-2024-2025.json --source-companies training_data\materiality_approved\reviewed-materiality-v5-20260609\training\companies_gpt41_plus_missing_profiles.csv --source-esrs training_data\new_format\gpt41\esrs_gpt41.csv --output-companies training_data\materiality_approved\reviewed-materiality-v5-20260609\training\companies_machine_reviewed_all_profiles.csv --output-esrs training_data\materiality_approved\reviewed-materiality-v5-20260609\training\esrs_machine_reviewed_all_profiles.csv --blocked training_data\materiality_approved\reviewed-materiality-v5-20260609\training\training-csv-blocked-machine-reviewed-all-profiles.jsonl
```

The augmented profile CSV adds 46 placeholder rows marked
`company_data_profile_quality=placeholder_missing_profile`. The final training
CSV export has 735 rows and 0 blocked rows.

The machine-reviewed retrain artifact is stored under
`training_data/materiality_approved/reviewed-materiality-v5-20260609/retrain_machine_reviewed_all_profiles/`:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\train_model_yaml.py --company_data training_data\materiality_approved\reviewed-materiality-v5-20260609\training\companies_machine_reviewed_all_profiles.csv --esrs_data training_data\materiality_approved\reviewed-materiality-v5-20260609\training\esrs_machine_reviewed_all_profiles.csv --output_path training_data\materiality_approved\reviewed-materiality-v5-20260609\retrain_machine_reviewed_all_profiles --base_model RF --wrapper chain --split group --optimise none --verbose
```

This retrain is intentionally not registered as a runtime profile. Its grouped
test micro-F1 is 0.16, so it is a pipeline/readiness artifact, not a production
candidate.

The 2026-06-09 official-multilingual pass downloads the official AR16 topic
translations for Commission Delegated Regulation (EU) 2023/2772 from the
Publications Office Cellar FMX4 files:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\ar16_official_translations.py --download
```

Outputs:

- `..\contracts\mappings\ar16-official-topic-translations.csv`
- `..\contracts\mappings\ar16-official-topic-translations.manifest.json`
- `training_data\official_sources\eurlex_32023R2772_ar16\fmx4\32023R2772-<lang>.fmx4`

The export contains 2,136 rows: 24 official EU languages x 89 AR16 topic rows.
The matcher uses the official strings plus normalization variants for layout
hyphens and one known Romanian official-source layout artifact; the official CSV
itself remains raw source text.

Using those translations, the evidence pass was regenerated as
`training_data\evidence\report-evidence-2024-2025-official-multilingual.jsonl`
with 926/926 targets processed, 165,081 evidence rows, and 0 failures. The
reviewed materiality release is stored under
`training_data\materiality_approved\reviewed-materiality-v5-official-multilingual-20260609\`.
Readiness passed with 820 trainable reports, 5,151 child labels, 80 child keys,
and 0 residual review rows. Final CSV export produced 820 training rows with 0
blocked rows after adding 48 explicit missing-profile placeholders.

The retrain artifact is stored at:

```
training_data\materiality_approved\reviewed-materiality-v5-official-multilingual-20260609\retrain_machine_reviewed_all_profiles\
```

Grouped validation remains weak for runtime promotion: test micro-F1 was 0.20.
Treat this as a pipeline/training artifact until model-quality work improves
recall and per-label coverage.

The 2026-06-09 auto-gap v6 pass keeps the same official multilingual evidence
file but regenerates materiality zones with tighter continuation handling and
PDF-layout hyphen normalization:

```
training_data\evidence\materiality-zones-2024-2025-auto-gap-v6.jsonl
training_data\materiality_approved\reviewed-materiality-v6-auto-gap-20260609\
```

Verified output: 926/926 reports processed, 32,889 zones, 0 zone failures, 839
trainable reports, 6,185 child labels, 82 child keys, 0 residual review rows,
839 final CSV rows, and 0 final CSV blockers. Remaining non-trainable reports:
34 `parent_only` reports deferred for manual review, 52 reports with topic hits
but no accepted materiality/DMA zone, and 1 report without usable topic hits.

The retrain artifact is stored at:

```
training_data\materiality_approved\reviewed-materiality-v6-auto-gap-20260609\retrain_machine_reviewed_all_profiles\
```

Grouped validation remains weak for runtime promotion: training accuracy was
0.7896 and test micro-F1 was 0.19. Treat this as the current training artifact,
not as a deployed/default runtime profile.

To regenerate the 102-key AR16 mapping inventory from the real new-format
artifacts and local equivalence evidence:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\export_new_format_mapping_inventory.py
```

To inspect runtime profile metadata while the service is running:

```
Invoke-RestMethod http://127.0.0.1:8001/model-profiles
```

To run the local shadow comparison between legacy and GPT-4.1 732:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\shadow_model_profiles.py
```

The default script compares `legacy_v0` with `new_format_732_v1_gpt41` on three
SME cases: 10, 150, and 400 employees. For broader characterization smoke, run:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\shadow_model_profiles.py --case-set heterogeneous --profiles new_format_732_v1_gpt41
```

To compare the stable GPT-4.1 732 profile with the materiality-gold-v4 overlay:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\shadow_model_profiles.py --case-set heterogeneous --profiles new_format_732_v1_gpt41,new_format_732_v1_gpt41_materiality_gold_v4
```

The heterogeneous matrix covers micro, small, and medium companies up to 499
employees across multiple sectors, regions, legal forms, subsidiary footprints,
and listing status. It reports all high-confidence candidates without a fixed
count cap, plus aggregate-only and review-required projection counts.

`src/requirements.txt` delegates to the canonical root `requirements.txt`; do not
maintain a second dependency list under `src/`.

`/retrain-status/{job_id}` uses in-memory status for the current private
single-worker service mode. Multi-worker or persistent job status belongs to a
deployment/ops revision.

The legacy private `/retrain` trainer was archived on 2026-06-09. The original
script is retained at `archive/2026-06-09-train-model-legacy.py`, while
`src/train_model.py` is now a guard that raises a clear error. Use
`src/train_model_yaml.py` for the approved offline new-format 732 training
pipeline.

# Offline extraction AR16 guard
The manual extraction script can validate AR16/YAML/Python key alignment before
calling an external LLM:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\ar16_reconcile.py --strict
.\.venv\Scripts\python.exe src\extract.py config --tasks extraction_models --path <pdf-or-folder> --save <output.csv> --require-ar16-aligned
```

`--require-ar16-aligned` is opt-in. Without it, the historical extraction flow
is unchanged.

# Manual external-AI helpers
The FastAPI runtime (`/predict`, `/retrain`, `/retrain-status`) uses local model
artifacts only. External AI use is limited to manual/offline scripts.

- `src/extract.py` can call OpenAI or Gemini for sustainability-report
  extraction. Use `--require-ar16-aligned` when you want the AR16/YAML/Python
  key preflight before those external calls. It reads provider keys from
  environment variables first, then `keys.properties` in flat or `[keys]` INI
  format.
- `src/clean_data_set.py` can call OpenAI to normalize revenue/currency text in
  extracted CSVs. It is a manual helper, not part of the private runtime. It
  reads `OPENAI_API_KEY` from the environment first, then `keys.properties`
  from the current working directory. Both flat `KEY=VALUE` and `[keys]` INI
  formats are accepted, for example:

```
OPENAI_API_KEY=<your-key>
```

or:

```
[keys]
OPENAI_API_KEY=<your-key>
```

Keep `keys.properties` local and uncommitted.

# AR16-align an extraction CSV
After a manual extraction run, the output CSV can be post-processed into
approved AR16/Python keys without calling an LLM or changing training data:

```
$env:PYTHONPATH='src'
.\.venv\Scripts\python.exe src\ar16_align_extraction.py --input <extracted.csv> --output <aligned-values.csv> --trace-output <alignment-trace.csv>
```

`aligned-values.csv` contains only `file` plus approved AR16/Python topic keys.
`alignment-trace.csv` keeps the original source key, target key, value,
context/evidence, status, and `review_required` flag for developer review.
