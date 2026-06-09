import argparse
import contextlib
import json
import sys
import time

from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

from project_paths import resolve_web_data_file
from services import service_predict
from services.datatypes import CompanyData, Prediction


DEFAULT_MODEL_PROFILES = ["legacy_v0", "new_format_732_v1_gpt41"]
NEW_FORMAT_MODEL_PREFIX = "new_format_732_v1_"
DEFAULT_MAPPING_FILENAME = "ar16_to_python_esrs_mapping_new_format_732_v1.json"


@dataclass(frozen=True)
class ShadowCase:
    name: str
    company: CompanyData


def build_sme_shadow_cases() -> list[ShadowCase]:
    return [
        ShadowCase(
            name="small_10_agri",
            company=CompanyData(
                company_name="I4S Shadow Small Agri",
                sector_list=["Agriculture"],
                subsidiaries_regions=["EU"],
                products_services=["A", "C"],
                headquarters_country="Spain",
                num_subsidiaries_countries=0,
                employees_total=10,
                annual_turnover_million_euro=1.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="small_150_manufacturing",
            company=CompanyData(
                company_name="I4S Shadow Small Manufacturing",
                sector_list=["Manufacturing"],
                subsidiaries_regions=["EU", "LATAM"],
                products_services=["C"],
                headquarters_country="Spain",
                num_subsidiaries_countries=1,
                employees_total=150,
                annual_turnover_million_euro=6.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="medium_400_services",
            company=CompanyData(
                company_name="I4S Shadow Medium Services",
                sector_list=["Information technology"],
                subsidiaries_regions=["EU"],
                products_services=["J", "M"],
                headquarters_country="Spain",
                num_subsidiaries_countries=1,
                employees_total=400,
                annual_turnover_million_euro=45.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
    ]


def build_heterogeneous_shadow_cases() -> list[ShadowCase]:
    return [
        ShadowCase(
            name="micro_3_software_consultancy",
            company=CompanyData(
                company_name="I4S Shadow Micro Software",
                sector_list=["J"],
                subsidiaries_regions=[],
                products_services=["J", "M"],
                company_size="SMALL",
                juridic_form="limited_company",
                headquarters_country="Spain",
                num_subsidiaries_countries=0,
                employees_total=3,
                annual_turnover_million_euro=0.35,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="micro_8_hospitality_local",
            company=CompanyData(
                company_name="I4S Shadow Micro Hospitality",
                sector_list=["I"],
                subsidiaries_regions=["EU"],
                products_services=["I"],
                company_size="SMALL",
                juridic_form="sole_proprietorship",
                headquarters_country="Spain",
                num_subsidiaries_countries=0,
                employees_total=8,
                annual_turnover_million_euro=0.8,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        *build_sme_shadow_cases(),
        ShadowCase(
            name="small_45_construction_local",
            company=CompanyData(
                company_name="I4S Shadow Small Construction",
                sector_list=["F"],
                subsidiaries_regions=["EU"],
                products_services=["F"],
                company_size="SMALL",
                juridic_form="limited_company",
                headquarters_country="Portugal",
                num_subsidiaries_countries=0,
                employees_total=45,
                annual_turnover_million_euro=5.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="small_80_retail_ecommerce",
            company=CompanyData(
                company_name="I4S Shadow Small Retail",
                sector_list=["G"],
                subsidiaries_regions=["EU", "APAC"],
                products_services=["G", "J"],
                company_size="SMALL",
                juridic_form="limited_company",
                headquarters_country="Spain",
                num_subsidiaries_countries=2,
                employees_total=80,
                annual_turnover_million_euro=8.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="small_90_water_waste_services",
            company=CompanyData(
                company_name="I4S Shadow Water Waste",
                sector_list=["E"],
                subsidiaries_regions=["EU"],
                products_services=["E"],
                company_size="SMALL",
                juridic_form="public_private_company",
                headquarters_country="Spain",
                num_subsidiaries_countries=0,
                employees_total=90,
                annual_turnover_million_euro=12.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="small_120_real_estate_assets",
            company=CompanyData(
                company_name="I4S Shadow Real Estate",
                sector_list=["L"],
                subsidiaries_regions=["EU"],
                products_services=["L", "F"],
                company_size="SMALL",
                juridic_form="limited_company",
                headquarters_country="Spain",
                num_subsidiaries_countries=1,
                employees_total=120,
                annual_turnover_million_euro=20.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="small_180_agri_cooperative",
            company=CompanyData(
                company_name="I4S Shadow Agri Cooperative",
                sector_list=["A", "C"],
                subsidiaries_regions=["EU", "LATAM"],
                products_services=["A", "C", "G"],
                company_size="SMALL",
                juridic_form="cooperative",
                headquarters_country="Spain",
                num_subsidiaries_countries=1,
                employees_total=180,
                annual_turnover_million_euro=24.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="small_220_transport_logistics",
            company=CompanyData(
                company_name="I4S Shadow Logistics",
                sector_list=["H"],
                subsidiaries_regions=["EU", "LATAM"],
                products_services=["H"],
                company_size="SMALL",
                juridic_form="limited_company",
                headquarters_country="Spain",
                num_subsidiaries_countries=2,
                employees_total=220,
                annual_turnover_million_euro=28.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="medium_300_energy_services",
            company=CompanyData(
                company_name="I4S Shadow Energy Services",
                sector_list=["D"],
                subsidiaries_regions=["EU", "MENA"],
                products_services=["D", "F"],
                company_size="MEDIUM",
                juridic_form="limited_company",
                headquarters_country="Spain",
                num_subsidiaries_countries=2,
                employees_total=300,
                annual_turnover_million_euro=70.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="medium_350_financial_services",
            company=CompanyData(
                company_name="I4S Shadow Financial Services",
                sector_list=["K"],
                subsidiaries_regions=["EU"],
                products_services=["K", "J"],
                company_size="MEDIUM",
                juridic_form="limited_company",
                headquarters_country="Spain",
                num_subsidiaries_countries=0,
                employees_total=350,
                annual_turnover_million_euro=85.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="medium_420_education_group",
            company=CompanyData(
                company_name="I4S Shadow Education Group",
                sector_list=["P"],
                subsidiaries_regions=["EU"],
                products_services=["P", "J"],
                company_size="MEDIUM",
                juridic_form="non_profit_foundation",
                headquarters_country="France",
                num_subsidiaries_countries=1,
                employees_total=420,
                annual_turnover_million_euro=32.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="medium_480_healthcare_provider",
            company=CompanyData(
                company_name="I4S Shadow Healthcare",
                sector_list=["R"],
                subsidiaries_regions=["EU"],
                products_services=["R"],
                company_size="MEDIUM",
                juridic_form="limited_company",
                headquarters_country="Italy",
                num_subsidiaries_countries=0,
                employees_total=480,
                annual_turnover_million_euro=55.0,
                stock_listed=False,
                reporting_currency="EUR",
            ),
        ),
        ShadowCase(
            name="medium_499_listed_industrial",
            company=CompanyData(
                company_name="I4S Shadow Listed Industrial",
                sector_list=["C", "D"],
                subsidiaries_regions=["EU", "NA", "APAC"],
                products_services=["C", "D"],
                company_size="MEDIUM",
                juridic_form="public_limited_company",
                headquarters_country="Germany",
                num_subsidiaries_countries=4,
                employees_total=499,
                annual_turnover_million_euro=110.0,
                stock_listed=True,
                reporting_currency="EUR",
            ),
        ),
    ]


def build_shadow_cases(case_set: str) -> list[ShadowCase]:
    if case_set == "sme":
        return build_sme_shadow_cases()
    if case_set == "heterogeneous":
        return build_heterogeneous_shadow_cases()
    raise ValueError(f"Unknown case set '{case_set}'. Use 'sme' or 'heterogeneous'.")


def run_shadow_matrix(
    cases: Iterable[ShadowCase] | None = None,
    model_profiles: list[str] | None = None,
    mapping_path: Path | None = None,
) -> dict:
    cases = list(cases or build_sme_shadow_cases())
    model_profiles = model_profiles or DEFAULT_MODEL_PROFILES
    mapping_path = mapping_path or resolve_web_data_file(DEFAULT_MAPPING_FILENAME)
    mapping_inventory = None
    if any(profile_name.startswith(NEW_FORMAT_MODEL_PREFIX) for profile_name in model_profiles):
        mapping_inventory = load_mapping_inventory(mapping_path)

    results = {
        "model_profiles": model_profiles,
        "mapping_path": str(mapping_path),
        "cases": [],
    }

    for case in cases:
        case_result = {
            "case": case.name,
            "employees_total": case.company.employees_total,
            "annual_turnover_million_euro": case.company.annual_turnover_million_euro,
            "runs": {},
        }

        for profile_name in model_profiles:
            company = case.company.model_copy(update={"model_profile": profile_name})
            allow_shadow = profile_name != "legacy_v0"
            start = time.perf_counter()
            with contextlib.redirect_stdout(sys.stderr):
                prediction = service_predict.predict_esrs(
                    company,
                    allow_inventoried_profile=allow_shadow,
                )
            elapsed_ms = int((time.perf_counter() - start) * 1000)
            case_result["runs"][profile_name] = prediction_summary(
                prediction=prediction,
                elapsed_ms=elapsed_ms,
                mapping_inventory=mapping_inventory if profile_name.startswith(NEW_FORMAT_MODEL_PREFIX) else None,
            )

        results["cases"].append(case_result)

    return results


def prediction_summary(
    prediction: Prediction,
    elapsed_ms: int,
    mapping_inventory: dict | None = None,
) -> dict:
    positive_keys = sorted([
        key
        for key, value in prediction.esrs.items()
        if int(value) == 1
    ])

    summary = {
        "model_profile": prediction.model_profile,
        "model_key_count": prediction.model_key_count,
        "positive_key_count": len(positive_keys),
        "positive_keys": positive_keys,
        "elapsed_ms": elapsed_ms,
        "feature_metadata": prediction.feature_metadata.model_dump(),
        "mapping_metadata": prediction.mapping_metadata,
    }
    if mapping_inventory is not None:
        summary["mapping_projection"] = project_new_format_mapping(
            positive_keys=positive_keys,
            mapping_inventory=mapping_inventory,
        )
    return summary


def load_mapping_inventory(mapping_path: Path) -> dict:
    with mapping_path.open("r", encoding="utf-8") as handle:
        value = json.load(handle)
    if not isinstance(value, dict) or not isinstance(value.get("keys"), list):
        raise ValueError(f"{mapping_path} is not a new-format mapping inventory.")
    return value


def project_new_format_mapping(positive_keys: list[str], mapping_inventory: dict) -> dict:
    rows_by_key = {
        row["python_esrs_key"]: row
        for row in mapping_inventory.get("keys", [])
        if isinstance(row, dict) and isinstance(row.get("python_esrs_key"), str)
    }
    candidate_topic_ids: set[int] = set()
    approved_positive_keys: list[str] = []
    aggregate_positive_keys: list[str] = []
    review_required_keys: list[str] = []

    for key in positive_keys:
        row = rows_by_key.get(key)
        status = row.get("status") if row else None
        topic_ids = row.get("ar16_topic_ids") if row else []

        if status == "approved" and isinstance(topic_ids, list) and topic_ids:
            approved_positive_keys.append(key)
            for topic_id in topic_ids:
                if isinstance(topic_id, int) or str(topic_id).isdigit():
                    candidate_topic_ids.add(int(topic_id))
            continue

        if status == "aggregate_only":
            aggregate_positive_keys.append(key)
            continue

        review_required_keys.append(key)

    return {
        "candidate_topic_count": len(candidate_topic_ids),
        "candidate_topic_ids": sorted(candidate_topic_ids),
        "approved_positive_key_count": len(approved_positive_keys),
        "aggregate_positive_keys": aggregate_positive_keys,
        "review_required_keys": review_required_keys,
        "review_required_key_count": len(review_required_keys),
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Run an offline shadow comparison between legacy and inventoried 732-report model profiles."
    )
    parser.add_argument(
        "--profiles",
        default=",".join(DEFAULT_MODEL_PROFILES),
        help="Comma-separated profile names. Defaults to legacy_v0,new_format_732_v1_gpt41.",
    )
    parser.add_argument(
        "--mapping",
        type=Path,
        default=None,
        help="New-format AR16 mapping inventory used to project shadow positives into candidate topics.",
    )
    parser.add_argument(
        "--case-set",
        choices=["sme", "heterogeneous"],
        default="sme",
        help="Case matrix to run. Use heterogeneous for broad characterization smoke.",
    )
    args = parser.parse_args()

    profiles = [profile.strip() for profile in args.profiles.split(",") if profile.strip()]
    try:
        print(json.dumps(
            run_shadow_matrix(
                cases=build_shadow_cases(args.case_set),
                model_profiles=profiles,
                mapping_path=args.mapping,
            ),
            ensure_ascii=False,
            indent=2,
        ))
        return 0
    except ModuleNotFoundError as error:
        missing_module = str(error).split("'")[1] if "'" in str(error) else str(error)
        print(
            "shadow_failed=true "
            f"missing_python_module={missing_module} "
            "hint=Run .\\.venv\\Scripts\\python.exe -m pip install -r requirements.txt "
            "and rerun with .\\.venv\\Scripts\\python.exe src\\shadow_model_profiles.py",
            file=sys.stderr,
        )
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
