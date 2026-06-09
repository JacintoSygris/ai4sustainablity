import time
import warnings
import os
import numpy as np
import pandas as pd
import joblib

from argparse import ArgumentParser
from dataclasses import dataclass
from pathlib import Path
from sklearn.preprocessing import MultiLabelBinarizer
from utils.constants import PATH_ESRS_CLASSIFIER, PATH_ESRS_COLUMNS, PATH_SECTOR_COLUMNS
from .datatypes import CompanyData, FeatureMetadata, Prediction

LIGHTGBM_FEATURE_NAME_WARNING = (
    "X does not have valid feature names, but LGBMClassifier was fitted with feature names"
)
MODEL_ARTIFACTS = {
    "sector_columns.pkl": PATH_SECTOR_COLUMNS,
    "esrs_classifier.pkl": PATH_ESRS_CLASSIFIER,
    "esrs_columns.pkl": PATH_ESRS_COLUMNS,
}
AI_SERVICE_ROOT = Path(__file__).resolve().parents[2]
MODEL_PROFILE_ENV = "I4S_AI_MODEL_PROFILE"
LEGACY_MODEL_PROFILE = "legacy_v0"
NEW_FORMAT_MODEL_PREFIX = "new_format_732_v1_"
UNKNOWN_JURIDIC_FORM = "UNKNOWN"
NEW_FORMAT_SCORE_THRESHOLD_ENV = "I4S_AI_NEW_FORMAT_SCORE_THRESHOLD"
DEFAULT_NEW_FORMAT_SCORE_THRESHOLD = 0.95
NEW_FORMAT_NON_CANDIDATE_KEYS = {
    "esrs_e3_other_issues_related_to_esrs_e3",
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


MODEL_PROFILES = {
    LEGACY_MODEL_PROFILE: ModelProfile(
        name=LEGACY_MODEL_PROFILE,
        artifact_dir=Path(PATH_SECTOR_COLUMNS).resolve().parent,
        expected_key_count=96,
        required_artifacts=("sector_columns.pkl", "esrs_classifier.pkl", "esrs_columns.pkl"),
        runtime_enabled=True,
        description="Current 75-report runtime classifier and 96-key ESRS vocabulary.",
    ),
    "new_format_732_v1_gpt41": ModelProfile(
        name="new_format_732_v1_gpt41",
        artifact_dir=AI_SERVICE_ROOT / "trained_classifier" / "new_format" / "gpt41",
        expected_key_count=102,
        required_artifacts=(
            "sector_columns.pkl",
            "region_columns.pkl",
            "esrs_classifier.pkl",
            "esrs_columns.pkl",
        ),
        runtime_enabled=True,
        description="732-report GPT-4.1 new-format classifier with high-confidence score filtering and no fixed candidate-count cap.",
    ),
    "new_format_732_v1_gpt41_materiality_gold_v4": ModelProfile(
        name="new_format_732_v1_gpt41_materiality_gold_v4",
        artifact_dir=AI_SERVICE_ROOT / "trained_classifier" / "new_format" / "gpt41_materiality_gold_v4",
        expected_key_count=102,
        required_artifacts=(
            "sector_columns.pkl",
            "region_columns.pkl",
            "product_columns.pkl",
            "esrs_classifier.pkl",
            "esrs_columns.pkl",
        ),
        runtime_enabled=True,
        description="Experimental GPT-4.1 732 baseline plus conservative materiality-gold-v4 overlay; selectable for controlled shadow/runtime tests.",
    ),
    "new_format_732_v1_gemini": ModelProfile(
        name="new_format_732_v1_gemini",
        artifact_dir=AI_SERVICE_ROOT / "trained_classifier" / "new_format" / "gemini",
        expected_key_count=102,
        required_artifacts=(
            "sector_columns.pkl",
            "region_columns.pkl",
            "esrs_classifier.pkl",
            "esrs_columns.pkl",
        ),
        runtime_enabled=False,
        description="Inventoried 732-report Gemini new-format classifier; blocked until mapping and crosswalk approval.",
    ),
}


def filter_known_labels(labels: list[str], known_labels) -> list[str]:
    known_label_set = set(known_labels)

    return [label for label in labels if label in known_label_set]


def supported_model_profile_names() -> list[str]:
    return sorted(MODEL_PROFILES.keys())


def model_profiles_metadata() -> dict:
    active_profile = resolve_model_profile()

    return {
        "active_model_profile": active_profile.name,
        "runtime_enabled_profiles": [
            profile.name
            for profile in MODEL_PROFILES.values()
            if profile.runtime_enabled
        ],
        "profiles": {
            profile.name: {
                "name": profile.name,
                "expected_key_count": profile.expected_key_count,
                "runtime_enabled": profile.runtime_enabled,
                "required_artifacts": list(profile.required_artifacts),
                "description": profile.description,
            }
            for profile in MODEL_PROFILES.values()
        },
    }


def resolve_model_profile(requested_profile: str | None = None) -> ModelProfile:
    profile_name = requested_profile or os.getenv(MODEL_PROFILE_ENV) or LEGACY_MODEL_PROFILE
    profile_name = profile_name.strip() if isinstance(profile_name, str) else LEGACY_MODEL_PROFILE
    profile_name = profile_name or LEGACY_MODEL_PROFILE

    if profile_name not in MODEL_PROFILES:
        supported = ", ".join(supported_model_profile_names())
        raise ValueError(f"Unknown model profile '{profile_name}'. Supported profiles: {supported}.")

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


def validate_model_artifacts(data_dir: str | Path | None = None, model_profile: str | None = None):
    if data_dir is None:
        profile = resolve_model_profile(model_profile)
        ensure_profile_runtime_enabled(profile)
        validate_profile_inventory(profile)
        return
    else:
        base_path = Path(data_dir)
        paths = [base_path / filename for filename in MODEL_ARTIFACTS.keys()]

    for path in paths:
        if not path.is_file():
            raise FileNotFoundError(2, "No such file", str(path))


# ----------------------------------------------------------------
#  SERVICE version
# ----------------------------------------------------------------

# Load company data
def load_data(company_data: CompanyData):
    df = pd.DataFrame([company_data.model_dump()])

    # load sector columns from training
    sector_columns = joblib.load(PATH_SECTOR_COLUMNS)
    mlb = MultiLabelBinarizer(classes=sector_columns)
    mlb.fit([])  # Needed to initialize

    # create sector flags
    df['sector_list'] = df['sector_list'].apply(lambda labels: filter_known_labels(labels, sector_columns))
    sector_flags = pd.DataFrame(mlb.transform(df['sector_list']), columns=mlb.classes_, index=df.index)

    # other features
    x_numeric_categorical = df[['headquarters_country',
                                'employees_total',
                                'annual_turnover_million_euro',
                                'num_subsidiaries_countries',
                                'stock_listed',
                                'reporting_currency']]

    return pd.concat([sector_flags, x_numeric_categorical], axis=1)


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

    for code in sector_codes:
        column = f"sector_{code}"
        if column in row:
            row[column] = 1

    for code in region_codes:
        column = f"region_{code}"
        if column in row:
            row[column] = 1

    for code in product_codes:
        column = f"product_{code}"
        if column in row:
            row[column] = 1

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
        mapped = LEGACY_SECTOR_LABEL_TO_NACE_SECTION.get(label.lower())
        if mapped:
            normalized.append(mapped)

    return unique_ordered(normalized)


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


def new_format_positive_scores(classifier, dataframe, expected_key_count: int) -> list[float]:
    if not hasattr(classifier, "predict_proba"):
        raise ValueError("Runtime-enabled new-format profiles must expose predict_proba for candidate filtering.")

    probabilities = classifier.predict_proba(dataframe)
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

    metadata = {
        "new_format_score_threshold": score_threshold,
        "raw_positive_key_count": len(raw_positive),
        "threshold_positive_key_count": len(threshold_positive),
        "excluded_non_candidate_key_count": len(threshold_positive) - len(candidate_positive),
        "emitted_positive_key_count": len(candidate_positive),
    }

    return [key for key, _score in candidate_positive], metadata


# Predict esrs of a company
def predict_esrs(company_data: CompanyData, allow_inventoried_profile: bool = False):
    profile = resolve_model_profile(company_data.model_profile)
    if not allow_inventoried_profile:
        ensure_profile_runtime_enabled(profile)

    # load company data and ML classifier
    print("Loading company data...")
    clf = load_profile_classifier(profile)
    if is_new_format_profile(profile):
        df, feature_metadata = load_new_format_data(company_data, profile, clf)
    else:
        df = load_data(company_data)
        feature_metadata = FeatureMetadata()
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
            "runtime_activation": "runtime_enabled"
            if profile.runtime_enabled
            else "shadow_only_profile_not_endpoint_enabled",
        },
        evidence_refs=[],
    )
    if len(predictions) == 1:
        if is_new_format_profile(profile):
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
        else:
            prediction.esrs.update(dict(zip(esrs_columns, [int(p) for p in predictions[0]])))
    return prediction


# ----------------------------------------------------------------
#  MAIN version
# ----------------------------------------------------------------

# Load company data
def main_load_data(input_file, classifier_path):
    df = pd.read_csv(input_file, sep=';')
    file_values = df['file'].tolist()   # Save 'file' column values as a list
    df.drop(columns='file', inplace=True)  # Drop the column from DataFrame
    # Split sector strings into lists
    df['sector_list'] = df['sector'].str.split(',')

    # Load sector columns from training
    sector_columns = joblib.load(classifier_path + '/sector_columns.pkl')
    mlb = MultiLabelBinarizer(classes=sector_columns)
    mlb.fit([])  # Needed to initialize

    # Create sector flags
    df['sector_list'] = df['sector_list'].apply(lambda labels: filter_known_labels(labels, sector_columns))
    sector_flags = pd.DataFrame(mlb.transform(df['sector_list']), columns=mlb.classes_, index=df.index)

    # Other features
    X_numeric_categorical = df[['headquarters_country',
                                'employees_total',
                                'annual_turnover_million_euro',
                                'num_subsidiaries_countries',
                                'stock_listed',
                                'reporting_currency']]

    X = pd.concat([sector_flags, X_numeric_categorical], axis=1)

    return X, file_values


def main(company_data, classifier_path, output_path):
    print(f"Loading data {company_data}")
    df, file_names = main_load_data(company_data, classifier_path)
    clf = joblib.load(classifier_path+'/esrs_classifier.pkl')
    esrs_columns = joblib.load(classifier_path+'/esrs_columns.pkl')

    print(f"Start prediction.")
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

    decoded_predictions = {}
    index = 0
    for row in predictions:
        # Get ESRS names where prediction == 1
        decoded_predictions[file_names[index]] = [esrs for esrs, val in zip(esrs_columns, row) if val == 1]
        index = index + 1

    for key, value in decoded_predictions.items():
        print(f"name: {key}.\nESRs: {value}")
        print("-------------------------------------")

    if output_path:
        predictions_df = pd.DataFrame(predictions, columns=esrs_columns)
        predictions_df.insert(0, 'file', file_names)
        predictions_df.to_csv(output_path, index=False, sep=";")


# Example usage
if __name__ == "__main__":
    argument_parser = ArgumentParser(description='Predict sustainability data out of company data')
    argument_parser.add_argument('--company_data', default='./training_data/company_data_prev.csv',
                                 help='path to csv file with company data')
    argument_parser.add_argument('--classifier_path', default='./trained_classifier/',
                                 help='path where classifier parameters are stored')
    argument_parser.add_argument('--store_prediction', default='./trained_classifier/predictions.csv',
                                 help='path where classifier predictions are stored')
    args = argument_parser.parse_args()
    main(args.company_data, args.classifier_path, args.store_prediction)
