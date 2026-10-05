"""Synthetic transport vectors only; never an operational issuer receipt."""
import copy
import hashlib
import json


def encode(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False)


def digest(value):
    return hashlib.sha256(encode(value).encode()).hexdigest()


def bundle(count=1):
    authority = dict(framework_version='synthetic-v1', catalog_version='synthetic-v1',
                     catalog_digest='a'*64, mapping_version='synthetic-v1', mapping_digest='b'*64)
    declared = dict(authority, topic_ids=['1','2','3'], datapoint_ids=[],
                    ambiguous_topic_ids=[], ambiguous_datapoint_ids=[])
    vocab = dict(employee_count_range=['1_9','10_49','50_249','250_499','500_999','1000_plus','not_sure'],
                 headquarters_country=['Spain','Portugal','France','Germany','Italy','United Kingdom','United States','Other'])
    lines, bindings, cases = [], [], []
    for i in range(count):
        date = f'2025-01-{i+1:02d}T00:00:00.000000Z'
        revisions = {p: dict(generation=1,revision=1,digest=d*64) for p,d in zip(('p5','p6','p8','p9'),'cdef')}
        headers = {p: dict(revisions['p6' if p=='p6_base' else p],epoch='1'*64) for p in ('p5','p6_base','p8','p9')}
        headers['p6_base']['digest']='2'*64
        ref=dict(case_id=f'synthetic-{i:03d}',case_hash=digest(['case',i]),expected_revisions=headers,
                 source_token=digest(['token',i]),expected_authorization_generation=1)
        values=dict(employee_count_range='10_49' if i%2 else '1_9',headquarters_country='Spain',stock_listed=bool(i%2))
        receipt=dict(schema_version='learning-case-closure-receipt-v1',case_id=ref['case_id'],case_hash=ref['case_hash'],
                     source_token=ref['source_token'],p5_completion_reference='3'*64,authorization_generation=1,
                     authorization_digest='4'*64,recorded_at=date,provenance='synthetic-only',promotion_allowed=False,receipt_hash='5'*64)
        line=dict(schema_version='learning-case-export-v1',namespace='test-namespace:t07-export',synthetic_only=True,
                  promotion_allowed=False,reference=ref,company_group_key=digest(['group',i]),
                  period_scope=dict(period_key='6'*64,perimeter_key='7'*64),authority=authority,source_revisions=revisions,
                  provenance=dict(synthetic_only=True,source_record_digest='8'*64),receipt=receipt,
                  X=dict(schema_version='learning-p5-features-v1',input_schema_version='p5-learning-input-v1',
                         transform_version='p5-small-categorical-v1',digest=digest(values),values=values),
                  topic_labels=[dict(topic_id='1',value=1 if count==1 else i%2,observed_mask=1),
                                dict(topic_id='2',value=0 if count==1 else 1-i%2,observed_mask=1)],
                  topic_universe=dict(reviewed_topic_ids=['1','2'],outside_scope_topic_ids=['3']),
                  p9_feedback=dict(universe=dict(reviewed_datapoint_ids=[],outside_scope_datapoint_ids=[]),decisions=[]))
        raw=encode(line)+'\n';lines.append(raw)
        entry=dict(case_id=ref['case_id'],case_hash=ref['case_hash'],source_revisions=revisions,rights_digest='4'*64,policy_digest='9'*64)
        cases.append(entry)
        bindings.append(dict(entry,export_digest=hashlib.sha256(raw.encode()).hexdigest(),source_kind='human_product',
                             source_revision='synthetic-v1',authorization_generation=1,authority=declared,feature_vocab=vocab))
    manifest=dict(schema_version='learning-eligibility-v1',generation=1,issued_at='2026-10-02T09:00:00.000000Z',
                  valid_until='2026-10-02T10:00:00.000000Z',cases=cases,eligible_case_ids=[c['case_id'] for c in cases],
                  tombstones=dict(revoked=[],deleted=[]),rights_snapshot_digest='a'*64,eligibility_policy_digest='b'*64)
    manifest['canonical_digest']=digest(manifest)
    binding=dict(schema_version='learning-t07-bindings-v1',namespace='test-namespace:t07-export',synthetic_only=True,
                 promotion_allowed=False,purpose='synthetic:learning',manifest_digest=manifest['canonical_digest'],cases=bindings)
    return dict(jsonl=''.join(lines),manifest_json=encode(manifest),bindings_json=encode(binding))


def request():
    return dict(bundle=bundle(24),state={},token=dict(batch_id='a'*32,fence=1),context_digest='b'*64,
                cutoffs=dict(train_end='2025-01-13T00:00:00Z',calibration_end='2025-01-17T00:00:00Z',holdout_end='2025-02-01T00:00:00Z'))


def withdrawal():
    initial=bundle();fresh=copy.deepcopy(initial);manifest=json.loads(fresh['manifest_json']);binding=json.loads(fresh['bindings_json'])
    manifest['generation']=2;manifest['eligible_case_ids']=[]
    manifest['tombstones']['revoked']=[dict(case_id=manifest['cases'][0]['case_id'],case_hash=manifest['cases'][0]['case_hash'],at=manifest['issued_at'])]
    manifest['cases']=[];binding['cases']=[]
    manifest.pop('canonical_digest');manifest['canonical_digest']=digest(manifest);binding['manifest_digest']=manifest['canonical_digest']
    fresh.update(manifest_json=encode(manifest),bindings_json=encode(binding))
    return dict(build=initial,publication=fresh)
