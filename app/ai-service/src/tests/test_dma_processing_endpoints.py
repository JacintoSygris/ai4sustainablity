import asyncio
import multiprocessing as mp
import os
import time
import threading
import tempfile
import importlib.util
import sys
from pathlib import Path
import unittest
from unittest.mock import patch
from fastapi.testclient import TestClient
import httpx
from services.dma_processing_endpoint import SLOTS
from services.dma_processing import process_isolated, ROOT


def sleeping_worker(connection, request):
    time.sleep(30)

class DmaEndpointTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location('dma_builder', ROOT / 'ai-service/scripts/dma_processing/build_model.py')
        builder = importlib.util.module_from_spec(spec); spec.loader.exec_module(builder)
        temporary = tempfile.TemporaryDirectory(prefix='dma-endpoint-test-')
        cls.addClassCleanup(temporary.cleanup)
        cls.package = Path(temporary.name)
        builder.build(cls.package)

    def setUp(self):
        self.env=patch.dict(os.environ,{'I4S_DMA_NLP_ENABLED':'true','I4S_AI_API_TOKEN':'synthetic-test-token','I4S_DMA_NLP_MODEL_DIR':str(self.package)});self.env.start();self.addCleanup(self.env.stop)
        sys.path.insert(0, str(ROOT / 'ai-service'))
        from public_model_app import app
        self.app = app
        self.client=TestClient(self.app);self.headers={'Authorization':'Bearer synthetic-test-token'}
        self.request={'version':'dma-processing-v1','locale':'en','iros':[{'iro_id':'a'*64,'source_sha256':'b'*64,'description':'Total amount of radioactive waste.'}]}

    def test_default_off_missing_config_and_bearer_before_parsing(self):
        with patch.dict(os.environ,{'I4S_DMA_NLP_ENABLED':'false'}):self.assertEqual(self.client.post('/dma/process',content='broken').status_code,404)
        with patch.dict(os.environ,{'I4S_AI_API_TOKEN':''}):self.assertEqual(self.client.post('/dma/process',content='broken').status_code,503)
        for headers in [{},{'Authorization':'bearer synthetic-test-token'},{'Authorization':'Bearer wrong'}]:
            self.assertEqual(self.client.post('/dma/process',content='broken',headers=headers).status_code,401)

    def test_missing_external_model_fails_closed(self):
        with patch.dict(os.environ, {'I4S_DMA_NLP_MODEL_DIR': ''}):
            self.assertEqual(self.client.post('/dma/process', json=self.request, headers=self.headers).status_code, 503)

    def test_streamed_size_cap_even_with_lying_content_length(self):
        async def run():
            async def body():
                yield b' '*40000;yield b' '*40000
            async with httpx.AsyncClient(transport=httpx.ASGITransport(app=self.app),base_url='http://test') as client:
                return await client.post('/dma/process',content=body(),headers={**self.headers,'Content-Length':'1'})
        self.assertEqual(asyncio.run(run()).status_code,413)

    def test_strict_schema_duplicate_json_and_utf8_byte_bound(self):
        bad=[{**self.request,'company':'private'}, {**self.request,'locale':'fr'}, {**self.request,'iros':self.request['iros']*2},
             {**self.request,'iros':[{**self.request['iros'][0],'description':'💧'*501}]}, {**self.request,'iros':[]}]
        for request in bad:self.assertEqual(self.client.post('/dma/process',json=request,headers=self.headers).status_code,422)
        self.assertEqual(self.client.post('/dma/process',content='{"version":"dma-processing-v1","version":"bad"}',headers=self.headers).status_code,422)

    def test_saturation_returns_retry_after_and_releases_slot_after_failure(self):
        SLOTS.acquire()
        try:
            response=self.client.post('/dma/process',json=self.request,headers=self.headers)
            self.assertEqual(response.status_code,503);self.assertEqual(response.headers['retry-after'],'10')
        finally:SLOTS.release()
        with patch('services.dma_processing_endpoint.process_isolated',side_effect=TimeoutError):
            self.assertEqual(self.client.post('/dma/process',json=self.request,headers=self.headers).status_code,503)
        response=self.client.post('/dma/process',json=self.request,headers=self.headers)
        self.assertEqual(response.status_code,200);self.assertEqual(response.json()['iros'][0]['levels']['datapoint']['candidates'][0]['id'],'E5-5_16')

    def test_client_cancellation_keeps_capacity_until_worker_cleanup(self):
        entered=threading.Event(); release=threading.Event()
        def block(payload):
            entered.set(); release.wait(2); raise RuntimeError('synthetic worker failure')
        async def run():
            async with httpx.AsyncClient(transport=httpx.ASGITransport(app=self.app),base_url='http://test') as client:
                task=asyncio.create_task(client.post('/dma/process',json=self.request,headers=self.headers))
                await asyncio.to_thread(entered.wait,1)
                task.cancel(); await asyncio.sleep(.02)
                acquired=SLOTS.acquire(blocking=False)
                if acquired:SLOTS.release()
                release.set()
                try:await task
                except asyncio.CancelledError:pass
                return acquired
        with patch('services.dma_processing_endpoint.process_isolated',side_effect=block):
            self.assertFalse(asyncio.run(run()))
        self.assertTrue(SLOTS.acquire(blocking=False));SLOTS.release()

    def test_deadline_terminates_reaps_worker_and_recovers(self):
        before={child.pid for child in mp.active_children()}; start=time.monotonic()
        with self.assertRaises(TimeoutError):process_isolated(self.request,deadline=.1,worker=sleeping_worker)
        self.assertLess(time.monotonic()-start,2)
        self.assertEqual({child.pid for child in mp.active_children()},before)
        result=process_isolated(self.request)
        self.assertEqual(result['version'],'dma-processing-v1');self.assertEqual({child.pid for child in mp.active_children()},before)

if __name__ == '__main__':unittest.main()
