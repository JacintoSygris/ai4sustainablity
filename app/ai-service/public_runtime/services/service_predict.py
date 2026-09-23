import time
import warnings
import os
import re
import numpy as np
import pandas as pd
import joblib

from dataclasses import dataclass
from pathlib import Path
from .datatypes import CompanyData, FeatureMetadata, Prediction

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


def load_profile_esrs_columns(profile: ModelProfile) -> list[str]:
    return joblib.load(profile.artifact_path("esrs_columns.pkl"))


def load_profile_classifier(profile: ModelProfile):
    with warnings.catch_warnings():
        warnings.filterwarnings(
            "ignore",
            message="Trying to unpickle estimator .* from version .*",
            category=UserWarning,
        )
        return joblib.load(profile.artifact_path("esrs_classifier.pkl"))


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


# ----------------------------------------------------------------
#  SERVICE version
# ----------------------------------------------------------------

def load_new_format_data(company_data: CompanyData, profile: ModelProfile, classifier=None) -> tuple[pd.DataFrame, FeatureMetadata]:
    if not is_new_format_profile(profile):
        raise ValueError(f"Model profile '{profile.name}' is not a new-format 732 profile.")

    feature_columns = load_profile_feature_columns(profile, classifier)
    row = {column: 0 for column in feature_columns}
    metadata = FeatureMetadata()

    sector_codes = normalize_sector_codes(company_data.sector_list)
    region_codes = normalize_region_codes(company_data.subsidiaries_regions)
    product_codes = normalize_sector_codes(company_data.products_services)

    if not region_codes:
        region_codes = default_region_codes(company_data.headquarters_country)
        if region_codes:
            metadata.defaulted_fields["subsidiaries_regions"] = region_codes

    if not product_codes:
        product_codes = sector_codes
        if product_codes:
            metadata.defaulted_fields["products_services"] = product_codes

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

    sector_feature_codes, sector_basis = feature_codes_for_nace_hierarchy(
        sector_codes,
        "sector",
        row.keys(),
    )
    product_feature_codes, product_basis = feature_codes_for_nace_hierarchy(
        product_codes,
        "product",
        row.keys(),
    )

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

    return pd.DataFrame([row], columns=feature_columns), metadata


def normalize_sector_codes(values: list[str] | None) -> list[str]:
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
        mapped = LEGACY_SECTOR_LABEL_TO_NACE_SECTION.get(label.lower())
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
    if employees_total <= 249:
        return "SMALL"
    if employees_total <= 499:
        return "MEDIUM"
    if employees_total <= 4_999:
        return "LARGE"
    if employees_total <= 49_999:
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
    if value < 0 or value > 1:
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
) -> tuple[list[str], dict]:
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
        if score >= score_threshold
    ]
    candidate_positive = [
        (key, score)
        for key, score in threshold_positive
        if is_new_format_candidate_key(key)
    ]
    candidate_positive.sort(key=lambda item: (-item[1], item[0]))

    excluded_non_candidate_count = len(threshold_positive) - len(candidate_positive)

    metadata = {
        "new_format_score_threshold": score_threshold,
        "raw_positive_key_count": len(raw_positive),
        "threshold_positive_key_count": len(threshold_positive),
        "excluded_non_candidate_key_count": excluded_non_candidate_count,
        "emitted_positive_key_count": len(candidate_positive),
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
def predict_esrs(company_data: CompanyData):
    profile = resolve_model_profile(company_data.model_profile)
    ensure_profile_runtime_enabled(profile)

    # load company data and ML classifier
    print("Loading company data...")
    clf = load_profile_classifier(profile)
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
        },
        evidence_refs=[],
    )
    industry_basis = feature_metadata.derived_fields.get("industry_basis")
    if industry_basis:
        prediction.mapping_metadata["industry_basis"] = industry_basis
    if len(predictions) == 1:
        scores = new_format_positive_scores(clf, df, len(esrs_columns))
        positive_keys, filter_metadata = filter_new_format_runtime_predictions(
            esrs_columns=esrs_columns,
            raw_predictions=predictions[0],
            scores=scores,
        )
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
    return prediction
