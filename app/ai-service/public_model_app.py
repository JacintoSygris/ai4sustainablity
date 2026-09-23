import sys
from pathlib import Path

# The EC2 service runs this module directly from the ai-service checkout rather
# than through Docker, so expose the bundled public runtime before importing it.
sys.path.append(str(Path(__file__).resolve().parent / "public_runtime"))

from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, status

from services.datatypes import CompanyData, Prediction
from services.service_predict import (
    new_format_score_threshold,
    predict_esrs,
    resolve_model_profile,
    validate_model_artifacts,
)

PUBLIC_MODEL_PROFILE = "new_format_732_v1_gpt41"


@asynccontextmanager
async def lifespan(_app: FastAPI):
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


@app.get("/healthz")
def healthz():
    profile = resolve_model_profile(PUBLIC_MODEL_PROFILE)

    return {
        "status": "ok",
        "mode": "public-model-runtime",
        "model_profile": profile.name,
        "model_key_count": profile.expected_key_count,
    }


@app.get("/model-profiles")
def model_profiles():
    profile = resolve_model_profile(PUBLIC_MODEL_PROFILE)

    return {
        "active_model_profile": profile.name,
        "runtime_enabled_profiles": [profile.name],
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
