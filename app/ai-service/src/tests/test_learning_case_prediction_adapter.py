import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
import uuid

from test_learning_candidate_registry import fitted, ROOT
from learning_case_prediction_adapter import predict_offline, predict_operational
from learning_candidate_registry import RegistryError, LocalCandidateRegistry

class AdapterTests(unittest.TestCase):
    def setUp(self):
        self.root=ROOT/('01c-adapter-'+uuid.uuid4().hex)
        self.registry=LocalCandidateRegistry(self.root)
        self.dataset,self.split,self.candidate=fitted()

    def register(self):
        return self.registry.register(self.candidate,self.dataset,self.split)
    def test_operational_adapter_rejects_without_laravel_authority(self):
        receipt = self.register()
        with self.assertRaises(RegistryError): predict_operational(self.registry,receipt,self.dataset.X.iloc[:1],None)

    def test_real_fresh_process_cli_exact_inference(self):
        script = Path(__file__).resolve().parents[2]/'scripts/learning-case-standalone.py'
        output = ROOT/('01c-cli-'+self.root.name)
        result = subprocess.run([sys.executable,str(script),'--root',str(output)],capture_output=True,text=True,timeout=70,env=os.environ.copy())
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        evidence = json.loads((output/'comparison.json').read_text())
        self.assertNotEqual(evidence['parent_pid'],evidence['child']['pid'])
        self.assertEqual(evidence['before'],evidence['child']['prediction'])
        self.assertEqual(evidence['child']['executable'],sys.executable)
        self.assertFalse(evidence['receipt']['promotion_allowed'])
        self.assertTrue(evidence['before']['visible_keys'])
        self.assertNotEqual(evidence['before']['scores'][0],evidence['before']['scores'][1])

    def test_t10_case_shadow_and_operational_remain_denied_without_private_authority(self):
        from learning_case_prediction_adapter import predict_case_shadow
        for trusted in [False,True]:
            with self.subTest(trusted=trusted), self.assertRaises(RegistryError):
                predict_case_shadow(self.registry,{},self.dataset.X.iloc[:1],None,trusted_local_launcher=trusted)
        with self.assertRaises(RegistryError):
            predict_operational(self.registry,{},self.dataset.X.iloc[:1],{'promotion_allowed':True})
