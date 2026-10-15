"""Authenticated XBRL inference; separate capacity from predictions, no request logging."""
import asyncio
import os
from pathlib import Path
import secrets
import threading
from fastapi import HTTPException, Request
from pydantic import ValidationError
from starlette.concurrency import run_in_threadpool
from .datatypes import XbrlConceptRequest, XbrlConceptResponse
from .xbrl_concept_matcher import RuntimeMatcher

SLOTS = threading.BoundedSemaphore(1)
_model = None
_model_lock = threading.Lock()


def enabled():
    return os.getenv("XBRL_RUNTIME_IDENTIFICATION_ENABLED", "false").lower() in ("true", "1")


def model():
    global _model
    with _model_lock:
        if _model is None:
            directory = os.getenv("XBRL_RUNTIME_MODEL_DIR")
            if not directory or not Path(directory).is_absolute():
                raise ValueError("model_unconfigured")
            _model = RuntimeMatcher.load(Path(directory))
    return _model


async def concept_candidates(request: Request):
    if not enabled():
        raise HTTPException(404, detail="disabled")
    token = os.getenv("I4S_AI_API_TOKEN", "")
    if not token:
        raise HTTPException(503, detail="runtime_unconfigured")
    supplied = request.headers.get("authorization", "")
    if not secrets.compare_digest(supplied.encode(), ("Bearer " + token).encode()):
        raise HTTPException(401, detail="unauthorized")
    if not SLOTS.acquire(blocking=False):
        raise HTTPException(503, detail="runtime_busy", headers={"Retry-After": "10"})
    try:
        body = bytearray()
        try:
            async with asyncio.timeout(5):
                async for chunk in request.stream():
                    if len(body) + len(chunk) > 32768:
                        raise HTTPException(413, detail="body_too_large")
                    body.extend(chunk)
        except TimeoutError:
            raise HTTPException(408, detail="body_timeout") from None
        try:
            parsed = XbrlConceptRequest.model_validate_json(bytes(body))
        except ValidationError:
            raise HTTPException(422, detail="invalid_request") from None
        try:
            result = await run_in_threadpool(lambda: model().identify(parsed.model_dump(mode="json")))
            return XbrlConceptResponse.model_validate(result)
        except Exception:
            raise HTTPException(503, detail="runtime_unavailable") from None
    finally:
        SLOTS.release()
