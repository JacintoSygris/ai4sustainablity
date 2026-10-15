"""Synthetic mathematical fixtures, plus integrity checks on the real run artifact."""
import hashlib
import importlib.util
import json
import sys
from pathlib import Path
import unittest

from concept_relations import association_metrics, cluster_purity


class ConceptRelationsTest(unittest.TestCase):
    def test_identical_nonconstant(self):
        m = association_metrics([1, 0, 1, 0], [1, 0, 1, 0])
        self.assertEqual(m, {'joint_count': 2, 'support': .5, 'phi': 1., 'jaccard': 1.,
                             'lift': 2., 'confidence': 1., 'reverse_confidence': 1.})

    def test_independence(self):
        m = association_metrics([1, 1, 0, 0], [1, 0, 1, 0])
        self.assertEqual(m['phi'], 0)
        self.assertAlmostEqual(m['jaccard'], 1/3)
        self.assertEqual(m['lift'], 1)
        self.assertEqual(m['confidence'], .5)

    def test_negative_correlation(self):
        m = association_metrics([1, 0], [0, 1])
        self.assertEqual(m['phi'], -1)
        self.assertEqual(m['lift'], 0)

    def test_directional_confidence(self):
        m = association_metrics([1, 1, 0, 0], [1, 0, 0, 0])
        self.assertEqual(m['confidence'], .5)
        self.assertEqual(m['reverse_confidence'], 1)
        self.assertEqual(m['lift'], 2)

    def test_zero_and_constant_vectors(self):
        zero = association_metrics([0, 0], [0, 0])
        self.assertIsNone(zero['phi'])
        self.assertIsNone(zero['jaccard'])
        self.assertIsNone(zero['lift'])
        self.assertIsNone(zero['confidence'])
        one = association_metrics([1, 1], [1, 1])
        self.assertIsNone(one['phi'])
        self.assertEqual(one['lift'], 1)

    def test_reject_invalid_vectors(self):
        for x, y in (([], []), ([1], [1, 0]), ([2], [1]), ([-1], [0])):
            with self.assertRaises(ValueError):
                association_metrics(x, y)

    def test_weighted_purity(self):
        self.assertEqual(cluster_purity([0, 0, 0, 1], ['A', 'A', 'B', 'C']), .75)
        self.assertEqual(cluster_purity([0, 1], ['A', 'B']), 1)
        with self.assertRaises(ValueError):
            cluster_purity([], [])
        with self.assertRaises(ValueError):
            cluster_purity([0], [])

    def test_relation_support_thresholds_and_direction(self):
        import numpy as np
        path = Path(__file__).resolve().parents[2]/'scripts/concepts/build_relations.py'
        spec = importlib.util.spec_from_file_location('concept_builder', path)
        module = importlib.util.module_from_spec(spec)
        original_path = list(sys.path)
        try:
            spec.loader.exec_module(module)
        finally:
            sys.path[:] = original_path
        matrix = np.array([[1, 1], [1, 0], [0, 0], [0, 0]])
        edges = module.relations(matrix, [1, 2], minimum_count=1, minimum_support=.25,
                                 minimum_phi=.1, minimum_lift=1.1, minimum_confidence=.75)
        self.assertEqual([(e['source'], e['target']) for e in edges], [(2, 1)])
        self.assertEqual(module.relations(matrix, [1, 2], minimum_count=2, minimum_support=.25,
                                         minimum_phi=.1, minimum_lift=1.1, minimum_confidence=.1), [])
        self.assertEqual(module.relations(matrix, [1, 2], minimum_count=1, minimum_support=.3,
                                         minimum_phi=.1, minimum_lift=1.1, minimum_confidence=.1), [])

    def test_committed_artifact(self):
        app = Path(__file__).resolve().parents[3]
        path = app/'web/data/esrs_concept_relations_v1.json'
        data = json.loads(path.read_text())
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), path.with_suffix('.json.sha256').read_text().strip())
        for name, source in [('ig3_datapoints', app/'web/data/esrs_datapoints_ig3.json'),
                             ('ar16_mapping', app/'web/data/ar16_to_python_esrs_mapping_new_format_732_v1.json'),
                             ('generator', app/'ai-service/scripts/concepts/build_relations.py'),
                             ('metric_functions', app/'ai-service/src/concept_relations.py')]:
            self.assertEqual(data['source_sha256'][name], hashlib.sha256(source.read_bytes()).hexdigest())
        ids = {dp['id'] for dp in json.loads((app/'web/data/esrs_datapoints_ig3.json').read_text())['datapoints']}
        self.assertEqual(set(data['datapoints']), ids)
        self.assertEqual(sum(map(len, data['clusters'].values())), len(ids))
        for id_, row in data['datapoints'].items():
            cluster = set(data['clusters'][row['cluster']])
            self.assertEqual(set(row['siblings']), cluster-{id_})
            self.assertEqual(len(row['siblings']), len(cluster)-1)
        for edge in data['topic_relations']+data['leaf_relations']:
            for metric, threshold in [('support', 'minimum_support'), ('phi', 'minimum_phi'),
                                      ('lift', 'minimum_lift'), ('confidence', 'minimum_confidence'),
                                      ('joint_count', 'minimum_count')]:
                self.assertGreaterEqual(edge[metric], data['parameters']['thresholds'][threshold])
        selected = data['metrics']['selected']
        self.assertEqual(selected['silhouette_cosine'], max(c['silhouette_cosine'] for c in data['metrics']['clustering_candidates']))
