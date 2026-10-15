"""Strict JSON and contract validation for the supervised production feedback dataset.

Public layout: only the production contract consumed by learning_production_cli
and the trainer. Laravel exports pseudonymous, consented rows; company names,
identifiers and free text are rejected. No file conversion, fitting or network.
"""
import json
import re


def require(ok):
    if not ok:
        raise ValueError('Contract validation failed (input values suppressed)')

def exact(obj, keys):
    require(type(obj) is dict and set(obj) == set(keys))

def pairs(items):
    result = {}
    for (k, v) in items:
        require(k not in result)
        result[k] = v
    return result

def loads(s):

    def invalid(_):
        raise ValueError('Nonfinite JSON rejected')
    return json.loads(s, object_pairs_hook=pairs, parse_constant=invalid)

def integer(v, lo=0, hi=2 ** 63 - 1):
    require(type(v) is int and lo <= v <= hi)


def production_rows(document, mapping_document, *, synthetic_only=True):
    import uuid
    from learning_serving_adapter import company_type
    CompanyData = company_type()
    exact(document, ['schema', 'rows', 'rejected_rows'])
    require(document['schema'] == 'learning-feedback-production-v1')
    require(type(document['rows']) is list and 1 <= len(document['rows']) <= 5000)
    integer(document['rejected_rows'])
    axes = {}
    for entry in mapping_document['keys']:
        if entry.get('status') == 'approved' and entry.get('ar16_topic_ids'):
            key = entry['python_esrs_key']
            require(re.fullmatch(r'esrs_[a-z0-9_]+', key) is not None)
            axes[key] = [str(topic) for topic in entry['ar16_topic_ids']]
    require(bool(axes))
    seen = set()
    for row in document['rows']:
        exact(row, ['subject', 'group', 'authority_binding', 'synthetic', 'consent_version', 'p5_sha256', 'generation',
                    'p8_revision', 'p9_revision', 'input', 'topics', 'p9'])
        require(str(uuid.UUID(row['subject'])) == row['subject'] and row['subject'] not in seen)
        seen.add(row['subject'])
        require(re.fullmatch(r'[a-f0-9]{64}', row['authority_binding']) is not None)
        require(re.fullmatch(r'[a-f0-9]{64}', row['group']) is not None)
        require(type(row['synthetic']) is bool and (not synthetic_only or row['synthetic']))
        require(row['consent_version'] == 'learning-feedback-bandit-2026-10-08')
        require(re.fullmatch(r'[a-f0-9]{64}', row['p5_sha256']) is not None)
        integer(row['generation']); integer(row['p8_revision'], 1)
        require(row['p9_revision'] is None or type(row['p9_revision']) is int)
        require(type(row['input']) is dict and set(row['input']) <= set(CompanyData.model_fields))
        require(row['input'].get('company_name') == 'pseudonymous')
        require(not row['input'].get('juridic_form'))
        require(row['input'].get('model_profile') == 'new_format_732_v1_gpt41')
        CompanyData.model_validate(row['input'])
        require(type(row['topics']) is dict)
        for topic, cell in row['topics'].items():
            require(re.fullmatch(r'[1-9][0-9]*', topic) is not None)
            exact(cell, ['value', 'mask'])
            require(type(cell['mask']) is int and cell['mask'] in (0, 1))
            require((cell['mask'] == 0 and cell['value'] is None) or
                    (cell['mask'] == 1 and type(cell['value']) is int and cell['value'] in (0, 1)))
        require(type(row['p9']) is list)
        for decision in row['p9']:
            exact(decision, ['datapoint_id', 'relevant', 'selected_to_answer'])
            require(type(decision['datapoint_id']) is str and len(decision['datapoint_id']) <= 256)
            require(type(decision['relevant']) is bool and type(decision['selected_to_answer']) is bool)
    return document['rows'], axes
