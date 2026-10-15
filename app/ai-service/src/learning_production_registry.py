"""Local durable supervised-learning registry. Laravel owns consent and administrative actions.

SQLite transactions bind manifests, exact model bytes, pointers and audit events.
Only this trusted local writer may create pickles; there is no upload endpoint.
The historical LocalCandidateRegistry remains synthetic and unpromotable.
"""
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import platform
import re
import sqlite3
import time
import uuid

from wp13_feedback_bridge import loads, require

BASE_PROFILE = 'new_format_732_v1_gpt41'
SCHEMA = 'learning-production-candidate-v1'


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()


def digest(value):
    return hashlib.sha256(encoded(value)).hexdigest()


def feature_fingerprint(value):
    from wp13_feedback_training import production_features
    return digest(production_features(value))


def gate_decision_ids(fixed_sets):
    """Opaque per-case RNG identities bound to immutable fixed-set bytes/order."""
    return {name: [digest(['learning-gate-decision-v1', name, sha, index])
                   for index in range(len(document['cases']))]
            for name, (document, sha) in fixed_sets.items()}


def read_fixed(path, expected):
    require(isinstance(expected, str) and re.fullmatch(r'[a-f0-9]{64}', expected) is not None)
    p = Path(path)
    require(p.is_absolute() and p.is_file() and not p.is_symlink() and p.stat().st_size <= 32 * 1024 * 1024)
    with p.open('rb') as stream:
        raw = stream.read(32 * 1024 * 1024 + 1)
    require(len(raw) <= 32 * 1024 * 1024 and hashlib.sha256(raw).hexdigest() == expected)
    return loads(raw)


def versions():
    from importlib.metadata import version
    return {'python': platform.python_version(), **{k: version(k) for k in ('numpy', 'scikit-learn', 'joblib', 'scipy')}}


def code_hashes():
    root = Path(__file__).parent
    names = ['learning_production_registry.py', 'learning_production_runtime.py', 'learning_production_cli.py',
             'wp13_feedback_training.py', 'wp13_feedback_bridge.py', 'train/masked_multilabel.py',
             'learning_gate_metrics.py', 'learning_serving_adapter.py', 'learning_activation.py',
             'feedback_bandit.py', 'feedback_bandit_registry.py', 'esrs_standard.py']
    from learning_serving_adapter import sources
    paths = {n: root / n for n in names} | sources()
    paths['contracts/learning-gate-metrics-v1.schema.json'] = root.parents[1] / 'contracts/api/learning-gate-metrics-v1.schema.json'
    return {n: hashlib.sha256(p.read_bytes()).hexdigest() for n, p in paths.items()}


class ProductionCandidateRegistry:
    def __init__(self, root, *, create=False):
        self.root = Path(root)
        require(self.root.is_absolute() and self.root.resolve() == self.root)
        require(not any(p.is_symlink() for p in [self.root, *self.root.parents]))
        if create:
            self.root.mkdir(mode=0o700, parents=True, exist_ok=True)
        require(self.root.is_dir())
        self.path = self.root / 'registry.sqlite3'
        require(not self.path.is_symlink())
        if create:
            with self.connect() as db:
                db.executescript('''
                    CREATE TABLE IF NOT EXISTS candidates (
                        id TEXT PRIMARY KEY, manifest TEXT NOT NULL, manifest_sha TEXT NOT NULL,
                        model BLOB NOT NULL, erased INTEGER NOT NULL DEFAULT 0);
                    CREATE TABLE IF NOT EXISTS pointers (name TEXT PRIMARY KEY, candidate_id TEXT);
                    CREATE TABLE IF NOT EXISTS audit (
                        id INTEGER PRIMARY KEY, at REAL NOT NULL, action TEXT NOT NULL,
                        actor TEXT NOT NULL, candidate_id TEXT, detail TEXT NOT NULL);
                    CREATE TABLE IF NOT EXISTS shadow (
                        id INTEGER PRIMARY KEY, at REAL NOT NULL, candidate_id TEXT NOT NULL,
                        served TEXT NOT NULL, candidate TEXT NOT NULL, subject TEXT);
                    CREATE TABLE IF NOT EXISTS erased_subjects (subject TEXT PRIMARY KEY);
                ''')
                if 'subject' not in {r['name'] for r in db.execute('PRAGMA table_info(shadow)')}:
                    db.execute('ALTER TABLE shadow ADD COLUMN subject TEXT')
                # Old unowned records cannot satisfy requester erasure.
                db.execute('DELETE FROM shadow WHERE subject IS NULL')
            os.chmod(self.path, 0o600)
            from learning_gate_metrics import enabled
            if enabled():
                with self.connect() as db:
                    db.execute('CREATE TABLE IF NOT EXISTS gate_protocols (protocol_sha256 TEXT PRIMARY KEY, document TEXT NOT NULL, created_at REAL NOT NULL)')

    def connect(self, readonly=False):
        if readonly:
            require(self.path.is_file())
        db = sqlite3.connect(self.path.as_uri() + ('?mode=ro' if readonly else '?mode=rw'),
                             uri=True, timeout=5) if self.path.exists() else sqlite3.connect(self.path, timeout=5)
        db.row_factory = sqlite3.Row
        return contextlib.closing(db) if readonly else _transaction(db)

    def freeze_protocol(self, document):
        sha = digest(document)
        with self.connect() as db:
            db.execute('INSERT OR IGNORE INTO gate_protocols VALUES(?,?,?)',
                       (sha, encoded(document).decode(), document['created_at']))
        return sha

    def protocol(self, sha):
        with self.connect(readonly=True) as db:
            row = db.execute('SELECT document FROM gate_protocols WHERE protocol_sha256=?', (sha,)).fetchone()
        require(row is not None)
        document = loads(row[0])
        require(digest(document) == sha)
        return document

    def authority(self, subjects, mapping_sha256=None, bindings=None):
        path = self.root / 'authority.json'
        require(not path.is_symlink() and path.stat().st_size <= 1024 * 1024)
        lease = loads(path.read_bytes())
        now = time.time()
        require(now < lease['expires_at'] <= now + 95)
        require(set(subjects) <= set(lease['subjects']))
        if bindings is not None:
            require(set(bindings) == set(subjects))
            current = lease.get('bindings', {})
            require(all(isinstance(value, str) and re.fullmatch(r'[a-f0-9]{64}', value)
                        and current.get(subject) == value for subject, value in bindings.items()))
        if mapping_sha256 is not None:
            require(lease.get('mapping_sha256') == mapping_sha256)

    @staticmethod
    def audit(db, action, actor, candidate, detail):
        require(re.fullmatch(r'(?:admin:[1-9][0-9]*|system)', actor) is not None)
        db.execute('INSERT INTO audit(at,action,actor,candidate_id,detail) VALUES(?,?,?,?,?)',
                   (time.time(), action, actor, candidate, encoded(detail).decode()))

    def pointer(self, name):
        with self.connect(readonly=True) as db:
            row = db.execute('SELECT candidate_id FROM pointers WHERE name=?', (name,)).fetchone()
        return row[0] if row else None

    def load(self, candidate, *, consent=True):
        require(isinstance(candidate, str) and re.fullmatch(r'[a-f0-9]{32}', candidate) is not None)
        with self.connect(readonly=True) as db:
            row = db.execute('SELECT * FROM candidates WHERE id=?', (candidate,)).fetchone()
        require(row is not None and not row['erased'])
        raw, blob = row['manifest'].encode(), row['model']
        require(hashlib.sha256(raw).hexdigest() == row['manifest_sha'])
        manifest = loads(raw)
        require(manifest['schema'] == SCHEMA and manifest['id'] == candidate)
        require(hashlib.sha256(blob).hexdigest() == manifest['model_sha256'])
        require(manifest['versions'] == versions() and manifest['code_sha256'] == code_hashes())
        require(time.time() < manifest['expires_at'])
        if consent:
            self.authority(manifest['subjects'], manifest['mapping_sha256'], manifest['authority_bindings'])
        # Integrity is verified before deserialisation. Registry is private and
        # writable only by the training/admin OS identity, never by HTTP clients.
        import joblib
        from wp13_feedback_training import ProductionFeedbackModel
        model = joblib.load(io.BytesIO(blob))
        require(type(model) is ProductionFeedbackModel and model.keys == manifest['training']['trained_keys'])
        return manifest, model

    def register(self, model, manifest):
        import joblib
        candidate = uuid.uuid4().hex
        stream = io.BytesIO()
        joblib.dump(model, stream, compress=3)
        blob = stream.getvalue()
        require(len(blob) <= 64 * 1024 * 1024)
        manifest = {**manifest, 'schema': SCHEMA, 'id': candidate,
                    'model_sha256': hashlib.sha256(blob).hexdigest(),
                    'versions': versions(), 'code_sha256': code_hashes(),
                    'created_at': time.time(), 'expires_at': time.time() + 30 * 86400}
        self.authority(manifest['subjects'], manifest['mapping_sha256'], manifest['authority_bindings'])
        raw = encoded(manifest)
        with self.connect() as db:
            revoked = {r[0] for r in db.execute('SELECT subject FROM erased_subjects')}
            require(not revoked.intersection(manifest['subjects']))
            db.execute('INSERT INTO candidates(id,manifest,manifest_sha,model) VALUES(?,?,?,?)',
                       (candidate, raw.decode(), hashlib.sha256(raw).hexdigest(), blob))
            self.audit(db, 'registered', 'system', candidate,
                       {'dataset_sha256': manifest['dataset_sha256'], 'gate': manifest['gate']})
            # A new candidate is eligible for shadow only. No serving mutation.
            db.execute("INSERT OR REPLACE INTO pointers VALUES('shadow',?)", (candidate,))
        return candidate

    def activate(self, candidate, actor, gate, expected_active, reason=""):
        require(isinstance(reason, str) and len(reason) <= 500)
        require(re.fullmatch(r'admin:[1-9][0-9]*', actor) is not None)
        manifest, _ = self.load(candidate)
        require(not manifest['synthetic'] and manifest['gate']['passed'] is True and gate['passed'] is True)
        require(gate['fixed_sha256'] == manifest['gate']['fixed_sha256'])
        from learning_gate_metrics import enabled, policy, SCHEMA as METRICS_SCHEMA
        if enabled():
            require(gate.get('schema') == manifest['gate'].get('schema') == METRICS_SCHEMA)
            require(gate.get('protocol_sha256') == manifest['gate'].get('protocol_sha256'))
            protocol = self.protocol(gate['protocol_sha256'])
            require(protocol == manifest['gate_protocol'])
            require(protocol['policy'] == policy() and protocol['selected'] == expected_active)
            require(gate['policy'] == protocol['policy'] and gate['primary']['passed'] is True
                    and gate['zero_positive_loss'] is True)
            require(gate['report'] == manifest['gate']['report'])
        with self.connect() as db:
            current = db.execute("SELECT candidate_id FROM pointers WHERE name='active'").fetchone()
            require((current[0] if current else None) == expected_active)
            require(not db.execute('SELECT erased FROM candidates WHERE id=?', (candidate,)).fetchone()[0])
            if current and current[0] == candidate:
                return  # Idempotent promotion, inside the pointer transaction.
            self.audit(db, 'promoted', actor, candidate, {'previous': expected_active, 'gate': gate, 'reason': reason,
                       'before_sha256': self.model_hash(db, expected_active), 'after_sha256': manifest['model_sha256']})
            db.execute("INSERT OR REPLACE INTO pointers VALUES('previous',?)", ((current[0] if current else None),))
            db.execute("INSERT OR REPLACE INTO pointers VALUES('active',?)", (candidate,))

    def rollback(self, candidate, actor, baseline_identity, reason=""):
        require(isinstance(reason, str) and len(reason) <= 500)
        require(re.fullmatch(r'admin:[1-9][0-9]*', actor) is not None)
        if candidate is not None:
            manifest, _ = self.load(candidate)
            require(not manifest['synthetic'] and manifest['baseline_identity'] == baseline_identity)
        with self.connect() as db:
            if candidate is not None:
                require(db.execute("SELECT 1 FROM audit WHERE action='promoted' AND candidate_id=?",
                                   (candidate,)).fetchone() is not None)
                require(not db.execute('SELECT erased FROM candidates WHERE id=?', (candidate,)).fetchone()[0])
            current = db.execute("SELECT candidate_id FROM pointers WHERE name='active'").fetchone()
            self.audit(db, 'rollback', actor, candidate, {'previous': current[0] if current else None, 'reason': reason,
                       'before_sha256': self.model_hash(db, current[0] if current else None),
                       'after_sha256': manifest['model_sha256'] if candidate is not None else None})
            db.execute("INSERT OR REPLACE INTO pointers VALUES('previous',?)", ((current[0] if current else None),))
            db.execute("INSERT OR REPLACE INTO pointers VALUES('active',?)", (candidate,))

    @staticmethod
    def model_hash(db, candidate):
        if candidate is None:
            return None
        row = db.execute('SELECT manifest FROM candidates WHERE id=?', (candidate,)).fetchone()
        return loads(row[0]).get('model_sha256') if row else None

    def erase(self, subject=None):
        # Tombstones defeat a training job that began before withdrawal. UUIDs
        # are random per consent and never reused after withdrawal/re-consent.
        with self.connect() as db:
            if subject is not None:
                require(str(uuid.UUID(subject)) == subject)
                db.execute('INSERT OR IGNORE INTO erased_subjects VALUES(?)', (subject,))
                db.execute('DELETE FROM shadow WHERE subject=?', (subject,))
            for row in db.execute('SELECT id,manifest FROM candidates WHERE erased=0').fetchall():
                manifest = loads(row['manifest'])
                if (subject is not None and subject not in manifest['subjects']) or (
                        subject is None and manifest['expires_at'] > time.time()):
                    continue
                db.execute('UPDATE pointers SET candidate_id=NULL WHERE candidate_id=?', (row['id'],))
                # Remove subjects, fitted bytes and row-level provenance. Keep an
                # anonymous audit that the version was erased, not its training data.
                db.execute("UPDATE candidates SET erased=1,model=X'',manifest='{}',manifest_sha='' WHERE id=?", (row['id'],))
                db.execute('DELETE FROM shadow WHERE candidate_id=?', (row['id'],))
                self.audit(db, 'erased', 'system', row['id'], {})
            db.execute('DELETE FROM shadow WHERE at<?', (time.time() - 30 * 86400,))
        # SQLite secure_delete removes old content; no dataset or pickle files
        # are kept outside this database. Backups require the same retention.

    def shadow(self, candidate, served, predicted, subject):
        require(isinstance(subject, str) and str(uuid.UUID(subject)) == subject)
        with self.connect() as db:
            self.authority([subject])
            require(db.execute('SELECT 1 FROM erased_subjects WHERE subject=?', (subject,)).fetchone() is None)
            row = db.execute('SELECT erased FROM candidates WHERE id=?', (candidate,)).fetchone()
            if row is None or row[0]:
                return
            db.execute('INSERT INTO shadow(at,candidate_id,served,candidate,subject) VALUES(?,?,?,?,?)',
                       (time.time(), candidate, encoded(served).decode(), encoded(predicted).decode(), subject))
            db.execute('DELETE FROM shadow WHERE id NOT IN (SELECT id FROM shadow ORDER BY id DESC LIMIT 10000)')


def effective_active(registry, active, identity):
    """Resolve the same fail-closed overlay for serving and evaluation."""
    if active is None:
        return None
    try:
        manifest, model = registry.load(active)
        require(not manifest['synthetic'] and manifest['gate']['passed'] is True
                and manifest['baseline_identity'] == identity)
        return manifest, model
    except Exception:
        # Deliberately suppress private path/subject/exception details.
        return None


@contextlib.contextmanager
def _transaction(db):
    try:
        db.execute('PRAGMA secure_delete=ON')
        db.execute('BEGIN IMMEDIATE')
        yield db
        db.commit()
    except BaseException:
        db.rollback()
        raise
    finally:
        db.close()


def evaluate(model, fixed_sets, baseline, *, comparator=None, excluded_subjects=(), excluded_inputs=(), metrics_policy=None, protocol_sha256=None, expected_identity=None, decision_ids=None):
    """Every baseline/served true positive must survive on every fixed case.

    No aggregate improvement may hide a per-report loss. Empty/malformed gold
    sets fail closed; absent gold is explicitly recorded, never fabricated.
    """
    from learning_serving_adapter import company_type
    CompanyData = company_type()
    from learning_gate_metrics import binary, measure, improvement, SCHEMA as METRICS_SCHEMA
    results = []
    reports = {}
    passed = True
    identities = set()
    require(bool(fixed_sets) and 'heldout' in fixed_sets)
    if metrics_policy is not None:
        from feedback_bandit import gate_decision
        frozen_ids = gate_decision_ids(fixed_sets)
        require(decision_ids is None or decision_ids == frozen_ids)
    else:
        require(decision_ids is None)
    for name, (document, sha256) in fixed_sets.items():
        require(document['schema'] == 'learning-heldout-v1' and document['cases'])
        unit = document.get('unit', 'keys')
        require(unit in ('keys', 'standards') and (name != 'heldout' or unit == 'keys'))
        seen = set()
        truths = []
        predictions = {k: [] for k in ('base', 'comparator', 'candidate')}
        axes = None
        losses = 0
        baseline_tp = candidate_tp = positives = 0
        for index, case in enumerate(document['cases']):
            require(set(case) == {'group', 'p5_sha256', 'input', 'truth'})
            require(case['group'] not in excluded_subjects and case['group'] not in seen)
            seen.add(case['group'])
            require(case['p5_sha256'] not in excluded_inputs)
            require(feature_fingerprint(case['input']) not in excluded_inputs)
            company = CompanyData.model_validate(case['input'])
            decision = gate_decision(frozen_ids[name][index]) if metrics_policy is not None else contextlib.nullcontext()
            with decision:
                result = baseline(company)
            identities.add(result.mapping_metadata['serving_identity']['serving_identity_sha256'])
            if expected_identity is not None:
                require(result.mapping_metadata['serving_identity'] == expected_identity)
            base = result.esrs
            binary(base, base)
            if axes is None:
                axes = set(base)
            require(set(base) == axes)
            require(type(case['truth']) is dict)
            require(all(v is None or (type(v) is int and v in (0, 1)) for v in case['truth'].values()))
            decision = gate_decision(frozen_ids[name][index]) if metrics_policy is not None else contextlib.nullcontext()
            with decision:
                predicted = model.predict(company, dict(base))
                served = comparator.predict(company, dict(base)) if comparator else dict(base)
            binary(predicted, base)
            binary(served, base)
            if unit == 'standards':
                standards = {key.split('_')[1].upper() for key in base}
                def standard_projection(values):
                    return {standard: int(any(v == 1 and key.split('_')[1].upper() == standard
                                             for key, v in values.items())) for standard in standards}
                base, served, predicted = map(standard_projection, (base, served, predicted))
            require(set(case['truth']) == set(base))
            truths.append(case['truth'])
            for key, values in zip(predictions, (base, served, predicted)):
                predictions[key].append(dict(values))
            for key, truth in case['truth'].items():
                if truth != 1:
                    continue
                positives += 1
                baseline_tp += int(bool(served[key]))
                candidate_tp += int(bool(predicted[key]))
                losses += int(bool(base[key] or served[key]) and not predicted[key])
        if metrics_policy is None:
            require(positives > 0)
        else:
            reports[name] = {'unit': unit, **measure(truths, predictions, truths[0], metrics_policy)}
        passed = passed and losses == 0
        results.append({'name': name, 'unit': unit, 'cases': len(seen), 'positive_support': positives,
                        'served_true_positives': baseline_tp, 'candidate_true_positives': candidate_tp,
                        'lost_true_positives': losses,
                        'served_recall': baseline_tp / positives if positives else None, 'candidate_recall': candidate_tp / positives if positives else None})
    require(len(identities) == 1)
    gate = {'passed': passed, 'rule': 'zero-report-key-recall-loss', 'sets': results,
            'gold_oos': 'evaluated' if 'gold_oos' in fixed_sets else 'unavailable',
            'fixed_sha256': {name: sha for name, (_, sha) in fixed_sets.items()},
            'baseline_identity': next(iter(identities))}

    if metrics_policy is not None:
        primary = improvement(reports['heldout'], metrics_policy)
        gate.update(schema=METRICS_SCHEMA, protocol_sha256=protocol_sha256,
                    zero_positive_loss=passed, primary=primary, policy=metrics_policy,
                    report=reports, passed=passed and primary['passed'],
                    failure_reasons=([primary['reason']] if primary['reason'] else []) + ([] if passed else ['positive_loss']))
    return gate
