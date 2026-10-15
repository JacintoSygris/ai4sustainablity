import asyncio, hashlib, os, shutil, subprocess, sys, tempfile, unittest
from pathlib import Path
from unittest.mock import patch
from fastapi.testclient import TestClient
from services.sector_comparison import build_artifact,write_artifact
import public_model_app as endpoints
from services.sector_comparison_runtime import SectorRuntime
from services.datatypes import SectorComparisonRequest
from test_sector_comparison import synthetic_inputs


class SectorComparisonApiTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.path=Path(self.temp.name)/'artifact.json';write_artifact(build_artifact(**synthetic_inputs(self.temp.name)),self.path)
        env=patch.dict(os.environ,dict(ESRS_SECTOR_COMPARISON_ENABLED='true',ESRS_SECTOR_COMPARISON_PATH=str(self.path),ESRS_SECTOR_COMPARISON_SHA256=hashlib.sha256(self.path.read_bytes()).hexdigest(),I4S_AI_API_TOKEN='synthetic-test-token'))
        env.start();self.addCleanup(env.stop)
        runtime=patch.object(endpoints,'sector_runtime',SectorRuntime());runtime.start();self.addCleanup(runtime.stop)
        self.client=TestClient(endpoints.app)
        self.addCleanup(self.client.close)
        self.payload=dict(version='sector-comparison-v1',sections=['K','L','M'],topics=['1','2','3','4'])

    def request(self,payload=None):
        return self.client.post('/sector-comparison',json=self.payload if payload is None else payload,headers={'Authorization':'Bearer synthetic-test-token'})

    def test_known_response(self):
        r=self.request();self.assertEqual(r.status_code,200);cells=r.json()['comparisons'][0]['topics']
        self.assertEqual((cells[0]['sector']['n_material'],cells[0]['proportion']),(3,.6))
        self.assertEqual(cells[1]['source'],'global_fallback');self.assertIsNone(cells[2]['proportion']);self.assertEqual(r.json()['global_population_companies'],6)

    def test_disabled_does_not_read_artifact(self):
        with patch.dict(os.environ,{'ESRS_SECTOR_COMPARISON_ENABLED':'false'}),patch('services.sector_comparison_runtime.load_snapshot') as load:
            endpoints.sector_runtime=SectorRuntime();self.assertEqual(self.request().status_code,503);load.assert_not_called()

    def test_bearer_and_unconfigured_token(self):
        for headers in [{},{'Authorization':'Bearer wrong'},{'Authorization':'Basic synthetic-test-token'}]:self.assertEqual(self.client.post('/sector-comparison',json=self.payload,headers=headers).status_code,401)
        with patch.dict(os.environ,{'I4S_AI_API_TOKEN':''}):self.assertEqual(self.request().status_code,503)

    def test_strict_request_extras_size_vocabulary(self):
        for update in [dict(extra=True),dict(topics=[1]),dict(topics=['90']),dict(topics=['1','1']),dict(sections=['W']),dict(sections=['K','K']),dict(version=1),dict(topics=[])]:self.assertEqual(self.request({**self.payload,**update}).status_code,422)
        headers={'Authorization':'Bearer synthetic-test-token'}
        self.assertEqual(self.client.post('/sector-comparison',content=b' '*16385,headers=headers).status_code,413)
        self.assertEqual(self.client.post('/sector-comparison',content=b'{"version":"x","version":"y"}',headers=headers).status_code,422)
        self.assertEqual(self.client.post('/sector-comparison',content=b'['*2000+b'0'+b']'*2000,headers=headers).status_code,422)

    def test_corrupt_snapshot_does_not_disable_prediction(self):
        for raw in [b'{}',b'{invalid',b'['*2000+b'0'+b']'*2000]:
            self.path.write_bytes(raw)
            with patch.dict(os.environ,{'ESRS_SECTOR_COMPARISON_SHA256':hashlib.sha256(raw).hexdigest()}):endpoints.sector_runtime=SectorRuntime()
            self.assertEqual(self.request().status_code,503)
        with patch.object(endpoints,'predict_esrs',return_value={'esrs':{'test':1}}):
            r=self.client.post('/predict',json=dict(company_name='synthetic',sector_list=['K'],headquarters_country='Spain',num_subsidiaries_countries=0,employees_total=10,annual_turnover_million_euro=1,stock_listed=False,reporting_currency='EUR'))
            self.assertEqual(r.status_code,200);self.assertEqual(r.json()['esrs'],{'test':1})

    def test_snapshot_is_immutable_and_hash_pinned(self):
        self.path.write_text('{}');self.assertEqual(self.request().status_code,200)
        endpoints.sector_runtime=SectorRuntime();self.assertEqual(self.request().status_code,503)

    def test_public_lifespan_loads_snapshot(self):
        async def startup():
            async with endpoints.lifespan(endpoints.app):
                result = endpoints.sector_runtime.compare(SectorComparisonRequest.model_validate(self.payload))
                self.assertEqual(result['comparisons'][0]['topics'][0]['sector'],
                                 {'n_scored': 5, 'n_material': 3})
        with patch.object(endpoints, 'validate_model_artifacts') as validate:
            asyncio.run(startup())
            validate.assert_called_once_with(model_profile=endpoints.PUBLIC_MODEL_PROFILE)

    def test_public_image_layout_serves_without_source_tree(self):
        service_root = Path(__file__).resolve().parents[2]
        layout = Path(self.temp.name) / 'image'
        layout.mkdir()
        # Dockerfile.public copies public_runtime to src, with no source-tree alias.
        shutil.copytree(service_root / 'public_runtime', layout / 'src')
        shutil.copy2(service_root / 'public_model_app.py', layout / 'public_model_app.py')
        env = {**os.environ, 'PYTHONPATH': str(layout / 'src'), 'LEARNING_DEPLOYED': 'false',
               'PYTHONDONTWRITEBYTECODE': '1'}
        script = '''
import asyncio
from unittest.mock import patch
import public_model_app as endpoint
from services.sector_comparison import VERSION
from services.datatypes import SectorComparisonRequest
assert any(route.path == '/sector-comparison' and route.endpoint is endpoint.sector_comparison
           for route in endpoint.app.routes)
async def startup():
    async with endpoint.lifespan(endpoint.app):
        result = endpoint.sector_runtime.compare(SectorComparisonRequest(
            version=VERSION, sections=['K'], topics=['1', '2', '3']))
        cells = result['comparisons'][0]['topics']
        assert cells[0]['sector'] == {'n_scored': 5, 'n_material': 3}
        assert cells[1]['source'] == 'global_fallback'
        assert cells[2]['proportion'] is None
with patch.object(endpoint, 'validate_model_artifacts'):
    asyncio.run(startup())
'''
        result = subprocess.run([sys.executable, '-c', script], cwd=layout, env=env,
                                capture_output=True, text=True, timeout=60)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_rate_and_concurrency_boundaries(self):
        for _ in range(60):self.assertEqual(self.request().status_code,200)
        r=self.request();self.assertEqual(r.status_code,429);self.assertEqual(r.headers['Retry-After'],'60')
        runtime=SectorRuntime();endpoints.sector_runtime=runtime;runtime.slots.acquire();runtime.slots.acquire()
        r=self.request();self.assertEqual(r.status_code,503);self.assertEqual(r.headers['Retry-After'],'1')
        runtime.slots.release();self.assertEqual(self.request().status_code,200);runtime.slots.release()
