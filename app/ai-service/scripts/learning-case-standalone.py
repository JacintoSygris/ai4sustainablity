"""Real fit -> SQLite registry -> exact reload in a fresh interpreter.

Only controlled synthetic fixtures; no operational models or endpoints.
"""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys

from learning_candidate_registry import LocalCandidateRegistry
from learning_case_prediction_adapter import predict_offline

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--root',required=True)
    parser.add_argument('--reload',action='store_true')
    args=parser.parse_args()
    registry=LocalCandidateRegistry(args.root)
    root=registry.root
    if args.reload:
        import pandas as pd
        receipt=json.loads((root/'selected-receipt.json').read_text())
        frame=json.loads((root/'unseen.json').read_text())
        X=pd.DataFrame(frame['data'],columns=frame['columns'],index=frame['index'],dtype=object)
        candidate=registry.load_exact(receipt)
        result=dict(pid=os.getpid(),executable=sys.executable,prediction=predict_offline(candidate,X))
        (root/'fresh-process.json').write_text(json.dumps(result,allow_nan=False,indent=2))
        print(json.dumps(result,allow_nan=False))
        return
    # Fixture helper import is explicit, local and synthetic only.
    sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'src/tests'))
    from learning_case_01b_synthetic_helper import fixture,CUTOFFS
    from learning_case_features import build_dataset
    from learning_case_training import split_dataset,train_candidate
    from learning_case_evaluation import evaluate_raw
    from learning_case_training import predict_raw
    policy=dict(schema_version='synthetic-raw-policy-v1',synthetic_only=True,promotion_allowed=False,
                label_thresholds={'101':.5,'102':.5,'103':.5},sector_guard=False,crc_floor=False)
    dataset=build_dataset(fixture(),['101','102','103']); split=split_dataset(dataset,**CUTOFFS)
    candidate=train_candidate(dataset,split,synthetic_policy=policy,seed=42)
    receipt=registry.register(candidate,dataset,split)
    X=dataset.X.loc[list(split.holdout)]
    before=predict_offline(candidate,X)
    labels,_=predict_raw(candidate,X)
    evaluation=evaluate_raw(dataset.values.loc[X.index],dataset.masks.loc[X.index],labels,
                            list(X.index),candidate.topic_ids,candidate.label_status)
    (root/'selected-receipt.json').write_text(json.dumps(receipt,indent=2))
    (root/'unseen.json').write_text(json.dumps(X.to_dict('split')))
    command=[sys.executable,str(Path(__file__).resolve()),'--root',str(root),'--reload']
    child=subprocess.run(command,capture_output=True,text=True,timeout=60,env=os.environ.copy())
    (root/'fresh-process-stdout.txt').write_text(child.stdout)
    (root/'fresh-process-stderr.txt').write_text(child.stderr)
    if child.returncode: raise RuntimeError(f'fresh process exit {child.returncode}: {child.stderr}')
    result=json.loads((root/'fresh-process.json').read_text())
    if before != result['prediction'] or result['pid'] == os.getpid(): raise RuntimeError('reload comparison mismatch')
    evidence=dict(parent_pid=os.getpid(),executable=sys.executable,child_command=command,child_exit=child.returncode,
        receipt=receipt,before=before,child=result,evaluation=evaluation,
        fixture_cases=len(dataset.case_ids),split=dict(train=len(split.train),calibration=len(split.calibration),holdout=len(split.holdout)),
        supports=candidate.supports,flags=dict(operational=False,shadow=False,promotion_allowed=False))
    (root/'comparison.json').write_text(json.dumps(evidence,allow_nan=False,indent=2))
    print(json.dumps(evidence,allow_nan=False))

if __name__ == '__main__':
    main()
