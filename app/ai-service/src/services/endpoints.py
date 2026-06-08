import threading

from contextlib import asynccontextmanager
from fastapi import FastAPI, HTTPException, status
from uuid import uuid4
from .datatypes import *
from .service_predict import predict_esrs, validate_model_artifacts

# ----------------------------------------------------------------
#  ENDPOINTS
# ----------------------------------------------------------------


@asynccontextmanager
async def lifespan(app: FastAPI):
    validate_model_artifacts()
    yield


app = FastAPI(lifespan=lifespan)


@app.post(
    "/predict",
    response_model=Prediction,
    summary="Predict esrs of a company based on the company features",
    description="This endpoint predicts the esrs of a company characterised by name, sectors,"
                " headquarters country, number of subsidiaries countries, number of employees,"
                " annual turnover million euro, stock_listed, and reporting currency. The prediction"
                " assigns to esrs either 1 (relevant) or 0 (irrelevant)."
)
async def predict(company: CompanyData):
    try:
        return predict_esrs(company)
    except Exception as e:
        handle_exception(e)


@app.post(
    "/retrain",
    response_model=JobStatus,
    summary="Add a new company to the esrs predictive model",
    description="This endpoint retrains the predictive model to incorporate a new company. It"
                " receives the features of the company (name, sectors, headquarters country,"
                " number of subsidiaries countries, number of employees, annual turnover million euro,"
                " stock_listed, reporting currency), its relevant esrs (value=1), and its irrelevant"
                " esrs (value=0). The endpoint returns an identifier of the retrain process, which"
                " can be used to know its status via /retrain-status."
)
async def retrain(company: CompanyDataAndEsrs):
    try:
        job_id = str(uuid4())
        job_store[job_id] = JobStatus(job_id=job_id, status=JobStatus.Status.started)
        thread = threading.Thread(target=long_job, args=(job_id, run_retrain_classifier, company), daemon=True)
        thread.start()
        return JobStatus(job_id=job_id, status=JobStatus.Status.started)
    except Exception as e:
        handle_exception(e)


@app.get(
    "/retrain-status/{job_id}",
         response_model=JobStatus,
         summary="Status of a retrain process",
         description="This endpoint returns the status of a retrain process triggered by a previous"
                     f" call to /retrain. Possible status are {[js.value for js in JobStatus.Status]}."
)
def retrain_status(job_id: str):
    job_status = job_store.get(job_id)
    if not job_status:
        return JobStatus(job_id=job_id, status=JobStatus.Status.not_found)
    return job_status


# ----------------------------------------------------------------
#  UTILITY METHODS
# ----------------------------------------------------------------

job_store: dict[str, JobStatus] = {}


def run_retrain_classifier(company: CompanyDataAndEsrs):
    from .service_retrain import retrain_classifier

    retrain_classifier(company)


def long_job(job_id: str, method, *params):
    job_store.setdefault(job_id, JobStatus(job_id=job_id, status=JobStatus.Status.started))
    job_store[job_id].status = JobStatus.Status.running
    job_store[job_id].error = None
    try:
        method(*params)
        job_store[job_id].status = JobStatus.Status.finished
    except Exception as e:
        job_store[job_id].status = JobStatus.Status.failed
        job_store[job_id].error = message_exception(e)


def handle_exception(e: Exception):
    raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=message_exception(e))


def message_exception(e: Exception) -> str:
    if isinstance(e, FileNotFoundError):
        filename = getattr(e, "filename", None) or str(e)
        return f"The file {filename} was not found."
    elif isinstance(e, EOFError):
        return "The model file is corrupted or incomplete."
    else:
        return f"Service error: {e}"
