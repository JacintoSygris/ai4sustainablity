import abc
import io
import csv
from pydantic import BaseModel, Field


class BooleanResponse(BaseModel):
    data_extracted: bool

    def is_True(self):
        return self.data_extracted

# --------------------------------------------------------------------------------------------------------------------
class CompanyFeatures(BaseModel):
    company_name: str = Field(..., description="The full legal name of the company.")
    sector: list[str] = Field(..., description="Industry sector(s) the company operates in. Use high-level terms like 'Technology', 'Utilities', 'Healthcare'.")
    headquarters_country: str = Field(..., description = "Country where the company’s headquarters is located.")
    subsidiaries_countries: list[str] = Field(..., description = "List of countries where the company has significant subsidiaries or operational presence.")
    employees_total: str = Field(..., description="Most recent total number of employees reported.")
    employees_context: str = Field(..., description="Verbatim paragraph from the document where the number of employees is mentioned. Include the page number if available.")
    annual_turnover: str = Field(..., description="The most recent annual turnover figure. Express in million euros if possible. If in another currency, include the amount and currency.")
    annual_turnover_context: str = Field(..., description="Exact excerpt from the document where turnover is mentioned. Include the page number if available.")
    stock_listed: str = Field(..., description="true or false — is the company publicly traded?")
    reporting_currency: str = Field(..., description="Currency used in financial figures throughout the report.")
    ownership_structure: str = Field(..., description="Short description of the company's ownership model, e.g., 'privately held', 'state - owned', 'public company'.")

    def to_csv_row(self, file_name) -> str:
        return (f"{file_name}; {escape_csv_cell(self.company_name)}; {escape_csv_cell(','.join(self.sector))}; "
                f"{escape_csv_cell(self.headquarters_country)}; {escape_csv_cell(','.join(self.subsidiaries_countries))}; "
                f"{escape_csv_cell(self.employees_total)}; {escape_csv_cell(self.employees_context)}; {escape_csv_cell(self.annual_turnover)}; "
                f"{escape_csv_cell(self.annual_turnover_context)}; {escape_csv_cell(self.stock_listed)}; {escape_csv_cell(self.reporting_currency)}; {escape_csv_cell(self.ownership_structure)}")

    def to_header_row(self) -> str:
        return f"file_name; company_name; sector; headquarters_country; subsidiaries_countries; employees_total; employees_context; annual_turnover; annual_turnover_context; stock_listed; reporting_currency; ownership_structure"

# --------------------------------------------------------------------------------------------------------------------
def escape_csv_cell(value, delimiter=';'):
    output = io.StringIO()
    cleaned = value.replace("\n", " ").replace("\r", " ") # remove breakline
    writer = csv.writer(output, delimiter=delimiter, quotechar='"', quoting=csv.QUOTE_MINIMAL)
    writer.writerow([cleaned])
    return output.getvalue().strip()


class ESRSBase(BaseModel, abc.ABC):
    def esr_name(self):
        return self.__class__.__name__

    def get_esrs_attributes(self): # to-do: refactor
        return {key: value for key, value in vars(self).items() if key.startswith(self.esr_name())}

    def to_csv_row(self, file_name=None) -> str:
        #values = [str(val).replace(";", ",") for val in self.get_esrs_attributes().values()]
        values = [escape_csv_cell(str(val)) for val in self.get_esrs_attributes().values()]
        if file_name:
            return file_name+";"+(";".join(values))
        else:
            return ";".join(values)

    def to_header_row(self, with_file_name : bool = True) -> str:
        if with_file_name:
            return "file;"+(";".join(self.get_esrs_attributes().keys()))
        else:
            return ";".join(self.get_esrs_attributes().keys())

# --------------------------------------------------------------------------------------------------------------------
class ESRS_E1(ESRSBase):
    esrs_e1_climate_change: bool = Field(..., description="Whether ESRS E1 (Climate change) was reported.")
    esrs_e1_adaptation_to_climate_change: str = Field(..., description="ESRS E1 subtheme: Actions for adapting to climate change, including Physical climate risk assessment (chronic and acute risks), Adaptation strategies and actions, Climate resilience of assets, operations, and value chain, Investments in adaptation measures")
    esrs_e1_adaptation_to_climate_change_context: str = Field(...,
                                                      description="verbatim quote of the information extracted in field esrs_e1_adaptation_to_climate_change, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e1_mitigation_of_climate_change: str = Field(..., description="ESRS E1 subtheme: Mitigation of climate change, like GHG emissions reduction targets, policies and actions for GHG reduction, decarbonization levers and initiatives, use of renewable energy and energy efficiency measures, and Scope 1, 2, and 3 GHG emissions (with breakdowns and methodologies), Internal carbon pricing (if any)")
    esrs_e1_mitigation_of_climate_change_context: str = Field(...,
                                                      description="verbatim quote of the information extracted in field esrs_e1_mitigation_of_climate_change, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e1_energy_use: str = Field(..., description="ESRS E1 subtheme: Energy consumption, like Total energy consumption (direct and indirect), Share of renewable vs. non-renewable energy, Energy efficiency initiatives, Energy intensity ratios (e.g., per product, revenue, etc.).")
    esrs_e1_energy_use_context: str = Field(...,
                                                      description="verbatim quote of the information extracted in field esrs_e1_energy_use, indicating page number (page X). Truncate if the paragraph is too long.")

    def esr_name(self):
        return "esrs_e1"


class ESRS_E2(ESRSBase):
    esrs_e2_pollution: bool = Field(..., description="Whether ESRS E2 (Pollution) was reported.")
    esrs_e2_pollution_air_pollution: str = Field(..., description="ESRS E2 (Pollution) subtheme: air pollution.")
    esrs_e2_pollution_air_pollution_context: str = Field(..., description="verbatim quote of the information extracted in field esrs_e2_pollution_air_pollution, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e2_pollution_soil_pollution: str = Field(..., description="ESRS E2 (Pollution) subtheme: soil pollution.")
    esrs_e2_pollution_soil_pollution_context: str = Field(...,
                                                         description="verbatim quote of the information extracted in field esrs_e2_pollution_soil_pollution, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e2_pollution_water_pollution: str = Field(..., description="ESRS E2 (Pollution) subtheme: water pollution.")
    esrs_e2_pollution_water_pollution_context: str = Field(...,
                                                          description="verbatim quote of the information extracted in field esrs_e2_pollution_water_pollution, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e2_substances_of_concern: str = Field(..., description="ESRS E2 (Pollution) subtheme: substances of concern.")
    esrs_e2_substances_of_concern_context: str = Field(...,
                                                           description="verbatim quote of the information extracted in field esrs_e2_substances_of_concern, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e2_substances_of_very_high_concern: str = Field(..., description="ESRS E2 (Pollution) subtheme: substances of very high concern.")
    esrs_e2_substances_of_very_high_concern_context: str = Field(...,
                                                         description="verbatim quote of the information extracted in field esrs_e2_substances_of_very_high_concern, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e2_impact_ecosystems_and_Human_Health: str = Field(..., description="ESRS E2 (Pollution) subtheme: impact on ecosystems, food and human health.")
    esrs_e2_impact_ecosystems_and_Human_Health_context: str = Field(...,
                                                         description= "verbatim quote of the information extracted in field esrs_e2_impact_ecosystems_and_Human_Health, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e2_pollution_microplastics: str = Field(..., description="ESRS E2 (Pollution) subtheme: microplastics.")
    esrs_e2_pollution_microplastics_context: str = Field(..., description="verbatim quote of the information extracted in field esrs_e2_pollution_microplastics, indicating page number (page X). Truncate if the paragraph is too long.")

    def esr_name(self):
        return "esrs_e2"

class ESRS_E3(ESRSBase):
    esrs_e3_water_and_marine_resources: bool = Field(..., description="Whether ESRS E3 (Water and marine resources) was reported.")
    esrs_e3_Water_Withdrawal: str = Field(...,
                                          description="ESRS E3 (Water and marine resources) subtheme: Water withdrawal.")
    esrs_e3_Water_Withdrawal_context: str = Field(...,
                                          description="verbatim quote of the information extracted in field esrs_e3_Water_Withdrawal, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e3_Water_Consumption: str = Field(...,
                                           description="ESRS E3 (Water and marine resources) subtheme: Water consumption.")
    esrs_e3_Water_Consumption_context: str = Field(...,
                                                  description="verbatim quote of the information extracted in field esrs_e3_Water_Consumption, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e3_Water_Discharge: str = Field(..., description="ESRS E3 (Water and marine resources) subtheme: Water discharge.")
    esrs_e3_Water_Discharge_context: str = Field(...,
                                                   description="verbatim quote of the information extracted in field esrs_e3_Water_Discharge, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e3_Water_Discharge_into_oceans: str = Field(..., description="ESRS E3 (Water and marine resources) subtheme: Water discharge into oceans.")
    esrs_e3_Water_Discharge_into_oceans_context: str = Field(...,
                                                 description="verbatim quote of the information extracted in field esrs_e3_Water_Discharge_into_oceans, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e3_Extraction_and_use_of_marine_resources: str = Field(..., description="ESRS E3 (Water and marine resources) subtheme: Extraction and use of marine resources.")
    esrs_e3_Extraction_and_use_of_marine_resources_context: str = Field(...,
                                                             description="verbatim quote of the information extracted in field esrs_e3_Extraction_and_use_of_marine_resources, indicating page number (page X). Truncate if the paragraph is too long.")
    esrs_e3_other: str = Field(..., description="ESRS E3 (Water and marine resources) Any other issue reported about ESRS E3")
    esrs_e3_other_context: str = Field(...,description="verbatim quote of the information extracted in field esrs_e3_other, indicating page number (page X). Truncate if the paragraph is too long.")

    def esr_name(self):
        return "esrs_e3"


class ESRS_E4(ESRSBase):
    esrs_e4_direct_impact_biodiversity_loss_climate_change: str = Field(...,
            description="ESRS E4 (Biodiversity and ecosystems) subtheme: Factors with a direct impact on biodiversity loss: climate change.")
    esrs_e4_direct_impact_biodiversity_loss_climate_change_context: str = Field(...,
            description="verbatim quote of the information extracted in field esrs_e4_direct_impact_biodiversity_loss_climate_change, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e4_direct_impact_biodiversity_loss_change_land_use_freshwater_sea_use: str = Field(...,
            description="ESRS E4 (Biodiversity and ecosystems) subtheme: Factors with a direct impact on biodiversity loss: Land use change, freshwater use change and sea use change .")
    esrs_e4_direct_impact_biodiversity_loss_change_land_use_freshwater_sea_use_context: str = Field(...,
            description="verbatim quote of the information extracted in field esrs_e4_direct_impact_biodiversity_loss_change_land_use_freshwater_sea_use, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e4_direct_impact_biodiversity_loss_direct_exploitation: str = Field(...,
            description="ESRS E4 (Biodiversity and ecosystems) subtheme: Factors with a direct impact on biodiversity loss: direct exploitation")
    esrs_e4_direct_impact_biodiversity_loss_direct_exploitation_context: str = Field(...,
            description="verbatim quote of the information extracted in field esrs_e4_direct_impact_biodiversity_loss_direct_exploitation, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e4_direct_impact_biodiversity_exotic_invading_species: str = Field(...,
            description="ESRS E4 (Biodiversity and ecosystems) subtheme: Factors with a direct impact on biodiversity loss: exotic invading species")
    esrs_e4_direct_impact_biodiversity_exotic_invading_species_context: str = Field(...,
            description="verbatim quote of the information extracted in field esrs_e4_direct_impact_biodiversity_exotic_invading_species, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e4_direct_impact_biodiversity_pollution: str = Field(...,
            description="ESRS E4 (Biodiversity and ecosystems) subtheme: Factors with a direct impact on biodiversity loss: pollution")
    esrs_e4_direct_impact_biodiversity_pollution_context: str = Field(...,
            description="verbatim quote of the information extracted in field esrs_e4_direct_impact_biodiversity_pollution, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e4_direct_impact_biodiversity_others: str = Field(...,
            description="ESRS E4 (Biodiversity and ecosystems) subtheme: Factors with a direct impact on biodiversity loss: others")
    esrs_e4_direct_impact_biodiversity_others_context: str = Field(...,
            description="verbatim quote of the information extracted in field esrs_e4_direct_impact_biodiversity_others, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e4_impact_on_status_species_size_of_species_population: str = Field(...,
            description="ESRS E4 (Biodiversity and ecosystems) subtheme: impact on status of species: size of species population")
    esrs_e4_impact_on_status_species_size_of_species_population_context: str = Field(...,
            description="verbatim quote of the information extracted in field esrs_e4_impact_on_status_species_size_of_species_population, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e4_impact_on_status_species_extinction_risk_species : str = Field(...,
            description="ESRS E4 (Biodiversity and ecosystems) subtheme: impact on status of species: extinction risk of species")
    esrs_e4_impact_on_status_species_extinction_risk_species_context: str = Field(...,
            description="verbatim quote of the information extracted in field esrs_e4_impact_on_status_species_extinction_risk_species, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e4_impact_extension_status_ecosystems_soil_degradation: str = Field(...,
            description="ESRS E4 (Biodiversity and ecosystems) subtheme: impact on extension and status of ecosystems: soil degradation")
    esrs_e4_impact_extension_status_ecosystems_soil_degradation_context: str = Field(...,
            description="verbatim quote of the information extracted in field esrs_e4_impact_extension_status_ecosystems_soil_degradation, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e4_impact_extension_status_ecosystems_desertification: str = Field(...,
            description="ESRS E4 (Biodiversity and ecosystems) subtheme: impact on extension and status of ecosystems: desertification")
    esrs_e4_impact_extension_status_ecosystems_desertification_context: str = Field(...,
            description="verbatim quote of the information extracted in field esrs_e4_impact_extension_status_ecosystems_desertification, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e4_impact_extension_status_ecosystems_soil_sealing: str = Field(...,
            description="ESRS E4 (Biodiversity and ecosystems) subtheme: impact on extension and status of ecosystems: soil sealing")
    esrs_e4_impact_extension_status_ecosystems_soil_sealing_context: str = Field(...,
            description="verbatim quote of the information extracted in field esrs_e4_impact_extension_status_ecosystems_soil_sealing, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e4_impacts_dependencies_services_ecosystems: str = Field(...,
            description="ESRS E4 (Biodiversity and ecosystems) subtheme: impact and dependencies on the services of the ecosystems")
    esrs_e4_impacts_dependencies_services_ecosystems_context: str = Field(...,
            description="verbatim quote of the information extracted in field esrs_e4_impacts_dependencies_services_ecosystems, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")


    def esr_name(self):
        return "esrs_e4"


class ESRS_E5(ESRSBase):
    esrs_e5_resource_use_circular_economy_resource_input: str = Field(...,
        description = "ESRS E5 (Resource Use and Circular Economy) subtheme:  Resource input: Sustainable sourcing of materials, Resource consumption and efficiency improvements, Dependency on virgin raw materials, Use of secondary (recycled) materials, etc.")
    esrs_e5_resource_use_circular_economy_resource_input_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_e5_resource_use_circular_economy_resource_input, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e5_resource_use_circular_economy_resource_output: str = Field(...,
        description = "ESRS E5 (Resource Use and Circular Economy) subtheme: Resource output related to products and services")
    esrs_e5_resource_use_circular_economy_resource_output_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_e5_resource_use_circular_economy_resource_output, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_e5_resource_use_circular_economy_waste_management: str = Field(...,
        description = "ESRS E5 (Resource Use and Circular Economy) subtheme: Waste management: Waste generation (hazardous and non-hazardous), Waste prevention, reuse, and recycling strategies, Waste diversion from landfill and incineration, Industrial symbiosis and by-product valorization, etc")
    esrs_e5_resource_use_circular_economy_waste_management_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_e5_resource_use_circular_economy_waste_management, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")

    def esr_name(self):
        return "esrs_e5"

class ESRS_S1(ESRSBase):
    esrs_s1_workforce_working_conditions_safe_employment: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Workforce working conditions: Safe employment")
    esrs_s1_workforce_working_conditions_safe_employment_context: str = Field(...,
        description= "verbatim quote of the information extracted in field esrs_s1_workforce_working_conditions_safe_employment, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_workforce_working_conditions_working_time: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Workforce working conditions: Working time")
    esrs_s1_workforce_working_conditions_working_time_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_workforce_working_conditions_working_time, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_workforce_working_conditions_fair_salary: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Workforce working conditions: Fair salary")
    esrs_s1_workforce_working_conditions_fair_salary_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_workforce_working_conditions_fair_salary, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_workforce_working_conditions_social_dialogue: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Workforce working conditions: Social dialogue")
    esrs_s1_workforce_working_conditions_social_dialogue_context: str = Field(...,
        description= "verbatim quote of the information extracted in field esrs_s1_workforce_working_conditions_social_dialogue, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_workforce_working_conditions_association_freedom_works_council_information_rights: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Workforce working conditions: Association freedom, works council, information, query and participation rights of the workers")
    esrs_s1_workforce_working_conditions_association_freedom_works_council_information_rights_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_workforce_working_conditions_association_freedom_works_council_information_rights, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_workforce_working_conditions_collective_negotiation_collective_agreement: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Workforce working conditions: Collective negotiation, including ratio of workers covered by collective agreements")
    esrs_s1_workforce_working_conditions_collective_negotiation_collective_agreement_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_workforce_working_conditions_collective_negotiation_collective_agreement, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_workforce_working_conditions_reconciling_work_family_life: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Workforce working conditions: Reconciling work and family life")
    esrs_s1_workforce_working_conditions_reconciling_work_family_life_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_workforce_working_conditions_reconciling_work_family_life, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_workforce_working_conditions_health_safety: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Workforce working conditions: Health and safety")
    esrs_s1_workforce_working_conditions_health_safety_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_workforce_working_conditions_health_safety, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_equal_treatment_opportunities_gender_equality_equal_pay_work_equal_value: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Equal treatment and opportunities for all: gender equality and equal pay for work of equal value")
    esrs_s1_equal_treatment_opportunities_gender_equality_equal_pay_work_equal_value_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_equal_treatment_opportunities_gender_equality_equal_pay_work_equal_value, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_equal_treatment_opportunities_training_competence_development: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Equal treatment and opportunities for all: training and competence development")
    esrs_s1_equal_treatment_opportunities_training_competence_development_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_equal_treatment_opportunities_training_competence_development, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_equal_treatment_opportunities_employment_inclusion_people_disabilities: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Equal treatment and opportunities for all: employment and inclusion of people with disabilities")
    esrs_s1_equal_treatment_opportunities_employment_inclusion_people_disabilities_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_equal_treatment_opportunities_employment_inclusion_people_disabilities, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_equal_treatment_opportunities_measures_against_violence_harassment_workplace: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Equal treatment and opportunities for all: measures against violence and harassment in the workplace")
    esrs_s1_equal_treatment_opportunities_measures_against_violence_harassment_workplace_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_equal_treatment_opportunities_measures_against_violence_harassment_workplace, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_equal_treatment_opportunities_diversity: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Equal treatment and opportunities for all: diversity")
    esrs_s1_equal_treatment_opportunities_diversity_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_equal_treatment_opportunities_diversity, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_other_labour_rights_child_labour: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Other labour rights: child labour")
    esrs_s1_other_labour_rights_child_labour_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_other_labour_rights_child_labour, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_other_labour_rights_forced_labour: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Other labour rights: forced labour")
    esrs_s1_other_labour_rights_forced_labour_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_other_labour_rights_forced_labour, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_other_labour_rights_adequate_housing: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Other labour rights: adequate housing")
    esrs_s1_other_labour_rights_adequate_housing_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s1_other_labour_rights_adequate_housing, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s1_other_labour_rights_privacy: str = Field(...,
        description = "ESRS S1 (own workforce) subtheme: Other labour rights: privacity")
    esrs_s1_other_labour_rights_privacy_context: str = Field(...,
        description= "verbatim quote of the information extracted in field esrs_s1_other_labour_rights_privacity, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")

    def esr_name(self):
        return "esrs_s1"


class ESRS_S2(ESRSBase):
    esrs_s2_workers_value_chain_working_conditions_safe_employment: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Value chain workers working conditions: Safe employment")
    esrs_s2_workers_value_chain_working_conditions_safe_employment_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_workers_value_chain_working_conditions_safe_employment, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_workers_value_chain_working_conditions_working_time: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Value chain workers working conditions: Working time")
    esrs_s2_workers_value_chain_working_conditions_working_time_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_workers_value_chain_working_conditions_working_time, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_workers_value_chain_working_conditions_fair_salary: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Value chain workers working conditions: Fair salary")
    esrs_s2_workers_value_chain_working_conditions_fair_salary_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_workers_value_chain_working_conditions_fair_salary, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_workers_value_chain_working_conditions_social_dialogue: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Value chain workers working conditions: Social dialogue")
    esrs_s2_workers_value_chain_working_conditions_social_dialogue_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_workers_value_chain_working_conditions_social_dialogue, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_workers_value_chain_working_conditions_association_freedom_works_council_information_rights: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Value chain workers working conditions: Association freedom, including works council")
    esrs_s2_workers_value_chain_working_conditions_association_freedom_works_council_information_rights_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_workers_value_chain_working_conditions_association_freedom_works_council_information_rights, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_workers_value_chain_working_conditions_collective_negotiation_collective_agreement: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Value chain workers working conditions: Collective negotiation")
    esrs_s2_workers_value_chain_working_conditions_collective_negotiation_collective_agreement_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_workers_value_chain_working_conditions_collective_negotiation_collective_agreement, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_workers_value_chain_working_conditions_reconciling_work_family_life: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Value chain workers working conditions: Reconciling work and family life")
    esrs_s2_workers_value_chain_working_conditions_reconciling_work_family_life_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_workers_value_chain_working_conditions_reconciling_work_family_life, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_workers_value_chain_working_conditions_health_safety: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Value chain workers working conditions: Health and safety")
    esrs_s2_workers_value_chain_working_conditions_health_safety_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_workers_value_chain_working_conditions_health_safety, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_equal_treatment_opportunities_gender_equality_equal_pay_work_equal_value: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Equal treatment and opportunities for all: gender equality and equal pay for work of equal value")
    esrs_s2_equal_treatment_opportunities_gender_equality_equal_pay_work_equal_value_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_equal_treatment_opportunities_gender_equality_equal_pay_work_equal_value, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_equal_treatment_opportunities_training_competence_development: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Equal treatment and opportunities for all: training and competence development")
    esrs_s2_equal_treatment_opportunities_training_competence_development_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_equal_treatment_opportunities_training_competence_development, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_equal_treatment_opportunities_employment_inclusion_people_disabilities: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Equal treatment and opportunities for all: employment and inclusion of people with disabilities")
    esrs_s2_equal_treatment_opportunities_employment_inclusion_people_disabilities_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_equal_treatment_opportunities_employment_inclusion_people_disabilities, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_equal_treatment_opportunities_measures_against_violence_harassment_workplace: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Equal treatment and opportunities for all: measures against violence and harassment in the workplace")
    esrs_s2_equal_treatment_opportunities_measures_against_violence_harassment_workplace_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_equal_treatment_opportunities_measures_against_violence_harassment_workplace_context, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_equal_treatment_opportunities_diversity: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Equal treatment and opportunities for all: diversity")
    esrs_s2_equal_treatment_opportunities_diversity_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_equal_treatment_opportunities_diversity, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_other_labour_rights_child_labour: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Other labour rights: child labour")
    esrs_s2_other_labour_rights_child_labour_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_other_labour_rights_child_labour, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_other_labour_rights_forced_labour: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Other labour rights: forced labour")
    esrs_s2_other_labour_rights_forced_labour_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_other_labour_rights_forced_labour, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_other_labour_rights_adequate_housing: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Other labour rights: adequate housing")
    esrs_s2_other_labour_rights_adequate_housing_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_other_labour_rights_adequate_housing, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_other_labour_rights_water_sanitation: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Other labour rights: Water and sanitation")
    esrs_s2_other_labour_rights_water_sanitation_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_other_labour_rights_water_sanitation, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s2_other_labour_rights_privacy: str = Field(...,
        description = "ESRS S2 (Workers in the value chain) subtheme: Other labour rights: privacy")
    esrs_s2_other_labour_rights_privacy_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s2_other_labour_rights_privacy, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")

    def esr_name(self):
        return "esrs_s2"


class ESRS_S3(ESRSBase):
    esrs_s3_affected_groups_adequate_housing: str = Field(...,
        description = "ESRS S3 (Affected groups) subtheme: Economic, social and cultural rights of the collectives: Adequate housing")
    esrs_s3_affected_groups_adequate_housing_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s3_affected_groups_adequate_housing, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s3_affected_groups_adequate_food: str = Field(...,
        description = "ESRS S3 (Affected groups) subtheme: Economic, social and cultural rights of the collectives: Adequate food")
    esrs_s3_affected_groups_adequate_food_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s3_affected_groups_adequate_food, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s3_affected_groups_water_sanitation: str = Field(...,
        description = "ESRS S3 (Affected groups) subtheme: Economic, social and cultural rights of the collectives: Water and sanitation")
    esrs_s3_affected_groups_water_sanitation_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s3_affected_groups_water_sanitation, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s3_affected_groups_land_incidents: str = Field(...,
        description = "ESRS S3 (Affected groups) subtheme: Economic, social and cultural rights of the collectives: Land-related incidents")
    esrs_s3_affected_groups_land_incidents_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s3_affected_groups_land_incidents, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s3_affected_groups_security_incidents: str = Field(...,
        description = "ESRS S3 (Affected groups) subtheme: Economic, social and cultural rights of the collectives: Security-related incidents")
    esrs_s3_affected_groups_security_incidents_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s3_affected_groups_security_incidents, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s3_affected_groups_expression_freedom: str = Field(...,
        description = "ESRS S3 (Affected groups) subtheme: Collective civil and political rights: Freedom of expression")
    esrs_s3_affected_groups_expression_freedom_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s3_affected_groups_expression_freedom, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s3_affected_groups_assembly_freedom: str = Field(...,
        description = "ESRS S3 (Affected groups) subtheme: Collective civil and political rights: Freedom of assembly")
    esrs_s3_affected_groups_assembly_freedom_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s3_affected_groups_assembly_freedom, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s3_affected_groups_indicence_human_rights: str = Field(...,
        description = "ESRS S3 (Affected groups) subtheme: Collective civil and political rights: Incidences on human rights defenders")
    esrs_s3_affected_groups_indicence_human_rights_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s3_affected_groups_indicence_human_rights, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s3_affected_groups_consent: str = Field(...,
        description = "ESRS S3 (Affected groups) subtheme: Indigenous peoples' rights: Free, prior and informed consent")
    esrs_s3_affected_groups_consent_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s3_affected_groups_consent, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s3_affected_groups_self_determination: str = Field(...,
        description = "ESRS S3 (Affected groups) subtheme: Indigenous peoples' rights: Self-determination")
    esrs_s3_affected_groups_self_determination_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s3_affected_groups_self_determination, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s3_affected_groups_cultural_rights: str = Field(...,
        description = "ESRS S3 (Affected groups) subtheme: Indigenous peoples' rights: Cultural rights")
    esrs_s3_affected_groups_cultural_rights_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s3_affected_groups_cultural_rights, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")


    def esr_name(self):
        return "esrs_s3"


class ESRS_S4(ESRSBase):
    esrs_s4_consumers_incidents_privacy: str = Field(...,
        description = "ESRS S4 (Consumers and end-users) subtheme: Incidents related to consumer or end-user information: Privacity")
    esrs_s4_consumers_incidents_privacy_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s4_consumers_incidents_privacy, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s4_consumers_incidents_expression_freedom: str = Field(...,
        description = "ESRS S4 (Consumers and end-users) subtheme: Incidents related to consumer or end-user information: Expression freedom")
    esrs_s4_consumers_incidents_expression_freedom_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s4_consumers_incidents_expression_freedom, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s4_consumers_incidents_information_access: str = Field(...,
        description = "ESRS S4 (Consumers and end-users) subtheme: Incidents related to consumer or end-user information: Access to (quality) information")
    esrs_s4_consumers_incidents_information_access_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s4_consumers_incidents_information_access, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s4_consumers_safety_health: str = Field(...,
        description = "ESRS S4 (Consumers and end-users) subtheme: Personal safety of consumers or end-users: Health and safety")
    esrs_s4_consumers_safety_health_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s4_consumers_safety_health, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s4_consumers_safety_security: str = Field(...,
        description = "ESRS S4 (Consumers and end-users) subtheme: Personal safety of consumers or end-users: Security of people")
    esrs_s4_consumers_safety_security_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s4_consumers_safety_security, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s4_consumers_safety_child_protection: str = Field(...,
        description = "ESRS S4 (Consumers and end-users) subtheme: Personal safety of consumers or end-users: Child protection")
    esrs_s4_consumers_safety_child_protection_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s4_consumers_safety_child_protection, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s4_consumers_inclusion_non_discrimination: str = Field(...,
        description = "ESRS S4 (Consumers and end-users) subtheme: Social inclusion of consumers or end-users: Non-discrimination")
    esrs_s4_consumers_inclusion_non_discrimination_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s4_consumers_inclusion_non_discrimination, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s4_consumers_inclusion_access_products: str = Field(...,
        description = "ESRS S4 (Consumers and end-users) subtheme: Social inclusion of consumers or end-users: Access to products and services")
    esrs_s4_consumers_inclusion_access_products_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s4_consumers_inclusion_access_products, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_s4_consumers_inclusion_responsible_marketing: str = Field(...,
        description = "ESRS S4 (Consumers and end-users) subtheme: Social inclusion of consumers or end-users: Responsible marketing practices")
    esrs_s4_consumers_inclusion_responsible_marketing_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_s4_consumers_inclusion_responsible_marketing, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")

    def esr_name(self):
        return "esrs_s4"


class ESRS_G1(ESRSBase):
    esrs_g1_corporate_behaviour_culture : str = Field(...,
        description = "ESRS G1 (Corporate behaviour) subtheme: Corporate culture")
    esrs_g1_corporate_behaviour_culture_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_g1_corporate_behaviour_culture, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_g1_corporate_behaviour_whistleblower_protection: str = Field(...,
        description = "ESRS G1 (Corporate behaviour) subtheme: Whistleblower protection")
    esrs_g1_corporate_behaviour_whistleblower_protection_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_g1_corporate_behaviour_whistleblower_protection, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_g1_corporate_behaviour_animal_welfare: str = Field(...,
        description = "ESRS G1 (Corporate behaviour) subtheme: Animal welfare")
    esrs_g1_corporate_behaviour_animal_welfare_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_g1_corporate_behaviour_animal_welfare, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_g1_corporate_behaviour_political_engagement: str = Field(...,
        description = "ESRS G1 (Corporate behaviour) subtheme: Political engagement and lobbying activities")
    esrs_g1_corporate_behaviour_political_engagement_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_g1_corporate_behaviour_political_engagement, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_g1_corporate_behaviour_supplier_relationship: str = Field(...,
        description = "ESRS G1 (Corporate behaviour) subtheme: Supplier relationship management, including payment practices")
    esrs_g1_corporate_behaviour_supplier_relationship_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_g1_corporate_behaviour_supplier_relationship, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_g1_corporate_behaviour_corruption_prevention: str = Field(...,
        description = "ESRS G1 (Corporate behaviour) subtheme: Corruption and bribery: Prevention and detection, including training")
    esrs_g1_corporate_behaviour_corruption_prevention_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_g1_corporate_behaviour_corruption_prevention, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")
    esrs_g1_corporate_behaviour_corruption_incidents: str = Field(...,
        description = "ESRS G1 (Corporate behaviour) subtheme: Corruption and bribery: incidents, cases")
    esrs_g1_corporate_behaviour_corruption_incidents_context: str = Field(...,
        description = "verbatim quote of the information extracted in field esrs_g1_corporate_behaviour_corruption_incidents, indicating page number (page X). Truncate if the paragraph is too long, but include at least two or three sentences.")

    def esr_name(self):
        return "esrs_g1"

#-------------------------------------------------------------------------------------------------------------
# model to put everything together
class ESRSFeatures(BaseModel):
    esr1: ESRS_E1

    def to_csv_row(self) -> str:
        return self.esr1.to_csv_row()

    def to_header_row(self) -> str:
        return self.esr1.to_header_row()
