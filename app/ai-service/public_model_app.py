import os
import secrets
from typing import Annotated
import sys
from pathlib import Path

# The EC2 service runs this module directly from the ai-service checkout rather
# than through Docker, so expose the bundled public runtime before importing it.
# src/ follows it: it provides the optional learning hooks (supervised learning
# overlay and feedback policy), which stay inactive unless learning is configured.
_SERVICE_ROOT = Path(__file__).resolve().parent
for _path in (_SERVICE_ROOT / "public_runtime", _SERVICE_ROOT / "src"):
    if _path.is_dir() and str(_path) not in sys.path:
        sys.path.append(str(_path))

from contextlib import asynccontextmanager

from fastapi import FastAPI, Header, HTTPException, Request, status
from starlette.concurrency import run_in_threadpool

from services.sector_comparison_runtime import SectorRuntime
from services.sector_comparison import strict_json
from services.datatypes import SectorComparisonRequest, SectorComparisonResponse

from services.datatypes import CompanyData, Prediction, XbrlConceptResponse
from services.xbrl_runtime_endpoint import concept_candidates
from services.dma_processing_endpoint import dma_process
from services.dma_processing_schema import DmaProcessingResponse
from services.service_predict import (
    model_serving_identity,
    new_format_score_threshold,
    predict_esrs,
    resolve_model_profile,
    validate_model_artifacts,
)

PUBLIC_MODEL_PROFILE = "new_format_732_v1_gpt41"
sector_runtime = None


@asynccontextmanager
async def lifespan(_app: FastAPI):
    global sector_runtime
    sector_runtime = SectorRuntime()
    validate_model_artifacts(model_profile=PUBLIC_MODEL_PROFILE)
    yield


app = FastAPI(
    title="IA4Sustainability public prediction service",
    description=(
        "Public runtime for the trained new_format_732_v1_gpt41 classifier. "
        "Prediction output is candidate support for materiality review, not a final decision."
    ),
    lifespan=lifespan,
)

# The handler checks its independent flag before loading any runtime artifacts.
app.add_api_route("/xbrl/concept-candidates", concept_candidates, methods=["POST"],
                  response_model=XbrlConceptResponse)


# Independent default-off, authenticated text analysis; no model I/O at startup.
app.add_api_route("/dma/process", dma_process, methods=["POST"],
                  response_model=DmaProcessingResponse)

def learning_flags() -> dict:
    """Read-only learning configuration of THIS process; no paths or identifiers."""
    try:
        import learning_activation
    except ModuleNotFoundError:
        return {"hooks_available": False}
    return {
        "hooks_available": True,
        "engine_enabled": learning_activation.flag("LEARNING_ENGINE_ENABLED"),
        "serving_enabled": learning_activation.flag("LEARNING_ENGINE_SERVING_ENABLED"),
        "bandit_mode": learning_activation.bandit_mode(),
    }


def learning_runtime_status() -> dict:
    """Learning flags plus the runtime fingerprint of THIS process.

    runtime_sha256 must equal the value computed by the Laravel learning
    interpreter (.venv-learning) for promoted supervised-learning candidates
    to be served.
    """
    status_value = learning_flags()
    if not status_value["hooks_available"]:
        return status_value
    try:
        import learning_production_registry as registry

        status_value["runtime_sha256"] = registry.digest(
            {"versions": registry.versions(), "code_sha256": registry.code_hashes()}
        )
    except Exception:
        status_value["runtime_sha256"] = None
    return status_value


def serving_identity_sha256(profile) -> str | None:
    """Base serving identity of THIS process (model bytes plus effective policy).

    It must equal the baseline identity that the Laravel learning bridge reports;
    a mismatch means environment or policy drift between the two processes.
    """
    try:
        return model_serving_identity(profile)["serving_identity_sha256"]
    except Exception:
        return None


def learning_serving_state(profile, identity: str | None) -> dict:
    """Supervised-learning candidate that THIS process would serve right now.

    It is resolved through the same fail-closed path as /predict, so
    effective_sha256 must equal the effective_sha256 that the Laravel learning
    bridge status reports. The base identity alone cannot show that this
    process reads a different registry (for example a missing
    LEARNING_ENGINE_ROOT while the feedback policy is off). registry_readable is
    null when serving is disabled, and false when the registry cannot be
    opened, which includes "no candidate trained yet".
    Only a model hash is returned: no paths, candidate or subject identifiers.
    """
    try:
        import learning_production_registry as registry_module
        import learning_production_runtime as runtime
    except ModuleNotFoundError:
        return {}
    state = {"registry_readable": None, "effective_sha256": None}
    if (not runtime.flag("LEARNING_ENGINE_ENABLED") or not runtime.flag("LEARNING_ENGINE_SERVING_ENABLED")
            or profile.name != registry_module.BASE_PROFILE):
        return state
    try:
        registry = registry_module.ProductionCandidateRegistry(os.environ["LEARNING_ENGINE_ROOT"])
        active = registry.pointer("active")
    except Exception:
        state["registry_readable"] = False
        return state
    state["registry_readable"] = True
    resolved = registry_module.effective_active(registry, active, identity) if identity else None
    if resolved is not None:
        state["effective_sha256"] = resolved[0]["model_sha256"]
    return state


@app.get("/healthz")
def healthz():
    profile = resolve_model_profile(PUBLIC_MODEL_PROFILE)

    return {
        "status": "ok",
        "mode": "public-model-runtime",
        "model_profile": profile.name,
        "model_key_count": profile.expected_key_count,
        "learning": learning_runtime_status(),
    }


@app.get("/model-profiles")
def model_profiles():
    profile = resolve_model_profile(PUBLIC_MODEL_PROFILE)
    identity = serving_identity_sha256(profile)
    learning = learning_flags()
    if learning["hooks_available"]:
        learning.update(learning_serving_state(profile, identity))

    return {
        "active_model_profile": profile.name,
        "runtime_enabled_profiles": [profile.name],
        "serving_identity_sha256": identity,
        "learning": learning,
        "profiles": {
            profile.name: {
                "name": profile.name,
                "expected_key_count": profile.expected_key_count,
                "runtime_enabled": profile.runtime_enabled,
                "required_artifacts": list(profile.required_artifacts),
                "description": profile.description,
                "new_format_score_threshold": new_format_score_threshold(),
            }
        },
    }


@app.post("/predict", response_model=Prediction)
def predict(company: CompanyData):
    if company.model_profile not in (None, PUBLIC_MODEL_PROFILE):
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            detail=f"Only the public model profile '{PUBLIC_MODEL_PROFILE}' is available.",
        )

    company.model_profile = PUBLIC_MODEL_PROFILE

    try:
        return predict_esrs(company)
    except ValueError as exception:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            detail=str(exception),
        ) from exception
    except Exception as exception:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Prediction failed without producing a result.",
        ) from exception


@app.post("/sector-comparison", response_model=SectorComparisonResponse)
async def sector_comparison(request: Request, authorization: Annotated[str | None, Header()] = None):
    runtime = sector_runtime
    if runtime is None or not runtime.enabled:
        raise HTTPException(503, detail={"code": "sector_comparison_disabled"}, headers={"Retry-After": "60"})
    expected = os.getenv("I4S_AI_API_TOKEN", "")
    if not expected:
        raise HTTPException(503, detail={"code": "sector_comparison_unavailable"}, headers={"Retry-After": "60"})
    if not authorization or not authorization.startswith("Bearer ") or not secrets.compare_digest(authorization[7:].encode("utf-8"), expected.encode("utf-8")):
        raise HTTPException(401, detail={"code": "sector_comparison_unauthorized"}, headers={"WWW-Authenticate": "Bearer"})
    if runtime.snapshot is None:
        raise HTTPException(503, detail={"code": "sector_comparison_unavailable"}, headers={"Retry-After": "60"})
    if not runtime.admit():
        raise HTTPException(429, detail={"code": "sector_comparison_rate_limit"}, headers={"Retry-After": "60"})
    raw = bytearray()
    async for chunk in request.stream():
        if len(raw) + len(chunk) > 16 * 1024:
            raise HTTPException(413, detail={"code": "sector_comparison_request_too_large"})
        raw.extend(chunk)
    try:
        payload = SectorComparisonRequest.model_validate(strict_json(raw))
    except (ValueError, TypeError, UnicodeError):
        raise HTTPException(422, detail={"code": "sector_comparison_invalid_request"})
    if not runtime.slots.acquire(blocking=False):
        raise HTTPException(503, detail={"code": "sector_comparison_busy"}, headers={"Retry-After": "1"})
    try:
        return await run_in_threadpool(runtime.compare, payload)
    finally:
        runtime.slots.release()
