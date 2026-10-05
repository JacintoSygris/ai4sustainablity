import unittest
import pandas as pd
from learning_case_dataset import DatasetError, validate_long_rows, validate_matrices


def rows():
    return pd.DataFrame([dict(case_id='synthetic-case', case_hash='a'*64, company_group_key='synthetic-group',
                              period_key='2025', perimeter_key='entity-only', source_revision='r1',
                              source_kind='report', topic_id=t, value=v, observed_mask=m)
                         for t,v,m in [('101',1,1),('102',None,0)]], dtype=object)


class DatasetTests(unittest.TestCase):
    def test_missing_mask_and_outside_universe_are_rejected(self):
        for bad in [rows().drop(columns=['observed_mask']), rows().assign(topic_id=['101','103'])]:
            with self.assertRaises(DatasetError): validate_long_rows(bad, ['101','102'])

    def test_valid_report_preserves_absence_and_does_not_add_rows(self):
        out = validate_long_rows(rows(), ['101','102'])
        self.assertEqual(len(out),2); self.assertIsNone(out.loc[1,'value'])
        self.assertEqual(out.loc[1,'observed_mask'],0)

    def test_raw_labels_and_masks_reject_coercion(self):
        for column in ['value','observed_mask']:
            for bad in [True,False,'0',0.0,1.0,float('nan'),2]:
                frame=rows(); frame.loc[0,column]=bad
                with self.subTest(column=column,bad=repr(bad)), self.assertRaises(DatasetError):
                    validate_long_rows(frame,['101','102'])

    def test_human_null_mask_relationship_and_duplicate_rows(self):
        for mode in ['human','negative','observed_null','duplicate','extra']:
            f=rows()
            if mode=='human': f['source_kind']='human_product'
            if mode=='negative': f.loc[1,'value']=0
            if mode=='observed_null': f.loc[1,'observed_mask']=1
            if mode=='duplicate': f=pd.concat([f,f.iloc[:1]],ignore_index=True)
            if mode=='extra': f['extra']=1
            with self.subTest(mode=mode), self.assertRaises(DatasetError): validate_long_rows(f,['101','102'])

    def test_explicit_axes_order_uniqueness_and_complete_universe(self):
        for axis in [['102','101'],['101','101'],['101','102','103'],[]]:
            with self.assertRaises(DatasetError): validate_long_rows(rows(),axis)
        with self.assertRaises(DatasetError): validate_long_rows(rows().iloc[:1],['101','102'])

    def test_matrices_validate_without_filling_nulls(self):
        values=pd.DataFrame([[1,None]],index=['case'],columns=['101','102'],dtype=object)
        masks=pd.DataFrame([[1,0]],index=['case'],columns=['101','102'],dtype=object)
        y,m=validate_matrices(values,masks,['case'],['101','102'])
        self.assertIsNone(y.loc['case','102']); self.assertEqual(m.loc['case','102'],0)
        for bad in [values.assign(**{'102':0}),values.assign(**{'101':True}),values.assign(**{'101':1.0}),
                    values[['102','101']],values.rename(index={'case':'other'}),values.assign(extra=0)]:
            with self.assertRaises(DatasetError): validate_matrices(bad,masks,['case'],['101','102'])
        for bad in [masks.assign(**{'101':'1'}),masks.assign(**{'101':1.0}),masks.assign(**{'101':True})]:
            with self.assertRaises(DatasetError): validate_matrices(values,bad,['case'],['101','102'])

    def test_human_complete_and_metadata_and_synthetic_boundaries(self):
        f=rows(); f['source_kind']='human_product'; f.loc[1,'value']=0; f.loc[1,'observed_mask']=1
        out=validate_long_rows(f,['101','102'])
        out.loc[0,'value']=0
        self.assertEqual(f.loc[0,'value'],1)
        for bad in [f.assign(source_kind='synthetic'),f.assign(case_hash='wrong'),
                    f.assign(company_group_key=['g1','g2']),f.assign(source_revision=['r1','r2'])]:
            with self.assertRaises(DatasetError): validate_long_rows(bad,['101','102'])

    def test_matrix_nan_null_duplicate_and_empty_axes(self):
        y=pd.DataFrame([[1,None]],index=['case'],columns=['101','102'],dtype=object)
        m=pd.DataFrame([[1,0]],index=['case'],columns=['101','102'],dtype=object)
        for bad in [y.assign(**{'101':float('nan')}),y.assign(**{'101':None}),pd.concat([y,y]),y.astype(float)]:
            with self.assertRaises(DatasetError): validate_matrices(bad,m,['case'],['101','102'])
        for cases,topics in [([],['101','102']),(['case','case'],['101','102']),(['case'],['101','101'])]:
            with self.assertRaises(DatasetError): validate_matrices(y,m,cases,topics)

    def test_schema_diagnostics_are_sanitized(self):
        try:
            validate_long_rows(rows().assign(case_hash='invalid'),['101','102'])
        except DatasetError as error:
            self.assertEqual(str(error),'learning_dataset.schema_invalid')
            self.assertTrue(error.__suppress_context__)
        else: self.fail('invalid hash accepted')


if __name__ == '__main__': unittest.main()

# Synthetic public transport vectors; native issuer qualification is HOST-owned.
import json
from pathlib import Path
from datetime import datetime
import learning_case_dataset as integrated

def t08_sample():
    from learning_portable_transport_helper import bundle
    return bundle()

def t08_build(bundle=None, state=None, **kwargs):
    b=bundle or t08_sample()
    now=datetime.fromisoformat(json.loads(b['manifest_json'])['issued_at'])
    return integrated.build_authorized_dataset(b['jsonl'],b['manifest_json'],b['bindings_json'],
        state={} if state is None else state,now=now,trusted_local_launcher=True,synthetic_only=True,
        namespace='test-namespace:t07-export',**kwargs)

class AuthorizedProjectionTests(unittest.TestCase):
    def test_native_issuer_projection_joins_persisted_x_and_masks(self):
        b=t08_sample(); out=t08_build(b); line=json.loads(b['jsonl'])
        self.assertEqual(out['X'].iloc[0].to_dict(),line['X']['values'])
        self.assertEqual(out['Y'].iloc[0].tolist(),[1,0,None])
        self.assertEqual(out['masks'].iloc[0].tolist(),[1,1,0])
        self.assertEqual(out['lineage'][0]['source_revisions'],line['source_revisions'])
        self.assertNotEqual(line['source_revisions']['p6']['digest'],line['reference']['expected_revisions']['p6_base']['digest'])

    def test_default_deny_and_client_digest_is_not_authenticity(self):
        b=t08_sample(); now=datetime.fromisoformat(json.loads(b['manifest_json'])['issued_at'])
        with self.assertRaises(DatasetError): integrated.build_authorized_dataset(b['jsonl'],b['manifest_json'],b['bindings_json'],state={},now=now)

    def test_raw_projection_types_duplicates_and_extra_keys_fail(self):
        from learning_case_contracts import canonical_json
        import hashlib, copy
        b=t08_sample()
        for field,bad in [('value',True),('value','1'),('observed_mask',True),('value',1.0)]:
            c=copy.deepcopy(b); line=json.loads(c['jsonl']);line['topic_labels'][0][field]=bad
            c['jsonl']=json.dumps(line,separators=(',',':'))+'\n'
            binding=json.loads(c['bindings_json']);binding['cases'][0]['export_digest']=hashlib.sha256(c['jsonl'].encode()).hexdigest();c['bindings_json']=json.dumps(binding)
            with self.subTest(field=field,bad=bad),self.assertRaises(DatasetError):t08_build(c)
        for raw in [b['jsonl'].replace('"schema_version":','"extra":1,"schema_version":',1),b['jsonl'].replace('"synthetic_only":true','"synthetic_only":true,"synthetic_only":true',1)]:
            c=dict(b,jsonl=raw)
            with self.assertRaises(DatasetError):t08_build(c)

    def test_feature_versions_vocabulary_and_binding_cannot_drift(self):
        import copy,hashlib
        b=t08_sample()
        for variant in ['version','vocab','digest','p6','rights']:
            c=copy.deepcopy(b);line=json.loads(c['jsonl']);binding=json.loads(c['bindings_json'])
            if variant=='version':line['X']['transform_version']='other'
            if variant=='vocab':line['X']['values']['headquarters_country']='UNKNOWN'
            if variant=='digest':line['X']['digest']='0'*64
            if variant=='p6':binding['cases'][0]['source_revisions']['p6']=line['reference']['expected_revisions']['p6_base']
            if variant=='rights':binding['cases'][0]['rights_digest']='0'*64
            c['jsonl']=json.dumps(line,separators=(',',':'))+'\n';binding['cases'][0]['export_digest']=hashlib.sha256(c['jsonl'].encode()).hexdigest();c['bindings_json']=json.dumps(binding)
            with self.subTest(variant=variant),self.assertRaises(DatasetError):t08_build(c)
# Controlled launcher mutation fixtures: synthetic derivatives, no report producer claim.
def t08_variant_bundle(mode):
    import copy,hashlib
    from learning_case_contracts import manifest_digest
    b=copy.deepcopy(t08_sample());p=json.loads(b['jsonl']);bind=json.loads(b['bindings_json']);manifest=json.loads(b['manifest_json'])
    if mode=='repeat': b['jsonl']+=b['jsonl'];return b
    q=copy.deepcopy(p);binding=copy.deepcopy(bind['cases'][0]);entry=copy.deepcopy(manifest['cases'][0])
    q['reference']['case_id']='synthetic-duplicate';q['receipt']['case_id']='synthetic-duplicate'
    binding['case_id']=entry['case_id']='synthetic-duplicate'
    if mode=='conflict':q['topic_labels'][0]['value']=0
    if mode=='authority':q['authority']['mapping_digest']='0'*64;binding['authority']['mapping_digest']='0'*64
    if mode=='report':
        binding['source_kind']='report';q['topic_labels'][1]['observed_mask']=0;q['topic_labels'][1]['value']=None
        b['jsonl']='';bind['cases']=[];manifest['cases']=[];manifest['eligible_case_ids']=[]
    raw=json.dumps(q,separators=(',',':'))+'\n';binding['export_digest']=hashlib.sha256(raw.encode()).hexdigest()
    b['jsonl']+=raw;bind['cases'].append(binding);manifest['cases'].append(entry);manifest['eligible_case_ids'].append(entry['case_id'])
    manifest['canonical_digest']=manifest_digest(json.dumps(manifest));bind['manifest_digest']=manifest['canonical_digest']
    b['manifest_json']=json.dumps(manifest);b['bindings_json']=json.dumps(bind);return b

class AuthorizedDedupeTests(unittest.TestCase):
    def test_identical_duplicate_collapses_by_group_period_perimeter(self):
        for mode in ['repeat','identical']:
            out=t08_build(t08_variant_bundle(mode));self.assertEqual(len(out['Y']),1)
            self.assertEqual(out['quarantine'],[])

    def test_conflicting_current_group_is_quarantined_without_newest_wins(self):
        out=t08_build(t08_variant_bundle('conflict'))
        self.assertEqual(len(out['Y']),0);self.assertEqual(len(out['quarantine']),2)

    def test_full_authority_incompatible_is_rejected(self):
        with self.assertRaises(DatasetError):t08_build(t08_variant_bundle('authority'))

    def test_transparently_synthetic_report_preserves_unobserved_and_outside_null(self):
        out=t08_build(t08_variant_bundle('report'));self.assertEqual(out['Y'].iloc[0].tolist(),[1,None,None])
        self.assertEqual(out['masks'].iloc[0].tolist(),[1,0,0]);self.assertEqual(out['lineage'][0]['source_kind'],'report')
def t08_advance(bundle, generation):
    import copy
    from learning_case_contracts import manifest_digest
    b=copy.deepcopy(bundle);m=json.loads(b['manifest_json']);m['generation']=generation
    m['canonical_digest']=manifest_digest(json.dumps(m));bindings=json.loads(b['bindings_json']);bindings['manifest_digest']=m['canonical_digest']
    b['manifest_json']=json.dumps(m);b['bindings_json']=json.dumps(bindings);return b

class AuthorizedFreshnessTests(unittest.TestCase):
    def test_native_withdrawal_before_publication_invalidates_lineage(self):
        from learning_portable_transport_helper import withdrawal
        sample=withdrawal()
        state={};dataset=t08_build(sample['build'],state)
        publication=sample['publication'];now=datetime.fromisoformat(json.loads(publication['manifest_json'])['issued_at'])
        result=integrated.revalidate_authorized_dataset(dataset,publication['manifest_json'],publication['bindings_json'],
            state=state,now=now,trusted_local_launcher=True,synthetic_only=True,namespace='test-namespace:t07-export')
        self.assertFalse(result['publication_allowed']);self.assertEqual(result['purge_execution'],'NOT_RUN')
        self.assertEqual(result['purge_inventory'][0]['dataset_digest'],dataset['dataset_digest'])
        self.assertIn(dataset['dataset_digest'],state['invalidated'])

    def test_accumulated_tombstone_and_noncurrent_exclusion_survive_new_manifest(self):
        from learning_case_contracts import manifest_digest
        import copy
        original=t08_sample();state={};dataset=t08_build(original,state);id=dataset['lineage'][0]['case_id']
        removed=t08_advance(original,2);m=json.loads(removed['manifest_json']);bind=json.loads(removed['bindings_json'])
        m['cases']=[];m['eligible_case_ids']=[];m['tombstones']['revoked']=[{'case_id':id,'case_hash':dataset['lineage'][0]['case_hash'],'at':m['issued_at']}]
        m['canonical_digest']=manifest_digest(json.dumps(m));bind['manifest_digest']=m['canonical_digest'];bind['cases']=[]
        removed['manifest_json']=json.dumps(m);removed['bindings_json']=json.dumps(bind)
        self.assertEqual(len(t08_build(removed,state)['Y']),0)
        self.assertEqual(len(t08_build(t08_advance(original,3),state)['Y']),0)
        self.assertIn(id,state['tombstones'])
        state2={};t08_build(original,state2);m['tombstones']['revoked']=[];m['canonical_digest']=manifest_digest(json.dumps(m));bind['manifest_digest']=m['canonical_digest']
        removed['manifest_json']=json.dumps(m);removed['bindings_json']=json.dumps(bind)
        t08_build(removed,state2);self.assertEqual(len(t08_build(t08_advance(original,3),state2)['Y']),0)

    def test_replay_future_expired_are_rejected_without_state_mutation(self):
        import copy
        from learning_case_contracts import manifest_digest
        state={};b=t08_sample();t08_build(b,state);before=copy.deepcopy(state)
        with self.assertRaises(DatasetError):t08_build(b,state)
        self.assertEqual(state,before)
        for key,time in [('issued_at','2099-01-01T00:00:00Z'),('valid_until','2000-01-01T00:00:00Z')]:
            c=t08_advance(b,2);m=json.loads(c['manifest_json']);m[key]=time;m['canonical_digest']=manifest_digest(json.dumps(m));bind=json.loads(c['bindings_json']);bind['manifest_digest']=m['canonical_digest']
            now=datetime.fromisoformat(json.loads(b['manifest_json'])['issued_at'])
            with self.assertRaises(DatasetError):integrated.build_authorized_dataset(c['jsonl'],json.dumps(m),json.dumps(bind),state=state,now=now,trusted_local_launcher=True,synthetic_only=True,namespace='test-namespace:t07-export')
            self.assertEqual(state,before)

    def test_fresh_equal_eligibility_revalidation_and_default_deny(self):
        b=t08_sample();state={};dataset=t08_build(b,state);fresh=t08_advance(b,2);now=datetime.fromisoformat(json.loads(b['manifest_json'])['issued_at'])
        with self.assertRaises(DatasetError):integrated.revalidate_authorized_dataset(dataset,fresh['manifest_json'],fresh['bindings_json'],state=state,now=now)
        r=integrated.revalidate_authorized_dataset(dataset,fresh['manifest_json'],fresh['bindings_json'],state=state,now=now,trusted_local_launcher=True,synthetic_only=True,namespace='test-namespace:t07-export')
        self.assertTrue(r['publication_allowed']);self.assertFalse(r['promotion_allowed'])

    def test_missing_eligible_export_is_corruption_not_empty_dataset(self):
        b=t08_sample();b['jsonl']=''
        with self.assertRaises(DatasetError):t08_build(b)
class AuthorizedDerivativeIntegrityTests(unittest.TestCase):
    def test_mutated_derivative_cannot_pass_fresh_publication_check(self):
        b=t08_sample();state={};d=t08_build(b,state);d['Y'].iloc[0,0]=0;fresh=t08_advance(b,2)
        with self.assertRaises(DatasetError):integrated.revalidate_authorized_dataset(d,fresh['manifest_json'],fresh['bindings_json'],state=state,now=datetime.fromisoformat(json.loads(b['manifest_json'])['issued_at']),trusted_local_launcher=True,synthetic_only=True,namespace='test-namespace:t07-export')

class AuthorizedFreshMembershipTests(unittest.TestCase):
    def test_conflicting_addition_invalidates_old_dedupe_derivative(self):
        state = {}; old = t08_build(state=state)
        fresh = t08_advance(t08_variant_bundle('conflict'), 2)
        rebuilt = t08_build(fresh)
        self.assertEqual(len(rebuilt['Y']), 0)
        self.assertEqual(len(rebuilt['quarantine']), 2)
        result = integrated.revalidate_authorized_dataset(old, fresh['manifest_json'], fresh['bindings_json'],
            state=state, now=datetime.fromisoformat(json.loads(fresh['manifest_json'])['issued_at']),
            trusted_local_launcher=True, synthetic_only=True, namespace='test-namespace:t07-export')
        self.assertFalse(result['publication_allowed'])
        self.assertEqual(result['purge_execution'], 'NOT_RUN')
        self.assertEqual(result['purge_inventory'][0]['dataset_digest'], old['dataset_digest'])
        self.assertEqual(result['purge_inventory'][0]['case_ids'], [old['lineage'][0]['case_id']])
        self.assertIn(old['dataset_digest'], state['invalidated'])

    def test_identical_and_compatible_additions_conservatively_deny_old_derivative(self):
        import hashlib
        for mode in ('identical', 'compatible'):
            with self.subTest(mode=mode):
                state = {}; old = t08_build(state=state)
                fresh = t08_advance(t08_variant_bundle('identical'), 2)
                if mode == 'compatible':
                    lines = fresh['jsonl'].splitlines(); added = json.loads(lines[1])
                    added['company_group_key'] = '0' * 64
                    lines[1] = json.dumps(added, separators=(',', ':'))
                    fresh['jsonl'] = '\n'.join(lines) + '\n'
                    bindings = json.loads(fresh['bindings_json'])
                    bindings['cases'][1]['export_digest'] = hashlib.sha256((lines[1] + '\n').encode()).hexdigest()
                    fresh['bindings_json'] = json.dumps(bindings)
                rebuilt = t08_build(fresh)
                self.assertEqual(len(rebuilt['Y']), 1 if mode == 'identical' else 2)
                self.assertEqual(rebuilt['quarantine'], [])
                result = integrated.revalidate_authorized_dataset(old, fresh['manifest_json'], fresh['bindings_json'],
                    state=state, now=datetime.fromisoformat(json.loads(fresh['manifest_json'])['issued_at']),
                    trusted_local_launcher=True, synthetic_only=True, namespace='test-namespace:t07-export')
                self.assertFalse(result['publication_allowed'])
                self.assertEqual(result['purge_execution'], 'NOT_RUN')
                self.assertEqual(result['purge_inventory'][0]['dataset_digest'], old['dataset_digest'])

    def test_addition_invalidation_survives_return_to_original_membership(self):
        original = t08_sample(); state = {}; old = t08_build(original, state)
        for fresh in (t08_advance(t08_variant_bundle('conflict'), 2), t08_advance(original, 3)):
            result = integrated.revalidate_authorized_dataset(old, fresh['manifest_json'], fresh['bindings_json'],
                state=state, now=datetime.fromisoformat(json.loads(fresh['manifest_json'])['issued_at']),
                trusted_local_launcher=True, synthetic_only=True, namespace='test-namespace:t07-export')
            self.assertFalse(result['publication_allowed'])
            self.assertEqual(result['purge_execution'], 'NOT_RUN')
            self.assertEqual(result['purge_inventory'][0]['dataset_digest'], old['dataset_digest'])
            self.assertIn(old['dataset_digest'], state['invalidated'])

    def test_shared_build_invalidates_old_but_allows_fresh_consistent_derivative(self):
        state = {}; old = t08_build(state=state)
        fresh = t08_advance(t08_variant_bundle('identical'), 2)
        rebuilt = t08_build(fresh, state)
        self.assertEqual(len(rebuilt['Y']), 1)
        self.assertEqual(rebuilt['quarantine'], [])
        self.assertIn(old['dataset_digest'], state['invalidated'])
        for dataset, generation, allowed in ((old, 3, False), (rebuilt, 4, True)):
            current = t08_advance(fresh, generation)
            result = integrated.revalidate_authorized_dataset(dataset, current['manifest_json'], current['bindings_json'],
                state=state, now=datetime.fromisoformat(json.loads(current['manifest_json'])['issued_at']),
                trusted_local_launcher=True, synthetic_only=True, namespace='test-namespace:t07-export')
            self.assertEqual(result['publication_allowed'], allowed)
            self.assertFalse(result['promotion_allowed'])
            self.assertEqual(result['purge_execution'], 'NOT_RUN')
            self.assertEqual(result['purge_inventory'][0]['dataset_digest'], old['dataset_digest'])
        self.assertNotIn(rebuilt['dataset_digest'], state['invalidated'])
