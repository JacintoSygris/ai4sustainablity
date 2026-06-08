from abc import ABC, abstractmethod

from features.feature_description import CompanyFeatures, ESRS_E1, ESRS_E2, ESRS_E3, ESRS_E4, ESRS_E5, ESRS_S1, ESRS_S2, \
    ESRS_S3, ESRS_S4, ESRS_G1


class Task(ABC):
    base_prompt = """
    You are an AI assistant specializing in sustainability and ESRS (European Sustainability Reporting Standards)
    reporting, with a specific focus on double materiality assessment.
    """

    base_instructions = """
    IMPORTANT NOTES
        - If a value is not found, return ONLY 'None', just the word 'None'
        - Do not infer or generate assumed data — extract only explicitly stated facts.
        - In fields named as *_context (if any), always provide the page number where the information appears (e.g., (page 14)),
        and if the value is in a table or footnote, extract the nearest referenced narrative text along with its page number.
        - When multiple values exist, extract the most recent one.
        - Responses must be in English
        - Respond ONLY in the JSON format described below
        - Avoid line breaks ('\n') in your responses when possible

    REPORT:
    """

    def prompt(self):
        return self.base_prompt

    @abstractmethod
    def data_format(self):
        pass

    @abstractmethod
    def task_name(self):
        pass

    @abstractmethod
    def description(self):
        pass

class ExtractCompanyDataTask(Task):
    def prompt(self):
        return super().prompt() + """
        You are a top-performing expert in extracting structured company-level data from corporate documents.
        Your role is to extract relevant company information from annual and sustainability reports, strictly limited
        to what is required for double materiality assessments under ESRS guidelines.

        Given the report below, extract the following company information in a structured format:
        - company_name: The full legal name of the company.
        - sector: Industry sector(s) the company operates in. Use high-level terms like 'Technology', 'Utilities', 'Healthcare'.
        - headquarters_country: Country where the company’s headquarters is located.
        - subsidiaries_countries: List of countries where the company has significant subsidiaries or operational presence. DO NOT REPEAT COUNTRIES IN THIS LIST.
        - employees_total: Most recent total number of employees reported.
        - employees_context: Verbatim paragraph from the document where the number of employees is mentioned. Include the page number if available. If the paragraph is too long, truncate.
        - annual_turnover: The most recent annual turnover figure. Express in million euros if possible. If in another currency, include the amount and currency.
        - annual_turnover_context: Exact excerpt from the document where turnover is mentioned. Include the page number if available. If the paragraph is too long, truncate.
        - stock_listed: true or false — is the company publicly traded?
        - reporting_currency: Currency used in financial figures throughout the report.
        - ownership_structure: Short description of the company's ownership model, e.g., 'privately held', 'state-owned', 'public company'.

        INSTRUCTIONS:
        - If a value is not found, return null.
        - Do not infer or generate assumed data — only extract explicitly stated facts.
        - Please keep the JSON under 6,000 tokens. Truncate long paragraphs of *_context fields if needed to achieve this.
        - For all *_context fields, quote the source text verbatim and always include the page number where the information appears (e.g., (page 14)).
        - For all the other fields always include the page number where the information appears (e.g., (page 14)).
        - If the value is in a table or footnote, extract the nearest narrative text that references the table, along with its page number.
        - When multiple values exist, extract the most recent one.
        - Use sector terms consistent with sustainability taxonomies (e.g., NACE, GICS).
        """

    def data_format(self):
        return CompanyFeatures

    def task_name(self):
        return 'company_data'

    def description(self):
        return 'Company data:'


class ExtractESRSE1Task(Task):
    def prompt(self):
        return super().prompt() + """
        You are an assistant specialized in extracting sustainability-related data from corporate reports in line with the
        European Sustainability Reporting Standards (ESRS) under the Corporate Sustainability Reporting Directive (CSRD).
        Your task is to analyze the provided corporate reports and extract only the information that is explicitly reported
        by the company in relation to ESRS E1 – Climate Change.

        OBJECTIVE

        Determine whether the company discloses any information aligned with ESRS E1, and if so, extract the reported
        data related to the following three key thematic areas.

        DISCLOSURE AREAS

        For each of the following topics, extract reported data if available. If nothing is reported, return "None".

        1. Climate Change Adaptation
        Include data such as:
        - Physical risk assessments (chronic and acute risks)
        - Adaptation strategies and actions
        - Climate resilience of assets, operations, and value chain
        - Investments in adaptation measures
        2. Climate Change Mitigation
        Include:
        - GHG reduction targets
        - Policies and actions for GHG reduction
        - Decarbonization initiatives and levers
        - Use of renewable energy and energy efficiency measures
        - Scope 1, 2, and 3 GHG emissions (with breakdowns and methodologies)
        - Use of internal carbon pricing (if any)
        3. Energy Use
        Include:
        - Total energy consumption (direct and indirect)
        - Share of renewable vs. non-renewable energy
        - Energy efficiency initiatives
        - Energy intensity ratios (e.g., per product, per revenue)

        """+self.base_instructions

    def data_format(self):
        return ESRS_E1

    def task_name(self):
        return 'esrse1'

    def description(self):
        return 'Reported ESRS E1 (climate change)'


class ExtractESRSE2Task(Task):
    def prompt(self):
        return super().prompt() + """
        You are a data extraction assistant for sustainability reporting, specialized in interpreting corporate reports
        in accordance with the European Sustainability Reporting Standards (ESRS) and the Corporate Sustainability Reporting Directive (CSRD).
        Your task is to review the provided sustainability reports and extract only the explicitly disclosed information related to ESRS E2 – Pollution.

        OBJECTIVE
        Determine whether the company reports under ESRS E2. If yes, extract the most recent values for each of the following
        pollution-related disclosure areas. If no information is found, return null.

        DISCLOSURE AREAS – ESRS E2 (Pollution)

        1. Air pollution
        - Nitrogen oxides (NOₓ), sulfur oxides (SOₓ)
        - Particulate matter (PM₂.₅, PM₁₀), Volatile organic compounds (VOCs)
        - Heavy metals (e.g., lead, mercury), Ozone-depleting substances (CFCs, HCFCs).
        2. Soil pollution
        - Heavy metals, pesticides
        - Hazardous waste disposal
        - Land contamination from industrial activities.
        3. Water pollution
        - Discharge of hazardous substances into water bodies,
        - Chemical oxygen demand (COD), biological oxygen demand (BOD)
        - Oil spills and microplastic contamination
        4. Susbtances of concern
        - Persistent organic pollutants (POPs), Long-lasting chemicals
        - Carcinogenic, mutagenic, or toxic to reproduction (CMRs)
        - Endocrine disruptors, Nanomaterials
        5. Substances of very high concern
        - PFAS and other long-lasting industry chemicals
        - Acidifying substances (SOₓ, NOₓ, ammonia),
        - Eutrophication agents (phosphates, nitrates),
        - Ozone-depleting substances, heavy metals
        6. Impact on ecosystems and Human Health
        - Health effects (e.g., toxicity, respiratory issues)
        - Ecosystem effects (e.g., biodiversity loss, deforestation)
        - Community exposure risks
        7. Microplastics

        """+self.base_instructions

    def data_format(self):
        return ESRS_E2

    def task_name(self):
        return 'esrse2'

    def description(self):
        return 'Reported ESRS E2 (Pollution)'

class ExtractESRSE3Task(Task):
    def prompt(self):
        return super().prompt() + """
        You are a data extraction assistant specialized in sustainability reporting under the European Sustainability
        Reporting Standards (ESRS) and the Corporate Sustainability Reporting Directive (CSRD).
        Your task is to analyze the provided sustainability reports and extract information explicitly disclosed under
        ESRS E3 – Water and Marine Resources.

        OBJECTIVE

        Determine whether the company reports under ESRS E3. If so, extract the most recent values for each disclosure
        category below. If no data is found, return null.

        DISCLOSURE AREAS – ESRS E3 (Water and Marine Resources)
        1. Water Withdrawal
        - Amount of water withdrawn from various sources (e.g., rivers, lakes, groundwater, oceans)
        2. Water Consumption
        - Amount of water used and not returned to the environment (e.g., through evaporation or product absorption)
        3. Water Discharge
        - Amount of water discharged back into the environment (excluding oceans)
        4. Water Discharge into Oceans
        - Amount of water discharged specifically into seas or oceans
        5. Extraction and Use of Marine Resources
        - Impacts on rivers, lakes, oceans, marine biodiversity, ecosystems
        6. Other Issues Related to ESRS E3
        - Any other disclosed data or qualitative information explicitly related to ESRS E3 that does not fit in the categories above

        """+self.base_instructions

    def data_format(self):
        return ESRS_E3

    def task_name(self):
        return 'esrse3'

    def description(self):
        return 'Reported ESRS E3 (Water and marine resources)'


class ExtractESRSE4Task(Task):
    def prompt(self):
        return super().prompt() + """
        You are a data extraction assistant specialized in sustainability reporting in alignment with the European
        Sustainability Reporting Standards (ESRS) and the Corporate Sustainability Reporting Directive (CSRD).
        Your task is to analyze the provided sustainability report and extract all explicitly disclosed information related
        to ESRS E4 – Biodiversity and Ecosystems.

        OBJECTIVE
        Determine whether the company reports under ESRS E4. If yes, extract the most recent data for each disclosure
        subtheme listed below. If no data is found, return null.

        DISCLOSURE AREAS – ESRS E4 (Biodiversity and Ecosystems)

        1. Direct Impacts on Biodiversity Loss
        - Climate change
        - Change in land use, freshwater use, and sea use
        - Direct exploitation of natural resources
        - Introduction or spread of exotic/invasive species
        - Pollution
        - Other factors
        2. Impact on the Status of Species
        - Changes in population size of species
        - Extinction risk of species
        3. Impact on the Extension and Status of Ecosystems
        - Soil degradation
        - Desertification
        - Soil sealing
        4. Impacts and Dependencies on Ecosystem Services
        - Any dependencies or effects on services provided by ecosystems (e.g., pollination, flood regulation, carbon sequestration)

        """+self.base_instructions

    def data_format(self):
        return ESRS_E4

    def task_name(self):
        return 'esrse4'

    def description(self):
        return 'Reported ESRS E4 (Biodiversity and ecosystems)'


class ExtractESRSE5Task(Task):
    def prompt(self):
        return super().prompt() + """
        You are a data extraction assistant for sustainability reporting in line with the European Sustainability
        Reporting Standards (ESRS) and the Corporate Sustainability Reporting Directive (CSRD).
        Your task is to analyze the provided sustainability reports and extract information explicitly disclosed under
        ESRS E5 – Resource Use and Circular Economy.

        OBJECTIVE
        Determine whether the company reports under ESRS E5. If so, extract the most recent data for each disclosure
        subtheme below. If no data is found for a subtheme, return null.

        DISCLOSURE AREAS – ESRS E5 (Resource Use and Circular Economy)
        1. Resource Input
        - Sustainable sourcing of materials
        - Resource consumption and efficiency improvements
        - Dependency on virgin raw materials
        - Use of secondary (recycled) materials
        2. Resource Output Related to Products and Services
        - Information on resource use embedded in outputs such as goods or services
        3. Waste Management
        - Waste generation (hazardous and non-hazardous)
        - Waste prevention, reuse, and recycling strategies
        - Waste diversion from landfill and incineration
        - Industrial symbiosis and by-product valorization

        """+self.base_instructions

    def data_format(self):
        return ESRS_E5

    def task_name(self):
        return 'esrse5'

    def description(self):
        return 'Reported ESRS E5 (Circular economy)'

class ExtractESRSS1Task(Task):
    def prompt(self):
        return super().prompt() + """
        You are a data extraction assistant for sustainability reporting based on the European Sustainability Reporting
        Standards (ESRS) and the Corporate Sustainability Reporting Directive (CSRD).
        Your role is to analyze the provided sustainability report and extract only the information explicitly disclosed
        under ESRS S1 – Own Workforce.

        OBJECTIVE
        Determine whether the company reports under ESRS S1. If so, extract the most recent data related to each of the
        disclosure subthemes listed below. If no data is found for a subtheme, return null.

        DISCLOSURE AREAS – ESRS S1 (Own Workforce)
        1. Workforce Working Conditions
        - Safe employment
        - Working time
        - Fair salary
        - Social dialogue
        - Freedom of association, works councils, and participation rights
        - Collective bargaining (including % covered by agreements)
        - Reconciling work and family life
        - Health and safety
        2. Equal Treatment and Opportunities for All
        - Gender equality and equal pay for work of equal value
        - Training and competence development
        - Employment and inclusion of people with disabilities
        - Measures against violence and harassment in the workplace
        - Workforce diversity
        3. Other Labour Rights
        - Child labour
        - Forced labour
        - Adequate housing
        - Privacy

        """+self.base_instructions

    def data_format(self):
        return ESRS_S1

    def task_name(self):
        return 'esrss1'

    def description(self):
        return 'Reported ESRS S1 (owned workforce)'


class ExtractESRSS2Task(Task):
    def prompt(self):
        return super().prompt() + """
        You are a data extraction assistant for sustainability reporting under the European Sustainability Reporting
        Standards (ESRS) and the Corporate Sustainability Reporting Directive (CSRD).
        Your task is to review the provided sustainability report and extract only the information that is explicitly
        reported under ESRS S2 – Workers in the Value Chain.

        OBJECTIVE
        Identify whether the company reports on ESRS S2. If so, extract the most recent and explicitly stated information
        for each disclosure area listed below. If no data is reported for a specific subtheme, return null.

        DISCLOSURE AREAS – ESRS S2 (Workers in the Value Chain)
        1. Value Chain Workers' Working Conditions
        - Safe employment
        - Working time
        - Fair salary
        - Social dialogue
        - Freedom of association, works councils, and participation rights
        - Collective bargaining
        - Work–life balance (reconciling work and family life)
        - Health and safety
        2. Equal Treatment and Opportunities for All
        - Gender equality and equal pay for work of equal value
        - Training and competence development
        - Employment and inclusion of people with disabilities
        - Measures against violence and harassment in the workplace
        - Diversity
        3. Other Labour Rights
        - Child labour
        - Forced labour
        - Adequate housing
        - Water and sanitation
        - Privacy

        """+self.base_instructions

    def data_format(self):
        return ESRS_S2

    def task_name(self):
        return 'esrss2'

    def description(self):
        return 'Reported ESRS S2 (workers in the value chain)'


class ExtractESRSS3Task(Task):
    def prompt(self):
        return super().prompt() + """
        You are a data extraction assistant for sustainability reporting under the European Sustainability Reporting
        Standards (ESRS) and the Corporate Sustainability Reporting Directive (CSRD).
        Your task is to analyze the provided sustainability report and extract all explicitly disclosed information
        related to ESRS S3 – Affected Communities.

        OBJECTIVE

        Determine whether the company reports on ESRS S3. If so, extract the most recent information available for each
        of the subthemes listed below. If no data is found for a given subtheme, return null.

        DISCLOSURE AREAS – ESRS S3 (Affected Communities)
        1. Economic, Social and Cultural Rights of Affected Groups
        - Adequate housing
        - Adequate food
        - Water and sanitation
        - Land-related incidents (e.g., displacement, land access or ownership conflict)
        - Security-related incidents (e.g., threats, violence, presence of security forces)
        2. Collective Civil and Political Rights
        - Freedom of expression
        - Freedom of assembly
        - Incidents involving human rights defenders (e.g., retaliation, threats)
        3. Rights of Indigenous Peoples
        - Free, prior, and informed consent
        - Right to self-determination
        - Cultural rights (e.g., access to sacred sites, protection of cultural heritage)

        """+self.base_instructions

    def data_format(self):
        return ESRS_S3

    def task_name(self):
        return 'esrss3'

    def description(self):
        return 'Reported ESRS S3 (affected groups)'


class ExtractESRSS4Task(Task):
    def prompt(self):
        return super().prompt() + """
        You are a data extraction assistant for sustainability reporting under the European Sustainability Reporting
        Standards (ESRS) and the Corporate Sustainability Reporting Directive (CSRD).
        Your task is to analyze the provided sustainability report and extract all explicitly disclosed information
        related to ESRS S4 – Consumers and End-Users.

        OBJECTIVE
        Determine whether the company reports under ESRS S4. If so, extract the most recent information for each of the
        subthemes listed below. If no data is found for a subtheme, return null.
        Respon

        DISCLOSURE AREAS – ESRS S4 (Consumers and End-Users)
        1. Incidents Related to Consumer or End-User Information
        - Privacy (e.g., breaches of personal data)
        - Freedom of expression (e.g., suppression or interference)
        - Access to quality information (e.g., transparency, accuracy)
        2. Personal Safety of Consumers or End-Users
        - Health and safety impacts of products and services
        - Security of individuals (e.g., product-related physical threats)
        - Child protection (e.g., age-appropriate services, harmful content)
        3. Social Inclusion of Consumers or End-Users
        - Non-discrimination (e.g., based on gender, ethnicity, disability)
        - Access to essential products and services (e.g., affordability, availability)
        - Responsible marketing practices (e.g., advertising ethics, deceptive claims)

        """+self.base_instructions

    def data_format(self):
        return ESRS_S4

    def task_name(self):
        return 'esrss4'

    def description(self):
        return 'Reported ESRS S4 (Consumers and end-users)'


class ExtractESRSG1Task(Task):
    def prompt(self):
        return super().prompt() + """
        You are a data extraction assistant for sustainability reporting in accordance with the European Sustainability
        Reporting Standards (ESRS) and the Corporate Sustainability Reporting Directive (CSRD).
        Your task is to analyze the provided sustainability report and extract all explicitly disclosed information
        related to ESRS G1 – Business Conduct (corporate behaviour).

        OBJECTIVE
        Determine whether the company reports under ESRS G1. If so, extract the most recent information for each of the
        subthemes listed below. If no data is found for a specific subtheme, return null.

        DISCLOSURE AREAS – ESRS G1 (Business Conduct)
        1. Governance of Corporate Conduct
        - Corporate culture (values, tone at the top, behavioral expectations)
        - Whistleblower protection systems
        2. Ethical Practices and Social Responsibility
        - Animal welfare (including policies and practices)
        3. Political Engagement and External Influence
        - Political engagement and lobbying activities (e.g., advocacy, political contributions)
        4. Supply Chain Conduct
        - Supplier relationship management, including payment terms and performance expectations
        5. Anti-Corruption and Integrity
        - Corruption and bribery: prevention and detection (e.g., policies, training)
        - Corruption and bribery: incidents, legal cases or confirmed breaches

        """+self.base_instructions

    def data_format(self):
        return ESRS_G1

    def task_name(self):
        return 'esrsg1'

    def description(self):
        return 'Reported ESRS G1 (corporate behaviour)'
