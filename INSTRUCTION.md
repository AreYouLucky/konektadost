# Project Definition and System Implementation Instructions: konektaDOST

## 1. Project Overview

Develop and enhance **konektaDOST**, a geospatial intelligence and decision-support platform designed to help the Department of Science and Technology (DOST) identify local development needs, assess geographic conditions, and recommend appropriate Science, Technology, and Innovation (STI) interventions.

The platform uses geographic information, demographic statistics, socioeconomic indicators, infrastructure data, environmental information, and the existing DOST technology inventory to understand the development situation of a selected municipality or locality.

The ultimate purpose is to connect identified local needs with existing DOST technologies, programs, resources, and potential interventions.

konektaDOST should function as a decision-support system, not merely a geographic visualization application or a chatbot.

Its outputs must be evidence-based, geographically contextualized, measurable, explainable, and useful for development planning.

## 2. Main System Objectives

The system must be capable of:

1. Assessing the demographic, geographic, socioeconomic, infrastructure, and environmental conditions of a selected municipality.
2. Identifying development needs, deficiencies, risks, and opportunities using available datasets.
3. Determining which barangays or geographic areas should receive greater attention based on measurable indicators.
4. Identifying existing DOST technologies that may address the identified local development gaps.
5. Evaluating the suitability and availability of potential technologies based on local conditions and implementation requirements.
6. Generating proposed STI interventions supported by assessment findings.
7. Identifying potential resources, implementing partners, and requirements for proposed interventions.
8. Defining expected outcomes and measurable performance indicators.
9. Presenting all findings through an interactive Intelligence Map and an executive assessment dashboard.

## 3. Core System Workflow

The platform must follow this logical workflow:

### Step 1: Select a Geographic Area

Allow users to select a geographic location, initially focusing on municipalities and their corresponding barangays.

Retrieve the available geographic and demographic information associated with the selected location.

Expected information may include:

- Province and municipality information.
- Barangay information.
- Geographic boundaries and land area.
- Population and population trends.
- Population density.
- Available geographic and socioeconomic datasets.

### Step 2: Gather and Analyze Available Data

Retrieve relevant datasets associated with the selected geographic area.

Potential data domains include:

- Population and demographics.
- Health and public services.
- Education and human capital.
- Agriculture and food production.
- Local economy and livelihood.
- Infrastructure and transportation.
- Digital connectivity.
- Environment and natural hazards.
- Science, technology, and innovation resources.

The system must distinguish between available, missing, outdated, estimated, and insufficient data.

Do not assume that all datasets are available for every municipality.

### Step 3: Calculate Development Indicators

Use deterministic backend functions to calculate measurable indicators from available source data.

Examples include:

**Demographic indicators**
- Population growth rate.
- Population density.
- Population distribution by barangay.
- Population change over time.

**Health indicators**
- Health facilities per population.
- Hospital distribution.
- Geographic proximity to health facilities.
- Potentially underserved areas.

**Education indicators**
- School distribution.
- Educational facilities per population.
- Geographic accessibility.

**Agriculture and economic indicators**
- Agricultural production trends.
- Distribution of agricultural resources.
- Available processing facilities.
- Business establishment distribution.
- Employment and livelihood indicators, when available.

**Environmental and hazard indicators**
- Population within mapped hazard zones.
- Facilities exposed to identified hazards.
- Geographic distribution of environmental risks.

**Infrastructure indicators**
- Road network distribution.
- Connectivity and accessibility.
- Distribution of essential public facilities.

Only calculate indicators when the required input data is available and suitable.

Every calculated indicator should preserve its source, reference period, geographic coverage, unit of measurement, and calculation methodology.

### Step 4: Identify Development Gaps and Opportunities

Analyze the calculated indicators and available evidence to identify local development needs.

Examples:

- Limited access to healthcare facilities.
- Uneven distribution of educational facilities.
- Insufficient agricultural processing infrastructure.
- Potential exposure of communities and facilities to natural hazards.
- Limited access to digital connectivity.
- Areas with economic or livelihood development opportunities.

Each identified gap must be represented as a structured assessment finding.

An assessment finding should contain, when applicable:

- Geographic location.
- Development domain.
- Identified problem or opportunity.
- Supporting indicators.
- Observed values.
- Benchmark or comparison values.
- Severity or priority classification.
- Supporting data sources.
- Data quality and limitations.
- Explanation of how the finding was identified.

Do not generate development gaps based solely on generic assumptions or AI-generated statements.

### Step 5: Determine Geographic Priority Areas

Identify barangays or geographic locations that require attention based on the assessment findings.

The system should be able to:

- Compare indicators between barangays.
- Identify underserved or vulnerable areas.
- Calculate configurable priority scores.
- Identify populations and facilities potentially affected.
- Display priority areas through the Intelligence Map.

Priority scoring must use documented criteria, normalized indicators where appropriate, and configurable weights.

The system must explain the basis of every priority classification.

Do not interpret a high score as an objective measure without documenting its methodology.

### Step 6: Match Identified Needs with DOST Technologies

This is one of the central functions of konektaDOST.

The system must connect identified development gaps with the existing DOST technology inventory.

Use structured technology information, including:

- Technology name.
- Technology description.
- Technology applications.
- Problems addressed by the technology.
- Target industries and beneficiaries.
- Expected benefits.
- Technical requirements.
- Infrastructure requirements.
- Implementation requirements.
- Availability status (`tech_status`).

Technology matching must consider:

1. Relevance to the identified development gap.
2. Alignment with the technology's documented applications.
3. Suitability for the affected population or industry.
4. Compatibility with local geographic and infrastructure conditions.
5. Availability for adoption or deployment.
6. Required resources, skills, utilities, and technical support.

Use the following `tech_status` values:

- `available`
- `limited`
- `unavailable`
- `discontinued`
- `unknown`

Interpret these statuses correctly:

- `available`: Eligible for normal matching.
- `limited`: May be recommended with availability restrictions clearly identified.
- `unavailable`: Exclude from actionable deployment recommendations.
- `discontinued`: Exclude from current recommendations.
- `unknown`: May be considered a potential candidate but requires verification.

Do not assume that a technology is deployable merely because its description matches a development problem.

The matching engine must explain why a technology is considered suitable or unsuitable.

### Step 7: Assess Intervention Feasibility

After identifying potential technology matches, assess whether the municipality can realistically implement the proposed intervention.

Consider:

- Technology availability.
- Required infrastructure.
- Local utilities.
- Workforce and technical skills.
- Potential beneficiaries.
- Required equipment and facilities.
- Estimated investment requirements, when reliable cost information exists.
- Potential implementing agencies and institutional partners.
- Operational and maintenance requirements.

Classify feasibility based on documented evidence.

Where requirements are unknown, flag them for validation rather than assuming they are satisfied.

### Step 8: Generate Recommended Interventions

Convert validated assessment findings and technology matches into proposed STI interventions.

Each recommendation should contain:

- Intervention title.
- Identified development gap.
- Target geographic location.
- Supporting assessment findings.
- Matched DOST technology, when applicable.
- Rationale for the recommendation.
- Target beneficiaries.
- Proposed implementation activities.
- Required resources and infrastructure.
- Potential implementing partners.
- Estimated timeline, when supported.
- Expected outputs.
- Expected outcomes.
- Performance indicators.
- Assumptions and limitations.

Recommendations may involve technology adoption, technology transfer, technical assistance, capacity development, research collaboration, infrastructure support, or other appropriate STI interventions.

Do not force every development gap to have a matching DOST technology. When no suitable technology exists, identify the gap and indicate that further assessment, research, or alternative intervention is needed.

### Step 9: Define Expected Outcomes and Performance Indicators

For every proposed intervention, identify how its implementation and effectiveness can be measured.

Examples:

- Number of beneficiaries served.
- Number of trained personnel.
- Number of facilities established or improved.
- Increase in production or processing capacity.
- Reduction in post-harvest losses.
- Improvement in geographic accessibility.
- Number of technology adopters.
- Improvement in service delivery.

Distinguish between:

- Baseline values.
- Proposed targets.
- Actual implementation results.
- Estimated outcomes.

Never present a projected outcome as an already achieved result.

### Step 10: Present Results Through the Dashboard and Intelligence Map

The system must present assessment results in a clear, interactive, and executive-friendly interface.

The dashboard should include:

**A. Municipality Overview**
- Population.
- Land area.
- Population density.
- Population growth.
- Geographic profile.
- Available dataset coverage.

**B. Development Assessment**
- Identified development gaps.
- Assessment findings by domain.
- Important indicators and comparisons.
- Data quality and missing information.

**C. Geographic Priorities**
- Priority barangays.
- Underserved or vulnerable areas.
- Geographic distribution of development gaps.
- Affected populations and facilities, when measurable.

**D. DOST Technology Matching**
- Number of relevant technology candidates.
- Technology match results.
- Availability status.
- Suitability and feasibility assessment.
- Reasons for matching and limitations.

**E. Recommended Interventions**
- Prioritized proposed interventions.
- Supporting evidence.
- Target locations and beneficiaries.
- Implementation requirements.
- Potential partners.
- Expected outputs and outcomes.

**F. Performance and Monitoring**
- Baseline indicators.
- Proposed targets.
- Actual results when available.
- Progress and performance comparisons.

## 4. Intelligence Map Requirements

The existing Intelligence Map is an important part of the platform and must be preserved.

Enhance its analytical capabilities where necessary.

It should support:

- Geographic selection.
- Municipality and barangay boundaries.
- Population distribution.
- Public facility locations.
- Infrastructure layers.
- Hazard and environmental layers.
- Identified development gaps.
- Geographic priority areas.
- Recommended intervention locations.

Users should be able to select a location or map feature and inspect its relevant assessment information.

Map visualizations must be based on actual geographic datasets and computed results.

Do not display fabricated geographic information or assume that missing geographic records mean that no facilities or risks exist.

## 5. Role of Artificial Intelligence

AI should function as an analytical assistant and recommendation engine.

It should not replace deterministic statistical calculations or act as the source of factual data.

Use backend services to:

- Retrieve structured data.
- Calculate indicators.
- Identify development gaps.
- Compute priority scores.
- Search and match DOST technologies.
- Evaluate implementation requirements.

Use AI to:

- Interpret assessment findings.
- Explain development gaps in understandable language.
- Explain why technologies match identified needs.
- Identify missing information and assumptions.
- Compare intervention options.
- Generate proposed intervention plans.
- Summarize assessment results for decision-makers.

AI-generated conclusions must be grounded in retrieved data and backend computation results.

The AI must never invent population values, facility counts, technology availability, costs, implementation partners, or performance results.

If evidence is insufficient, the AI must explicitly state that the information is insufficient.

## 6. Function Tool Architecture

Organize backend functionality into reusable services or tools.

Suggested functions include:

**Municipality and Data Retrieval**
- `get_municipality_profile`
- `get_population_statistics`
- `get_local_assets`
- `get_available_datasets`

**Statistical and Geographic Analysis**
- `calculate_indicators`
- `analyze_health_access`
- `analyze_education_access`
- `analyze_agriculture`
- `analyze_hazard_exposure`
- `analyze_digital_connectivity`
- `identify_underserved_areas`

**Assessment and Prioritization**
- `detect_development_gaps`
- `get_assessment_findings`
- `calculate_priority_scores`

**DOST Technology Matching**
- `search_dost_technologies`
- `match_technology_to_needs`
- `check_technology_availability`
- `assess_implementation_feasibility`

**Intervention Planning**
- `generate_intervention_options`
- `estimate_intervention_requirements`
- `identify_implementation_partners`
- `generate_action_plan`
- `define_performance_indicators`

**Assessment Reporting**
- `generate_assessment_summary`
- `get_dashboard_results`
- `get_intervention_outcomes`

These are logical function names. Reuse existing implementations wherever possible and avoid creating duplicate services that perform the same operation.

## 7. Important Architectural Principles

1. Keep raw source data separate from calculated assessment results.
2. Keep calculated results separate from AI-generated interpretations.
3. Preserve data provenance and reference dates.
4. Use existing geographic relationships and identifiers.
5. Support municipality-level and barangay-level analysis where data permits.
6. Allow datasets to have different reference years.
7. Do not treat missing data as zero.
8. Do not treat estimated or proxy values as verified observations.
9. Make assessment formulas and priority scoring rules transparent.
10. Preserve assessment history so that results can be compared over time.
11. Preserve the existing Intelligence Map and its working integrations.
12. Avoid unnecessary changes to existing models and database relationships.
13. Make technology matching explainable and traceable.
14. Require appropriate human validation before recommendations become approved implementation plans.

## 8. Expected System Output

The final system should be capable of producing a comprehensive municipality-level development assessment with five major executive outputs:

1. **Priority Areas** – Where should attention and resources be directed?
2. **Critical Needs and Development Gaps** – What problems or opportunities have been identified, and what evidence supports them?
3. **Recommended STI Interventions** – Which DOST technologies, programs, and interventions could address these needs?
4. **Investment and Resource Priorities** – What resources, infrastructure, expertise, and partnerships are required?
5. **Expected Outcomes and Performance** – What measurable results should be achieved, and how will progress be evaluated?

The platform should help DOST and local government decision-makers understand not only what is happening in a municipality, but also where intervention is needed, which available technologies could help, why those technologies are suitable, and what results their implementation is expected to produce.

## 9. Implementation Direction

Before implementing new functionality:

1. Inspect the existing Laravel Models and their relationships.
2. Review the existing Intelligence Map implementation and its data flow.
3. Identify existing reusable services, controllers, APIs, and computations.
4. Evaluate whether the existing database structure can support the required outputs.
5. Identify only the missing structures and functionality.
6. Present the proposed implementation approach before making substantial changes.
7. Implement incrementally without breaking existing functionality.

Prioritize a complete municipality assessment workflow over implementing many disconnected dashboard widgets.

The goal is a functioning evidence-to-intervention decision-support platform, not simply a dashboard containing statistical cards and AI-generated recommendations.
