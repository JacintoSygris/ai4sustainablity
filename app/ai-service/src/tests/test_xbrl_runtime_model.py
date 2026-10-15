"""Synthetic fixtures only; no specialist or business-indicator evaluation."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import numpy as np
from services.xbrl_concept_matcher import (Matcher, RuntimeMatcher, SCHEMA, compatibility,
    digest, json_bytes, save_runtime)

def fixture(directory):
    concepts = ['esrs:CarbonEmissions', 'esrs:WaterConsumption']
    rows = [{'name': 'carbon greenhouse emissions', 'dr': 'E1-6', 'esrs': 'E1', 'target': concepts[0]},
            {'name': 'water consumption freshwater', 'dr': 'E3-4', 'esrs': 'E3', 'target': concepts[1]}]
    model = Matcher(concepts, rows).fit(rows)
    runtime = save_runtime(model, directory, model_version='synthetic-test-v1', source_commit='a' * 40,
        inputs={'concept_map_sha256': 'b' * 64}, taxonomy_profile='test', catalog_sha256='b' * 64,
        min_score=0, min_margin=0)
    return model, runtime


def request(text='Freshwater consumption during the summer'):
    return {'schema_version': SCHEMA, 'request_id': '67a0e4ba-0936-4e41-81a0-383521754912',
            'taxonomy_profile': 'test', 'catalog_sha256': 'b' * 64,
            'indicator': {'label': '', 'description': text, 'language': 'en'}}


class RuntimeModelTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='xbrl-runtime-model-')
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name) / 'model'
        self.original, self.runtime = fixture(self.directory)

    def test_cli_build_uses_public_serving_sources_and_exact_input_bytes(self):
        root = Path(__file__).resolve().parents[2]
        inputs = Path(self.temp.name)
        points = {'datapoints': [
            {'id': 'synthetic-carbon', 'name': 'carbon greenhouse emissions', 'dr': 'E1-6', 'esrs': 'E1'},
            {'id': 'synthetic-water', 'name': 'water consumption freshwater', 'dr': 'E3-4', 'esrs': 'E3'}]}
        mapping = {'concepts': {
            'synthetic-carbon': {'concept_id': 'esrs:CarbonEmissions', 'taggable_state': 'mapped'},
            'synthetic-water': {'concept_id': 'esrs:WaterConsumption', 'taggable_state': 'mapped'}}}
        ig3, concept_map = inputs / 'ig3.json', inputs / 'map.json'
        ig3.write_bytes(json.dumps(points, indent=2).replace('\n', '\r\n').encode())
        concept_map.write_bytes(json_bytes(mapping))
        output = inputs / 'cli-model'
        result = subprocess.run([sys.executable, '-B', str(root / 'scripts/xbrl_mapping/train_runtime.py'),
            '--ig3', str(ig3), '--concept-map', str(concept_map), '--output-dir', str(output),
            '--model-version', 'synthetic-cli-v1', '--min-score', '0', '--min-margin', '0'],
            cwd=root, env={**os.environ, 'PYTHONPATH': 'src', 'OPENBLAS_NUM_THREADS': '1', 'OMP_NUM_THREADS': '1'},
            capture_output=True, text=True, timeout=60)
        self.assertEqual(result.returncode, 0, result.stderr)
        runtime = RuntimeMatcher.load(output)
        self.assertEqual(runtime.manifest['inputs'], {'ig3_sha256': digest(ig3.read_bytes()),
            'concept_map_sha256': digest(concept_map.read_bytes())})
        sources = runtime.manifest['source_files']
        self.assertIn('public_runtime/services/xbrl_concept_matcher.py', sources)
        self.assertIn('public_model_app.py', sources)
        self.assertFalse(any(path.startswith('src/services/') for path in sources))
        for path, sha256 in sources.items():
            self.assertEqual(sha256, digest((root / path).read_bytes()))
        payload = request()
        payload.update(taxonomy_profile=runtime.manifest['taxonomy_profile'], catalog_sha256=digest(concept_map.read_bytes()))
        self.assertEqual(runtime.identify(payload)['candidates'][0]['qname'], 'esrs:WaterConsumption')

    def test_save_load_ranking_parity_and_no_fit(self):
        point = {'name': 'freshwater consumption', 'description': '', 'dr': None, 'esrs': None}
        with patch.object(Matcher, 'fit', side_effect=AssertionError('inference trained')), patch.object(Matcher, '__init__', side_effect=AssertionError('inference fitted vocabulary')):
            loaded = RuntimeMatcher.load(self.directory)
            np.testing.assert_allclose(self.original.scores(point)['logistic_reranker'], loaded.matcher.scores(point)['logistic_reranker'], rtol=0, atol=1e-14)
            self.assertFalse(loaded.identify(request())['abstained'])

    def test_unseen_description_changes_ranking(self):
        self.assertEqual(self.runtime.identify(request())['candidates'][0]['qname'], 'esrs:WaterConsumption')
        self.assertEqual(self.runtime.identify(request('Greenhouse carbon emissions from the factory'))['candidates'][0]['qname'], 'esrs:CarbonEmissions')

    def test_abstention_languages_overlap_context_and_thresholds(self):
        r = request(); r['indicator']['language'] = 'es'
        self.assertEqual(self.runtime.identify(r)['reason_codes'], ['unsupported_language'])
        r = request('zzzzzz qqqqqq'); r['indicator']['dr'] = 'E1-6'
        self.assertEqual(self.runtime.identify(r)['reason_codes'], ['zero_textual_overlap'])
        r = request(); r['catalog_sha256'] = 'c' * 64
        self.assertEqual(self.runtime.identify(r)['reason_codes'], ['unsupported_context'])
        self.runtime.manifest['thresholds']['min_score'] = 1
        self.assertEqual(self.runtime.identify(request())['reason_codes'], ['below_threshold'])
        self.runtime.manifest['thresholds'] = {'min_score': 0, 'min_margin': 1}
        self.assertEqual(self.runtime.identify(request())['reason_codes'], ['insufficient_margin'])

    def test_metadata_filters_abstract_and_incompatible(self):
        entry = {'type': '{http://www.xbrl.org/2003/instance}decimalItemType', 'period_type': 'duration', 'abstract': True}
        self.runtime.metadata = {c: entry for c in self.runtime.matcher.concepts}
        self.assertEqual(self.runtime.identify(request())['reason_codes'], ['no_compatible_candidates'])
        self.assertEqual(compatibility({**entry, 'abstract': False}, {'value_type': 'text', 'period_type': 'duration'})[0], 'incompatible')
        self.assertEqual(compatibility({**entry, 'abstract': False}, {'value_type': 'number', 'period_type': 'duration', 'unit': 'pure'})[0], 'verified_taxonomy')
        self.assertEqual(compatibility({**entry, 'abstract': False, 'type': '{unknown}custom'}, {})[0], 'unverified')

    def test_unknown_and_mismatched_context_abstains_before_ranking(self):
        for context in ({'standard': 'E1', 'dr': 'E1-99'}, {'dr': 'E1-99'},
                        {'standard': 'E1', 'dr': 'E3-4'}, {'standard': 'E1', 'dr': 'MDR-P'},
                        {'standard': 'unknown'}):
            with self.subTest(context=context), patch.object(self.runtime.matcher, 'scores', side_effect=AssertionError('ranked invalid context')):
                r = request(); r['indicator'].update(context)
                result = self.runtime.identify(r)
                self.assertTrue(result['abstained'])
                self.assertEqual(result['reason_codes'], ['unsupported_context'])
                self.assertEqual(result['candidates'], [])

    def test_canonical_context_is_independent_of_training_membership(self):
        from services.xbrl_runtime_context import supported_context, TOPICAL_DR_COUNTS
        for standard, last in TOPICAL_DR_COUNTS.items():
            self.assertTrue(supported_context({'standard': standard, 'dr': f'{standard}-{last}'}))
            self.assertFalse(supported_context({'dr': f'{standard}-{last + 1}'}))
        r = request(); r['indicator'].update(standard='S1', dr='S1-17')
        self.assertFalse(self.runtime.identify(r)['abstained'])
        self.assertTrue(supported_context({'standard': 'ESRS 2', 'dr': 'MDR-P'}))
        self.assertFalse(supported_context({'dr': 'E1-01'}))

    def test_ties_deterministic(self):
        with patch.object(self.runtime.matcher, 'scores', return_value={'logistic_reranker': np.array([0.7, 0.7])}):
            self.assertEqual([c['qname'] for c in self.runtime.identify(request())['candidates']], sorted(self.runtime.matcher.concepts))

    def test_corrupt_artifact_hash(self):
        with (self.directory / 'state.json').open('ab') as f: f.write(b' ')
        with self.assertRaisesRegex(ValueError, 'hash_mismatch'): RuntimeMatcher.load(self.directory)

    def test_invalid_dimensions_nonfinite_versions_and_sizes(self):
        path = self.directory / 'arrays.npz'
        with np.load(path, allow_pickle=False) as data: original = {key: data[key] for key in data.files}
        manifest_path = self.directory / 'manifest.json'
        manifest = json.loads(manifest_path.read_bytes())
        for bad in (np.zeros((1, 5)), np.full((1, 4), np.nan)):
            np.savez(path, **{**original, 'coef': bad})
            manifest['artifacts']['arrays.npz'] = digest(path.read_bytes())
            manifest_path.write_bytes(json_bytes(manifest))
            with self.assertRaises(ValueError): RuntimeMatcher.load(self.directory)
        manifest['dependencies']['sklearn'] = 'wrong'
        manifest_path.write_bytes(json_bytes(manifest))
        with self.assertRaisesRegex(ValueError, 'incompatible'): RuntimeMatcher.load(self.directory)
        manifest_path.write_bytes(b' ' * 65537)
        with self.assertRaisesRegex(ValueError, 'too_large'): RuntimeMatcher.load(self.directory)

    def test_optional_catalog_languages_do_not_inherit_benchmark(self):
        from services.xbrl_concept_matcher import catalog_inventory
        metadata = {'esrs:WaterConsumption': {'labels': {'es': 'consumo agua', 'en': 'water consumption'},
            'descriptions': {'es': 'agua dulce'}, 'type': '{http://www.xbrl.org/2003/instance}decimalItemType',
            'period_type': 'duration', 'abstract': False}}
        catalog = {'schema_version': SCHEMA, 'taxonomy_profile': 'test', 'package_sha256': 'd' * 64,
                   'languages': ['es', 'en'], 'concepts': metadata}
        self.assertEqual(catalog_inventory(catalog), metadata)
        model = Matcher(['esrs:CarbonEmissions', 'esrs:WaterConsumption'], [
            {'name': 'carbon emissions', 'target': 'esrs:CarbonEmissions', 'dr': 'E1-6', 'esrs': 'E1'},
            {'name': 'water consumption', 'target': 'esrs:WaterConsumption', 'dr': 'E3-4', 'esrs': 'E3'}],
            ['carbon emissions', 'consumo agua water consumption']).fit([
            {'name': 'carbon emissions', 'target': 'esrs:CarbonEmissions', 'dr': 'E1-6', 'esrs': 'E1'},
            {'name': 'water consumption', 'target': 'esrs:WaterConsumption', 'dr': 'E3-4', 'esrs': 'E3'}])
        extended = save_runtime(model, Path(self.temp.name) / 'bilingual', model_version='synthetic-bilingual', source_commit='a' * 40,
            inputs={'concept_map_sha256': 'b' * 64}, taxonomy_profile='test', catalog_sha256='c' * 64,
            package_sha256='d' * 64, metadata=metadata, languages=['es', 'en'], min_score=0, min_margin=0)
        r = request('consumo agua dulce'); r['catalog_sha256'] = 'c' * 64; r['indicator']['language'] = 'es'
        self.assertEqual(extended.identify(r)['candidates'][0]['qname'], 'esrs:WaterConsumption')
        self.assertNotIn('evaluation', extended.manifest)
        catalog['concepts']['esrs:WaterConsumption']['labels'].pop('es')
        with self.assertRaisesRegex(ValueError, 'label_missing'): catalog_inventory(catalog)
