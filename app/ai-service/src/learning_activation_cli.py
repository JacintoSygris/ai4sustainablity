"""Local Laravel bridge for health, bandit candidates and safe registry summaries."""
import argparse
import hashlib
import contextlib
import importlib
import io
import json
import os
from pathlib import Path
import sys
import time
import re

from learning_gate_metrics import enabled as metrics_enabled, administrative_gate, SCHEMA as METRICS_SCHEMA

from feedback_bandit_registry import BanditRegistry, profile_initial, read_json
from learning_activation import bandit_mode, flag, deployed
from learning_production_registry import ProductionCandidateRegistry, versions, code_hashes, BASE_PROFILE, effective_active


def status(root, candidate=None):
    if candidate is not None and not re.fullmatch(r'[a-f0-9]{32}', candidate):
        raise ValueError('learning.candidate')
    for module in ('numpy', 'sklearn', 'scipy', 'joblib', 'optuna'):
        importlib.import_module(module)
    registry = BanditRegistry(root)
    authority_lease = True
    try:
        registry.lease()
    except (ValueError, OSError, KeyError):
        authority_lease = False
    # This bridge resolves the local configuration, not a remote FastAPI probe.
    serving = {'scope': 'local_bridge', 'engine_enabled': flag('LEARNING_ENGINE_ENABLED'),
               'serving_enabled': flag('LEARNING_ENGINE_SERVING_ENABLED'), 'bandit_mode': bandit_mode(),
               'baseline': None}
    identity = None
    try:
        from learning_serving_adapter import model_serving_identity, resolve_model_profile
        serving['baseline'] = model_serving_identity(resolve_model_profile(BASE_PROFILE))
        identity = serving['baseline']['serving_identity_sha256']
    except Exception:
        pass  # Missing base artifacts must not hide the rest of the health panel.
    effective = None
    fallback_reason = 'no_selection'
    candidates = []
    active = previous = model_hash = effective_hash = None
    if (Path(root) / 'registry.sqlite3').is_file():
        models = ProductionCandidateRegistry(root)
        active, previous = models.pointer('active'), models.pointer('previous')
        if not serving['engine_enabled'] or not serving['serving_enabled']:
            fallback_reason = 'serving_disabled'
        elif identity is None:
            fallback_reason = 'baseline_unavailable'
        elif active:
            resolved = effective_active(models, active, identity)
            effective = active if resolved else None
            effective_hash = resolved[0]['model_sha256'] if resolved else None
            fallback_reason = None if resolved else 'selected_incompatible_or_unauthorized'
        expected_versions, expected_code = versions(), code_hashes()
        current_sets = current_comparator = None
        if metrics_enabled():
            from learning_production_cli import fixed, protocol_document, resolve_comparator
            from types import SimpleNamespace
            try:
                args = SimpleNamespace(**{key: os.getenv('LEARNING_GATE_' + key.upper(), '')
                    for key in ('heldout', 'heldout_sha256', 'gold', 'gold_sha256')})
                current_sets = fixed(args)
                current_comparator = resolve_comparator(models, active, identity)
            except Exception:
                current_sets = None
        with models.connect(readonly=True) as db:
            model_hash = models.model_hash(db, active)
            query = 'SELECT id,manifest,manifest_sha FROM candidates WHERE erased=0'
            query += ' AND id=?' if candidate else ' ORDER BY rowid DESC LIMIT 30'
            for row in db.execute(query, (candidate,) if candidate else ()):
                if hashlib.sha256(row['manifest'].encode()).hexdigest() != row['manifest_sha']:
                    raise ValueError('learning.manifest_integrity')
                manifest = json.loads(row['manifest'])
                eligible = False
                try:
                    # The candidate list uses metadata; the selected model above
                    # is resolved through the same integrity checks as serving.
                    # Promotion rechecks the full model and re-runs the gate.
                    models.authority(manifest['subjects'], manifest['mapping_sha256'], manifest['authority_bindings'])
                    eligible = (hashlib.sha256(row['manifest'].encode()).hexdigest() == row['manifest_sha']
                        and manifest['versions'] == expected_versions and manifest['code_sha256'] == expected_code
                        and time.time() < manifest['expires_at'] and not manifest['synthetic']
                        and manifest['gate']['passed'] is True
                        and identity is not None and manifest['baseline_identity'] == identity)
                    if metrics_enabled():
                        protocol = manifest.get('gate_protocol', {})
                        eligible = (eligible and current_sets is not None and manifest['gate'].get('schema') == METRICS_SCHEMA
                            and models.protocol(manifest['gate']['protocol_sha256']) == protocol
                            and protocol == protocol_document(current_sets, serving['baseline'], active, current_comparator,
                                created_at=protocol['created_at'], dataset_sha256=manifest['dataset_sha256']))
                except Exception:
                    eligible = False
                candidates.append({'id': row['id'], 'sha256': manifest['model_sha256'], 'gate': administrative_gate(manifest['gate'], detail=bool(candidate)) if metrics_enabled() else administrative_gate({k: v for k, v in manifest['gate'].items() if k != 'schema'}),
                                   'eligible': eligible, 'expires_at': manifest['expires_at'],
                                   'comparator': {'baseline_identity': manifest['baseline_identity'],
                                       'selected': manifest.get('compared_active'),
                                       'effective': manifest.get('compared_effective'),
                                       'effective_known': 'compared_effective' in manifest}})
    return {'candidate_found': candidate is None or bool(candidates), 'gate_metrics_enabled': metrics_enabled(), 'wp13': {'active': active, 'previous': previous, 'active_sha256': model_hash, 'selected': active, 'effective': effective,
                     'effective_sha256': effective_hash, 'fallback_reason': fallback_reason, 'serving': serving, 'candidates': candidates},
            'wp23': registry.summary(), 'python': True, 'authority_lease': authority_lease}


def execute(args):
    if args.operation == 'status':
        return status(args.root, args.candidate)
    registry = BanditRegistry(args.root)
    if args.operation == 'bandit-maintain':
        return registry.maintain()
    if args.operation == 'bandit-rollback':
        return registry.select('rollback', None, args.actor, args.reason)
    if (bandit_mode() not in ('active', 'shadow') or not flag('LEARNING_ENGINE_ENABLED')
            or os.getenv('LEARNING_ENGINE_AUTHORITY', 'consent' if deployed() else 'deny') != 'consent'):
        raise ValueError('learning.disabled')
    if args.operation == 'bandit-sync':
        doc = read_json(Path(args.dataset))
        if hashlib.sha256(Path(args.dataset).read_bytes()).hexdigest() != args.digest:
            raise ValueError('learning.dataset_digest')
        return registry.sync(profile_initial(), doc)
    if args.operation == 'bandit-promote':
        return registry.select('promote', args.candidate, args.actor, args.reason)
    raise ValueError('learning.operation')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('operation', choices=['status', 'bandit-sync', 'bandit-promote', 'bandit-rollback', 'bandit-maintain'])
    parser.add_argument('--root', required=True)
    for field in ('dataset', 'digest', 'candidate', 'actor', 'reason'):
        parser.add_argument('--' + field)
    args = parser.parse_args()
    def offline(event, _):
        if event.startswith('socket.') or event in ('http.client.connect', 'subprocess.Popen'):
            raise PermissionError('learning.offline_only')
    sys.addaudithook(offline)
    try:
        with contextlib.redirect_stdout(io.StringIO()):
            result = execute(args)
        print(json.dumps(result, allow_nan=False))
        return 0
    except Exception:
        print('{"status":"failed_closed","values_suppressed":true}', file=sys.stderr)
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
