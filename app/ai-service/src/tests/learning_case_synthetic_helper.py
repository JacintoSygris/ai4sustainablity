"""Isolated test fixtures only: no export, production policy or promotion authority."""
import hashlib
import json


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False,
                                    separators=(',', ':'), allow_nan=False).encode()).hexdigest()


def seal(case):
    case['case_hash'] = digest({k: v for k, v in case.items() if k != 'case_hash'})
    return case


def standalone_fixture(source_kind='human_product'):
    authority = dict(framework_version='esrs-2023', catalog_version='ar16-v1',
                     catalog_digest='a'*64, mapping_version='ar16-python-v1', mapping_digest='b'*64)
    revisions = {p: dict(generation=i, revision=i+1, digest=d*64)
                 for i, (p, d) in enumerate(zip(('p5','p6','p8','p9'), 'def0'), 1)}
    case = dict(schema_version='learning-case-v1', case_id='case-001', case_hash='1'*64,
                company_group_key='group-pseudonymous-001',
                period_scope=dict(period_key='2025', perimeter_key='entity-only'), authority=authority.copy(),
                provenance=dict(source_kind=source_kind, source_record_digest='c'*64, source_revision='source-r1'),
                source_revisions=revisions, p5_snapshot=dict(schema_version='p5-learning-input-v1', digest='4'*64),
                p6_snapshot=dict(model_profile='candidate-profile', model_digest='5'*64, policy_digest='6'*64),
                topic_universe=dict(reviewed_topic_ids=['101','102'], outside_scope_topic_ids=['103']),
                topic_labels=[dict(topic_id='101', value=1, observed_mask=1), dict(topic_id='102', value=0, observed_mask=1)],
                datapoint_universe=dict(reviewed_datapoint_ids=['E1.IRO-1_01'], outside_scope_datapoint_ids=['E1.IRO-1_02']),
                datapoint_decisions=[dict(datapoint_id='E1.IRO-1_01', relevant=True, selected_to_answer=False,
                                         reason_codes=['scope'], note=None)],
                rights=dict(policy_version='learning-rights-v1', policy_digest='7'*64, policy_status='approved',
                            state='granted', authorization_generation=4),
                closure_evidence=dict(declaration_version='technical-closure-v1', declaration_status='accepted',
                                      reviewed_universe=True, final_for_period_scope=True,
                                      server_actor_id='system:laravel', recorded_at='2026-10-02T09:00:00Z'))
    authority.update(topic_ids=['101','102','103'], datapoint_ids=['E1.IRO-1_01','E1.IRO-1_02'],
                     ambiguous_topic_ids=[], ambiguous_datapoint_ids=[])
    return dict(namespace='standalone-synthetic-only', synthetic_only=True,
                promotion_allowed=False, case=seal(case), authority=authority)


def manifest_fixture():
    case = standalone_fixture()['case']
    result = dict(schema_version='learning-eligibility-v1', generation=8,
                  issued_at='2026-10-02T09:00:00Z', valid_until='2026-10-02T10:00:00Z',
                  cases=[dict(case_id='case-001', case_hash='1'*64, source_revisions=case['source_revisions'],
                              rights_digest='6'*64, policy_digest='7'*64)], eligible_case_ids=['case-001'],
                  tombstones=dict(revoked=[dict(case_id='case-revoked', case_hash='2'*64, at='2026-10-02T08:00:00Z')],
                                  deleted=[dict(case_id='case-deleted', case_hash='3'*64, at='2026-10-02T08:30:00Z')]),
                  rights_snapshot_digest='8'*64, eligibility_policy_digest='9'*64)
    result['canonical_digest'] = digest(result)
    return result
