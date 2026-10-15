"""Public HTTP route tests use genuine inference, only synthetic descriptions."""
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from fastapi.testclient import TestClient
from services import xbrl_runtime_endpoint as endpoint
from public_model_app import app
from test_xbrl_runtime_model import fixture, request


class RuntimeEndpointTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='xbrl-runtime-endpoint-')
        self.addCleanup(self.temp.cleanup)
        directory = Path(self.temp.name) / 'model'
        fixture(directory)
        self.env = patch.dict(os.environ, {'XBRL_RUNTIME_IDENTIFICATION_ENABLED': 'true', 'XBRL_RUNTIME_MODEL_DIR': str(directory), 'I4S_AI_API_TOKEN': 'synthetic-test-token'})
        self.env.start(); self.addCleanup(self.env.stop)
        self.model_patch = patch.object(endpoint, '_model', None)
        self.model_patch.start(); self.addCleanup(self.model_patch.stop)
        self.client = TestClient(app)
        self.addCleanup(self.client.close)
        self.headers = {'Authorization': 'Bearer synthetic-test-token'}

    def post(self, payload=None, **kwargs):
        return self.client.post('/xbrl/concept-candidates', json=request() if payload is None else payload, headers=self.headers, **kwargs)

    def test_auth_and_configuration(self):
        self.assertEqual(self.client.post('/xbrl/concept-candidates', json=request()).status_code, 401)
        with patch.dict(os.environ, {'I4S_AI_API_TOKEN': ''}): self.assertEqual(self.post().status_code, 503)
        with patch.dict(os.environ, {'XBRL_RUNTIME_IDENTIFICATION_ENABLED': 'false'}), patch.object(endpoint, 'model', side_effect=AssertionError('loaded disabled')):
            self.assertEqual(self.post().status_code, 404)

    def test_real_unseen_inference_load_once(self):
        result = self.post()
        self.assertEqual(result.status_code, 200, result.text)
        self.assertEqual(result.json()['candidates'][0]['qname'], 'esrs:WaterConsumption')
        with patch.object(endpoint.RuntimeMatcher, 'load', side_effect=AssertionError('reloaded')):
            self.assertEqual(self.post().status_code, 200)

    def test_body_and_schema_bounds(self):
        for change in ({'unexpected': True}, {'indicator': {'label': '', 'description': ' ', 'language': 'en'}}, {'indicator': {'label': 'x' * 301, 'description': '', 'language': 'en'}}, {'indicator': {**request()['indicator'], 'value': 123}}):
            self.assertEqual(self.post({**request(), **change}).status_code, 422)
        response = self.client.post('/xbrl/concept-candidates', content=b' ' * 32769, headers=self.headers)
        self.assertEqual(response.status_code, 413)
        self.assertNotIn('input', self.post({'secret_text': 'private'}).text)

    def test_capacity_and_bad_model(self):
        endpoint.SLOTS.acquire()
        try:
            response = self.post()
            self.assertEqual(response.status_code, 503)
            self.assertEqual(response.headers['retry-after'], '10')
        finally: endpoint.SLOTS.release()
        with patch.dict(os.environ, {'XBRL_RUNTIME_MODEL_DIR': '/nonexistent-private-directory'}):
            result = self.post()
            self.assertEqual(result.status_code, 503)
            self.assertNotIn('directory', result.text)

    def test_rejects_unknown_drs_and_mismatched_standard_before_inference(self):
        for context in ({'standard': 'E1', 'dr': 'E1-99'}, {'dr': 'E1-99'},
                        {'dr': 'S2-6'}, {'standard': 'E1', 'dr': 'E3-4'},
                        {'standard': 'E1', 'dr': 'MDR-P'}):
            with self.subTest(context=context), patch.object(endpoint, 'model', side_effect=AssertionError('loaded for invalid context')):
                r = request(); r['indicator'].update(context)
                self.assertEqual(self.post(r).status_code, 422)
