"""Dedicated monoprocess SQLite registry. Synthetic offline evidence only.

An exact receipt is integrity evidence from a trusted local writer, not legal
authority. Never accept receipts or pickle bytes from an untrusted producer.
"""
import copy
from dataclasses import asdict
import hashlib
import io
import json
import os
from pathlib import Path
import re
import uuid
import platform
from importlib.metadata import version as distribution_version

from importlib import import_module

# Root admission must not initialize the training/serialization/SDK stack.
# Retain historical module attributes for imports and patch targets.
_LAZY_IMPORTS = {
    'joblib': ('joblib', None), 'np': ('numpy', None), 'pd': ('pandas', None),
    'MlflowClient': ('mlflow', 'MlflowClient'),
    'digest': ('learning_case_features', 'digest'),
    'feature_schema': ('learning_case_features', 'feature_schema'),
    'LearningDataset': ('learning_case_features', 'LearningDataset'),
    'train_candidate': ('learning_case_training', 'train_candidate'),
    'DatasetSplit': ('learning_case_training', 'DatasetSplit'),
}

def __getattr__(name):
    if name not in _LAZY_IMPORTS:
        raise AttributeError(name)
    module, attribute = _LAZY_IMPORTS[name]
    value = import_module(module)
    if attribute is not None:
        value = getattr(value, attribute)
    globals()[name] = value
    return value

def _require_imports(*names):
    for name in names:
        if name not in globals():
            __getattr__(name)

class RegistryError(ValueError):
    pass

NAMESPACE = 'standalone-synthetic-only'
MODEL_NAME = 'standalone-synthetic-learning-v1'
ALLOWED_ROOT = Path(__file__).resolve().parents[1]/'artifacts/learning'
MAPPING = {'version':'synthetic-axis-fixture-v1','namespace':NAMESPACE,
           'topic_ids':['101','102','103'],
           'filter_keys':['esrs_e1_energy','esrs_e2_pollution_of_air','esrs_e3_summary'],
           'approved_common_axis':False}

def allowed_root():
    """Caller containment metadata only; package authority never chooses a root."""
    marker = os.environ.get('I4S_BATCH_NATIVE_ARTIFACT_ROOT')
    if marker is None:
        return ALLOWED_ROOT
    if (os.name != 'nt' or os.environ.get('APP_ENV') != 'testing'
            or os.environ.get('I4S_BATCH_SYNTHETIC_CAPABILITY') != 'test-namespace:t07-export'
            or os.environ.get('I4S_BATCH_ADVERSARIAL_MODE', '') not in ('', 'receipt_generation')):
        raise RegistryError('registry.native_root_denied')
    expected = Path(__file__).resolve().parents[2] / 'artifacts/learning-native/tests'
    lexical = marker.replace('\\', '/')
    if re.fullmatch(re.escape(expected.as_posix()) + '/[a-f0-9]{32}', lexical) is None:
        raise RegistryError('registry.native_root_denied')
    root = Path(lexical)
    if not root.is_dir():
        raise RegistryError('registry.native_root_denied')
    for component in (root, *root.parents):
        if (component.is_symlink() or component.stat().st_file_attributes & 0x400
                or component.resolve().as_posix() != component.as_posix()):
            raise RegistryError('registry.native_root_denied')
    return root


def sha(data):
    return hashlib.sha256(data).hexdigest()

def _representation(candidate):
    fields = {key:copy.deepcopy(value) for key,value in vars(candidate).items() if key != 'pipelines'}
    fitted = {}
    for label,pipeline in candidate.pipelines.items():
        if list(pipeline.named_steps) != ['preprocessor','classifier']:
            raise RegistryError('registry.pipeline_shape')
        pre = pipeline.named_steps['preprocessor']; clf = pipeline.named_steps['classifier']
        enc = pre.named_transformers_['cat']
        fitted[label] = dict(pipeline_type=type(pipeline).__module__+'.'+type(pipeline).__name__,
            preprocessor_type=type(pre).__module__+'.'+type(pre).__name__,
            encoder_type=type(enc).__module__+'.'+type(enc).__name__,
            classifier_type=type(clf).__module__+'.'+type(clf).__name__,
            features=pre.feature_names_in_.tolist(),
            transformers=[(name,cols) for name,_,cols in pre.transformers], remainder=pre.remainder,
            handle_unknown=enc.handle_unknown, categories=[v.tolist() for v in enc.categories_],
            classes=clf.classes_.tolist(),coef=clf.coef_.tolist(),intercept=clf.intercept_.tolist(),
            n_features=int(clf.n_features_in_),iterations=clf.n_iter_.tolist(),
            classifier_params=clf.get_params(), encoder_params={
                key:(value.__module__+'.'+value.__name__ if key == 'dtype' else value)
                for key,value in enc.get_params().items()})
    fields['pipelines']=fitted
    return fields

def validate_candidate(candidate,dataset,split,binding=None):
    """Refit from sealed synthetic matrices, never trust mutable model metadata."""
    _require_imports('train_candidate', 'digest')
    try:
        expected = train_candidate(dataset,split,synthetic_policy=candidate.policy,seed=candidate.seeds['estimator'],
            synthetic_search={'synthetic_only':True,'trials':2,'C':[0.1,1.0,10.0]} if binding is not None else None)
        if candidate.topic_ids != (binding['mapping']['topic_ids'] if binding is not None else MAPPING['topic_ids']) or candidate.promotion_allowed is not False:
            raise RegistryError('registry.synthetic_axis_only')
        if binding is not None and dataset.authorized_context is None:
            raise RegistryError('registry.authorized_snapshot_required')
        actual = _representation(candidate)
        if digest(actual) != digest(_representation(expected)):
            raise RegistryError('registry.candidate_mutated')
        return actual
    except RegistryError:
        raise
    except Exception as exc:
        raise RegistryError('registry.candidate_invalid') from exc

def _json_bytes(value):
    # JSON representation also rejects NaN, arbitrary params, and non-JSON values.
    return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode()

def _serialization_dependencies():
    return {key:distribution_version(key) for key in ('mlflow','pandera','joblib','scipy','sqlalchemy')}

def _validate_manifest_representation(manifest):
    """JSON-only structural checks before loading trusted, exact-hashed pickle."""
    _require_imports('np', 'feature_schema', 'digest')
    rep=manifest['representation']; topics=manifest['mapping']['topic_ids'] if manifest['namespace']=='test-namespace:t10-synthetic-shadow' else MAPPING['topic_ids']
    axes=set(topics); p=rep['policy']
    if (rep['topic_ids'] != topics or rep['feature_schema'] != feature_schema()
            or rep['promotion_allowed'] is not False
            or set(rep['label_status']) != axes or set(rep['supports']) != axes
            or set(rep['thresholds']) != axes or set(p) != {'schema_version','synthetic_only','promotion_allowed','label_thresholds','sector_guard','crc_floor'}
            or p['schema_version'] != 'synthetic-raw-policy-v1' or p['synthetic_only'] is not True
            or any(p[key] is not False for key in ['promotion_allowed','sector_guard','crc_floor'])
            or p['label_thresholds'] != rep['thresholds']
            or any(type(v) not in (int,float) or not np.isfinite(v) or not 0 <= v <= 1 for v in rep['thresholds'].values())):
        raise RegistryError('registry.representation_policy_axis')
    deps={'python':platform.python_version(),**{key:distribution_version(key) for key in ('scikit-learn','pandas','numpy')}}
    if (manifest['dependency_versions'] != deps or rep['dependency_versions'] != deps
            or manifest['dependency_digest'] != digest(deps)
            or manifest['serialization_dependencies'] != _serialization_dependencies()
            or manifest['dependency_lock_sha256'] != sha((Path(__file__).resolve().parents[1]/'requirements-learning.lock.txt').read_bytes())
            or manifest['seeds'] != rep['seeds'] or set(rep['seeds']) != {'estimator'}
            or type(rep['seeds']['estimator']) is not int or not 0 <= rep['seeds']['estimator'] < 2**32
            or rep['training_case_ids'] != manifest['split']['train']
            or rep['split_digest'] != manifest['split']['split_digest']
            or rep['dataset_digest'] != manifest['dataset_digest']):
        raise RegistryError('registry.representation_lineage')
    fitted=set()
    for topic in topics:
        support=rep['supports'][topic]
        if (set(support) != {'observed','positive','negative'}
                or any(type(v) is not int or v < 0 for v in support.values())
                or support['observed'] != support['positive']+support['negative']
                or support['observed'] > len(rep['training_case_ids'])):
            raise RegistryError('registry.representation_support')
        status=('not_evaluable:no_observations' if not support['observed'] else
                'fitted' if support['positive'] and support['negative'] else 'not_evaluable:single_class')
        if rep['label_status'][topic] != status: raise RegistryError('registry.representation_status')
        if status == 'fitted': fitted.add(topic)
    if set(rep['pipelines']) != fitted: raise RegistryError('registry.representation_pipeline_axis')
    for pipe in rep['pipelines'].values():
        categories=pipe['categories']; width=sum(len(c) for c in categories)
        if (pipe['classes'] != [0,1] or any(type(c) is not int for c in pipe['classes'])
                or pipe['features'] != list(feature_schema()['columns'])
                or len(categories) != len(feature_schema()['columns'])
                or not all(categories) or pipe['n_features'] != width
                or len(pipe['coef']) != 1 or len(pipe['coef'][0]) != width
                or len(pipe['intercept']) != 1
                or not np.isfinite(np.asarray(pipe['coef'],dtype=float)).all()
                or not np.isfinite(np.asarray(pipe['intercept'],dtype=float)).all()):
            raise RegistryError('registry.representation_model_shape')

def _manifest(candidate,dataset,split,representation,generation,run_id,artifact_sha256,binding=None):
    """Complete deterministic snapshot shared by producer and packet reconciliation."""
    _require_imports('digest')
    return dict(namespace=NAMESPACE if binding is None else binding['mapping']['namespace'],synthetic_only=True,promotion_allowed=False,generation=generation,
                run_id=run_id,artifact_sha256=artifact_sha256,schema_digest=digest(candidate.feature_schema),policy_digest=digest(candidate.policy),
                mapping=MAPPING if binding is None else binding['mapping'],mapping_digest=digest(MAPPING if binding is None else binding['mapping']),representation=representation,
                dataset_content_digest=dataset.content_digest,split=asdict(split),
                dataset_schema=dict(columns=dataset.X.columns.tolist(),topic_ids=dataset.topic_ids),
                dataset_digest=dataset.dataset_digest,dependency_versions=candidate.dependency_versions,
                dependency_digest=digest(candidate.dependency_versions),seeds=candidate.seeds,
                serialization_dependencies=_serialization_dependencies(),
                dependency_lock_sha256=sha((Path(__file__).resolve().parents[1]/'requirements-learning.lock.txt').read_bytes()),
                **({} if binding is None else dict(case_binding=binding,code_sha256={
                    name:sha((Path(__file__).resolve().parent/name).read_bytes()) for name in
                    ('learning_case_features.py','learning_case_training.py','learning_case_dataset.py','learning_candidate_registry.py',
                     'learning_case_prediction_adapter.py','../scripts/learning-case-batch.py')},optuna_version=distribution_version('optuna'))))

class LocalCandidateRegistry:
    PARAM_KEYS={'dataset_digest','split_digest','schema_digest','policy_digest','dependency_digest','seed','namespace'}
    def __init__(self,root,case_binding=None):
        self.case_binding=copy.deepcopy(case_binding)
        self.mapping=MAPPING if case_binding is None else case_binding['mapping']
        self.namespace=NAMESPACE if case_binding is None else 'test-namespace:t10-synthetic-shadow'
        self.model_name=MODEL_NAME if case_binding is None else 't10-synthetic-shadow-v1'
        if case_binding is not None:
            self._check_binding(case_binding)
        allowed=allowed_root()
        if os.environ.get('I4S_BATCH_NATIVE_ARTIFACT_ROOT') is not None:
            path=Path(root)
            if (case_binding is None or path.as_posix() != (allowed/('05b-candidate-'+case_binding['token']['batch_id'])).as_posix()):
                raise RegistryError('registry.private_root_required')
            for component in (path, *path.parents):
                if component.exists() and (component.is_symlink() or component.stat().st_file_attributes & 0x400 or component.resolve().as_posix() != component.as_posix()):
                    raise RegistryError('registry.private_root_required')
        self.root=Path(root).resolve()
        if not self.root.is_relative_to(allowed.resolve()) or not (self.root.name.startswith('01c-') or (case_binding is not None and self.root.name=='05b-candidate-'+case_binding['token']['batch_id'])):
            raise RegistryError('registry.private_root_required')
        self.root.mkdir(parents=True,exist_ok=True)
        self.private=self.root/'private'/self.namespace.replace(':','-')
        self.private.mkdir(parents=True,exist_ok=True)
        if not self.private.resolve().is_relative_to(self.root):
            raise RegistryError('registry.private_namespace_escape')
        self.uri='sqlite:///'+(self.root/'registry.sqlite').as_posix()
        _require_imports('MlflowClient')
        self.client=MlflowClient(tracking_uri=self.uri,registry_uri=self.uri)
        experiment=self.client.get_experiment_by_name(self.namespace)
        if experiment is None:
            self.experiment_id=self.client.create_experiment(self.namespace,artifact_location=(self.root/'experiment-artifacts').as_uri())
        else:
            if experiment.artifact_location != (self.root/'experiment-artifacts').as_uri():
                raise RegistryError('registry.experiment_artifact_root')
            self.experiment_id=experiment.experiment_id

    def register(self,candidate,dataset,split):
        _require_imports('pd', 'LearningDataset', 'DatasetSplit', 'joblib', 'digest', 'train_candidate')
        # Rebuild controlled frames/objects rather than pickle arbitrary caller attributes.
        def frame(value):
            clean=json.loads(_json_bytes(value.to_dict('split')))
            return pd.DataFrame(clean['data'],index=clean['index'],columns=clean['columns'],dtype=object)
        dataset=LearningDataset(frame(dataset.X),frame(dataset.values),frame(dataset.masks),frame(dataset.metadata),
            list(dataset.case_ids),list(dataset.topic_ids),dataset.dataset_digest,dataset.content_digest,copy.deepcopy(dataset.authorized_context) if self.case_binding is not None else None)
        split=DatasetSplit(tuple(split.train),tuple(split.calibration),tuple(split.holdout),
                           copy.deepcopy(split.excluded),copy.deepcopy(split.cutoffs),split.split_digest)
        representation=validate_candidate(candidate,dataset,split,self.case_binding)
        # Persist the refitted controlled object, whose representation was compared exactly.
        if self.case_binding is None:
            candidate=train_candidate(dataset,split,synthetic_policy=copy.deepcopy(candidate.policy),seed=candidate.seeds['estimator'])
        generation=uuid.uuid4().hex
        schema_digest=digest(candidate.feature_schema); policy_digest=digest(candidate.policy)
        run=self.client.create_run(self.experiment_id,tags={'namespace':self.namespace})
        run_id=run.info.run_id; version=None
        try:
            params=dict(dataset_digest=dataset.dataset_digest,split_digest=split.split_digest,
                schema_digest=schema_digest,policy_digest=policy_digest,
                dependency_digest=digest(candidate.dependency_versions),seed=str(candidate.seeds['estimator']),namespace=self.namespace)
            for key,value in params.items(): self.client.log_param(run_id,key,value)
            for key,value in dict(train_cases=len(split.train),calibration_cases=len(split.calibration),
                                  holdout_cases=len(split.holdout),fitted_labels=len(candidate.pipelines)).items():
                self.client.log_metric(run_id,key,float(value))
            package=self.private/generation
            package.mkdir()
            buffer=io.BytesIO(); joblib.dump(dict(candidate=candidate,dataset=dataset,split=split),buffer)
            payload=buffer.getvalue(); artifact_sha256=sha(payload)
            (package/'pipeline.joblib').write_bytes(payload)
            manifest=_manifest(candidate,dataset,split,representation,generation,run_id,artifact_sha256,self.case_binding)
            manifest_bytes=_json_bytes(manifest); manifest_digest=sha(manifest_bytes)
            (package/'manifest.json').write_bytes(manifest_bytes)
            self.client.log_artifacts(run_id,str(package),artifact_path='package')
            if not self.client.search_registered_models(filter_string=f"name = '{self.model_name}'"):
                self.client.create_registered_model(self.model_name,tags={'namespace':self.namespace})
            tags=dict(namespace=self.namespace,generation=generation,artifact_sha256=artifact_sha256,
                      schema_digest=schema_digest,policy_digest=policy_digest,manifest_digest=manifest_digest,
                      mapping_digest=digest(self.mapping),eligible='false')
            version=self.client.create_model_version(self.model_name,package.as_uri(),run_id=run_id,tags=tags,await_creation_for=0)
            self.client.set_terminated(run_id,status='FINISHED')
            # Eligibility is set only after terminal run and actual registry readback.
            actual=self.client.get_model_version(self.model_name,version.version)
            if actual.source != package.as_uri() or actual.run_id != run_id or actual.status != 'READY':
                raise RegistryError('registry.readback_failed')
            self.client.set_model_version_tag(self.model_name,version.version,'eligible','true')
            receipt=dict(name=self.model_name,version=str(version.version),run_id=run_id,generation=generation,
                artifact_sha256=artifact_sha256,schema_digest=schema_digest,policy_digest=policy_digest,
                manifest_digest=manifest_digest,mapping_digest=digest(self.mapping),namespace=self.namespace,promotion_allowed=False)
            self._verify_exact_packet(receipt)
            receipt_bytes=_json_bytes(receipt)
            (package/'receipt.json').write_bytes(receipt_bytes)
            if (package/'receipt.json').read_bytes() != receipt_bytes:
                raise RegistryError('registry.receipt_readback_failed')
            # Final publication step. Keep temporary evidence on failure; no removal.
            marker=_json_bytes(dict(state='complete',receipt=receipt))
            if len(marker) > 8192: raise RegistryError('registry.publication_invalid')
            temporary=package/'completion.pending'
            temporary.write_bytes(marker)
            if temporary.read_bytes() != marker:
                raise RegistryError('registry.publication_readback_failed')
            os.replace(temporary,package/'completion.json')
            return receipt
        except Exception as exc:
            # Independent best-effort SQL compensations; the missing marker fences
            # consumption even when both writes fail. Preserve the primary cause.
            try:
                self.client.set_terminated(run_id,status='FAILED')
            except Exception:
                pass
            if version is not None:
                try:
                    self.client.set_model_version_tag(self.model_name,version.version,'eligible','false')
                except Exception:
                    pass
            # Historical artifacts and failed versions remain; no destructive cleanup.
            raise RegistryError('registry.registration_ineligible') from exc

    def register_case(self,candidate,dataset,split,binding):
        self._check_binding(binding)
        if binding != self.case_binding:
            raise RegistryError('registry.case_binding_mismatch')
        return self.register(candidate,dataset,split)

    @staticmethod
    def _check_binding(binding):
        if (type(binding) is not dict or set(binding) != {'token','context_digest','authority_generation','authority_digest','mapping'}
                or type(binding['token']) is not dict or set(binding['token']) != {'batch_id','fence'}
                or type(binding['token']['fence']) is not int or not 1 <= binding['token']['fence'] <= 9007199254740991
                or re.fullmatch('[a-f0-9]{32}',binding['token']['batch_id']) is None
                or type(binding['authority_generation']) is not int or not 1 <= binding['authority_generation'] <= 9007199254740991
                or any(re.fullmatch('[a-f0-9]{64}',binding[k]) is None for k in ('context_digest','authority_digest'))):
            raise RegistryError('registry.case_binding_invalid')
        m=binding['mapping']
        if (type(m) is not dict or set(m) != set(MAPPING) or m['namespace'] != 'test-namespace:t10-synthetic-shadow'
                or m['version'] != 't10-explicit-fixture-v1' or m['approved_common_axis'] is not False
                or type(m['topic_ids']) is not list or len(m['topic_ids']) != 3 or len(set(m['topic_ids'])) != 3
                or any(type(x) is not str for x in m['topic_ids']) or m['filter_keys'] != MAPPING['filter_keys']):
            raise RegistryError('registry.explicit_fixture_required')

    def package_path(self,receipt):
        generation=receipt.get('generation')
        if type(generation) is not str or re.fullmatch('[0-9a-f]{32}',generation) is None:
            raise RegistryError('registry.generation_invalid')
        path=(self.private/generation).resolve()
        if not path.is_relative_to(self.private.resolve()): raise RegistryError('registry.source_invalid')
        return path

    def load_exact(self,receipt):
        try:
            package=self.package_path(receipt)
            def read_record(name):
                with (package/name).open('rb') as stream:
                    data=stream.read(8193)
                if len(data) > 8192: raise RegistryError('registry.publication_invalid')
                return json.loads(data)
            if (_json_bytes(read_record('completion.json')) != _json_bytes(dict(state='complete',receipt=receipt))
                    or _json_bytes(read_record('receipt.json')) != _json_bytes(receipt)):
                raise RegistryError('registry.publication_incomplete')
        except RegistryError:
            raise
        except Exception:
            raise RegistryError('registry.publication_incomplete') from None
        return self._verify_exact_packet(receipt)

    def _verify_exact_packet(self,receipt):
        """Trusted producer prepublication verification; never a public bypass flag."""
        try:
            keys={'name','version','run_id','generation','artifact_sha256','schema_digest','policy_digest',
                  'manifest_digest','mapping_digest','namespace','promotion_allowed'}
            if (type(receipt) is not dict or set(receipt) != keys or receipt['name'] != self.model_name
                    or type(receipt['version']) is not str or re.fullmatch('[1-9][0-9]*',receipt['version']) is None
                    or receipt['namespace'] != self.namespace or receipt['promotion_allowed'] is not False):
                raise RegistryError('registry.exact_receipt_required')
            for key in keys:
                if key.endswith('digest') or key == 'artifact_sha256':
                    if type(receipt[key]) is not str or re.fullmatch('[a-f0-9]{64}',receipt[key]) is None:
                        raise RegistryError('registry.digest_invalid')
            version=self.client.get_model_version(receipt['name'],receipt['version'])
            run=self.client.get_run(receipt['run_id'])
            package=self.package_path(receipt)
            if (version.source != package.as_uri() or version.run_id != receipt['run_id']
                    or version.status != 'READY' or version.tags.get('eligible') != 'true'
                    or run.info.status != 'FINISHED' or run.info.experiment_id != self.experiment_id):
                raise RegistryError('registry.source_or_run_ineligible')
            for key in ['generation','artifact_sha256','schema_digest','policy_digest','manifest_digest','mapping_digest','namespace']:
                if version.tags.get(key) != receipt[key]: raise RegistryError('registry.metadata_mismatch')
            manifest_bytes=(package/'manifest.json').read_bytes()
            payload=(package/'pipeline.joblib').read_bytes()
            # Hash EXACT in-memory bytes before any joblib deserialization (no TOCTOU reread).
            if sha(manifest_bytes) != receipt['manifest_digest'] or sha(payload) != receipt['artifact_sha256']:
                raise RegistryError('registry.hash_mismatch')
            manifest=json.loads(manifest_bytes)
            _validate_manifest_representation(manifest)
            if self.case_binding is not None and manifest.get('case_binding') != self.case_binding:
                raise RegistryError('registry.case_binding_mismatch')
            if self.case_binding is not None:
                refs=('learning_case_features.py','learning_case_training.py','learning_case_dataset.py','learning_candidate_registry.py',
                      'learning_case_prediction_adapter.py','../scripts/learning-case-batch.py')
                if (manifest.get('code_sha256') != {name:sha((Path(__file__).resolve().parent/name).read_bytes()) for name in refs}
                        or manifest.get('optuna_version') != distribution_version('optuna')):
                    raise RegistryError('registry.code_or_tuning_dependency_mismatch')
            if (manifest['namespace'] != self.namespace or manifest['synthetic_only'] is not True
                    or manifest['artifact_sha256'] != receipt['artifact_sha256']
                    or manifest['run_id'] != receipt['run_id']
                    or manifest['mapping'] != self.mapping or manifest['mapping_digest'] != digest(self.mapping)
                    or manifest['representation']['topic_ids'] != self.mapping['topic_ids']
                    or digest(manifest['representation']['feature_schema']) != receipt['schema_digest']
                    or digest(manifest['representation']['policy']) != receipt['policy_digest']
                    or manifest['generation'] != receipt['generation'] or manifest['promotion_allowed'] is not False):
                raise RegistryError('registry.manifest_mismatch')
            expected_params=dict(dataset_digest=manifest['dataset_digest'],split_digest=manifest['split']['split_digest'],
                schema_digest=receipt['schema_digest'],policy_digest=receipt['policy_digest'],
                dependency_digest=manifest['dependency_digest'],seed=str(manifest['seeds']['estimator']),namespace=self.namespace)
            if run.data.params != expected_params: raise RegistryError('registry.run_params_mismatch')
            _require_imports('joblib', 'LearningDataset', 'DatasetSplit')
            packet=joblib.load(io.BytesIO(payload))
            if (type(packet) is not dict or set(packet) != {'candidate','dataset','split'}
                    or type(packet['dataset']) is not LearningDataset or type(packet['split']) is not DatasetSplit):
                raise RegistryError('registry.packet_invalid')
            actual=validate_candidate(packet['candidate'],packet['dataset'],packet['split'],self.case_binding)
            expected=_manifest(packet['candidate'],packet['dataset'],packet['split'],actual,
                receipt['generation'],receipt['run_id'],receipt['artifact_sha256'],self.case_binding)
            if _json_bytes(expected) != _json_bytes(manifest):
                raise RegistryError('registry.reloaded_packet_mismatch')
            return packet['candidate']
        except RegistryError:
            raise
        except Exception as exc:
            raise RegistryError('registry.exact_load_failed') from exc
