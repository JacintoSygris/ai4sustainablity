"""Portable synthetic transport tests; no operational issuer claim."""
import copy
import hashlib
import json
from pathlib import Path
import unittest


class LearningCaseCurationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        from learning_case_curation_adapter import annotation_command, CurationError
        cls.adapter = staticmethod(annotation_command)
        cls.error = CurationError
        from learning_portable_transport_helper import bundle
        cls.raw = bundle()['jsonl']
        cls.labels = [{'topic_id': '1', 'value': 0, 'observed_mask': 1}]

    def call(self, raw=None, labels=None, note='SYNTHETIC_FREE_NOTE'):
        return self.adapter(self.raw if raw is None else raw, json.dumps({'schema_version': 'learning-case-curation-input-v1', 'expected_annotation_revision': 0, 'command_id': 'c' * 64, 'topic_labels': self.labels if labels is None else labels, 'note': note}))

    def test_actual_export_produces_command_only_and_withholds_entire_note(self):
        out = self.call()
        self.assertEqual(out['export_digest'], hashlib.sha256(self.raw.encode()).hexdigest())
        self.assertEqual(out['notes'], {'status': 'withheld', 'reason': 'free_text_transport_disabled'})
        self.assertNotIn('SYNTHETIC_FREE_NOTE', json.dumps(out))
        self.assertNotIn('X', out)
        self.assertNotIn('receipt', out)
        self.assertEqual(self.call(note=None), out)

    def test_strict_masks_types_and_outside_universe(self):
        for value in [False, '0', 0.0, None]:
            with self.subTest(value=value), self.assertRaises(self.error):
                self.call(labels=[{'topic_id':'1','value':value,'observed_mask':1}])
        for mask, value in [(0,0),(False,None),(2,None)]:
            with self.subTest(mask=mask), self.assertRaises(self.error):
                self.call(labels=[{'topic_id':'1','value':value,'observed_mask':mask}])
        self.assertEqual(self.call(labels=[{'topic_id':'1','value':None,'observed_mask':0}])['topic_labels'][0]['value'], None)
        with self.assertRaises(self.error): self.call(labels=[{'topic_id':'999','value':0,'observed_mask':1}])
        with self.assertRaises(self.error): self.call(labels=self.labels * 2)

    def test_duplicates_root_containers_and_extra_authority_denied(self):
        for raw in ['[]','null',self.raw.replace('"schema_version":','"schema_version":"x","schema_version":',1),self.raw.replace('"schema_version":','"schema_version":"x","schema\\u005fversion":',1)]:
            with self.subTest(), self.assertRaises(self.error): self.call(raw)
        data=json.loads(self.raw)
        for mutation in [lambda x:x.update(grant=True),lambda x:x['X'].update(note='SYNTHETIC_FREE'),lambda x:x['reference'].update(actor_id=1),lambda x:x.update(receipt=[])]:
            x=copy.deepcopy(data);mutation(x)
            with self.assertRaises(self.error): self.call(json.dumps(x,separators=(',',':'))+'\n')

    def test_closed_versions_digests_ids_and_transport(self):
        data=json.loads(self.raw)
        for field in ['schema_version','namespace']:
            x=copy.deepcopy(data);x[field]='unknown'
            with self.assertRaises(self.error): self.call(json.dumps(x)+'\n')
        for digest in ['a'*63,'a'*64+'\n','A'*64]:
            x=copy.deepcopy(data);x['reference']['case_hash']=digest
            with self.assertRaises(self.error): self.call(json.dumps(x)+'\n')
        for raw in [self.raw.rstrip('\n'), '\ufeff'+self.raw, self.raw+'\n']:
            with self.assertRaises(self.error): self.call(raw)
        x=copy.deepcopy(data);x['X']['values']['stock_listed']=0
        with self.assertRaises(self.error): self.call(json.dumps(x)+'\n')

    def test_input_is_strict_and_no_note_survives(self):
        for change in [{'expected_annotation_revision':False},{'command_id':'a'*64+'\n'},{'grant':True},{'topic_labels':{}},{'note':[]}]:
            d={'schema_version':'learning-case-curation-input-v1','expected_annotation_revision':0,'command_id':'c'*64,'topic_labels':self.labels,'note':None};d.update(change)
            with self.assertRaises(self.error): self.adapter(self.raw,json.dumps(d))
        for note in ['SYNTHETIC_NAME','SYNTHETIC_MAIL','SYNTHETIC_RAW','']:
            self.assertEqual(self.call(note=note)['notes']['status'],'withheld')


if __name__ == '__main__':
    unittest.main()
