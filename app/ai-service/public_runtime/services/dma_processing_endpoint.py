"""Authenticated bounded streaming before parsing; dedicated single inference slot."""
import asyncio
import os
import secrets
import threading
from fastapi import HTTPException, Request
from starlette.concurrency import run_in_threadpool
from .sector_comparison import strict_json
from .dma_processing_schema import DmaProcessingRequest, DmaProcessingResponse
from .dma_processing import process_isolated

SLOTS = threading.BoundedSemaphore(1)

def _process_with_slot(payload):
    # The blocking worker owns capacity until it has been reaped, even on disconnect.
    try:
        return process_isolated(payload)
    finally:
        SLOTS.release()

async def dma_process(request: Request):
    if os.getenv('I4S_DMA_NLP_ENABLED', 'false').lower() not in ('1', 'true'):
        raise HTTPException(404, detail='disabled')
    token = os.getenv('I4S_AI_API_TOKEN', '')
    if not token:
        raise HTTPException(503, detail='dma_unconfigured')
    if not secrets.compare_digest(request.headers.get('authorization', '').encode(), ('Bearer ' + token).encode()):
        raise HTTPException(401, detail='unauthorized', headers={'WWW-Authenticate': 'Bearer'})
    if not SLOTS.acquire(blocking=False):
        raise HTTPException(503, detail='dma_busy', headers={'Retry-After': '10'})
    transferred = False
    try:
        body = bytearray()
        try:
            async with asyncio.timeout(5):
                async for chunk in request.stream():
                    if len(body) + len(chunk) > 65536:
                        raise HTTPException(413, detail='body_too_large')
                    body.extend(chunk)
        except TimeoutError:
            raise HTTPException(408, detail='body_timeout') from None
        try:
            parsed = DmaProcessingRequest.model_validate(strict_json(body))
        except (ValueError, TypeError, UnicodeError):
            raise HTTPException(422, detail='invalid_request') from None
        try:
            task = asyncio.create_task(run_in_threadpool(_process_with_slot, parsed.model_dump()))
            transferred = True
            try:
                result = await asyncio.shield(task)
            except asyncio.CancelledError:
                try:
                    await asyncio.shield(task)
                except Exception:
                    pass
                raise
            return DmaProcessingResponse.model_validate(result)
        except Exception:
            raise HTTPException(503, detail='dma_unavailable', headers={'Retry-After': '10'}) from None
    finally:
        if not transferred:
            SLOTS.release()
