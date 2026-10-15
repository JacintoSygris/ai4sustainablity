"""Offline synthetic evaluation. No quality claim without independent human label review."""
import argparse
import json
from pathlib import Path
from services.dma_processing import load_model, process, canonical, digest, ROOT, LEVELS

FIXTURES = ROOT / 'ai-service/src/tests/fixtures/dma_processing'
GATES = {'datapoint_recall_at_3_per_language': .80, 'emitted_candidate_precision': .60,
         'determinate_coverage': .75, 'expected_abstention_success': .90, 'all_e5_contrasts': True}


def metrics(cases, predictions, level):
    true_positive = emitted = expected = determinate = abstained = correct_abstentions = positive_cases = abstention_cases = 0
    for case in cases:
        ids = predictions.get(case['id'], {}).get(level, [])[:3]
        gold = set(case['expected'][level]); predicted = set(ids)
        true_positive += len(gold & predicted); emitted += len(predicted); expected += len(gold)
        abstained += not ids
        if case['expected_abstention']:
            abstention_cases += 1; correct_abstentions += not ids
        else:
            positive_cases += 1; determinate += bool(ids)
    return {'n': len(cases), 'positive_cases': positive_cases, 'expected_abstention_cases': abstention_cases,
            'true_positive': true_positive, 'emitted': emitted, 'expected': expected, 'abstentions': abstained,
            'recall_at_3': true_positive / expected if expected else 0., 'precision': true_positive / emitted if emitted else 0.,
            'determinate_coverage': determinate / positive_cases if positive_cases else 0.,
            'expected_abstention_success': correct_abstentions / abstention_cases if abstention_cases else 0.}


def gate(cases, predictions):
    per_language = {lang: metrics([c for c in cases if c['locale'] == lang], predictions, 'datapoint') for lang in ('es','en')}
    summary = metrics(cases, predictions, 'datapoint')
    contrasts = [case for case in cases if 'e5_indicator_contrasts' in case['units']]
    checks = {'recall_es': per_language['es']['recall_at_3'] >= .8, 'recall_en': per_language['en']['recall_at_3'] >= .8,
              'precision': summary['precision'] >= .6, 'coverage': summary['determinate_coverage'] >= .75,
              'abstention': summary['expected_abstention_success'] >= .9,
              'e5_contrasts': len(contrasts) >= 6 and all(set(case['expected']['datapoint']) <= set(predictions.get(case['id'], {}).get('datapoint', [])) for case in contrasts)}
    return {'checks': checks, 'passed': all(checks.values())}


def evaluate(split, output, model_dir=None, policy=None):
    model = load_model(model_dir); raw = (FIXTURES / (split+'.json')).read_bytes(); fixture = json.loads(raw); cases = fixture['cases']
    policy = policy or FIXTURES / 'evaluation-policy.json'
    frozen_raw = policy.read_bytes()
    frozen = json.loads(frozen_raw)
    if (frozen['model_sha256'] != model['manifest']['model_sha256']
            or frozen['manifest_sha256'] != digest(canonical(model['manifest'])) or frozen['gates'] != GATES):
        raise ValueError('evaluation_not_frozen')
    for part in ('development','heldout'):
        if digest((FIXTURES / (part+'.json')).read_bytes()) != frozen['fixture_sha256'][part]:
            raise ValueError('fixture_not_frozen')
    mapping = json.loads((ROOT / 'web/data/ar16_to_esrs_dr_mapping_esrs2023_v1.json').read_text())
    mapped = {str(row['ar16_topic_id']): row['disclosure_requirements'] for row in mapping['mappings']}
    docs = model['docs']; predictions = {name:{} for name in ('hybrid','word_only','declared_topic_map')}; details=[]
    for case in cases:
        request = {'version':'dma-processing-v1', 'locale':case['locale'], 'iros':[{'iro_id':digest(case['id'].encode()), 'source_sha256':digest(raw), 'description':case['description']}]}
        for name in ('hybrid','word_only'):
            result = process(request, model, word_only=name == 'word_only')
            predictions[name][case['id']] = {level:[x['id'] for x in result['iros'][0]['levels'][level]['candidates']] for level in LEVELS}
            if name == 'hybrid': details.append({'case_id':case['id'], 'response':result, 'expected':case['expected']})
        drs = sorted(mapped[case['declared_topic']]); ids=sorted(doc['id'] for doc in docs['datapoint'] if doc['dr'] in drs)
        predictions['declared_topic_map'][case['id']] = {'topic':[case['declared_topic']], 'dr':drs[:3], 'datapoint':ids[:3]}
    report = {'synthetic':True,'label_status':fixture['label_status'], 'quality_validated':False,
              'split':split,'fixture_sha256':digest(raw),'freeze_sha256':digest(frozen_raw),
              'producer_sha256':{name:digest((ROOT/name).read_bytes()) for name in ['ai-service/public_runtime/services/dma_processing.py','ai-service/public_runtime/services/dma_processing_schema.py','ai-service/scripts/dma_processing/evaluate.py']},
              'manifest':model['manifest'],'gate_scope':'datapoint','gates':GATES,'gate_result':gate(cases,predictions['hybrid']),
              'definitions':{'recall':'micro intersection/gold at k=3; zero for empty gold', 'precision':'all emitted candidates, including negative cases',
                'coverage':'positive reference cases with at least one emission / positive reference cases', 'abstention':'all candidates absent on expected-abstention cases',
                'declared_topic_map':'map expansion has no textual ranking: canonical lexicographic first three, no abstention'},
              'arms':{},'cases':details}
    for arm,predicted in predictions.items():
        report['arms'][arm] = {'metrics':{level:{lang:metrics([c for c in cases if c['locale']==lang],predicted,level) for lang in ('es','en')} for level in LEVELS}, 'predictions':predicted}
    report['per_level_failures'] = {level:[{'case_id':c['id'], 'missing':sorted(set(c['expected'][level])-set(predictions['hybrid'][c['id']][level])), 'unexpected':sorted(set(predictions['hybrid'][c['id']][level])-set(c['expected'][level]))} for c in cases if set(c['expected'][level]) != set(predictions['hybrid'][c['id']][level])] for level in LEVELS}
    report['acceptance'] = {'human_labels_reviewed':False, 'laravel_python_review_p9_executed':False, 'quality_claims':[]}
    report['failures'] = [c['id'] for c in cases if (bool(predictions['hybrid'][c['id']]['datapoint']) if c['expected_abstention'] else not set(c['expected']['datapoint']) <= set(predictions['hybrid'][c['id']]['datapoint']))]
    output.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({'split':split,'cases':len(cases),'gate':report['gate_result'],'failures':report['failures']}))

if __name__ == '__main__':
    parser=argparse.ArgumentParser(); parser.add_argument('--split',choices=['development','heldout'],required=True); parser.add_argument('--output',type=Path,required=True)
    parser.add_argument('--model-dir',type=Path,required=True)
    parser.add_argument('--policy',type=Path,default=FIXTURES / 'evaluation-policy.json')
    args=parser.parse_args()
    if args.output.resolve().is_relative_to(ROOT.parent):
        parser.error('--output must be outside the checkout')
    evaluate(args.split,args.output,args.model_dir,args.policy)
