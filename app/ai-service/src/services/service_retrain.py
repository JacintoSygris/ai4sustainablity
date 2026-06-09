import pandas as pd
import shutil
import tempfile
import threading
import time

from pathlib import Path
from .datatypes import CompanyDataAndEsrs
from utils.constants import PATH_COMPANY_DATA, PATH_COMPANY_ESRS, PATH_DATA


DATA_FILES = ["company_data.csv", "company_esrs.csv"]
MODEL_ARTIFACTS = ["esrs_classifier.pkl", "esrs_columns.pkl", "sector_columns.pkl"]
_retrain_lock = threading.Lock()
LEGACY_TRAINER_ARCHIVED_MESSAGE = (
    "The legacy retrain trainer was archived on 2026-06-09. "
    "Use src/train_model_yaml.py for the approved new-format 732 training pipeline."
)


def load_retrain_esrs_keys(company_esrs_path: str | Path = PATH_COMPANY_ESRS) -> list[str]:
    header = pd.read_csv(company_esrs_path, sep=';', nrows=0).columns.tolist()
    return [column for column in header if column != 'file']


def _preview_keys(keys: list[str]) -> str:
    preview = ", ".join(keys[:5])
    if len(keys) > 5:
        return f"{preview}, ... ({len(keys)} total)"
    return preview


def validate_retrain_esrs_labels(
    company_data: CompanyDataAndEsrs,
    company_esrs_path: str | Path = PATH_COMPANY_ESRS,
):
    expected_keys = load_retrain_esrs_keys(company_esrs_path)
    expected_key_set = set(expected_keys)
    received_key_set = set(company_data.esrs.keys())

    unknown_keys = sorted(received_key_set - expected_key_set)
    missing_keys = [key for key in expected_keys if key not in received_key_set]
    invalid_values = {
        key: value
        for key, value in company_data.esrs.items()
        if type(value) is not int or value not in (0, 1)
    }

    errors = []
    if unknown_keys:
        errors.append(f"unknown ESRS keys: {_preview_keys(unknown_keys)}")
    if missing_keys:
        errors.append(f"missing ESRS keys: {_preview_keys(missing_keys)}")
    if invalid_values:
        invalid_preview = [f"{key}={value!r}" for key, value in list(invalid_values.items())[:5]]
        if len(invalid_values) > 5:
            invalid_preview.append(f"... ({len(invalid_values)} total)")
        errors.append(f"non-binary ESRS values: {', '.join(invalid_preview)}")

    if errors:
        raise ValueError("Invalid retrain ESRS labels: " + "; ".join(errors))


# Add company to the CSV file that collects all received companies
def save_data(
    company_data: CompanyDataAndEsrs,
    company_data_path: str | Path = PATH_COMPANY_DATA,
    company_esrs_path: str | Path = PATH_COMPANY_ESRS,
):
    validate_retrain_esrs_labels(company_data, company_esrs_path)

    # received company
    df_company = pd.DataFrame([company_data.model_dump()])
    df_company['sector'] = df_company['sector_list'].apply(lambda s: ','.join(s))  # concatenate sectors
    df_company['file'] = df_company['company_name']  # add column 'file'
    for key, value in company_data.esrs.items():     # add column for each esrs
        df_company[key] = value

    # save basic data of company
    df_company_data = df_company[[
        'file',
        'company_name',
        'sector',
        'headquarters_country',
        'num_subsidiaries_countries',
        'employees_total',
        'annual_turnover_million_euro',
        'stock_listed',
        'reporting_currency']]
    df_companies_data = pd.read_csv(company_data_path, sep=';')
    df_companies_data = pd.concat([df_companies_data, df_company_data], ignore_index=True)
    df_companies_data.to_csv(company_data_path, sep=';', index=False)

    # save esrs of company
    df_company_esrs = df_company[['file']+list(company_data.esrs.keys())]
    df_companies_esrs = pd.read_csv(company_esrs_path, sep=';')
    df_companies_esrs = pd.concat([df_companies_esrs, df_company_esrs], ignore_index=True)
    df_companies_esrs.to_csv(company_esrs_path, sep=';', index=False)


def _load_train_module():
    raise RuntimeError(LEGACY_TRAINER_ARCHIVED_MESSAGE)


def _copy_current_data(data_dir: Path, staging_dir: Path):
    for filename in DATA_FILES + MODEL_ARTIFACTS:
        source = data_dir / filename
        if source.exists():
            shutil.copy2(source, staging_dir / filename)


def _snapshot_current_data(data_dir: Path) -> dict[str, bytes | None]:
    snapshot: dict[str, bytes | None] = {}
    for filename in DATA_FILES + MODEL_ARTIFACTS:
        target = data_dir / filename
        snapshot[filename] = target.read_bytes() if target.exists() else None
    return snapshot


def _restore_current_data(snapshot: dict[str, bytes | None], data_dir: Path):
    for filename, contents in snapshot.items():
        target = data_dir / filename
        if contents is None:
            if target.exists():
                target.unlink()
            continue
        target.write_bytes(contents)


def _promote_staged_data(staging_dir: Path, data_dir: Path):
    data_dir.mkdir(parents=True, exist_ok=True)
    snapshot = _snapshot_current_data(data_dir)
    try:
        for filename in DATA_FILES + MODEL_ARTIFACTS:
            staged_file = staging_dir / filename
            if staged_file.exists():
                staged_file.replace(data_dir / filename)
    except Exception as promotion_error:
        try:
            _restore_current_data(snapshot, data_dir)
        except Exception as rollback_error:
            if hasattr(promotion_error, "add_note"):
                promotion_error.add_note(f"Rollback after failed promotion also failed: {rollback_error}")
        raise


# Retrain classifier of companies' esrs
def retrain_classifier(
    company_data: CompanyDataAndEsrs,
    data_path: str | Path = PATH_DATA,
    train_module=None,
):
    data_dir = Path(data_path)
    trainer = train_module or _load_train_module()

    with _retrain_lock:
        with tempfile.TemporaryDirectory(prefix="retrain-", dir=str(data_dir.parent)) as temp_dir:
            staging_dir = Path(temp_dir)
            staged_company_data = staging_dir / "company_data.csv"
            staged_company_esrs = staging_dir / "company_esrs.csv"

            _copy_current_data(data_dir, staging_dir)

            # add company data to a staged copy of the collected company data
            print("Saving company data...")
            save_data(company_data, staged_company_data, staged_company_esrs)

            # load staged company data
            print("Loading company data and esrs...")
            df = trainer.load_data(str(staged_company_data), str(staged_company_esrs))

            # train model into the staged directory before promoting artifacts
            print("Start retraining...")
            start = time.time()
            # to-do: provide optional parameters for base_model, wrapper, optimisation
            trainer.train_model(df, str(staging_dir), 0, base_model='RF', wrapper='chain', split='iterative', optimiser='none')
            end = time.time()
            print(f"Retraining completed in {end - start:.2f} seconds")

            _promote_staged_data(staging_dir, data_dir)
