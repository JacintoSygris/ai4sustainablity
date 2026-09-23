"""Regression coverage for bare-checkout imports of the public model app."""

import os
import subprocess
import sys
from pathlib import Path


AI_SERVICE_ROOT = Path(__file__).resolve().parents[1]


def test_public_model_app_imports_without_pythonpath():
    env = os.environ.copy()
    env.pop("PYTHONPATH", None)
    env["PYTHONDONTWRITEBYTECODE"] = "1"

    result = subprocess.run(
        [sys.executable, "-c", "import public_model_app"],
        cwd=AI_SERVICE_ROOT,
        env=env,
        capture_output=True,
        text=True,
        check=False,
    )

    assert result.returncode == 0, result.stderr
