"""Local subprocess protocol for Laravel; never expose this as a FastAPI route."""
import argparse
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import re
import sys
import time

from learning_gate_metrics import enabled as metrics_enabled, policy, validate_contract, SCHEMA as METRICS_SCHEMA
from learning_activation import flag

from learning_production_registry import (BASE_PROFILE, ProductionCandidateRegistry,
    digest, evaluate, read_fixed, feature_fingerprint, effective_active, gate_decision_ids)
from wp13_feedback_bridge import loads, require
from wp13_feedback_training import train_production_feedback


def baseline(company):
    from learning_serving_adapter import predict_esrs
    company = company.model_copy(update={'model_profile': BASE_PROFILE, 'company_name': 'pseudonymous'})
    return predict_esrs(company, _learning_bypass=True)


def fixed(args):
    result = {'heldout': (read_fixed(args.heldout, args.heldout_sha256), args.heldout_sha256)}
    require(bool(args.gold) == bool(args.gold_sha256))
    if args.gold:
        result['gold_oos'] = (read_fixed(args.gold, args.gold_sha256), args.gold_sha256)
    return result


def protocol_document(sets, identity, active, resolved, *, created_at, dataset_sha256):
    axes = {}
    for name, (document, _) in sets.items():
        require(document['schema'] == 'learning-heldout-v1' and document['cases'])
        unit = document.get('unit', 'keys')
        require(unit in ('keys', 'standards') and (name != 'heldout' or unit == 'keys'))
        keys = sorted(document['cases'][0]['truth'])
        require(keys and len(keys) <= 256)
        groups = set()
        for case in document['cases']:
            require(set(case) == {'group', 'p5_sha256', 'input', 'truth'})
            require(isinstance(case['group'], str) and case['group'] and case['group'] not in groups)
            groups.add(case['group'])
            require(sorted(case['truth']) == keys)
            require(all(v is None or (type(v) is int and v in (0, 1)) for v in case['truth'].values()))
        axes[name] = {'unit': unit, 'keys': keys}
    return dict(schema=METRICS_SCHEMA, policy=policy(),
                fixed_sha256={n: sha for n, (_, sha) in sets.items()}, axes=axes,
                decision_ids=gate_decision_ids(sets),
                selected=active, effective=active if resolved else None,
                effective_model_sha256=resolved[0]['model_sha256'] if resolved else None,
                baseline=identity, code_sha256=__import__('learning_production_registry').code_hashes(),
                dataset_sha256=dataset_sha256, created_at=created_at)


def resolve_comparator(registry, active, identity):
    return effective_active(registry, active, identity) if flag('LEARNING_ENGINE_ENABLED') and flag('LEARNING_ENGINE_SERVING_ENABLED') else None


def execute(args):
    registry = ProductionCandidateRegistry(args.root, create=True)
    if args.operation == 'erase':
        registry.erase(args.subject)
        return {'status': 'erased'}
    if args.operation == 'rollback' and args.candidate == 'base':
        registry.rollback(None, args.actor, None, getattr(args, 'reason', ''))
        return {'status': 'rolled_back', 'profile': BASE_PROFILE}
    require(os.getenv('LEARNING_ENGINE_ENABLED') == 'true')
    require(os.getenv('LEARNING_ENGINE_AUTHORITY') == 'consent')
    if args.operation == 'rollback':
        from learning_serving_adapter import resolve_model_profile, model_serving_identity
        identity = model_serving_identity(resolve_model_profile(BASE_PROFILE))['serving_identity_sha256']
        registry.rollback(args.candidate, args.actor, identity, getattr(args, 'reason', ''))
        return {'status': 'rolled_back', 'candidate_id': args.candidate}
    sets = fixed(args)
    active = registry.pointer('active')
    from learning_serving_adapter import company_type
    CompanyData = company_type()
    if metrics_enabled():
        from feedback_bandit import gate_decision
        with gate_decision(gate_decision_ids(sets)['heldout'][0]):
            probe = baseline(CompanyData.model_validate(sets['heldout'][0]['cases'][0]['input']))
    else:
        probe = baseline(CompanyData.model_validate(sets['heldout'][0]['cases'][0]['input']))
    identity = probe.mapping_metadata['serving_identity']
    resolved = resolve_comparator(registry, active, identity['serving_identity_sha256'])
    protocol = None
    protocol_sha = None
    comparator = resolved[1] if resolved else None
    if active is not None and resolved is None:
        with registry.connect() as db:
            registry.audit(db, 'comparator_fallback', args.actor, active, {'profile': BASE_PROFILE})
    if args.operation == 'train':
        require(re.fullmatch(r'[a-f0-9]{40}', args.code_commit or '') is not None)
        data = read_fixed(args.dataset, args.dataset_sha256)
        mapping = read_fixed(args.mapping, args.mapping_sha256)
        # Freeze the reserved membership before touching labels or fitting.
        subjects = [r['subject'] for r in data['rows']]
        groups = [r['group'] for r in data['rows']]
        fingerprints = [r['p5_sha256'] for r in data['rows']] + [feature_fingerprint(r['input']) for r in data['rows']]
        for document, _ in sets.values():
            for case in document['cases']:
                require(case['group'] not in groups and case['p5_sha256'] not in fingerprints
                        and feature_fingerprint(case['input']) not in fingerprints)
        bindings = {r['subject']: r['authority_binding'] for r in data['rows']}
        registry.authority(subjects, args.mapping_sha256, bindings)
        if metrics_enabled():
            for document, _ in sets.values():
                axes = (set(probe.esrs) if document.get('unit', 'keys') == 'keys'
                        else {key.split('_')[1].upper() for key in probe.esrs})
                for case in document['cases']:
                    require(set(case['truth']) == axes)
                    CompanyData.model_validate(case['input'])
            protocol = protocol_document(sets, identity, active, resolved, created_at=time.time(), dataset_sha256=args.dataset_sha256)
            validate_contract(protocol, 'protocol')
            protocol_sha = registry.freeze_protocol(protocol)
            require(fixed(args) == sets)
        model, receipt = train_production_feedback(data, mapping,
            synthetic_only=os.getenv('LEARNING_ENGINE_SYNTHETIC_ONLY', 'true') != 'false')
        gate = evaluate(model, sets, baseline, comparator=comparator,
                        excluded_subjects=groups, excluded_inputs=fingerprints,
                        metrics_policy=protocol['policy'] if protocol else None, protocol_sha256=protocol_sha,
                        expected_identity=identity if protocol else None,
                        decision_ids=protocol['decision_ids'] if protocol else None)
        require(registry.pointer('active') == active)
        manifest = {'dataset_sha256': args.dataset_sha256, 'mapping_sha256': args.mapping_sha256,
                    'code_commit': args.code_commit, 'subjects': subjects, 'groups': groups,
                    'authority_bindings': bindings,
                    'training_input_sha256': fingerprints, 'synthetic': any(r['synthetic'] for r in data['rows']),
                    'training': receipt, 'gate': gate, 'baseline_identity': gate['baseline_identity'],
                    'compared_active': active, 'compared_effective': active if resolved else None}
        if protocol:
            require(fixed(args) == sets)
            from learning_serving_adapter import model_serving_identity, resolve_model_profile
            require(model_serving_identity(resolve_model_profile(BASE_PROFILE)) == identity)
            require(protocol == protocol_document(sets, identity, registry.pointer('active'),
                resolve_comparator(registry, registry.pointer('active'), identity['serving_identity_sha256']),
                created_at=protocol['created_at'], dataset_sha256=args.dataset_sha256))
            validate_contract(gate, 'receipt')
            manifest['gate_protocol'] = protocol
        candidate = registry.register(model, manifest)
        return {'status': 'registered', 'candidate_id': candidate, 'dataset_sha256': args.dataset_sha256,
                'training': receipt, 'gate': gate, 'promotion': 'manual_only'}
    require(args.operation == 'promote')
    manifest, model = registry.load(args.candidate)
    if metrics_enabled():
        require(manifest['gate'].get('schema') == METRICS_SCHEMA)
        protocol_sha = manifest['gate']['protocol_sha256']
        protocol = registry.protocol(protocol_sha)
        require(protocol == manifest['gate_protocol'])
        require(protocol == protocol_document(sets, identity, active, resolved,
                created_at=protocol['created_at'], dataset_sha256=manifest['dataset_sha256']))
    gate = evaluate(model, sets, baseline, comparator=comparator,
                    excluded_subjects=manifest['groups'], excluded_inputs=manifest['training_input_sha256'],
                    metrics_policy=protocol['policy'] if protocol else None, protocol_sha256=protocol_sha,
                    expected_identity=identity if protocol else None,
                    decision_ids=protocol['decision_ids'] if protocol else None)
    if protocol:
        require(fixed(args) == sets)
        from learning_serving_adapter import model_serving_identity, resolve_model_profile
        require(model_serving_identity(resolve_model_profile(BASE_PROFILE)) == identity)
        require(protocol == protocol_document(sets, identity, registry.pointer('active'),
            resolve_comparator(registry, registry.pointer('active'), identity['serving_identity_sha256']),
            created_at=protocol['created_at'], dataset_sha256=manifest['dataset_sha256']))
    require(gate['baseline_identity'] == manifest['baseline_identity'])
    registry.activate(args.candidate, args.actor, gate, active, getattr(args, 'reason', ''))
    return {'status': 'promoted', 'candidate_id': args.candidate, 'gate': gate}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation', choices=['train', 'promote', 'rollback', 'erase'])
    parser.add_argument('--root', required=True)
    for field in ['dataset', 'dataset-sha256', 'mapping', 'mapping-sha256', 'code-commit',
                  'heldout', 'heldout-sha256', 'gold', 'gold-sha256', 'candidate', 'subject']:
        parser.add_argument('--' + field)
    parser.add_argument('--actor', default='system')
    parser.add_argument('--reason', default='')
    args = parser.parse_args(argv)
    active_guard = True
    def offline(event, _):
        if active_guard and (event.startswith('socket.') or event in ('http.client.connect', 'subprocess.Popen')):
            raise PermissionError('learning.offline_only')
    sys.addaudithook(offline)
    try:
        # Existing predictor emits progress to stdout; protocol contains JSON only.
        with contextlib.redirect_stdout(io.StringIO()):
            receipt = execute(args)
        print(json.dumps(receipt, allow_nan=False))
        return 0
    except Exception:
        print('{"status":"failed_closed","values_suppressed":true}', file=sys.stderr)
        return 2
    finally:
        active_guard = False


if __name__ == '__main__':
    raise SystemExit(main())
