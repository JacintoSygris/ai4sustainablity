"""Public-layout alias: ``services`` resolves to ``public_runtime/services``.

Learning entry points run with ``PYTHONPATH=src`` (Laravel LearningPythonBridge,
unit tests). The public serving code lives in ``public_runtime/services``, which
``Dockerfile.public`` copies to ``src/services`` inside the image. This alias
gives a bare checkout the same import layout, so the learning CLIs and the
FastAPI process load the same serving files. Do not add modules here.
"""
from pathlib import Path

_SERVING = Path(__file__).resolve().parents[2] / "public_runtime" / "services"
if _SERVING.is_dir():
    __path__ = [str(_SERVING)]
