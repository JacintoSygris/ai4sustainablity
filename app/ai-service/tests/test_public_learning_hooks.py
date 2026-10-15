"""Public runtime with and without the optional learning hooks (learning OFF).

Run from ``app/ai-service``: ``python -m pytest tests/test_public_learning_hooks.py -q``.
"""

import json
import os
import shlex
import shutil
import subprocess
import sys
from pathlib import Path

AI_SERVICE_ROOT = Path(__file__).resolve().parents[1]
PAYLOAD = {
    "company_name": "Synthetic public check", "model_profile": "new_format_732_v1_gpt41",
    "sector_list": ["C"], "subsidiaries_regions": ["EU"], "products_services": ["A"],
    "headquarters_country": "Spain", "num_subsidiaries_countries": 1, "employees_total": 150,
    "annual_turnover_million_euro": 5.0, "stock_listed": False, "reporting_currency": "EUR",
}
SCRIPT = r'''
import json, sys
if sys.argv[1] == "without-src":
    class Block:
        def find_spec(self, name, *args):
            if name in {"learning_production_runtime", "feedback_bandit", "learning_activation",
                        "learning_production_registry"}:
                raise ModuleNotFoundError("absent", name=name)
    sys.meta_path.insert(0, Block())
from fastapi.testclient import TestClient
import public_model_app
with TestClient(public_model_app.app) as client:
    health = client.get("/healthz").json()
    profiles = client.get("/model-profiles").json()
    response = client.post("/predict", json=json.loads(sys.argv[2]))
body = response.json()
print(json.dumps({"status": response.status_code, "learning": health["learning"], "profiles": profiles,
                  "profile": body["model_profile"], "metadata": body["mapping_metadata"],
                  "coverage": body["feature_metadata"]["derived_fields"]["encoding_coverage"]}))
'''


def run(mode):
    env = {"PATH": os.environ["PATH"], "PYTHONDONTWRITEBYTECODE": "1"}
    result = subprocess.run([sys.executable, "-c", SCRIPT, mode, json.dumps(PAYLOAD)], cwd=AI_SERVICE_ROOT,
                            env=env, capture_output=True, text=True, timeout=300, check=False)
    assert result.returncode == 0, result.stderr
    return json.loads(result.stdout.strip().splitlines()[-1])


def test_learning_off_serves_base_with_identity_and_no_feedback_policy():
    result = run("with-src")
    assert result["status"] == 200
    assert result["profile"] == "new_format_732_v1_gpt41"
    assert result["learning"]["hooks_available"] is True
    assert result["learning"]["bandit_mode"] == "off"
    identity = result["metadata"]["serving_identity"]
    assert identity["policy_sha256"] == {}
    assert identity["runtime_config"]["policy_active"] == {
        "label_thresholds": False, "crc_recall_floor": False, "sector_guard": False}
    assert "feedback_bandit" not in result["metadata"]
    assert result["coverage"]["version"] == "p5-gpt41-encoding-v1"
    assert len(result["learning"]["runtime_sha256"]) == 64
    assert result["profiles"]["serving_identity_sha256"] == identity["serving_identity_sha256"]
    assert result["profiles"]["learning"]["bandit_mode"] == "off"
    assert result["profiles"]["learning"]["registry_readable"] is None
    assert result["profiles"]["learning"]["effective_sha256"] is None


def test_runtime_without_learning_modules_still_serves_base():
    result = run("without-src")
    assert result["status"] == 200
    assert result["profile"] == "new_format_732_v1_gpt41"
    assert result["learning"] == {"hooks_available": False}
    assert result["profiles"]["learning"] == {"hooks_available": False}
    assert result["profiles"]["serving_identity_sha256"] == (
        result["metadata"]["serving_identity"]["serving_identity_sha256"])


def test_image_learning_sources_match_native_hashes(tmp_path):
    """Exercise the actual COPY inventory without a Docker daemon or real data."""
    schema = "learning-gate-metrics-v1.schema.json"
    assert (AI_SERVICE_ROOT / schema).read_bytes() == (
        AI_SERVICE_ROOT.parent / "contracts/api" / schema).read_bytes()
    ignores = (AI_SERVICE_ROOT / ".dockerignore").read_text().splitlines()
    for path in (schema, "src/learning_gate_metrics.py", "src/learning_serving_adapter.py", "src/esrs_standard.py"):
        assert "!" + path in ignores
    workdir = tmp_path / "app"
    workdir.mkdir()
    for line in (AI_SERVICE_ROOT / "Dockerfile.public").read_text().splitlines():
        if not line.startswith("COPY "):
            continue
        *sources, destination = shlex.split(line)[1:]
        # Source-only layout check; model loading is covered by the tests above.
        if sources == ["model-artifacts/gpt41"]:
            continue
        dest = tmp_path / destination.lstrip("/") if destination.startswith("/") else workdir / destination
        for source in sources:
            src = AI_SERVICE_ROOT / source
            if src.is_dir():
                shutil.copytree(src, dest, ignore=shutil.ignore_patterns("__pycache__"))
            else:
                target = dest / src.name if destination.endswith("/") else dest
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(src, target)
    script = """
import json
import learning_production_registry as registry
import public_model_app
import asyncio
from fastapi import HTTPException
from services import xbrl_runtime_endpoint
from unittest.mock import patch
assert any(route.path == '/xbrl/concept-candidates' and route.endpoint is xbrl_runtime_endpoint.concept_candidates
           for route in public_model_app.app.routes)
with patch.object(xbrl_runtime_endpoint, 'model', side_effect=AssertionError('loaded while disabled')):
    try:
        asyncio.run(xbrl_runtime_endpoint.concept_candidates(None))
    except HTTPException as error:
        xbrl_status = error.status_code
    else:
        raise AssertionError('disabled route did not reject the request')
print(json.dumps({'hashes': registry.code_hashes(), 'health': public_model_app.healthz()['learning'],
                  'xbrl_status': xbrl_status}))
"""
    outputs = []
    for root in (AI_SERVICE_ROOT, workdir):
        env = {"PATH": os.environ["PATH"], "PYTHONDONTWRITEBYTECODE": "1", "PYTHONPATH": str(root / "src")}
        result = subprocess.run([sys.executable, "-B", "-c", script], cwd=root, env=env,
                                capture_output=True, text=True, timeout=60, check=False)
        assert result.returncode == 0, result.stderr
        outputs.append(json.loads(result.stdout))
    assert outputs[0] == outputs[1]
    assert outputs[1]['xbrl_status'] == 404
    assert len(outputs[1]["health"]["runtime_sha256"]) == 64
