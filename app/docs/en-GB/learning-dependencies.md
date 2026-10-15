# Optional learning dependency inventory

Pinned direct requirements of the optional environment, with their versions and provenance. Licence texts and transitive obligations require review before distribution or operational installation; licence acceptance is not claimed.

| Distribution | Source pin | Licence review |
|---|---|---|
| `mlflow` | `3.16.1` | Pending verified licence text |
| `pandera[pandas]` | `0.33.1` | Pending verified licence text |
| `scikit-learn` | `1.7.0` | Pending verified licence text |
| `numpy` | `2.2.6` | Pending verified licence text |
| `pandas` | `2.3.3` | Pending verified licence text |
| `optuna` | `5.0.0` | Pending verified licence text |
| `jsonschema` | `4.26.0` | Pending verified licence text |
| `pydantic` | `2.13.5` | Pending verified licence text |

## Hash-locked file

`app/ai-service/requirements-learning.lock.txt` pins 137 distributions with their hashes, resolved for Python 3.13. It is the only source for building `app/ai-service/.venv-learning`:

    python3.13 -m venv app/ai-service/.venv-learning
    app/ai-service/.venv-learning/bin/python -m pip install --require-hashes -r app/ai-service/requirements-learning.lock.txt

That environment runs the trainer, the Laravel bridge and, when learning is deployed, the proposal service too (the lock includes `fastapi` 0.142.2 and `uvicorn` 0.54.0). A supervised candidate is served only if the service's Python, `numpy` (2.2.6), `scikit-learn` (1.7.0), `joblib` (1.6.0) and `scipy` (1.18.1) versions match the trainer's. `requirements.public.txt` uses other versions (for example `numpy` 2.5.3 and `pandas` 3.0.5) and is suitable only for the service without learning, as in the `Dockerfile.public` image.

The lock also includes heavy or optional dependencies of the pinned libraries, among them `xgboost` with `nvidia-nccl-cu13` on Linux, `lightgbm`, `pymupdf`, `openai` and `google-genai`. Installing them needs Python 3.13 wheels for the target operating system, access to PyPI or a wheel cache, time and disk space. Their licence review is pending, like that of the direct requirements.
