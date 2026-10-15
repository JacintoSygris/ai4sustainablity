import time
import warnings
import os
import importlib
import logging
import re
import hashlib
import io
import json
from functools import lru_cache
import numpy as np
import pandas as pd
import joblib

from dataclasses import dataclass
from pathlib import Path
from typing import Any
from .datatypes import CompanyData, FeatureMetadata, Prediction

LOGGER = logging.getLogger(__name__)
LIGHTGBM_FEATURE_NAME_WARNING = (
    "X does not have valid feature names, but LGBMClassifier was fitted with feature names"
)
AI_SERVICE_ROOT = Path(__file__).resolve().parents[2]
MODEL_PROFILE_ENV = "I4S_AI_MODEL_PROFILE"
MODEL_ARTIFACT_DIR_ENV = "I4S_AI_ARTIFACT_DIR"
PUBLIC_MODEL_PROFILE = "new_format_732_v1_gpt41"
NEW_FORMAT_MODEL_PREFIX = "new_format_732_v1_"
UNKNOWN_JURIDIC_FORM = "UNKNOWN"
NEW_FORMAT_SCORE_THRESHOLD_ENV = "I4S_AI_NEW_FORMAT_SCORE_THRESHOLD"
DEFAULT_NEW_FORMAT_SCORE_THRESHOLD = 0.95
NEW_FORMAT_NON_CANDIDATE_KEYS = {
    "esrs_e3_other_issues_related_to_esrs_e3",
}
NACE_DIVISION_LABEL_RE = re.compile(r"^(\d{2})(?:$|[\s:;\-].*)")
NACE_GROUP_DECIMAL_LABEL_RE = re.compile(r"^(\d{2})\.(\d)(?:$|[\s:;\-].*)")
NACE_GROUP_COMPACT_LABEL_RE = re.compile(r"^([1-9]\d{2})(?:$|[\s:;\-].*)")
NACE_CODE_LEVELS = {
    1: "section_1letter",
    2: "division_2digit",
    3: "group_3digit",
}
NACE_DIVISION_TO_SECTION = {
    **{f"{division:02d}": "A" for division in range(1, 4)},
    **{f"{division:02d}": "B" for division in range(5, 10)},
    **{f"{division:02d}": "C" for division in range(10, 34)},
    "35": "D",
    **{f"{division:02d}": "E" for division in range(36, 40)},
    **{f"{division:02d}": "F" for division in range(41, 44)},
    **{f"{division:02d}": "G" for division in range(45, 48)},
    **{f"{division:02d}": "H" for division in range(49, 54)},
    **{f"{division:02d}": "I" for division in range(55, 57)},
    **{f"{division:02d}": "J" for division in range(58, 64)},
    **{f"{division:02d}": "K" for division in range(64, 67)},
    "68": "L",
    **{f"{division:02d}": "M" for division in range(69, 76)},
    **{f"{division:02d}": "N" for division in range(77, 83)},
    "84": "O",
    "85": "P",
    **{f"{division:02d}": "Q" for division in range(86, 89)},
    **{f"{division:02d}": "R" for division in range(90, 94)},
    **{f"{division:02d}": "S" for division in range(94, 97)},
    **{f"{division:02d}": "T" for division in range(97, 99)},
    "99": "U",
}

LEGACY_SECTOR_LABEL_TO_NACE_SECTION = {
    "agriculture": "A",
    "agriculture, forestry and fishing": "A",
    "industry": "C",
    "manufacturing": "C",
    "energy": "D",
    "construction": "F",
    "retail": "G",
    "mobility": "H",
    "consumer goods": "I",
    "technology": "J",
    "information technology": "J",
    "financial services": "K",
    "real estate": "L",
    "healthcare": "R",
}

# The original GPT-4.1 extraction vocabulary is NACE Rev.2.1. The Rev.2 map
# above is kept only for numeric hierarchy feature vocabularies.
GPT41_SECTOR_LABELS = {
    **LEGACY_SECTOR_LABEL_TO_NACE_SECTION,
    "technology": "K", "information technology": "K",
    "financial services": "L", "real estate": "M",
    "mining": "B", "water and waste": "E",
    "professional services": "N", "administrative services": "O",
    "public administration": "P", "education": "Q",
}
GPT41_DIVISION_TO_SECTION = {
    **NACE_DIVISION_TO_SECTION,
    **{str(d): "J" for d in range(58, 61)},
    **{str(d): "K" for d in range(61, 64)},
    **{str(d): "L" for d in range(64, 67)},
    "68": "M",
    **{str(d): "N" for d in range(69, 76)},
    **{str(d): "O" for d in range(77, 83)},
    "84": "P", "85": "Q",
    **{str(d): "R" for d in range(86, 89)},
    **{str(d): "S" for d in range(90, 94)},
    **{str(d): "T" for d in range(94, 97)},
    "97": "U", "98": "U", "99": "V",
}

REGION_ALIASES = {
    "eu": ["EU"],
    "european_union": ["EU"],
    "europe": ["EU"],
    "north_america": ["NA"],
    "na": ["NA"],
    "latin_america": ["LATAM"],
    "latam": ["LATAM"],
    "asia": ["APAC"],
    "asia_pacific": ["APAC"],
    "apac": ["APAC"],
    "oceania": ["APAC"],
    "middle_east_africa": ["MENA", "SSA"],
    "middle_east": ["MENA"],
    "mena": ["MENA"],
    "africa": ["SSA"],
    "ssa": ["SSA"],
}

EUROPEAN_COUNTRY_TO_REGION = {
    "belgium",
    "france",
    "germany",
    "italy",
    "netherlands",
    "united kingdom",
    "uk",
    "norway",
    "portugal",
    "spain",
}


@dataclass(frozen=True)
class ModelProfile:
    name: str
    artifact_dir: Path
    expected_key_count: int
    required_artifacts: tuple[str, ...]
    runtime_enabled: bool
    description: str

    def artifact_path(self, filename: str) -> Path:
        return self.artifact_dir / filename


def resolve_artifact_dir() -> Path:
    """Resolve the model artifact directory for the public runtime.

    Priority order:
    1. Explicit ``I4S_AI_ARTIFACT_DIR`` override (used even when missing so a
       bad override fails closed at inventory validation with a clear path).
       A relative override is resolved against the ai-service root.
    2. Container/installed layout: ``trained_classifier/new_format/gpt41``
       (what ``Dockerfile.public`` builds).
    3. Bare git-checkout layout: ``model-artifacts/gpt41`` (so running the
       service directly from a checkout works without symlinks or copies).

    If neither directory exists, the canonical Docker layout path is returned
    so startup validation fails closed against the expected location.
    """
    override = os.getenv(MODEL_ARTIFACT_DIR_ENV)
    if override:
        override_dir = Path(override).expanduser()
        # A relative override is anchored to the service root, like the other
        # layouts, not to whatever working directory the process started in.
        if not override_dir.is_absolute():
            override_dir = AI_SERVICE_ROOT / override_dir
        return override_dir.resolve()

    default_dir = AI_SERVICE_ROOT / "trained_classifier" / "new_format" / "gpt41"
    if default_dir.is_dir():
        return default_dir

    checkout_dir = AI_SERVICE_ROOT / "model-artifacts" / "gpt41"
    if checkout_dir.is_dir():
        return checkout_dir

    return default_dir


MODEL_PROFILES = {
    PUBLIC_MODEL_PROFILE: ModelProfile(
        name=PUBLIC_MODEL_PROFILE,
        artifact_dir=resolve_artifact_dir(),
        expected_key_count=102,
        required_artifacts=(
            "sector_columns.pkl",
            "region_columns.pkl",
            "esrs_classifier.pkl",
            "esrs_columns.pkl",
        ),
        runtime_enabled=True,
        description="new_format_732_v1_gpt41 classifier with high-confidence score filtering and no fixed candidate-count cap.",
    ),
}


def filter_known_labels(labels: list[str], known_labels) -> list[str]:
    known_label_set = set(known_labels)

    return [label for label in labels if label in known_label_set]


def resolve_model_profile(requested_profile: str | None = None) -> ModelProfile:
    profile_name = requested_profile
    if profile_name is None:
        profile_name = os.getenv(MODEL_PROFILE_ENV)
    profile_name = profile_name.strip() if isinstance(profile_name, str) else ""

    if profile_name != PUBLIC_MODEL_PROFILE:
        raise ValueError(
            f"Unsupported model profile '{profile_name or '<blank>'}'. "
            f"Only '{PUBLIC_MODEL_PROFILE}' is available in the public runtime."
        )

    return MODEL_PROFILES[profile_name]


def is_new_format_profile(profile: ModelProfile) -> bool:
    return profile.name.startswith(NEW_FORMAT_MODEL_PREFIX)


def ensure_profile_runtime_enabled(profile: ModelProfile):
    if profile.runtime_enabled:
        return

    raise ValueError(
        f"Model profile '{profile.name}' is inventoried but not runtime-enabled. "
        "Complete feature crosswalk, 102-key AR16 mapping approval, SME dual-run, "
        "and rollback validation before activation."
    )


_loaded_artifact_sha256: dict[tuple[ModelProfile, str], str] = {}


@lru_cache(maxsize=len(MODEL_PROFILES) * 4)
def _load_joblib_artifact(profile: ModelProfile, filename: str):
    raw_bytes = profile.artifact_path(filename).read_bytes()
    loaded = joblib.load(io.BytesIO(raw_bytes))
    _loaded_artifact_sha256[(profile, filename)] = hashlib.sha256(raw_bytes).hexdigest()
    return loaded


@lru_cache(maxsize=len(MODEL_PROFILES))
def load_profile_esrs_columns(profile: ModelProfile) -> list[str]:
    return _load_joblib_artifact(profile, "esrs_columns.pkl")


@lru_cache(maxsize=len(MODEL_PROFILES))
def load_profile_classifier(profile: ModelProfile):
    with warnings.catch_warnings():
        warnings.filterwarnings(
            "ignore",
            message="Trying to unpickle estimator .* from version .*",
            category=UserWarning,
        )
        return _load_joblib_artifact(profile, "esrs_classifier.pkl")


def clear_model_artifact_cache() -> None:
    """Clear process-local artifacts for tests and controlled runtime reloads."""
    load_profile_classifier.cache_clear()
    load_profile_esrs_columns.cache_clear()
    _load_joblib_artifact.cache_clear()
    _loaded_artifact_sha256.clear()


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(1024 * 1024):
            digest.update(chunk)
    return digest.hexdigest()


def _model_artifact_hashes(profile: ModelProfile) -> dict[str, str]:
    hashes = {}
    for filename in profile.required_artifacts:
        loaded_hash = _loaded_artifact_sha256.get((profile, filename))
        hashes[filename] = loaded_hash or _sha256_file(profile.artifact_path(filename))
    return hashes


@dataclass(frozen=True)
class ServingPolicies:
    # Field order is part of the serving contract shared with the learning
    # modules. The public runtime ships no per-label thresholds, CRC recall
    # floor or sector guard: those stay None and are reported as inactive in
    # the serving identity.
    score_threshold: float | None
    label_thresholds: dict[str, float] | None
    crc_recall_floor: Any | None
    sector_guard: Any | None
    sha256: dict[str, str]
    feedback_bandit: Any | None = None


def _optional_learning_module(name: str):
    """Learning hooks ship in app/ai-service/src; without them the base model is served."""
    try:
        return importlib.import_module(name)
    except ModuleNotFoundError as error:
        if error.name != name:
            raise  # A broken learning installation must not be masked.
        if any(key.startswith("LEARNING_") for key in os.environ) or os.getenv("ESRS_FEEDBACK_BANDIT_PATH"):
            LOGGER.warning("learning.hooks_unavailable_using_base")
        return None


def load_serving_policies(profile: ModelProfile) -> ServingPolicies:
    hashes: dict[str, str] = {}
    score_threshold = new_format_score_threshold() if is_new_format_profile(profile) else None

    # Optional feedback-bandit policy: OFF unless the learning activation
    # configuration or ESRS_FEEDBACK_BANDIT_PATH selects it. Invalid state
    # fails closed to OFF inside load_feedback_bandit.
    feedback_bandit = None
    feedback = _optional_learning_module("feedback_bandit")
    activation = _optional_learning_module("learning_activation") if feedback else None
    if (activation is not None and is_new_format_profile(profile)
            and (os.getenv(feedback.PATH_ENV, "").strip() or activation.bandit_mode() in ("active", "shadow"))):
        feedback_bandit = feedback.load_feedback_bandit(
            profile.name,
            feature_columns=list(load_profile_feature_columns(profile)),
            model_sha256=_model_artifact_hashes(profile)["esrs_classifier.pkl"],
            topics=[k for k in load_profile_esrs_columns(profile) if is_new_format_candidate_key(k)],
        )
        if feedback_bandit is not None:
            hashes["feedback_bandit"] = feedback_bandit.envelope()["sha256"]

    return ServingPolicies(
        score_threshold=score_threshold,
        label_thresholds=None,
        crc_recall_floor=None,
        sector_guard=None,
        sha256=hashes,
        feedback_bandit=feedback_bandit,
    )


def model_serving_identity(profile: ModelProfile, policies: ServingPolicies | None = None) -> dict:
    """Bind a prediction to immutable model bytes and effective serving policy."""
    policies = policies or load_serving_policies(profile)
    artifact_sha256 = _model_artifact_hashes(profile)
    policy_state = {
        "label_thresholds": policies.label_thresholds is not None,
        "crc_recall_floor": policies.crc_recall_floor is not None,
        "sector_guard": policies.sector_guard is not None,
    }

    if policies.feedback_bandit is not None:
        policy_state["feedback_bandit"] = True

    runtime_config = {
        "score_threshold": policies.score_threshold,
        "policy_active": policy_state,
    }
    canonical = {
        "profile": profile.name,
        "artifact_sha256": artifact_sha256,
        "policy_sha256": policies.sha256,
        "runtime_config": runtime_config,
    }
    serving_hash = hashlib.sha256(
        json.dumps(canonical, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()

    return {**canonical, "serving_identity_sha256": serving_hash}


def load_profile_feature_columns(profile: ModelProfile, classifier=None) -> list[str]:
    classifier = classifier or load_profile_classifier(profile)
    feature_columns = getattr(classifier, "feature_names_in_", None)

    if feature_columns is None:
        raise ValueError(f"Model profile '{profile.name}' does not expose sklearn feature_names_in_.")

    return [str(column) for column in feature_columns]


def validate_profile_inventory(profile: ModelProfile):
    for filename in profile.required_artifacts:
        path = profile.artifact_path(filename)
        if not path.is_file():
            raise FileNotFoundError(2, "No such file", str(path))

    esrs_columns = load_profile_esrs_columns(profile)
    if len(esrs_columns) != profile.expected_key_count:
        raise ValueError(
            f"Model profile '{profile.name}' expected {profile.expected_key_count} ESRS keys "
            f"but loaded {len(esrs_columns)} from {profile.artifact_path('esrs_columns.pkl')}."
        )

    if is_new_format_profile(profile):
        feature_columns = load_profile_feature_columns(profile)
        required_features = {
            "headquarters_country",
            "annual_turnover_log",
            "company_size",
            "juridic_form",
            "stock_listed_flag",
            "reporting_currency",
        }
        missing_features = sorted(required_features.difference(feature_columns))
        if missing_features:
            raise ValueError(
                f"Model profile '{profile.name}' is missing required new-format features: "
                f"{', '.join(missing_features)}."
            )


def validate_model_artifacts(model_profile: str | None = None):
    profile = resolve_model_profile(model_profile)
    ensure_profile_runtime_enabled(profile)
    validate_profile_inventory(profile)
    model_serving_identity(profile)


# ----------------------------------------------------------------
#  SERVICE version
# ----------------------------------------------------------------

def load_new_format_data(company_data: CompanyData, profile: ModelProfile, classifier=None) -> tuple[pd.DataFrame, FeatureMetadata]:
    if not is_new_format_profile(profile):
        raise ValueError(f"Model profile '{profile.name}' is not a new-format 732 profile.")

    feature_columns = load_profile_feature_columns(profile, classifier)
    row = {column: 0 for column in feature_columns}
    metadata = FeatureMetadata()

    # Numeric sector/product columns identify Rev.2 hierarchy vocabularies.
    # Do not reinterpret their training vocabulary as Rev.2.1.
    hierarchy = any(re.fullmatch(r"sector_\d{2,3}", c) for c in feature_columns)
    sector_codes = normalize_sector_codes(company_data.sector_list, legacy_nace=hierarchy)
    region_codes = normalize_region_codes(company_data.subsidiaries_regions)
    product_codes = (
        normalize_sector_codes(company_data.products_services, legacy_nace=True)
        if hierarchy else unique_ordered([v.strip().upper() for v in company_data.products_services if v.strip()])
    )

    if not region_codes:
        region_codes = default_region_codes(company_data.headquarters_country)
        if region_codes:
            metadata.defaulted_fields["subsidiaries_regions"] = region_codes

    company_size = company_data.company_size or derive_company_size(company_data.employees_total)
    metadata.derived_fields["company_size"] = company_size

    juridic_form = company_data.juridic_form or UNKNOWN_JURIDIC_FORM
    if not company_data.juridic_form:
        metadata.defaulted_fields["juridic_form"] = UNKNOWN_JURIDIC_FORM

    if not sector_codes:
        metadata.missing_required_fields.append("sector_list")
    if not region_codes:
        metadata.missing_required_fields.append("subsidiaries_regions")
    if not product_codes:
        metadata.missing_required_fields.append("products_services")

    if hierarchy:
        sector_feature_codes, sector_basis = feature_codes_for_nace_hierarchy(sector_codes, "sector", row.keys())
        product_feature_codes, product_basis = feature_codes_for_nace_hierarchy(product_codes, "product", row.keys())
    else:
        sector_codes = unique_ordered([
            GPT41_DIVISION_TO_SECTION.get(c[:2], c) if c.isdigit() else c
            for c in sector_codes
        ])
        sector_feature_codes = [c for c in sector_codes if f"sector_{c}" in row]
        # R exists as a training-data anomaly, but is not an A-L product code.
        product_feature_codes = [c for c in product_codes if c in "ABCDEFGHIJKL" and len(c) == 1 and f"product_{c}" in row]
        sector_basis = {
            "taxonomy": "NACE Rev.2.1", "input_codes": sector_codes,
            "feature_codes": sector_feature_codes,
            "unsupported_codes": [c for c in sector_codes if c not in sector_feature_codes],
            "effective_nace_code": sector_feature_codes[0] if sector_feature_codes else None,
            "effective_nace_level": "section_1letter" if sector_feature_codes else None,
            "fallback_used": False,
        }
        product_basis = {
            "taxonomy": "company_data.products_services A-L", "input_codes": product_codes,
            "feature_codes": product_feature_codes,
            "unsupported_codes": [c for c in product_codes if c not in product_feature_codes],
            "fallback_used": False,
        }

    if sector_basis or product_basis:
        metadata.derived_fields["industry_basis"] = {
            "sector": sector_basis,
            "products_services": product_basis,
        }

    for code in sector_feature_codes:
        row[f"sector_{code}"] = 1

    for code in region_codes:
        column = f"region_{code}"
        if column in row:
            row[column] = 1

    for code in product_feature_codes:
        row[f"product_{code}"] = 1

    scalar_values = {
        "headquarters_country": company_data.headquarters_country,
        "annual_turnover_log": float(np.log1p(max(company_data.annual_turnover_million_euro, 0))),
        "company_size": company_size,
        "juridic_form": juridic_form,
        "stock_listed_flag": 1 if company_data.stock_listed else 0,
        "reporting_currency": company_data.reporting_currency,
    }
    for column, value in scalar_values.items():
        if column in row:
            row[column] = value

    unsupported = {}
    for field, codes, supported in (
        ("sector_list", sector_codes, sector_feature_codes),
        ("products_services", product_codes, product_feature_codes),
        ("subsidiaries_regions", region_codes, [c for c in region_codes if f"region_{c}" in row]),
    ):
        dropped = [c for c in codes if c not in supported]
        if dropped:
            unsupported[field] = dropped
        if not supported and field not in metadata.missing_required_fields:
            metadata.missing_required_fields.append(field)
    # Inspect the fitted encoder, not merely the CSV: some size categories were
    # absent from the training split and OneHotEncoder would silently ignore them.
    fitted = classifier if classifier is not None else load_profile_classifier(profile)
    preprocessor = getattr(fitted, "named_steps", {}).get("preprocessor")
    if preprocessor is not None:
        categorical = preprocessor.named_transformers_["cat"].named_steps["onehot"]
        cat_columns = next(columns for name, _, columns in preprocessor.transformers_ if name == "cat")
        for column, categories in zip(cat_columns, categorical.categories_):
            if row[column] not in categories:
                unsupported[column] = [row[column]]
    metadata.derived_fields["encoding_coverage"] = {
        "version": "p5-gpt41-encoding-v1" if not hierarchy else "nace-hierarchy",
        "status": "degraded" if unsupported or metadata.missing_required_fields else "complete",
        "unsupported_values": unsupported,
    }

    return pd.DataFrame([row], columns=feature_columns), metadata


def normalize_sector_codes(values: list[str] | None, *, legacy_nace: bool = False) -> list[str]:
    normalized: list[str] = []
    for value in values or []:
        if not isinstance(value, str):
            continue
        label = value.strip()
        if not label:
            continue
        code = label.upper()
        if len(code) == 1 and code.isalpha():
            normalized.append(code)
            continue
        decimal_group_match = NACE_GROUP_DECIMAL_LABEL_RE.match(label)
        if decimal_group_match:
            normalized.append(f"{decimal_group_match.group(1)}{decimal_group_match.group(2)}")
            continue
        compact_group_match = NACE_GROUP_COMPACT_LABEL_RE.match(label)
        if compact_group_match:
            normalized.append(compact_group_match.group(1))
            continue
        division_match = NACE_DIVISION_LABEL_RE.match(label)
        if division_match:
            normalized.append(division_match.group(1))
            continue
        mapped = (LEGACY_SECTOR_LABEL_TO_NACE_SECTION if legacy_nace else GPT41_SECTOR_LABELS).get(label.lower())
        if mapped:
            normalized.append(mapped)

    return unique_ordered(normalized)


def feature_codes_for_nace_hierarchy(
    input_codes: list[str],
    feature_prefix: str,
    feature_columns,
) -> tuple[list[str], dict]:
    feature_column_set = set(feature_columns)
    feature_codes: list[str] = []
    effective_code = None
    effective_level = 0
    first_input_level = code_level(input_codes[0]) if input_codes else 0

    for code in input_codes:
        for candidate in nace_hierarchy_candidates(code):
            if f"{feature_prefix}_{candidate}" not in feature_column_set:
                continue
            feature_codes.append(candidate)
            candidate_level = code_level(candidate)
            if candidate_level > effective_level:
                effective_code = candidate
                effective_level = candidate_level

    feature_codes = unique_ordered(feature_codes)
    basis = {
        "input_codes": input_codes,
        "feature_codes": feature_codes,
        "effective_nace_code": effective_code,
        "effective_nace_level": NACE_CODE_LEVELS.get(effective_level),
        "fallback_used": bool(effective_code and effective_level < first_input_level),
    }
    return feature_codes, basis


def nace_hierarchy_candidates(code: str) -> list[str]:
    if code_level(code) == 3:
        division = code[:2]
        candidates = [code, division]
        section = NACE_DIVISION_TO_SECTION.get(division)
        if section:
            candidates.append(section)
        return candidates
    if code_level(code) == 2:
        candidates = [code]
        section = NACE_DIVISION_TO_SECTION.get(code)
        if section:
            candidates.append(section)
        return candidates
    if code_level(code) == 1:
        return [code]
    return []


def code_level(code: str) -> int:
    if len(code) == 3 and code.isdigit():
        return 3
    if len(code) == 2 and code.isdigit():
        return 2
    if len(code) == 1 and code.isalpha():
        return 1
    return 0


def normalize_region_codes(values: list[str] | None) -> list[str]:
    normalized: list[str] = []
    for value in values or []:
        if not isinstance(value, str):
            continue
        label = value.strip()
        if not label:
            continue
        alias_key = label.lower().replace("-", "_").replace(" ", "_")
        mapped_values = REGION_ALIASES.get(alias_key)
        if mapped_values:
            normalized.extend(mapped_values)
            continue
        normalized.append(label.upper())

    return unique_ordered(normalized)


def default_region_codes(headquarters_country: str) -> list[str]:
    country = (headquarters_country or "").strip().lower()
    if country in EUROPEAN_COUNTRY_TO_REGION:
        return ["EU"]
    if country in {"united states", "usa", "us", "canada", "mexico"}:
        return ["NA"]
    return []


def derive_company_size(employees_total: int) -> str:
    if employees_total <= 0:
        return "NA"
    if employees_total <= 9:
        return "MICRO"
    if employees_total <= 49:
        return "SMALL"
    if employees_total <= 249:
        return "MEDIUM"
    # The extraction prompt overlaps at 5000: use its first matching band.
    if employees_total <= 5_000:
        return "LARGE"
    if employees_total <= 20_000:
        return "HUGE"
    return "ULTRA"


def unique_ordered(values: list[str]) -> list[str]:
    seen = set()
    ordered = []
    for value in values:
        if value in seen:
            continue
        seen.add(value)
        ordered.append(value)
    return ordered


def new_format_score_threshold() -> float:
    raw_value = os.getenv(NEW_FORMAT_SCORE_THRESHOLD_ENV)
    if raw_value is None or raw_value.strip() == "":
        return DEFAULT_NEW_FORMAT_SCORE_THRESHOLD

    value = float(raw_value)
    if not np.isfinite(value) or value < 0 or value > 1:
        raise ValueError(f"{NEW_FORMAT_SCORE_THRESHOLD_ENV} must be between 0 and 1.")
    return value


def _positive_probability_for_estimator(estimator, data) -> np.ndarray:
    probabilities = np.asarray(estimator.predict_proba(data))
    row_count = probabilities.shape[0]
    classes = list(getattr(estimator, "classes_", []))
    if 1 in classes:
        positive_index = classes.index(1)
        if probabilities.ndim == 2 and probabilities.shape[1] > positive_index:
            return probabilities[:, positive_index].astype(float)
    if classes and 1 not in classes:
        return np.zeros(row_count, dtype=float)
    if probabilities.ndim == 2 and probabilities.shape[1] > 1:
        return probabilities[:, 1].astype(float)
    if probabilities.ndim == 2:
        return probabilities[:, 0].astype(float)
    return probabilities.reshape(row_count).astype(float)


def _classifier_chain_positive_scores(classifier, dataframe, expected_key_count: int) -> list[float] | None:
    if hasattr(classifier, "steps") and classifier.steps:
        transformed = classifier[:-1].transform(dataframe)
        chain = classifier.steps[-1][1]
    else:
        transformed = dataframe
        chain = classifier

    if not (hasattr(chain, "estimators_") and hasattr(chain, "order_")):
        return None

    estimators = list(chain.estimators_)
    if len(estimators) != expected_key_count:
        return None

    from scipy import sparse as scipy_sparse

    row_count = transformed.shape[0]
    output_chain = np.zeros((row_count, len(estimators)))
    feature_chain = np.zeros((row_count, len(estimators)))
    hstack = scipy_sparse.hstack if scipy_sparse.issparse(transformed) else np.hstack
    chain_method = getattr(chain, "chain_method_", "predict")

    for chain_index, estimator in enumerate(estimators):
        previous_predictions = feature_chain[:, :chain_index]
        augmented = hstack((transformed, previous_predictions))

        if chain_method == "predict_proba":
            feature_predictions = _positive_probability_for_estimator(estimator, augmented)
        else:
            feature_predictions = getattr(estimator, chain_method)(augmented)
        feature_chain[:, chain_index] = np.asarray(feature_predictions).reshape(row_count)
        output_chain[:, chain_index] = _positive_probability_for_estimator(estimator, augmented)

    inverse_order = np.empty_like(chain.order_)
    inverse_order[chain.order_] = np.arange(len(chain.order_))
    output = output_chain[:, inverse_order]
    if output.shape[0] != 1:
        raise ValueError("new_format_positive_scores expects a single-row dataframe.")
    return [float(value) for value in output[0]]


def new_format_positive_scores(classifier, dataframe, expected_key_count: int) -> list[float]:
    if not hasattr(classifier, "predict_proba"):
        raise ValueError("Runtime-enabled new-format profiles must expose predict_proba for candidate filtering.")

    try:
        probabilities = classifier.predict_proba(dataframe)
    except ValueError:
        chain_scores = _classifier_chain_positive_scores(classifier, dataframe, expected_key_count)
        if chain_scores is None:
            raise
        scores = chain_scores
        if len(scores) != expected_key_count:
            raise ValueError(
                f"Runtime-enabled new-format profile returned {len(scores)} scores for "
                f"{expected_key_count} ESRS keys."
            )
        return scores

    if isinstance(probabilities, list):
        scores = []
        for probability in probabilities:
            probability_array = np.asarray(probability)
            if probability_array.ndim == 2 and probability_array.shape[1] > 1:
                scores.append(float(probability_array[0, 1]))
            elif probability_array.ndim == 2:
                scores.append(float(probability_array[0, 0]))
            else:
                scores.append(float(probability_array[0]))
    else:
        probability_array = np.asarray(probabilities)
        if probability_array.ndim == 2 and probability_array.shape[0] == 1:
            scores = [float(value) for value in probability_array[0]]
        elif probability_array.ndim == 3 and probability_array.shape[0] == expected_key_count:
            scores = [
                float(probability_array[index, 0, 1])
                if probability_array.shape[2] > 1
                else float(probability_array[index, 0, 0])
                for index in range(expected_key_count)
            ]
        else:
            raise ValueError(
                "Runtime-enabled new-format profile returned unsupported predict_proba shape "
                f"{probability_array.shape}."
            )

    if len(scores) != expected_key_count:
        raise ValueError(
            f"Runtime-enabled new-format profile returned {len(scores)} scores for "
            f"{expected_key_count} ESRS keys."
        )

    return scores


def is_new_format_candidate_key(key: str) -> bool:
    return not key.endswith("_summary") and key not in NEW_FORMAT_NON_CANDIDATE_KEYS


def filter_new_format_runtime_predictions(
    esrs_columns: list[str],
    raw_predictions,
    scores: list[float],
    score_threshold: float | None = None,
    label_thresholds: dict[str, float] | None = None,
    sector_guard=None,
    company_sectors: list[str] | None = None,
    crc_floor=None,
) -> tuple[list[str], dict]:
    # Signature and metadata keys are a stable response contract consumed by
    # Laravel. The public runtime always passes None for label_thresholds,
    # sector_guard and crc_floor (reported as inactive).
    score_threshold = new_format_score_threshold() if score_threshold is None else score_threshold
    raw_prediction_values = [int(value) for value in raw_predictions]
    raw_positive = [
        (key, score)
        for key, value, score in zip(esrs_columns, raw_prediction_values, scores)
        if value == 1
    ]
    threshold_positive = [
        (key, score)
        for key, score in raw_positive
        if score >= (label_thresholds or {}).get(key, score_threshold)
    ]
    candidate_positive = [
        (key, score)
        for key, score in threshold_positive
        if is_new_format_candidate_key(key)
    ]
    candidate_positive.sort(key=lambda item: (-item[1], item[0]))

    sector_suppressed: list[str] = []
    if sector_guard is not None:
        kept_keys, sector_suppressed = sector_guard.filter_keys(
            [key for key, _ in candidate_positive], company_sectors or []
        )
        kept_set = set(kept_keys)
        candidate_positive = [item for item in candidate_positive if item[0] in kept_set]

    excluded_non_candidate_count = (
        len(threshold_positive) - len(candidate_positive) - len(sector_suppressed)
    )

    crc_added: list[str] = []
    if crc_floor is not None:
        score_by_key = dict(zip(esrs_columns, [float(s) for s in scores]))
        crc_added = crc_floor.augment_keys(
            emitted_keys=[key for key, _ in candidate_positive],
            esrs_columns=esrs_columns,
            scores=scores,
            candidate_predicate=is_new_format_candidate_key,
        )
        candidate_positive.extend((key, score_by_key[key]) for key in crc_added)

    metadata = {
        "new_format_score_threshold": score_threshold,
        "per_label_threshold_count": len(label_thresholds or {}),
        "raw_positive_key_count": len(raw_positive),
        "threshold_positive_key_count": len(threshold_positive),
        "excluded_non_candidate_key_count": excluded_non_candidate_count,
        "emitted_positive_key_count": len(candidate_positive),
        "sector_guard_active": sector_guard is not None,
        "sector_guard_suppressed_count": len(sector_suppressed),
        "sector_guard_suppressed_keys": sector_suppressed,
        "crc_recall_floor_active": crc_floor is not None,
        "crc_lambda": crc_floor.lambda_ if crc_floor is not None else None,
        "crc_version": crc_floor.version if crc_floor is not None else None,
        "crc_added_key_count": len(crc_added),
        "crc_added_keys": crc_added,
    }

    return [key for key, _score in candidate_positive], metadata


def topic_industry_basis(industry_basis: dict | None) -> dict:
    industry_basis = industry_basis or {}
    sector_basis = industry_basis.get("sector") if isinstance(industry_basis, dict) else None
    product_basis = industry_basis.get("products_services") if isinstance(industry_basis, dict) else None
    sector_basis = sector_basis if isinstance(sector_basis, dict) else {}
    product_basis = product_basis if isinstance(product_basis, dict) else {}

    return {
        "effective_nace_code": sector_basis.get("effective_nace_code"),
        "effective_nace_level": sector_basis.get("effective_nace_level"),
        "fallback_used": bool(sector_basis.get("fallback_used")),
        "sector": sector_basis,
        "products_services": product_basis,
    }


# Predict esrs of a company
def predict_esrs(company_data: CompanyData, *, _learning_bypass: bool = False):
    # Optional learning hooks live in app/ai-service/src (public_model_app adds
    # it to sys.path). They are no-ops unless learning is configured.
    learning_runtime = _optional_learning_module("learning_production_runtime")
    profile = resolve_model_profile(company_data.model_profile)
    ensure_profile_runtime_enabled(profile)

    # load company data and ML classifier
    print("Loading company data...")
    clf = load_profile_classifier(profile)
    policies = load_serving_policies(profile)
    df, feature_metadata = load_new_format_data(company_data, profile, clf)
    esrs_columns = load_profile_esrs_columns(profile)

    # conduct prediction
    print("Start prediction...")
    start = time.time()
    with warnings.catch_warnings():
        warnings.filterwarnings(
            "ignore",
            message=LIGHTGBM_FEATURE_NAME_WARNING,
            category=UserWarning,
        )
        predictions = clf.predict(df)
    end = time.time()
    print(f"End prediction {end - start:.2f} seconds")

    # return prediction
    prediction = Prediction(
        esrs={},
        model_profile=profile.name,
        model_key_count=len(esrs_columns),
        mapped_key_count=0,
        feature_metadata=feature_metadata,
        mapping_metadata={
            "mapping_status": "external_laravel_mapping",
            "runtime_activation": "runtime_enabled",
        },
        evidence_refs=[],
    )
    prediction.mapping_metadata["serving_identity"] = model_serving_identity(profile, policies)
    industry_basis = feature_metadata.derived_fields.get("industry_basis")
    if industry_basis:
        prediction.mapping_metadata["industry_basis"] = industry_basis
    if len(predictions) == 1:
        scores = new_format_positive_scores(clf, df, len(esrs_columns))
        positive_keys, filter_metadata = filter_new_format_runtime_predictions(
            esrs_columns=esrs_columns,
            raw_predictions=predictions[0],
            scores=scores,
            score_threshold=policies.score_threshold,
            label_thresholds=policies.label_thresholds,
            sector_guard=policies.sector_guard,
            company_sectors=(industry_basis or {}).get("sector", {}).get("feature_codes", []),
            crc_floor=policies.crc_recall_floor,
        )
        if policies.feedback_bandit is not None:
            from feedback_bandit import ENCODER, digest, encode_context
            floor = policies.crc_recall_floor
            context = encode_context(df.iloc[0].to_dict())
            positive_keys, feedback_metadata = policies.feedback_bandit.apply(
                positive_keys, esrs_columns, scores, context,
                threshold=policies.score_threshold,
                label_thresholds=policies.label_thresholds,
                candidate_predicate=is_new_format_candidate_key,
                protected_standards=floor.served_standards(esrs_columns, scores) if floor else (),
            )
            # Preserved by Laravel result_data.mapping_metadata.python, only
            # while the feature is enabled. No P5 text or company identifier.
            feedback_metadata["basis"] = {
                "encoder": ENCODER,
                "context": context,
                "feature_schema_sha256": digest(list(df.columns)),
                "model_sha256": policies.feedback_bandit.config["model_sha256"],
                "base_scores": {k: float(s) for k, s in zip(esrs_columns, scores)
                                if is_new_format_candidate_key(k)},
            }
            filter_metadata["feedback_bandit"] = feedback_metadata
            filter_metadata["emitted_positive_key_count"] = len(positive_keys)
        positive_key_set = set(positive_keys)
        prediction.esrs.update({
            key: 1 if key in positive_key_set else 0
            for key in esrs_columns
        })
        prediction.mapping_metadata.update(filter_metadata)
        if industry_basis:
            prediction.mapping_metadata["industry_basis_by_key"] = {
                key: topic_industry_basis(industry_basis)
                for key in positive_keys
            }
    if _learning_bypass or learning_runtime is None:
        return prediction
    return learning_runtime.apply_learning(company_data, prediction)
