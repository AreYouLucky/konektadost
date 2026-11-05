# MASTER DEVELOPMENT INSTRUCTION
## Project: konektaDOST – Geospatial Needs Assessment and DOST Intervention Planning Platform

### 1. Project Overview

You are working on **konektaDOST**, an evidence-based geospatial needs assessment and DOST intervention planning platform.

The purpose of the system is to help assess municipalities and their barangays using available geographic, demographic, infrastructure, economic, agricultural, environmental, and science and technology data.

The system must be capable of:

1. Collecting and retrieving municipality-level and barangay-level information.
2. Calculating measurable development indicators.
3. Analyzing sectoral conditions.
4. Identifying development gaps.
5. Prioritizing barangays based on measurable needs.
6. Finding relevant DOST programs, technologies, projects, and services.
7. Matching identified needs with potential interventions.
8. Evaluating intervention suitability and implementation feasibility.
9. Generating structured intervention plans.
10. Defining expected outcomes and performance indicators.
11. Presenting consolidated assessment results for decision-makers.

The system must produce evidence-based findings. It must not invent statistics, development gaps, program availability, funding, partnerships, or project accomplishments.

---

### 2. Technology Stack

The existing application uses:

**Backend**
- Laravel 12
- Eloquent ORM
- MySQL/MariaDB-compatible database queries

**Frontend**
- React
- Inertia.js
- TypeScript
- Tailwind CSS

Follow the existing architecture, coding conventions, naming patterns, and component structure of the repository.

Do not introduce a new framework or unnecessarily replace existing components.

---

### 3. Critical Development Rules

These rules apply throughout the project.

#### 3.1 Inspect Before Implementing

Before writing or modifying code, inspect:

- Existing Eloquent models.
- Model relationships.
- Model attributes, fillable properties, casts, scopes, and methods.
- Existing database tables and columns.
- Existing services and business logic.
- Existing controllers and routes.
- Existing API resources and response structures.
- Existing frontend pages and components.
- Existing GIS and map functionality.
- Existing tests.

Use the actual models and database structure as the source of truth.

Do not assume that database migrations represent the current production schema. Existing migrations may be outdated.

Do not create duplicate models, services, tables, relationships, or components when equivalent implementations already exist.

#### 3.2 Preserve Existing Functionality

The application already has geographic data, assessment functionality, and an Intelligence Map.

Preserve and extend existing functionality.

Do not rebuild the Intelligence Map or replace existing assessment services without a clear technical reason.

Reuse existing services, particularly:
- MunicipalityProfileService
- LocationAssessor
- Existing geographic and assessment-related services

Inspect their actual implementations before extending them.

#### 3.3 No AI Integration During Layers A–F

IMPORTANT: The application currently does not have an AI endpoint.

Do not:
- Create an AI endpoint.
- Call an external LLM API.
- Add AI API keys or environment variables.
- Introduce prompt engineering.
- Add AI-related dependencies.
- Use AI to calculate indicators.
- Use AI to detect development gaps.
- Use AI to generate intervention matches.

All functionality in Layers A–F must use deterministic backend logic, database records, documented formulas, and configurable rules.

AI-assisted interpretation, explanations, suggestions, and proposal generation will be implemented ONLY in the final project phase.

Keep the architecture ready for future AI integration without making it a current dependency.

#### 3.4 Data Integrity

Always follow these principles:

- Missing data is not zero.
- Unknown does not mean unavailable.
- Historical data is not necessarily current.
- Estimated values are not verified values.
- A facility record does not prove that a facility is operational.
- A telecom tower does not prove household internet availability.
- Road geometry does not prove road condition.
- A historical DOST project does not prove current program availability.
- A proposed intervention is not an approved project.
- An estimated outcome is not an actual accomplishment.

Preserve data provenance, reference dates, sources, geographic coverage, units, and methodology.

#### 3.5 Coding Standards

- Put business logic in services.
- Keep controllers focused on request handling and response delivery.
- Use Eloquent relationships appropriately.
- Avoid N+1 queries.
- Use pagination for large result sets.
- Validate inputs.
- Use database transactions when necessary.
- Use structured JSON responses.
- Maintain TypeScript interfaces for frontend data.
- Add tests for calculations and important business rules.
- Ensure database queries are compatible with the actual database version.
- Avoid unrelated refactoring.

---

# 4. System Development Architecture

Develop the system through six independent layers.

## LAYER A – Municipality and Data Retrieval

**Objective:** Retrieve and validate all available information for a selected municipality and its barangays.

Implement the following capabilities:

### A1. get_municipality_profile

Retrieve:
- Municipality name and PSGC code.
- Province and region.
- Municipality classification.
- Income classification.
- Urban/rural classification, if available.
- Land area.
- Geographic coordinates.
- Total number of barangays.
- Urban and rural barangay counts.
- Population density, when calculable.

Reuse MunicipalityProfileService.

### A2. get_population_statistics

Retrieve:
- Municipality population.
- Barangay population.
- Historical population.
- Latest available population record.
- Absolute population growth.
- Percentage population growth.
- Annualized population growth.
- Population density.

Always include reference years and data sources.

Preserve historical population records.

### A3. get_available_datasets

Inventory datasets available for the municipality.

Inspect existing datasets, including:

- population_figures
- facilities
- laboratories
- lab_services
- telecom_sites
- agriculture_count_estimates
- dost_projects
- dost_techs
- technologies
- roads, if implemented
- other existing datasets

For each dataset, return:
- Dataset name.
- Record count.
- Number of barangays represented.
- Reference year or date.
- Source.
- Geographic coverage.
- Completeness.
- Availability status.

Use these statuses:
- available
- partial
- unavailable

Do not interpret zero records as proof that the dataset does not exist.

### A4. get_local_assets

Retrieve local assets and resources, grouped by sector and barangay.

Include:
- Health facilities.
- Education facilities.
- Laboratories and services.
- Telecom infrastructure.
- Agricultural resources.
- Roads.
- DOST projects.
- Other available institutional resources.

### A5. get_telecom_infrastructure

Use the existing telecom_sites table.

Retrieve:
- Telecom site count.
- Barangay distribution.
- Operators and tower companies.
- Recorded technologies.
- Frequency bands.
- Coordinates.
- Colocated sites.
- Source and verification dates.

Inspect the actual data formats before parsing technologies and bands.

Do not assume that stored LONGTEXT fields contain valid JSON.

### A6. get_geographic_data_availability

Inspect the availability of:
- Municipality boundaries.
- Barangay boundaries.
- Barangay coordinates.
- Facility coordinates.
- Road geometries.
- Telecom site coordinates.
- Other geographic datasets.

Do not duplicate existing geographic boundaries.

**Layer A expected result:** A municipality data profile containing available datasets, local assets, geographic coverage, and data limitations.

---

## LAYER B – Domain Indicator and Sector Analysis

**Objective:** Transform retrieved datasets into measurable indicators and sector assessments.

### B1. calculate_indicators

Create reusable indicator calculation services.

Potential indicators:
- Facilities per 100,000 population.
- Facilities per barangay.
- Road density per square kilometer.
- Telecom sites per 10,000 population.
- Agricultural production per hectare.
- Laboratories per million population.

Every indicator must contain:
- Indicator name.
- Observed value.
- Unit.
- Benchmark, if available.
- Calculated gap, if applicable.
- Reference year.
- Source.
- Confidence or data quality.

Only compare compatible indicators and benchmarks.

### B2. analyze_health_access

Calculate:
- Health facilities per 100,000 population.
- Hospital count.
- Health center count.
- Pharmacy count, if available.
- Barangays without verified facilities.
- Distance to nearest health facility.
- Population potentially affected by limited access.

### B3. analyze_education_access

Calculate:
- Schools per 100,000 population.
- Elementary school count.
- Secondary school count.
- School distribution by barangay.
- Barangays without verified schools.
- Distance to nearest school.

### B4. analyze_digital_connectivity

Calculate:
- Total telecom sites.
- Sites per 10,000 population.
- Operators and tower companies.
- Technology distribution.
- Colocation rate.
- Barangays with recorded sites.
- Distance to nearest site.

Clearly distinguish infrastructure presence from actual connectivity, coverage, and service quality.

### B5. analyze_road_accessibility

Calculate:
- Total road length.
- Road length by classification.
- Road density.
- Road length per capita.
- Paved and unpaved road lengths.
- Road condition, if available.
- Road length by barangay.
- Barangays with road access.
- Distance to important facilities and centers.
- Potentially isolated communities.

If road geometry crosses multiple barangays, calculate the intersecting length for each barangay.

Do not assign an entire road segment to only one barangay when it crosses multiple barangays.

Use actual network distances when routing data is available. Label straight-line distances as approximate.

Do not calculate geographic road length in meters directly from unprojected longitude and latitude coordinates.

Verify MariaDB spatial compatibility before using spatial functions or indexes.

### B6. analyze_agriculture

Calculate:
- Agricultural area.
- Crop production.
- Fisheries production.
- Livestock and poultry.
- Top commodities.
- Production volume.
- Revenue, cost, and net income, if available.

Respect original units and estimation methods.

### B7. analyze_sti_laboratory_capacity

Calculate:
- Laboratory count.
- Accredited laboratories per million population.
- Available services.
- Free and paid services.
- Laboratory distribution.
- Distance to nearest laboratory.

### B8. Additional sector analysis

Prepare the architecture for future domains:
- Population and social needs.
- Economy and livelihood.
- Environment and biodiversity.
- Weather and climate.
- Hazards and disaster risks.
- Governance and public services.
- Transport and logistics.
- Science, technology, and innovation.

Only implement calculations when the required data are available.

**Layer B expected result:** A structured collection of measurable indicators and sector assessments.

---

## LAYER C – Gap Detection and Barangay Prioritization

**Objective:** Identify measurable development gaps and determine barangays that require priority attention.

### C1. detect_development_gaps

Compare observed indicators against documented benchmarks.

For every finding, return:
- Gap description.
- Observed value.
- Benchmark.
- Absolute gap.
- Percentage gap, when valid.
- Severity.
- Affected population.
- Affected barangays.
- Reference year.
- Source.
- Confidence.

If a benchmark does not exist, do not invent one.

If information is missing, return insufficient information rather than declaring a development gap.

### C2. identify_underserved_areas

Identify barangays with verified development needs based on:
- Health access.
- Education access.
- Road accessibility.
- Digital connectivity.
- Agricultural resources.
- Laboratory access.
- Population.
- Distance to facilities.

Identify overlapping development needs without double-counting the same evidence.

### C3. calculate_priority_scores

Create a configurable priority scoring mechanism from 0 to 100.

Potential components:
- Population and social needs.
- Infrastructure gaps.
- Health and education access.
- Digital connectivity.
- Economic and agricultural needs.
- Geographic accessibility.

Weights must be configurable and documented.

The scoring system must:
- Normalize indicators.
- Handle missing data.
- Avoid double-counting correlated indicators.
- Return component scores.
- Explain the calculation methodology.
- Record the scoring version.

A high priority score represents greater assessed need. It does not automatically establish intervention suitability.

**Layer C expected result:** Development findings, underserved barangays, and explainable priority scores.

---

## LAYER D – DOST Catalog and Project Retrieval

**Objective:** Connect identified development needs with actual DOST programs, technologies, projects, and services.

Reuse existing models:
- DostTech
- Technology
- DostProject

Do not duplicate existing DOST data structures.

### D1. search_dost_technologies

Search technologies by:
- Sector.
- Agency.
- Keywords.
- Problem addressed.
- Application.
- Objectives.
- Beneficiaries.
- Availability, if recorded.

### D2. get_local_dost_projects

Retrieve historical and ongoing DOST projects based on:
- Municipality.
- Province.
- Agency.
- Program.
- Sector.
- Project title.
- Project dates.
- Status.
- Budget, if recorded.
- Accomplishments, if available.

Distinguish historical projects from currently available programs.

Do not assume historical project participation guarantees future funding or eligibility.

**Layer D expected result:** A searchable collection of DOST programs, technologies, services, and historical projects.

---

## LAYER E – Intervention Matching and Feasibility

**Objective:** Match identified development gaps with potential DOST interventions and assess their suitability.

IMPORTANT: This layer must use deterministic rule-based logic only. No AI integration.

### E1. match_dost_interventions

Match development gaps against:
- Sector alignment.
- Program objectives.
- Technology applications.
- Intended beneficiaries.
- Geographic suitability.
- Local assets.
- Previous projects.
- Program availability.

Return:
- Identified development gap.
- Matched program or technology ID.
- Relevance score.
- Matching criteria.
- Evidence.
- Intended beneficiaries.
- Availability verification status.

One gap may have multiple possible matches.

Do not force matches when no suitable intervention exists.

### E2. assess_intervention_suitability

Evaluate:
- Relevance to identified needs.
- Target beneficiaries.
- Local resources.
- Infrastructure.
- Geographic suitability.
- Program requirements.
- Existing capabilities.

### E3. assess_implementation_feasibility

Evaluate:
- Infrastructure.
- Utilities.
- Personnel.
- Skills.
- Funding.
- Maintenance capacity.
- Institutional partners.
- Eligibility.
- Geographic accessibility.
- Program availability.

Return one of:
- feasible
- conditionally_feasible
- not_yet_feasible
- insufficient_information

Unknown essential requirements must prevent the system from declaring an intervention feasible.

### E4. identify_implementation_partners

Identify potential partners using verified records.

Potential partners:
- DOST agencies.
- LGUs.
- State universities and colleges.
- Research institutions.
- Private organizations.
- Cooperatives.
- Enterprises.

Do not invent partnerships or commitments.

### E5. estimate_intervention_requirements

Identify:
- Equipment.
- Facilities.
- Personnel.
- Expertise.
- Training.
- Utilities.
- Infrastructure.
- Funding.
- Maintenance.
- Institutional partners.

Do not invent costs. Mark missing financial estimates as pending.

**Layer E expected result:** Evidence-backed intervention matches, suitability results, feasibility assessments, potential partners, and resource requirements.

---

## LAYER F – Intervention Planning and Outcomes

**Objective:** Produce structured intervention proposals with measurable objectives, resources, and expected outcomes.

This layer must initially use deterministic backend logic and structured templates.

### F1. generate_intervention_plan

Generate a structured plan containing:
- Intervention title.
- Identified development issue.
- Supporting evidence.
- Municipality and barangays.
- Target beneficiaries.
- Proposed DOST intervention.
- Activities.
- Required resources.
- Potential partners.
- Expected outputs.
- Expected outcomes.
- Risks.
- Assumptions.
- Verification requirements.

Clearly label plans as proposals pending human validation and approval.

### F2. define_performance_indicators

Define KPIs with:
- Indicator name.
- Category.
- Baseline.
- Target.
- Unit.
- Measurement method.
- Reporting frequency.
- Data source.
- Actual accomplishment.

Possible indicators:
- Enterprises assisted.
- Technologies deployed.
- Individuals trained.
- Production capacity.
- Processing efficiency.
- Barangays covered.
- Laboratory service capacity.

### F3. estimate_intervention_outcomes

Estimate outcomes only when sufficient evidence exists.

Include:
- Baseline.
- Target.
- Estimation method.
- Assumptions.
- Confidence.
- Limitations.

Clearly distinguish estimates from actual accomplishments.

### F4. generate_assessment_summary

Generate a structured summary using actual outputs from Layers A–F.

Include:
- Municipality profile.
- Development indicators.
- Critical gaps.
- Priority barangays.
- Potential DOST interventions.
- Feasibility results.
- Resource requirements.
- Expected outcomes.

Do not use an LLM to generate the summary at this stage.

**Layer F expected result:** Structured intervention proposals, performance indicators, expected outcomes, and consolidated assessment results.

---

# 5. Database Considerations

Before creating tables, inspect the actual database.

Potential structures, only if equivalent structures do not already exist:

- assessments
- assessment_indicators
- assessment_findings
- barangay_priority_scores
- technology_matches
- intervention_recommendations
- intervention_requirements
- intervention_performance_indicators
- intervention_outcomes

Preserve historical assessments.

Store:
- Assessment date.
- Municipality.
- Reference datasets.
- Calculation methodology version.
- Scoring configuration.
- Source references.
- Assessment status.

Do not recompute historical assessments simply because the dashboard is opened.

---

# 6. Intelligence Map Integration

The existing Intelligence Map must be preserved.

Extend it to support available assessment layers, such as:

- Population.
- Health facilities.
- Education facilities.
- Roads.
- Telecom sites.
- Agriculture.
- Laboratories.
- Development gaps.
- Priority barangays.
- Historical DOST projects.
- Proposed interventions.

Do not rebuild the map from scratch.

Use existing map components and GIS functionality wherever possible.

Map layers must only display data supported by actual geographic records.

---

# 7. Executive Outputs

The completed platform must provide five primary executive outputs:

1. **Priority Areas** – Municipalities and barangays requiring development attention.
2. **Critical Needs and Development Gaps** – Measurable gaps across sectors.
3. **Recommended DOST Interventions** – Potential programs, technologies, and services matched to local needs.
4. **Investment and Resource Priorities** – Required resources, infrastructure, personnel, expertise, and funding.
5. **Expected Outcomes and Performance** – KPIs, targets, estimated outcomes, and actual accomplishments.

All outputs must be traceable to underlying data and assessment results.

---

# 8. Final AI Integration Phase

AI integration must be developed only after Layers A–F are implemented, tested, and reviewed.

Potential future capabilities:
- Natural-language interpretation of assessment results.
- Explanation of development gaps.
- AI-assisted intervention suggestions.
- Drafting intervention proposals.
- Executive summaries.
- Strategic insights.

The future AI service must consume structured and verified backend outputs.

It must not replace deterministic calculations or invent evidence.

Human review must remain part of the recommendation and approval process.

---

# 9. Implementation Workflow

Do not implement the entire architecture in one operation.

Follow this exact development sequence:

1. Layer A – Municipality and Data Retrieval.
2. Layer B – Domain Indicator and Sector Analysis.
3. Layer C – Gap Detection and Barangay Prioritization.
4. Layer D – DOST Catalog and Project Retrieval.
5. Layer E – Intervention Matching and Feasibility.
6. Layer F – Intervention Planning and Outcomes.
7. Final Phase – AI Integration.

For EACH layer, follow this workflow:

**Step 1 – Audit**
Inspect existing code, models, tables, relationships, services, routes, and frontend integration.

**Step 2 – Gap Analysis**
Identify what already exists, what needs extension, and what is genuinely missing.

**Step 3 – Implementation Plan**
Present the proposed files, methods, changes, database modifications, and expected outputs.

**Step 4 – Wait for Approval**
Do not start implementation until the proposed plan has been reviewed and approved.

**Step 5 – Implementation**
Implement only the approved scope.

**Step 6 – Testing**
Run relevant tests and verify actual database results.

**Step 7 – Completion Report**
Report:
- Files created.
- Files modified.
- Existing components reused.
- Database changes.
- Implemented functionality.
- Tests performed and results.
- Known limitations.
- Remaining tasks.

**Step 8 – Stop**
Do not automatically proceed to the next layer. Wait for explicit instructions.

---

# 10. Initial Instruction

Start with **Layer A – Municipality and Data Retrieval ONLY**.

Do not implement Layer B or any subsequent layer.

First, audit the existing application and identify which Layer A capabilities already exist.

Pay particular
