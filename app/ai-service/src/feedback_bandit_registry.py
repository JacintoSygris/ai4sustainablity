"""Private single-writer version store. An atomic catalogue is the commit point.

Laravel sends its bounded, authorised ledger. Prefixes update incrementally;
withdrawal, correction or retention rebuilds from that ledger. Serving never writes.
"""
import contextlib
import fcntl
import json
import os
from pathlib import Path
import re
import tempfile
import time
import uuid

from feedback_bandit import FeedbackBandit, canonical, digest, require

RETENTION = 30 * 86400


def read_json(path, limit=32_000_000):
    require(not path.is_symlink() and path.stat().st_size <= limit)
    return json.loads(path.read_bytes())


def atomic(path, value):
    require(not path.is_symlink())
    raw = canonical(value)
    require(len(raw) <= 32_000_000)
    with tempfile.NamedTemporaryFile(dir=path.parent, prefix='.pending-', delete=False) as stream:
        temp = Path(stream.name)
        try:
            os.chmod(temp, 0o600)
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
            os.replace(temp, path)
            fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(fd)
            finally:
                os.close(fd)
        finally:
            temp.unlink(missing_ok=True)


class BanditRegistry:
    def __init__(self, root):
        self.root = Path(root)
        require(self.root.is_absolute() and self.root.is_dir() and self.root.resolve() == self.root)
        require(not any(p.is_symlink() for p in (self.root, *self.root.parents)))
        self.path = self.root / 'bandit-catalogue.json'

    @contextlib.contextmanager
    def locked(self):
        path = self.root / 'bandit.lock'
        fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'a') as stream:
            fcntl.flock(stream, fcntl.LOCK_EX)
            yield

    def catalogue(self):
        if not self.path.exists():
            return {'schema': 'bandit-registry-v1', 'candidate': None, 'active': None,
                    'previous': None, 'versions': {}, 'audit': []}
        value = read_json(self.path)
        require(value['schema'] == 'bandit-registry-v1')
        return value

    def lease(self):
        lease = read_json(self.root / 'authority.json', 1_048_576)
        require(time.time() < lease['expires_at'] <= time.time() + 95)
        return lease

    def allowed(self, manifest):
        lease = self.lease()
        require(manifest['created_at'] > time.time() - RETENTION and time.time() < manifest['expires_at'])
        require(set(manifest['subjects']) <= set(lease['bandit_subjects']))
        # A superseded revision must not be silently served or replayed.
        require(all(lease.get('bandit_revisions', {}).get(k) == v
                    for k, v in manifest['revisions'].items()))

    def state(self, manifest, *, authority=True):
        require(re.fullmatch('[a-f0-9]{32}', manifest['id']) is not None)
        if authority:
            self.allowed(manifest)
        learner = FeedbackBandit.from_envelope(read_json(self.root / ('bandit-' + manifest['id'] + '.json'), 8_000_000))
        require(learner.envelope()['sha256'] == manifest['sha256'])
        return learner

    def sync(self, initial, rows):
        require(isinstance(rows, list) and len(rows) <= 20000)
        require(initial.sequence == 0 and initial.config['shadow'] is False)
        ids = [r['id'] for r in rows]
        require(len(ids) == len(set(ids)) and all(re.fullmatch('[a-f0-9]{64}', k) for k in ids))
        revisions = {}
        for row in rows:
            require(set(row) == {'id', 'subject', 'stream', 'revision', 'expires_at', 'event'})
            require(re.fullmatch('[a-f0-9-]{36}', row['subject']) is not None)
            require(row['stream'] not in revisions or revisions[row['stream']] == row['revision'])
            require(type(row['expires_at']) in (int, float) and time.time() < row['expires_at'] <= time.time() + RETENTION + 1)
            revisions[row['stream']] = row['revision']
        with self.locked():
            cat = self.catalogue()
            current = cat['versions'].get(cat['candidate'])
            fingerprints = [digest(r) for r in rows]
            if current and current['events'] == fingerprints and current['config_sha256'] == digest(initial.config):
                self.allowed(current)
                return self.summary(cat)
            learner = initial
            if current and current['config_sha256'] == digest(initial.config) and fingerprints[:len(current['events'])] == current['events']:
                try:
                    learner = self.state(current)
                except (ValueError, OSError, KeyError):
                    learner = initial
            for row in rows[learner.sequence:]:
                learner.update_event({**row['event'], 'sequence': learner.sequence + 1})
            version = uuid.uuid4().hex
            manifest = {'id': version, 'sha256': learner.envelope()['sha256'], 'events_applied': learner.sequence,
                        'created_at': time.time(), 'expires_at': min((r['expires_at'] for r in rows), default=time.time() + RETENTION), 'subjects': sorted({r['subject'] for r in rows}),
                        'revisions': revisions, 'events': fingerprints, 'config_sha256': digest(initial.config)}
            self.allowed(manifest)
            # The file is durable before the catalogue points to it; an orphan
            # after interruption is harmless and is removed by privacy upkeep.
            learner.save(self.root / ('bandit-' + version + '.json'))
            cat['versions'][version] = manifest
            cat['candidate'] = version
            atomic(self.path, cat)
            return self.summary(cat)

    def select(self, operation, candidate, actor, reason):
        require(re.fullmatch(r'admin:[1-9][0-9]*', actor) is not None)
        require(isinstance(reason, str) and 1 <= len(reason) <= 500)
        with self.locked():
            cat = self.catalogue()
            target = candidate if operation == 'promote' else cat['previous']
            require(operation in ('promote', 'rollback'))
            if operation == 'promote':
                require(target is not None and target == cat['candidate'])
            if target is not None:
                self.state(cat['versions'][target])
            if operation == 'promote' and target == cat['active']:
                return self.summary(cat)  # Retry preserves the rollback target under the lock.
            before = cat['active']
            cat['previous'], cat['active'] = before, target
            cat['audit'].append({'at': time.time(), 'action': operation, 'actor': actor, 'reason': reason,
                                 'before': self.hash_of(cat, before), 'after': self.hash_of(cat, target)})
            atomic(self.path, cat)
            return self.summary(cat)

    @staticmethod
    def hash_of(cat, version):
        return cat['versions'][version]['sha256'] if version in cat['versions'] else None

    def maintain(self):
        with self.locked():
            cat = self.catalogue()
            # Never purge on an unavailable lease: stop serving and retry later.
            self.lease()
            remove = []
            for key, manifest in list(cat['versions'].items()):
                try:
                    self.allowed(manifest)
                except ValueError:
                    remove.append(key)
                    del cat['versions'][key]
            for name in ('candidate', 'active', 'previous'):
                if cat[name] in remove:
                    cat[name] = None
            cat['audit'] = [a for a in cat['audit'] if a['at'] > time.time() - RETENTION]
            atomic(self.path, cat)
            for path in self.root.glob('bandit-*.json'):
                if re.fullmatch(r'bandit-[a-f0-9]{32}\.json', path.name) and path.stem[7:] not in cat['versions']:
                    path.unlink()
            return self.summary(cat)

    def summary(self, cat=None):
        cat = self.catalogue() if cat is None else cat
        def item(version):
            if version is None or version not in cat['versions']:
                return None
            m = cat['versions'][version]
            eligible = True
            try:
                self.state(m)
            except (ValueError, OSError, KeyError):
                eligible = False
            return {k: m[k] for k in ('id', 'sha256', 'events_applied', 'created_at')} | {'eligible': eligible}
        return {name: item(cat[name]) for name in ('candidate', 'active', 'previous')}

    def active(self):
        cat = self.catalogue()
        return self.state(cat['versions'][cat['active']]) if cat['active'] else None


def profile_initial():
    from services import service_predict as sp
    profile = sp.resolve_model_profile('new_format_732_v1_gpt41')
    return FeedbackBandit(profile.name,
        [k for k in sp.load_profile_esrs_columns(profile) if sp.is_new_format_candidate_key(k)],
        list(sp.load_profile_feature_columns(profile)), sp._model_artifact_hashes(profile)['esrs_classifier.pkl'], shadow=False)
