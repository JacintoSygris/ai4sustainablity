"""Artifact-free regression tests against the actual fitted GPT-4.1 vocabulary."""
import json
import re
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from services import service_predict as serving
from services.datatypes import CompanyData

APP = Path(__file__).resolve().parents[3]
SNAPSHOT = json.loads((Path(__file__).parent / 'fixtures/gpt41_feature_vocabulary.json').read_text())
CROSSWALK = json.loads((APP / 'contracts/mappings/p5-gpt41-feature-encoding-v1.json').read_text())


def snapshot_classifier():
    categories = SNAPSHOT['categorical_values']
    cat = SimpleNamespace(named_steps={'onehot': SimpleNamespace(categories_=list(categories.values()))})
    prep = SimpleNamespace(named_transformers_={'cat': cat}, transformers_=[('cat', cat, list(categories))])
    return SimpleNamespace(feature_names_in_=SNAPSHOT['feature_columns'], named_steps={'preprocessor': prep})


def company(**overrides):
    return CompanyData(**{
        'company_name': 'Synthetic encoding test', 'sector_list': ['L'],
        'products_services': ['E'], 'subsidiaries_regions': ['EU'],
        'headquarters_country': 'Spain', 'num_subsidiaries_countries': 0,
        'employees_total': 375, 'annual_turnover_million_euro': 45,
        'juridic_form': 'PLC', 'stock_listed': False, 'reporting_currency': 'EUR',
        **overrides,
    })


def encode(**overrides):
    return serving.load_new_format_data(company(**overrides), serving.MODEL_PROFILES[SNAPSHOT['model_profile']], snapshot_classifier())


class FeatureEncodingTest(unittest.TestCase):
    def test_deployment_crosswalk_is_identical_to_contract(self):
        self.assertEqual((APP / 'web/data/p5-gpt41-feature-encoding-v1.json').read_bytes(),
                         (APP / 'contracts/mappings/p5-gpt41-feature-encoding-v1.json').read_bytes())

    def test_every_p5_product_option_has_explicit_semantic_mapping_or_missing_policy(self):
        php = (APP / 'web/app/Support/CharacterizationOptions.php').read_text()
        body = php.split('function productServiceTypes()')[1].split('function regions()')[0]
        self.assertEqual(set(re.findall(r"'([^']+)' =>", body)), set(CROSSWALK['products_by_p5_type']))
        for option, codes in CROSSWALK['products_by_p5_type'].items():
            with self.subTest(option=option):
                row, metadata = encode(products_services=codes)
                active = [c for c in SNAPSHOT['product_columns'] if row.loc[0, f'product_{c}'] == 1]
                unsupported = [c for c in codes if c not in SNAPSHOT['product_columns']]
                self.assertEqual(active, sorted(set(codes) - set(unsupported)))
                self.assertTrue(set(unsupported) <= set(CROSSWALK['unsupported_product_codes']))
                if unsupported:
                    self.assertEqual(metadata.derived_fields['encoding_coverage']['unsupported_values']['products_services'], unsupported)
                if not active:
                    self.assertIn('products_services', metadata.missing_required_fields)

    def test_every_catalogue_section_and_division_is_supported_or_explicitly_degraded(self):
        catalogue = json.loads((APP / 'web/data/nace_codes.json').read_text())
        sections = {item['code'][0] for item in catalogue}
        self.assertEqual(sections, set('ABCDEFGHIJKLMNOPQRSTUV'))
        self.assertEqual(sections - set(SNAPSHOT['sector_columns']), set(CROSSWALK['unsupported_sector_codes']))
        for item in catalogue:
            with self.subTest(code=item['code']):
                section = item['code'][0]
                row, metadata = encode(sector_list=[section])
                active = [c for c in SNAPSHOT['sector_columns'] if row.loc[0, f'sector_{c}'] == 1]
                self.assertEqual(active, [section] if section in SNAPSHOT['sector_columns'] else [])
                if not active:
                    self.assertIn('sector_list', metadata.missing_required_fields)
                    self.assertEqual(metadata.derived_fields['encoding_coverage']['unsupported_values']['sector_list'], [section])

    def test_bank_insurer_it_and_publishing_do_not_cross_taxonomies(self):
        for sector, expected in [('L', 'L'), ('64', 'L'), ('65', 'L'), ('Financial Services', 'L'),
                                 ('K', 'K'), ('62', 'K'), ('Information technology', 'K'), ('J', 'J'), ('58', 'J')]:
            with self.subTest(sector=sector):
                row, metadata = encode(sector_list=[sector])
                self.assertEqual([c for c in SNAPSHOT['sector_columns'] if row.loc[0, f'sector_{c}'] == 1], [expected])
                self.assertEqual(row.loc[0, 'product_E'], 1)
                self.assertEqual(row.loc[0, 'product_K'], 0)
                self.assertEqual(metadata.derived_fields['encoding_coverage']['status'], 'complete')

    def test_numeric_divisions_match_every_p5_catalogue_section(self):
        catalogue = json.loads((APP / 'web/data/nace_codes.json').read_text())
        divisions = {(item['code'][0], item['code'][1:].split('.')[0].zfill(2))
                     for item in catalogue if len(item['code']) > 1}
        for section, division in sorted(divisions):
            with self.subTest(section=section, division=division):
                row, metadata = encode(sector_list=[division])
                active = [c for c in SNAPSHOT['sector_columns'] if row.loc[0, f'sector_{c}'] == 1]
                self.assertEqual(active, [section] if section in SNAPSHOT['sector_columns'] else [])
                if not active:
                    self.assertEqual(metadata.derived_fields['encoding_coverage']['unsupported_values']['sector_list'], [section])

    def test_missing_products_never_inherit_sector_and_anomalous_R_is_not_health(self):
        for products in [[], ['R'], ['64'], ['invalid']]:
            row, metadata = encode(products_services=products)
            self.assertEqual(sum(row.loc[0, f'product_{c}'] for c in SNAPSHOT['product_columns']), 0)
            self.assertIn('products_services', metadata.missing_required_fields)

    def test_every_region_option_hits_real_columns_and_unknown_is_reported(self):
        php = (APP / 'web/app/Support/CharacterizationOptions.php').read_text()
        body = php.split('function regions()')[1].split('function valueChainPositions()')[0]
        self.assertEqual(set(re.findall(r"'([^']+)' =>", body)), set(CROSSWALK['regions_by_p5_region']))
        for option, codes in CROSSWALK['regions_by_p5_region'].items():
            row, metadata = encode(subsidiaries_regions=[option])
            self.assertEqual([c for c in SNAPSHOT['region_columns'] if row.loc[0, f'region_{c}'] == 1], sorted(codes))
            self.assertEqual(metadata.derived_fields['encoding_coverage']['status'], 'complete')
        _, metadata = encode(subsidiaries_regions=['unknown'])
        self.assertIn('subsidiaries_regions', metadata.missing_required_fields)
        row, metadata = encode(subsidiaries_regions=[], headquarters_country='United Kingdom')
        self.assertEqual(row.loc[0, 'region_EU'], 1)
        self.assertEqual(metadata.defaulted_fields['subsidiaries_regions'], ['EU'])

    def test_size_boundaries_follow_training_prompt_and_unseen_categories_are_explicit(self):
        cases = {0: 'NA', 1: 'MICRO', 9: 'MICRO', 10: 'SMALL', 49: 'SMALL', 50: 'MEDIUM',
                 150: 'MEDIUM', 249: 'MEDIUM', 250: 'LARGE', 375: 'LARGE', 750: 'LARGE',
                 1000: 'LARGE', 5000: 'LARGE', 5001: 'HUGE', 20000: 'HUGE', 20001: 'ULTRA'}
        for employees, size in cases.items():
            row, metadata = encode(employees_total=employees)
            self.assertEqual(row.loc[0, 'company_size'], size)
            coverage = metadata.derived_fields['encoding_coverage']
            self.assertEqual(coverage['status'], 'complete' if size in SNAPSHOT['categorical_values']['company_size'] else 'degraded')

    def test_unknown_legal_form_and_headquarters_are_not_fabricated(self):
        row, metadata = encode(juridic_form=None, headquarters_country='Other')
        self.assertEqual(row.loc[0, 'juridic_form'], 'UNKNOWN')
        self.assertEqual(metadata.derived_fields['encoding_coverage']['unsupported_values'],
                         {'headquarters_country': ['Other'], 'juridic_form': ['UNKNOWN']})

    def test_legacy_rev2_hierarchy_stays_separate(self):
        self.assertEqual(serving.normalize_sector_codes(['Financial Services'], legacy_nace=True), ['K'])
        self.assertEqual(serving.nace_hierarchy_candidates('641'), ['641', '64', 'K'])

    def test_sector_guard_receives_effective_rev21_columns_for_a_numeric_bank_input(self):
        classifier = snapshot_classifier()
        classifier.predict = lambda frame: [[1]]
        key = 'esrs_e1_climate_change_adaptation'
        policies = SimpleNamespace(score_threshold=0.95, label_thresholds=None,
                                   sector_guard=object(), crc_recall_floor=None,
                                   feedback_bandit=None)
        with (
            patch.object(serving, 'load_profile_classifier', return_value=classifier),
            patch.object(serving, 'load_profile_esrs_columns', return_value=[key]),
            patch.object(serving, 'load_serving_policies', return_value=policies),
            patch.object(serving, 'model_serving_identity', return_value={}),
            patch.object(serving, 'new_format_positive_scores', return_value=[1.0]),
            patch.object(serving, 'filter_new_format_runtime_predictions', return_value=([key], {})) as filter_predictions,
        ):
            serving.predict_esrs(company(model_profile=SNAPSHOT['model_profile'], sector_list=['64']))
        self.assertEqual(filter_predictions.call_args.kwargs['company_sectors'], ['L'])
