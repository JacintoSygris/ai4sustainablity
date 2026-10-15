# Inventario de dependencias del aprendizaje opcional

Requisitos directos fijados del entorno opcional, con sus versiones y su procedencia. Los textos de licencia y las obligaciones transitivas requieren revisión antes de la distribución o de una instalación operativa; no se afirma la aceptación de licencias.

| Distribución | Versión fijada | Revisión de licencia |
|---|---|---|
| `mlflow` | `3.16.1` | Pendiente de texto de licencia verificado |
| `pandera[pandas]` | `0.33.1` | Pendiente de texto de licencia verificado |
| `scikit-learn` | `1.7.0` | Pendiente de texto de licencia verificado |
| `numpy` | `2.2.6` | Pendiente de texto de licencia verificado |
| `pandas` | `2.3.3` | Pendiente de texto de licencia verificado |
| `optuna` | `5.0.0` | Pendiente de texto de licencia verificado |
| `jsonschema` | `4.26.0` | Pendiente de texto de licencia verificado |
| `pydantic` | `2.13.5` | Pendiente de texto de licencia verificado |

## Lock con hashes

`app/ai-service/requirements-learning.lock.txt` fija 137 distribuciones con sus hashes, resueltas para Python 3.13. Es la única fuente para construir `app/ai-service/.venv-learning`:

    python3.13 -m venv app/ai-service/.venv-learning
    app/ai-service/.venv-learning/bin/python -m pip install --require-hashes -r app/ai-service/requirements-learning.lock.txt

Ese entorno ejecuta el entrenador, el puente de Laravel y, cuando el aprendizaje está desplegado, también el servicio de propuestas (el lock incluye `fastapi` 0.142.2 y `uvicorn` 0.54.0). Un candidato supervisado solo se sirve si las versiones de Python, `numpy` (2.2.6), `scikit-learn` (1.7.0), `joblib` (1.6.0) y `scipy` (1.18.1) del servicio coinciden con las del entrenador. `requirements.public.txt` usa otras versiones (por ejemplo `numpy` 2.5.3 y `pandas` 3.0.5) y solo es apto para el servicio sin aprendizaje, como en la imagen `Dockerfile.public`.

El lock incluye además dependencias pesadas u opcionales de las bibliotecas fijadas, entre ellas `xgboost` con `nvidia-nccl-cu13` en Linux, `lightgbm`, `pymupdf`, `openai` y `google-genai`. Su instalación necesita ruedas para Python 3.13 en el sistema operativo de destino, acceso a PyPI o a una caché de ruedas, tiempo y espacio en disco. Su revisión de licencias sigue pendiente igual que la de los requisitos directos.
