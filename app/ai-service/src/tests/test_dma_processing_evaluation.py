import importlib.util
import json
from pathlib import Path
import unittest
from services.dma_processing import ROOT
spec=importlib.util.spec_from_file_location('dma_evaluation',ROOT/'ai-service/scripts/dma_processing/evaluate.py')
evaluation=importlib.util.module_from_spec(spec);spec.loader.exec_module(evaluation)

class EvaluationTest(unittest.TestCase):
    def test_hand_computed_precision_recall_coverage_and_abstention(self):
        cases=[{'id':'a','expected':{'datapoint':['x','y']},'expected_abstention':False}, {'id':'b','expected':{'datapoint':['z']},'expected_abstention':False}, {'id':'c','expected':{'datapoint':[]},'expected_abstention':True}]
        result=evaluation.metrics(cases,{'a':{'datapoint':['x','q']},'c':{'datapoint':['q']}},'datapoint')
        self.assertEqual(result['recall_at_3'],1/3);self.assertEqual(result['precision'],1/3)
        self.assertEqual(result['determinate_coverage'],.5);self.assertEqual(result['expected_abstention_success'],0)
        self.assertEqual(result['abstentions'],1)

    def test_empty_and_all_abstaining_cannot_pass(self):
        self.assertFalse(evaluation.gate([], {})['passed'])
        cases=json.loads((evaluation.FIXTURES/'heldout.json').read_text())['cases']
        self.assertFalse(evaluation.gate(cases,{})['passed'])

    def test_fixture_balance_family_separation_and_provisional_labels(self):
        families=[]
        for split in ['development','heldout']:
            data=json.loads((evaluation.FIXTURES/(split+'.json')).read_text());self.assertEqual(len(data['cases']),24)
            self.assertEqual(data['label_status'],'provisional_pending_human_review')
            self.assertEqual(sum(c['locale']=='es' for c in data['cases']),12)
            families.append({c['family'] for c in data['cases']})
        self.assertFalse(families[0]&families[1])

if __name__ == '__main__':unittest.main()
