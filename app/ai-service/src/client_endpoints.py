import argparse
import itertools
import json
import os
import sys
import threading
import time

import requests

DEFAULT_BASE_URL = "http://127.0.0.1:8001"
BASE_URL_ENV = "I4S_AI_BASE_URL"


def resolve_base_url(base_url=None):
    candidate = base_url or os.environ.get(BASE_URL_ENV) or DEFAULT_BASE_URL
    candidate = candidate.strip().rstrip("/")
    return candidate or DEFAULT_BASE_URL


def endpoint_url(path, base_url=None):
    return f"{resolve_base_url(base_url)}/{path.lstrip('/')}"


def spinner(msg="Waiting..."):
    stop_event = threading.Event()

    def spin():
        for c in itertools.cycle('|/-\\'):
            if stop_event.is_set():
                break
            sys.stdout.write(f'\r{msg} {c}')
            sys.stdout.flush()
            time.sleep(0.1)
        sys.stdout.write('\r' + ' ' * (len(msg) + 2) + '\r')  # clear line

    thread = threading.Thread(target=spin, daemon=True)
    thread.start()
    return stop_event, thread

def sample_predict(base_url=None):
    return (endpoint_url("predict", base_url),  # url
            {                                 # company data
                "company_name": "ACCIONA, S.A.",
                "sector_list": ["Renewable Energy", "Construction", "Water Management", "Infrastructure", "Real Estate", "Mobility"],
                "headquarters_country": "Spain",
                "num_subsidiaries_countries": 17,
                "employees_total": 66021,
                "annual_turnover_million_euro": 19190,
                "stock_listed": True,
                "reporting_currency": "EUR",
            })


def sample_retrain(base_url=None):
    return (endpoint_url("retrain", base_url),  # url
            {                                 # company_data
                "company_name": "Banco de Sabadell, Sociedad Anonima",
                "sector_list": ["Financial services", "Banking"],
                "headquarters_country": "Spain",
                "num_subsidiaries_countries": 2,
                "employees_total": 18769,
                "annual_turnover_million_euro": 251390.0,
                "stock_listed": True,
                "reporting_currency": "EUR",
                "esrs": {
                    "esrs_e1_climate_change": 1,
                    "esrs_e1_adaptation_to_climate_change": 1,
                    "esrs_e1_mitigation_of_climate_change": 1,
                    "esrs_e1_energy_use": 1,
                    "esrs_e2_pollution": 1,
                    "esrs_e2_pollution_air_pollution": 1,
                    "esrs_e2_pollution_soil_pollution": 1,
                    "esrs_e2_pollution_water_pollution": 1,
                    "esrs_e2_substances_of_concern": 1,
                    "esrs_e2_substances_of_very_high_concern": 1,
                    "esrs_e2_impact_on_ecosystems_and_Human_Health": 1,
                    "esrs_e2_pollution_microplastics": 1,
                    "esrs_e3_water_and_marine_resources": 1,
                    "esrs_e3_Water_Consumption": 1,
                    "esrs_e3_Water_Withdrawal": 1,
                    "esrs_e3_Water_Discharge": 1,
                    "esrs_e3_Water_Discharge_into_oceans": 1,
                    "esrs_e3_Extraction_and_use_of_marine_resources": 1,
                    "esrs_e3_other": 1,
                    "esrs_e4_direct_impact_biodiversity_loss_climate_change": 1,
                    "esrs_e4_direct_impact_biodiversity_loss_change_land_use_freshwater_sea_use": 1,
                    "esrs_e4_direct_impact_biodiversity_loss_direct_exploitation": 1,
                    "esrs_e4_direct_impact_biodiversity_exotic_invading_species": 1,
                    "esrs_e4_direct_impact_biodiversity_pollution": 1,
                    "esrs_e4_direct_impact_biodiversity_others": 1,
                    "esrs_e4_impact_on_status_species_size_of_species_population": 1,
                    "esrs_e4_impact_on_status_species_extinction_risk_species": 1,
                    "esrs_e4_impact_extension_status_ecosystems_soil_degradation": 1,
                    "esrs_e4_impact_extension_status_ecosystems_desertification": 1,
                    "esrs_e4_impact_extension_status_ecosystems_soil_sealing": 1,
                    "esrs_e4_impacts_dependencies_services_ecosystems": 1,
                    "esrs_e5_resource_use_circular_economy_resource_input": 1,
                    "esrs_e5_resource_use_circular_economy_resource_output": 1,
                    "esrs_e5_resource_use_circular_economy_waste_management": 1,
                    "esrs_s1_workforce_working_conditions_safe_employment": 1,
                    "esrs_s1_workforce_working_conditions_working_time": 1,
                    "esrs_s1_workforce_working_conditions_fair_salary": 1,
                    "esrs_s1_workforce_working_conditions_social_dialogue": 1,
                    "esrs_s1_workforce_working_conditions_association_freedom_works_council_information_rights": 1,
                    "esrs_s1_workforce_working_conditions_collective_negotiation_collective_agreement": 1,
                    "esrs_s1_workforce_working_conditions_reconciling_work_family_life": 1,
                    "esrs_s1_workforce_working_conditions_health_safety": 1,
                    "esrs_s1_equal_treatment_opportunities_gender_equality_equal_pay_work_equal_value": 1,
                    "esrs_s1_equal_treatment_opportunities_training_competence_development": 1,
                    "esrs_s1_equal_treatment_opportunities_employment_inclusion_people_disabilities": 1,
                    "esrs_s1_equal_treatment_opportunities_measures_against_violence_harassment_workplace": 1,
                    "esrs_s1_equal_treatment_opportunities_diversity": 1,
                    "esrs_s1_other_labour_rights_child_labour": 1,
                    "esrs_s1_other_labour_rights_forced_labour": 1,
                    "esrs_s1_other_labour_rights_adequate_housing": 1,
                    "esrs_s1_other_labour_rights_privacity": 1,
                    "esrs_s2_workers_value_chain_working_conditions_safe_employment": 1,
                    "esrs_s2_workers_value_chain_working_conditions_working_time": 1,
                    "esrs_s2_workers_value_chain_working_conditions_fair_salary": 1,
                    "esrs_s2_workers_value_chain_working_conditions_social_dialogue": 1,
                    "esrs_s2_workers_value_chain_working_conditions_association_freedom_works_council_information_rights": 1,
                    "esrs_s2_workers_value_chain_working_conditions_collective_negotiation_collective_agreement": 1,
                    "esrs_s2_workers_value_chain_working_conditions_reconciling_work_family_life": 1,
                    "esrs_s2_workers_value_chain_working_conditions_health_safety": 1,
                    "esrs_s2_equal_treatment_opportunities_gender_equality_equal_pay_work_equal_value": 1,
                    "esrs_s2_equal_treatment_opportunities_training_competence_development": 1,
                    "esrs_s2_equal_treatment_opportunities_employment_inclusion_people_disabilities": 1,
                    "esrs_s2_equal_treatment_opportunities_measures_against_violence_harassment_workplace": 1,
                    "esrs_s2_equal_treatment_opportunities_diversity": 1,
                    "esrs_s2_other_labour_rights_child_labour": 1,
                    "esrs_s2_other_labour_rights_forced_labour": 1,
                    "esrs_s2_other_labour_rights_adequate_housing": 1,
                    "esrs_s2_other_labour_rights_water_sanitation": 1,
                    "esrs_s2_other_labour_rights_privacity": 1,
                    "esrs_s3_affected_groups_adequate_housing": 0,
                    "esrs_s3_affected_groups_adequate_food": 0,
                    "esrs_s3_affected_groups_water_sanitation": 0,
                    "esrs_s3_affected_groups_land_incidents": 0,
                    "esrs_s3_affected_groups_security_incidents": 0,
                    "esrs_s3_affected_groups_expression_freedom": 0,
                    "esrs_s3_affected_groups_assembly_freedom": 0,
                    "esrs_s3_affected_groups_indicence_human_rights": 0,
                    "esrs_s3_affected_groups_consent": 0,
                    "esrs_s3_affected_groups_self_determination": 0,
                    "esrs_s3_affected_groups_cultural_rights": 0,
                    "esrs_s4_consumers_incidents_privacity": 0,
                    "esrs_s4_consumers_incidents_expression_freedom": 0,
                    "esrs_s4_consumers_incidents_information_access": 0,
                    "esrs_s4_consumers_safety_health": 0,
                    "esrs_s4_consumers_safety_security": 0,
                    "esrs_s4_consumers_safety_child_protection": 0,
                    "esrs_s4_consumers_inclusion_non_discrimination": 0,
                    "esrs_s4_consumers_inclusion_access_products": 0,
                    "esrs_s4_consumers_inclusion_responsible_marketing": 0,
                    "esrs_g1_corporate_behaviour_culture": 1,
                    "esrs_g1_corporate_behaviour_whistleblower_protection": 1,
                    "esrs_g1_corporate_behaviour_animal_welfare": 1,
                    "esrs_g1_corporate_behaviour_political_engagement": 1,
                    "esrs_g1_corporate_behaviour_supplier_relationship": 1,
                    "esrs_g1_corporate_behaviour_corruption_prevention": 1,
                    "esrs_g1_corporate_behaviour_corruption_incidents": 1
                }
            })


def format_request_exception(e: requests.exceptions.RequestException) -> str:
    response = getattr(e, "response", None)
    if response is None:
        return str(e)

    try:
        detail = response.json().get('detail')
    except ValueError:
        detail = response.text

    return f"{e} ---> {detail}"


def run_sample_predict(base_url=None):
    url, company_data = sample_predict(base_url=base_url)
    stop_spinner, spinner_thread = spinner("Sending request...")
    start_time = time.perf_counter()

    try:
        response = requests.post(url, json=company_data, timeout=30)
        response.raise_for_status()
        data = response.json()
    except requests.exceptions.RequestException as e:
        print(format_request_exception(e))
        return 1
    except ValueError as e:
        print(f"invalid_json_response=true detail={e}")
        return 1
    finally:
        end_time = time.perf_counter()
        stop_spinner.set()
        spinner_thread.join(timeout=1)

    elapsed = end_time - start_time
    print(f"\nResponse: {response.status_code} in {elapsed:.2f} seconds")
    print(f"{json.dumps(data, indent=4)}")
    return 0


# retrain -------------------------------------------------
# try:
#     url, company_data = sample_retrain()
#     response = requests.post(url, json=company_data)
#     response.raise_for_status()
#     data = response.json()
#     job_id = data.get("job_id")
#     while data.get("status") == "started" or data.get("status") == "running":
#         print(f"{job_id}: {data.get('status')}")
#         time.sleep(5)
#         response = requests.get(f"{resolve_base_url()}/retrain-status/{job_id}")
#         data = response.json()
#     print(f"{job_id}: {data.get('status')}")
#     if data.get('status') == 'failed':
#         print(f"{data.get('error')}")
# except requests.exceptions.RequestException as e:
#     print(format_request_exception(e))

def main(argv=None):
    parser = argparse.ArgumentParser(description="Run a sample prediction against the IA4S FastAPI service.")
    parser.add_argument(
        "--base-url",
        default=None,
        help=f"FastAPI base URL. Defaults to ${BASE_URL_ENV} or {DEFAULT_BASE_URL}.",
    )
    args = parser.parse_args(argv)
    return run_sample_predict(base_url=args.base_url)


# predict -------------------------------------------------
if __name__ == "__main__":
    sys.exit(main())
