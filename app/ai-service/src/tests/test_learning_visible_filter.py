"""Prospective public adaptation vectors; no serving assets or optional dependencies."""
import unittest
import importlib.util
from pathlib import Path
from types import SimpleNamespace, ModuleType
from unittest.mock import patch
import sys

from learning_visible_filter import filter_learning_predictions


class VisibleFilterTests(unittest.TestCase):
    def test_distinct_head_thresholds_raw_labels_and_excluded_heads(self):
        keys = ['esrs_e1_energy', 'esrs_e2_pollution_of_air', 'esrs_e3_summary',
                'esrs_e4_unknown', 'esrs_e5_resource_inflows']
        visible, metadata = filter_learning_predictions(
            keys, [1, 1, 1, 1, 0], [.7, .7, .99, .99, .99], score_threshold=.5,
            label_thresholds={keys[0]: .6, keys[1]: .8, keys[2]: .5, keys[4]: .5},
            sector_guard=None, crc_floor=None,
        )
        self.assertEqual(visible, [keys[3], keys[0]])
        self.assertEqual(metadata['per_label_threshold_count'], 4)
        self.assertEqual(metadata['raw_positive_key_count'], 4)
        self.assertEqual(metadata['threshold_positive_key_count'], 3)
        self.assertEqual(metadata['excluded_non_candidate_key_count'], 1)
        self.assertFalse(metadata['sector_guard_active'])
        self.assertFalse(metadata['crc_recall_floor_active'])

    def test_default_threshold_and_stable_tie_order(self):
        visible, _ = filter_learning_predictions(
            ['esrs_e2_pollution_of_air', 'esrs_e1_energy', 'esrs_e3_water'],
            [1, 1, 1], [.5, .5, .49], score_threshold=.5,
        )
        self.assertEqual(visible, ['esrs_e1_energy', 'esrs_e2_pollution_of_air'])

    def test_policy_objects_are_denied_at_optional_boundary(self):
        for field in ('sector_guard', 'crc_floor'):
            with self.subTest(field=field), self.assertRaises(ValueError):
                filter_learning_predictions([], [], [], **{field: object()})

    def test_adapter_omits_unknown_heads_before_visible_filter(self):
        mapping = {'topic_ids': ['1', '2', '3'],
                   'filter_keys': ['esrs_e1_energy', 'esrs_e2_pollution_of_air', 'esrs_e4_unknown']}
        registry = ModuleType('learning_candidate_registry')
        registry.MAPPING, registry.RegistryError, registry.digest = mapping, ValueError, lambda _: 'synthetic'
        training = ModuleType('learning_case_training')

        class Matrix:
            def __init__(self, row):
                self.row = row
                self.loc = self
            def __getitem__(self, key):
                return self.row[key[1]]
            def to_numpy(self):
                return SimpleNamespace(tolist=lambda: [list(self.row.values())])

        training.predict_raw = lambda *_: (Matrix({'1': 1, '2': 1, '3': None}),
                                           Matrix({'1': .7, '2': .7, '3': .99}))
        candidate = SimpleNamespace(topic_ids=mapping['topic_ids'], promotion_allowed=False,
            label_status={'1': 'fitted', '2': 'fitted', '3': 'unknown'}, thresholds={'1': .6, '2': .8})
        inputs = SimpleNamespace(index=['synthetic-row'], to_dict=lambda _: {})
        path = Path(__file__).resolve().parents[1] / 'learning_case_prediction_adapter.py'
        spec = importlib.util.spec_from_file_location('public_adapter_vector', path)
        module = importlib.util.module_from_spec(spec)
        with patch.dict(sys.modules, {'learning_candidate_registry': registry, 'learning_case_training': training}):
            spec.loader.exec_module(module)
            result = module.predict_offline(candidate, inputs)
        self.assertEqual(result['visible_keys'], [['esrs_e1_energy']])
        self.assertEqual(result['raw_labels'], [[1, 1, None]])
        self.assertFalse(result['promotion_allowed'])


if __name__ == '__main__':
    unittest.main()
