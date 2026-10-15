"""Reusable XBRL ranking and safe immutable runtime artifacts. No network or pickle."""
from __future__ import annotations
from collections import defaultdict
from datetime import datetime, timezone
import hashlib
import json
import platform
from pathlib import Path
import re
import zipfile
import numpy as np
import scipy
from scipy import sparse
import sklearn
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from services.xbrl_runtime_context import supported_context

MODEL_VERSION = "wp31-tfidf-logistic-v2"
SEED = 20261008
LEARNED_FEATURE_INDICES = [0, 1, 4, 5]
FEATURES = ["word_cosine", "char_cosine", "dr_agreement", "standard_agreement",
            "word_char_product", "token_overlap"]


def concept_text(qname: str) -> str:
    local = qname.split(":", 1)[-1]
    local = re.sub(r"([A-Z]+)([A-Z][a-z])", r"\1 \2", local)
    return re.sub(r"([a-z0-9])([A-Z])", r"\1 \2", local).replace("_", " ").lower()


def datapoint_text(row: dict) -> str:
    return " ".join(str(row.get(k) or "") for k in ("name", "description", "dr"))


def load_inputs(ig3_path: Path, map_path: Path):
    return load_input_bytes(ig3_path.read_bytes(), map_path.read_bytes())


def load_input_bytes(ig3_bytes: bytes, map_bytes: bytes):
    points = json.loads(ig3_bytes)["datapoints"]
    mapping = json.loads(map_bytes)["concepts"]
    ids = [p["id"] for p in points]
    if len(set(ids)) != len(ids) or set(ids) != set(mapping):
        raise ValueError("Datapoint IDs must be unique and reconcile with the map")
    known, unknown = [], []
    for p in points:
        entry = mapping[p["id"]]
        if entry.get("concept_id"):
            if entry.get("taggable_state") != "mapped" or not re.fullmatch(r"esrs:[A-Za-z_][A-Za-z0-9_]*", entry["concept_id"]):
                raise ValueError("Invalid known concept")
            known.append({**p, "target": entry["concept_id"]})
        else:
            unknown.append(p)
    return known, unknown, sorted({p["target"] for p in known})


class Matcher:
    def __init__(self, concepts: list[str], train: list[dict], labels: list[str] | None = None):
        self.concepts = concepts
        self.index = {c: i for i, c in enumerate(concepts)}
        labels = labels if labels is not None else [concept_text(c) for c in concepts]
        # The fixed candidate inventory is available to every fold. No held-out
        # datapoint text or target associations enter vectorizer/prior fitting.
        corpus = labels + [datapoint_text(p) for p in train]
        self.word = TfidfVectorizer(ngram_range=(1, 2), sublinear_tf=True)
        self.char = TfidfVectorizer(analyzer="char_wb", ngram_range=(3, 5), sublinear_tf=True)
        self.word.fit(corpus)
        self.char.fit(corpus)
        self.words = self.word.transform(labels)
        self.chars = self.char.transform(labels)
        self.tokens = [set(label.split()) for label in labels]
        self.dr = defaultdict(set)
        self.standard = defaultdict(set)
        for p in train:
            self.dr[p["dr"]].add(self.index[p["target"]])
            self.standard[p["esrs"]].add(self.index[p["target"]])
        self.model = None

    def features(self, point: dict, excluded_target: str | None = None) -> np.ndarray:
        query = datapoint_text(point)
        word = (self.words @ self.word.transform([query]).T).toarray().ravel()
        char = (self.chars @ self.char.transform([query]).T).toarray().ravel()
        dr = np.array([i in self.dr.get(point.get("dr"), ()) for i in range(len(self.concepts))], dtype=float)
        std = np.array([i in self.standard.get(point.get("esrs"), ()) for i in range(len(self.concepts))], dtype=float)
        if excluded_target is not None:
            # Simulate concept-disjoint inference during pair training too:
            # the positive group cannot supply its own DR/standard label.
            idx = self.index[excluded_target]
            dr[idx] = std[idx] = 0.0
        tokens = set(query.lower().split())
        overlap = np.array([len(tokens & t) / max(1, len(tokens | t)) for t in self.tokens])
        return np.column_stack((word, char, dr, std, word * char, overlap))

    def fit(self, train: list[dict]):
        rng = np.random.default_rng(SEED)
        xs, ys = [], []
        for point in train:
            features = self.features(point, excluded_target=point["target"])
            target = self.index[point["target"]]
            hard = self.order(self.cosine(features))[:20]
            random = rng.choice(len(self.concepts), min(20, len(self.concepts)), replace=False)
            negatives = sorted((set(hard) | set(random)) - {target})
            selected = [target] + negatives
            xs.append(features[selected])
            ys.extend([1] + [0] * len(negatives))
        self.model = LogisticRegression(max_iter=1000, random_state=SEED)
        # Learn textual similarities only. Target-derived DR/standard priors
        # cannot become label shortcuts or learn an inverse agreement penalty.
        self.model.fit(np.vstack(xs)[:, LEARNED_FEATURE_INDICES], ys)
        return self

    @staticmethod
    def cosine(features):
        return 0.5 * (features[:, 0] + features[:, 1]) + 0.05 * features[:, 2] + 0.02 * features[:, 3]

    @staticmethod
    def order(scores):
        # concepts sorted lexically: stable ties independent of source order.
        return np.argsort(-scores, kind="stable")

    def scores(self, point: dict):
        features = self.features(point)
        return {"dr_prefix_baseline": features[:, 2],
                "cosine_retrieval": self.cosine(features),
                "logistic_reranker": 1 / (1 + np.exp(-np.clip(
                    self.model.decision_function(features[:, LEARNED_FEATURE_INDICES])
                    + 0.25 * features[:, 2] + 0.10 * features[:, 3], -700, 700)))}


SCHEMA = "xbrl-runtime-identification-v1"
MAX_BYTES = 128 * 1024 * 1024
QNAME = re.compile(r"esrs:[A-Za-z_][A-Za-z0-9_]*\Z")
HASH = re.compile(r"[a-f0-9]{64}\Z")


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def json_bytes(value) -> bytes:
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, allow_nan=False) + "\n").encode()


def dependencies():
    return {"numpy": np.__version__, "scipy": scipy.__version__,
            "sklearn": sklearn.__version__, "python": platform.python_version()}


def read_bounded(path: Path, limit=MAX_BYTES) -> bytes:
    with path.open("rb") as handle:
        data = handle.read(limit + 1)
    if len(data) > limit:
        raise ValueError("artifact_too_large")
    return data


def catalog_inventory(raw: dict):
    if set(raw) != {"schema_version", "taxonomy_profile", "package_sha256", "languages", "concepts"} or raw["schema_version"] != SCHEMA:
        raise ValueError("catalog_schema")
    if not HASH.fullmatch(raw["package_sha256"]) or not re.fullmatch(r"[A-Za-z0-9_.-]{1,100}", raw["taxonomy_profile"]):
        raise ValueError("catalog_identity")
    if not raw["languages"] or not set(raw["languages"]) <= {"en", "es"}:
        raise ValueError("catalog_languages")
    entries = raw["concepts"]
    if not isinstance(entries, dict) or not 1 <= len(entries) <= 20000:
        raise ValueError("catalog_size")
    for qname, entry in entries.items():
        if not QNAME.fullmatch(qname) or set(entry) != {"labels", "descriptions", "type", "period_type", "abstract"}:
            raise ValueError("catalog_concept")
        if type(entry["abstract"]) is not bool or entry["period_type"] not in ("instant", "duration"):
            raise ValueError("catalog_metadata")
        if not isinstance(entry["type"], str) or len(entry["type"]) > 500 or not entry["type"].startswith("{"):
            raise ValueError("catalog_type")
        for field in ("labels", "descriptions"):
            if not isinstance(entry[field], dict) or not set(entry[field]) <= {"en", "es"}:
                raise ValueError("catalog_text")
            if any(not isinstance(v, str) or len(v) > 4000 for v in entry[field].values()):
                raise ValueError("catalog_text")
        if any(not entry["labels"].get(lang, "").strip() for lang in raw["languages"]):
            raise ValueError("catalog_label_missing")
    return entries


def compatibility(metadata: dict | None, indicator: dict):
    """Conservative exact resolved-type allowlist, shared with Laravel. Unknown != verified."""
    if metadata is None:
        return "map_only", ["taxonomy_unconfigured"]
    if metadata["abstract"]:
        return "incompatible", ["abstract_concept"]
    if indicator.get("period_type") and indicator["period_type"] != metadata["period_type"]:
        return "incompatible", ["period_mismatch"]
    types = {"stringItemType": "text", "textBlockItemType": "text", "booleanItemType": "boolean",
             "dateItemType": "date", "monetaryItemType": "monetary", "integerItemType": "integer",
             "decimalItemType": "number", "pureItemType": "number", "percentItemType": "number"}
    resolved = metadata["type"]
    namespace, _, local = resolved.partition("}")
    kind = types.get(local) if namespace in ("{http://www.xbrl.org/2003/instance", "{http://www.xbrl.org/dtr/type/non-numeric", "{http://www.xbrl.org/dtr/type/numeric") else None
    if kind is None or not indicator.get("value_type") or not indicator.get("period_type"):
        return "unverified", ["type_or_context_unverified"]
    if kind != indicator["value_type"]:
        return "incompatible", ["type_mismatch"]
    unit = indicator.get("unit")
    if kind == "monetary" and (not unit or not re.fullmatch(r"[A-Z]{3}", unit)):
        return "unverified", ["unit_unverified"]
    if kind in ("integer", "number") and unit != "pure":
        return "unverified", ["unit_unverified"]
    if kind in ("text", "boolean", "date") and unit:
        return "incompatible", ["unit_mismatch"]
    return "verified_taxonomy", []


def save_runtime(matcher, output: Path, *, model_version, source_commit, inputs,
                 taxonomy_profile, catalog_sha256, package_sha256=None, metadata=None,
                 languages=("en",), min_score=0.5, min_margin=0.05, source_files=None, source_dirty=False):
    if output.exists():
        raise ValueError("output_directory_already_exists")
    if not re.fullmatch(r"[A-Za-z0-9_.-]{1,100}", model_version) or not re.fullmatch(r"[a-f0-9]{40}", source_commit):
        raise ValueError("build_identity")
    if not (0 <= min_score <= 1 and 0 <= min_margin <= 1):
        raise ValueError("thresholds")
    output.mkdir(parents=True, mode=0o700)
    arrays = {"word_idf": matcher.word.idf_, "char_idf": matcher.char.idf_,
              "coef": matcher.model.coef_, "intercept": matcher.model.intercept_}
    for name in ("words", "chars"):
        matrix = getattr(matcher, name).tocsr()
        arrays.update({name + "_" + k: getattr(matrix, k) for k in ("data", "indices", "indptr")})
    np.savez(output / "arrays.npz", **arrays)
    state = {"concepts": matcher.concepts, "tokens": [sorted(t) for t in matcher.tokens],
             "word_vocab": matcher.word.vocabulary_, "char_vocab": matcher.char.vocabulary_,
             "dr": {k: sorted(v) for k, v in matcher.dr.items()},
             "standard": {k: sorted(v) for k, v in matcher.standard.items()}, "metadata": metadata or {}}
    (output / "state.json").write_bytes(json_bytes(state))
    manifest = {"schema_version": SCHEMA, "model_version": model_version, "source_commit": source_commit,
                "built_at": datetime.now(timezone.utc).isoformat(), "dependencies": dependencies(),
                "source_files": source_files or {}, "source_dirty": source_dirty,
                "features": FEATURES, "learned_feature_indices": LEARNED_FEATURE_INDICES,
                "parameters": {"word_ngrams": [1, 2], "char_ngrams": [3, 5], "sublinear_tf": True,
                               "dr_prior": 0.25, "standard_prior": 0.10, "seed": SEED,
                               "logistic_C": 1.0, "solver": "lbfgs", "hard_negatives": 20, "random_negatives": 20},
                "inputs": inputs, "languages": list(languages), "taxonomy_profile": taxonomy_profile,
                "catalog_sha256": catalog_sha256, "package_sha256": package_sha256,
                "thresholds": {"min_score": min_score, "min_margin": min_margin},
                "artifacts": {name: digest((output / name).read_bytes()) for name in ("state.json", "arrays.npz")}}
    (output / "manifest.json").write_bytes(json_bytes(manifest))
    return RuntimeMatcher.load(output)


class RuntimeMatcher:
    @classmethod
    def load(cls, directory: Path):
        # Fixed names only; bound both compressed bytes and ZIP's expanded arrays before numpy.
        manifest_bytes = read_bounded(directory / "manifest.json", 65536)
        manifest = json.loads(manifest_bytes)
        if manifest["schema_version"] != SCHEMA or manifest["dependencies"] != dependencies() or manifest["features"] != FEATURES or manifest["learned_feature_indices"] != LEARNED_FEATURE_INDICES:
            raise ValueError("incompatible_artifact")
        expected_parameters = {"word_ngrams": [1, 2], "char_ngrams": [3, 5], "sublinear_tf": True,
            "dr_prior": 0.25, "standard_prior": 0.10, "seed": SEED,
            "logistic_C": 1.0, "solver": "lbfgs", "hard_negatives": 20, "random_negatives": 20}
        if manifest["parameters"] != expected_parameters or not re.fullmatch(r"[A-Za-z0-9_.-]{1,100}", manifest["model_version"]) or not re.fullmatch(r"[a-f0-9]{40}", manifest["source_commit"]):
            raise ValueError("incompatible_parameters")
        if not HASH.fullmatch(manifest["inputs"]["concept_map_sha256"]):
            raise ValueError("invalid_input_identity")
        if not manifest["languages"] or not set(manifest["languages"]) <= {"es", "en"}:
            raise ValueError("invalid_languages")
        if not HASH.fullmatch(manifest["catalog_sha256"]) or (manifest["package_sha256"] is not None and not HASH.fullmatch(manifest["package_sha256"])):
            raise ValueError("invalid_identity")
        for threshold in ("min_score", "min_margin"):
            value = manifest["thresholds"][threshold]
            if type(value) not in (float, int) or not np.isfinite(value) or not 0 <= value <= 1:
                raise ValueError("invalid_thresholds")
        blobs = {name: read_bounded(directory / name) for name in ("state.json", "arrays.npz")}
        if set(manifest["artifacts"]) != set(blobs) or any(digest(data) != manifest["artifacts"][name] for name, data in blobs.items()):
            raise ValueError("artifact_hash_mismatch")
        import io
        with zipfile.ZipFile(io.BytesIO(blobs["arrays.npz"])) as archive:
            if len(archive.infolist()) != 10 or sum(i.file_size for i in archive.infolist()) > MAX_BYTES:
                raise ValueError("artifact_too_large")
        state = json.loads(blobs["state.json"])
        concepts = state["concepts"]
        if not 2 <= len(concepts) <= 20000 or concepts != sorted(set(concepts)) or any(not QNAME.fullmatch(c) for c in concepts):
            raise ValueError("invalid_inventory")
        model = Matcher.__new__(Matcher)  # Never __init__/fit during loading or serving.
        model.concepts, model.index = concepts, {c: i for i, c in enumerate(concepts)}
        with np.load(io.BytesIO(blobs["arrays.npz"]), allow_pickle=False) as arrays:
            for key in arrays.files:
                if arrays[key].dtype.kind not in "fiu" or not np.isfinite(arrays[key]).all():
                    raise ValueError("non_finite_array")
            for name, matrix_name in (("word", "words"), ("char", "chars")):
                vocab = state[name + "_vocab"]
                if not 1 <= len(vocab) <= 500000 or set(vocab.values()) != set(range(len(vocab))) or any(not isinstance(k, str) or len(k) > 500 for k in vocab):
                    raise ValueError("invalid_vocabulary")
                vectorizer = TfidfVectorizer(vocabulary=vocab, sublinear_tf=True,
                    **({"ngram_range": (1, 2)} if name == "word" else {"analyzer": "char_wb", "ngram_range": (3, 5)}))
                idf = arrays[name + "_idf"]
                if idf.shape != (len(vocab),) or (idf <= 0).any():
                    raise ValueError("invalid_idf")
                vectorizer.idf_ = idf
                setattr(model, name, vectorizer)
                data, indices, indptr = [arrays[matrix_name + "_" + k] for k in ("data", "indices", "indptr")]
                if indices.dtype.kind not in "iu" or indptr.dtype.kind not in "iu" or indptr.shape != (len(concepts) + 1,) or indptr[0] != 0 or indptr[-1] != len(data) or len(indices) != len(data) or (np.diff(indptr) < 0).any() or (indices < 0).any() or (indices >= len(vocab)).any() or (data < 0).any() or (data > 1 + 1e-12).any():
                    raise ValueError("invalid_sparse_dimensions")
                matrix = sparse.csr_matrix((data, indices, indptr), shape=(len(concepts), len(vocab)))
                matrix.check_format(full_check=True)
                setattr(model, matrix_name, matrix)
            if arrays["coef"].shape != (1, 4) or arrays["intercept"].shape != (1,):
                raise ValueError("invalid_coefficients")
            model.model = LogisticRegression()
            model.model.coef_, model.model.intercept_ = arrays["coef"], arrays["intercept"]
            model.model.classes_, model.model.n_features_in_ = np.array([0, 1]), 4
        if len(state["tokens"]) != len(concepts) or any(not isinstance(t, list) or any(not isinstance(s, str) for s in t) for t in state["tokens"]):
            raise ValueError("invalid_tokens")
        model.tokens = [set(t) for t in state["tokens"]]
        for name in ("dr", "standard"):
            prior = state[name]
            if any(any(type(i) is not int or not 0 <= i < len(concepts) for i in ids) for ids in prior.values()):
                raise ValueError("invalid_priors")
            setattr(model, name, {k: set(v) for k, v in prior.items()})
        metadata = state["metadata"]
        if metadata:
            catalog_inventory({"schema_version": SCHEMA, "taxonomy_profile": manifest["taxonomy_profile"],
                "package_sha256": manifest["package_sha256"], "languages": manifest["languages"], "concepts": metadata})
        if not set(metadata) <= set(concepts) or (manifest["package_sha256"] is None and metadata):
            raise ValueError("invalid_metadata")
        instance = cls()
        instance.matcher, instance.manifest, instance.metadata = model, manifest, metadata
        instance.artifact_sha256 = digest(manifest_bytes)
        return instance

    def identify(self, request: dict):
        manifest, model = self.manifest, self.matcher
        output = {"schema_version": SCHEMA, "request_id": str(request["request_id"]),
                  "model_version": manifest["model_version"], "artifact_sha256": self.artifact_sha256,
                  "catalog_sha256": manifest["catalog_sha256"], "taxonomy_profile": manifest["taxonomy_profile"],
                  "package_sha256": manifest["package_sha256"],
                  "concept_map_sha256": manifest["inputs"]["concept_map_sha256"],
                  "candidates": [], "abstained": True, "reason_codes": []}
        indicator = request["indicator"]
        if request["taxonomy_profile"] != manifest["taxonomy_profile"] or request["catalog_sha256"] != manifest["catalog_sha256"]:
            output["reason_codes"] = ["unsupported_context"]
            return output
        if not supported_context(indicator):
            output["reason_codes"] = ["unsupported_context"]
            return output
        if indicator["language"] not in manifest["languages"]:
            output["reason_codes"] = ["unsupported_language"]
            return output
        point = {"name": indicator["label"], "description": indicator["description"], "dr": indicator.get("dr"), "esrs": indicator.get("standard")}
        # DR priors or DR tokens alone must never rescue an unrelated description.
        text_features = model.features({**point, "dr": None, "esrs": None})
        if not (text_features[:, :2] > 0).any():
            output["reason_codes"] = ["zero_textual_overlap"]
            return output
        scores = model.scores(point)["logistic_reranker"]
        for idx in model.order(scores):
            qname = model.concepts[idx]
            validation, reasons = compatibility(self.metadata.get(qname), indicator)
            if validation == "incompatible":
                continue
            output["candidates"].append({"qname": qname, "rank": len(output["candidates"]) + 1,
                "score": float(scores[idx]), "validation_status": validation, "validation_reasons": reasons})
            if len(output["candidates"]) == 5:
                break
        candidates = output["candidates"]
        if not candidates:
            reason = "no_compatible_candidates"
        elif candidates[0]["score"] < manifest["thresholds"]["min_score"]:
            reason = "below_threshold"
        elif len(candidates) > 1 and candidates[0]["score"] - candidates[1]["score"] < manifest["thresholds"]["min_margin"]:
            reason = "insufficient_margin"
        else:
            reason = None
        output["abstained"], output["reason_codes"] = reason is not None, [reason] if reason else []
        return output
