"""Private native file protocol. No serving, credentials or operational training."""
import os
import sys
import json


def main():
    # Admission stays before input/files; registry admission imports stdlib only.
    if os.environ.get('I4S_BATCH_SYNTHETIC_CAPABILITY') != 'test-namespace:t07-export' or os.environ.get('APP_ENV') != 'testing':
        return 2
    # Read the bounded initial file descriptor before imports/work. The parent
    # never writes a bulk pipe while the child is starting or fitting.
    from learning_candidate_registry import allowed_root
    allowed_root()
    request = read_message()
    if sys.argv[1:] == ['--candidate-check']:
        from learning_candidate_registry import LocalCandidateRegistry, allowed_root
        package=request['package']
        binding=package['binding']
        registry=LocalCandidateRegistry(allowed_root()/('05b-candidate-'+binding['token']['batch_id']),case_binding=binding)
        model=registry.load_exact(package['receipt'])
        checked=dict(package=package,dataset_digest=model.dataset_digest,development_digest=model.development_digest)
        if 'features' in request:
            from learning_case_prediction_adapter import predict_case_shadow
            import pandas as pd
            checked['proposal']=predict_case_shadow(registry,package['receipt'],pd.DataFrame(**request['features']),
                dict(package=package,mode='verified-private-laravel-synthetic',promotion_allowed=False),trusted_local_launcher=True)
        print(json.dumps(checked,allow_nan=False),flush=True)
        return 0
    if sys.argv[1:] == ['--witness']:
        print(json.dumps(canonical_witness(request), allow_nan=False), flush=True)
        return 0
    mode = os.environ.get('I4S_BATCH_ADVERSARIAL_MODE','')
    if mode == 'timeout':
        import time
        time.sleep(30)
        return 3
    if mode == 'crash':
        return 3
    if mode == 'malformed':
        print('not-json',flush=True)
        return 3
    if mode == 'oversized':
        print('x'*1048577,flush=True)
        return 3
    if mode not in ('', 'receipt_generation'):
        return 2
    from datetime import datetime, timezone
    from learning_case_features import build_export_dataset
    from learning_case_training import split_dataset, train_candidate, predict_raw
    from learning_case_evaluation import evaluate_raw
    from learning_case_dataset import revalidate_authorized_dataset
    token = request['token']
    if (set(token) != {'batch_id','fence'} or type(token['fence']) is not int or not 1 <= token['fence'] <= 9007199254740991
            or type(token['batch_id']) is not str or len(token['batch_id']) != 32
            or any(c not in '0123456789abcdef' for c in token['batch_id'])):
        raise ValueError('learning_batch.token_invalid')
    state = request['state']
    now = datetime.fromisoformat(json.loads(request['bundle']['manifest_json'])['issued_at'])
    dataset = build_export_dataset(request['bundle'], state=state, now=now, trusted_local_launcher=True)
    split = split_dataset(dataset, **request['cutoffs'])
    policy = dict(schema_version='synthetic-raw-policy-v1',synthetic_only=True,promotion_allowed=False,
                  label_thresholds={t:0.5 for t in dataset.topic_ids},sector_guard=False,crc_floor=False)
    candidate = train_candidate(dataset, split, synthetic_policy=policy, seed=42,
                               synthetic_search={'synthetic_only':True,'trials':2,'C':[0.1,1.0,10.0]})
    ids = list(split.holdout)
    prediction, _ = predict_raw(candidate, dataset.X.loc[ids])
    # Empty holdout is not evidence of evaluation: reject rather than invent metrics.
    if not ids:
        raise ValueError('learning_batch.no_holdout')
    metrics = evaluate_raw(dataset.values.loc[ids], dataset.masks.loc[ids], prediction, ids, dataset.topic_ids, candidate.label_status)
    receipt = dict(token, dataset_digest=dataset.dataset_digest, context_digest=request['context_digest'],
                   mode='raw_masked_only',promotion_allowed=False,sector_guard=False,crc_floor=False,
                   fit_heads=len(candidate.pipelines),optuna_trials=int(candidate.tuning_status.rsplit(':',1)[1]),
                   metrics=metrics,development_digest=candidate.development_digest)
    print(json.dumps({'ready':True}), flush=True)
    fresh = read_refresh(token['batch_id'])
    bundle = fresh['bundle']
    result = revalidate_authorized_dataset(dataset.authorized_context['projection'], bundle['manifest_json'], bundle['bindings_json'],
        state=state,now=datetime.fromisoformat(json.loads(bundle['manifest_json'])['issued_at']),
        trusted_local_launcher=True,synthetic_only=True,namespace='test-namespace:t07-export')
    receipt['authority_generation'] = result['generation']
    receipt['authority_digest'] = result['manifest_digest']
    if mode == 'receipt_generation':
        receipt['authority_generation'] = True
    output={'receipt':receipt,'state':state,'consumable':result['publication_allowed']}
    if request.get('candidate_stage') is not None:
        if result['publication_allowed'] is not True:
            raise ValueError('learning_batch.candidate_ineligible')
        from learning_candidate_registry import LocalCandidateRegistry, allowed_root
        binding=dict(token=token,context_digest=request['context_digest'],authority_generation=result['generation'],
            authority_digest=result['manifest_digest'],mapping=request['candidate_stage'])
        registry=LocalCandidateRegistry(allowed_root()/('05b-candidate-'+token['batch_id']),case_binding=binding)
        technical=registry.register_case(candidate,dataset,split,binding)
        loaded=registry.load_exact(technical)
        raw_after,scores_after=predict_raw(loaded,dataset.X.loc[ids])
        raw_before,scores_before=predict_raw(candidate,dataset.X.loc[ids])
        if not raw_after.equals(raw_before) or not scores_after.equals(scores_before):
            raise ValueError('learning_batch.reload_mismatch')
        print('t10:fit+optuna/persist/sql/reload/infer exact',file=sys.stderr,flush=True)
        output['candidate']={'binding':binding,'receipt':technical}
    print(json.dumps(output,allow_nan=False),flush=True)
    return 0


def read_refresh(batch_id):
    import time
    from pathlib import Path
    path = Path(os.environ['I4S_BATCH_REFRESH_PATH'])
    from learning_candidate_registry import allowed_root
    root = allowed_root()
    if (path.name != 'refresh.json' or path.parent.parent.resolve() != root.resolve()
            or path.parent.name != '05a-batch-' + batch_id):
        raise ValueError('learning_batch.refresh_path_invalid')
    deadline = time.monotonic() + 20
    while not path.with_name('refresh-ready').exists():
        if time.monotonic() >= deadline:
            raise ValueError('learning_batch.refresh_timeout')
        time.sleep(0.02)
    with path.open('rb') as handle:
        return decode_message(handle.readline(1048577))


def read_message():
    return decode_message(sys.stdin.buffer.readline(1048577))


def decode_message(raw):
    if len(raw)>1048576 or not raw.endswith(b'\n'):
        raise ValueError('learning_batch.input_limit')
    return json.loads(raw)



def canonical_witness(request):
    # Server-owned fenced input only; the protected T08 builder owns selection.
    import copy
    from datetime import datetime
    from learning_case_dataset import build_authorized_dataset, revalidate_authorized_dataset
    state = copy.deepcopy(request['state'])
    bundle = request['bundle']
    dataset = build_authorized_dataset(bundle['jsonl'], bundle['manifest_json'], bundle['bindings_json'], state=state,
        now=datetime.fromisoformat(json.loads(bundle['manifest_json'])['issued_at']), trusted_local_launcher=True,
        synthetic_only=True, namespace='test-namespace:t07-export')
    refresh = request['refresh']
    checked = revalidate_authorized_dataset(dataset, refresh['manifest_json'], refresh['bindings_json'],
        state=state, now=datetime.fromisoformat(json.loads(refresh['manifest_json'])['issued_at']),
        trusted_local_launcher=True, synthetic_only=True, namespace='test-namespace:t07-export')
    return dict(dataset_digest=dataset['dataset_digest'], state=state, consumable=checked['publication_allowed'])

if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as error:
        code = str(error)
        print(code if code.startswith(('learning_','registry.','adapter.')) and len(code)<120 else 'learning_batch.child_failed:'+type(error).__name__,file=sys.stderr)
        sys.exit(3)
