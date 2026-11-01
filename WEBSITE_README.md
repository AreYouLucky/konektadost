# KonektaDOST: Website Description

This document provides project context that can be copied into ChatGPT. It describes the website's purpose, intended users, current features, and planned direction. Implementation status reflects the source reviewed on October 26, 2026; live data availability has not been verified.

## Short description

KonektaDOST is a web-based geospatial decision-support system designed for the Philippine Department of Science and Technology (DOST). It brings together geographic boundaries, population figures, recorded community facilities, laboratory capabilities, telecommunications infrastructure, and DOST technology resources. Users explore a location on an interactive map, assess available evidence, identify possible service gaps, and review relevant science, technology, and innovation interventions to support community development planning.

## Purpose and problem being addressed

Planning a community intervention requires understanding both local needs and the resources available to address them. Population figures, facility records, geographic information, and technology catalogs often sit in separate datasets. This makes it difficult to compare locations, recognize gaps in recorded services, and connect a community's needs with an appropriate DOST resource.

KonektaDOST organizes these datasets around a selected location. Its purpose is to help planners answer:

- What does the available evidence tell us about this community?
- Which barangays may need closer attention?
- How do recorded services compare with population and national reference rates?
- What laboratories, technical services, or DOST interventions may be relevant?
- What information is still missing before a decision can be made?

## Intended users

The primary intended users are DOST decision-makers, regional and local personnel, technical staff, and project planners. The wider product concept can also support local government partners, researchers, and development stakeholders who need geographic context for planning.

The application defines three account roles:

| Role | Purpose and current access |
| --- | --- |
| User | Standard authenticated access to the map and location assessment |
| Administrator | Access to the restricted admin dashboard and standard authenticated features |
| Technician | Role intended for reference-data maintenance; its management routes are currently disabled |

The admin dashboard is a basic restricted area. A complete user-management and dataset-management interface is not currently available.

## Main user journey

1. Register or sign in.
2. Open the service area map.
3. Select a province, then a city or municipality.
4. Explore recorded facilities, laboratories, DOST technology resources, and cell sites through map layers.
5. Choose a general assessment or a specific development focus.
6. Review available indicators, population information, and barangay-level coverage priorities.
7. Examine local laboratory capabilities and potentially relevant DOST interventions.
8. Use the findings as supporting evidence for further validation and planning.

The selected province and municipality are stored in the page URL, allowing users to share a selected area and return to it later.

## Features represented in the current application

### Interactive service area map

The map lets users explore Philippine provinces and drill down into cities and municipalities. Available marker categories include laboratories, DOST technology resources, health/education/safety facilities, and telecommunications sites. Users can filter categories and inspect location details. The records shown depend on the datasets and geographic assignments available in the database.

### Location assessment

The assessment page summarizes a selected municipality using population, polygon-derived land area, and recorded services. Where data and benchmarks exist, it compares measurements with national reference rates.

The indicator catalog currently includes:

- Population density.
- Health facilities, hospitals, and pharmacies per 100,000 people.
- Schools and elementary schools per 100,000 people.
- Safety facilities per 100,000 people.
- Laboratories and distinct laboratory test/method capabilities per million people.
- Telecommunications sites per 100,000 people and the percentage of recorded sites supporting 5G.

Results distinguish an available measurement, missing data, and a measurement without a benchmark. Confidence notes explain important limitations in the underlying records.

### Barangay-level coverage priorities

The assessment includes a barangay breakdown with population, density where calculable, and recorded facility counts. A simple rule highlights barangays with no recorded facilities relevant to the selected focus, giving higher priority to those whose population is at least the local barangay average. A barangay without population data remains unmeasured.

These are preliminary screening labels. A zero in a source dataset does not establish that a barangay has no real services, and a positive count does not establish that its services are adequate.

### Laboratory capabilities

Users can review local laboratory records and available service information, such as tests, methods, and fees where recorded. This provides context about technical resources that may support local projects, testing needs, or industry assistance.

### DOST intervention suggestions

The application presents entries from a DOST technology/intervention catalog. It matches the selected focus against catalog keywords and checks deployment-cluster text for city matches to order potentially relevant resources.

This is currently a rule-based suggestion feature. It does not establish suitability, funding availability, eligibility, or expected impact without further assessment.

### Accounts and settings

The website includes registration, login, password recovery, profile settings, and role-based access to the admin dashboard. The general dashboard and public welcome page still contain starter content, so they should not be described as completed analytics or marketing pages.

## Assessment framework

The product organizes community needs into ten development dimensions:

1. Population and social needs.
2. Health and public health.
3. Education, skills, and scientific talent.
4. Science, technology, and innovation capacity.
5. Infrastructure and digital connectivity.
6. Environment, climate, and disaster risk.
7. Economy and livelihood opportunities.
8. Agriculture, fisheries, and food systems.
9. Essential resources and environmental services.
10. Institutional capacity and service delivery.

The first six currently have indicators declared in the catalog, with coverage limited to the datasets used. For example, recorded safety facilities provide only a narrow view of the environment/disaster dimension; they do not amount to a flood, climate, or hazard assessment. The remaining four are currently marked unsupported in the assessment catalog.

## Agriculture data extension

The project now includes backend models for agriculture categories, agriculture types, and annual agriculture count estimates. The supplied data structure can represent commodities, variants, production activities and methods, land area, estimated quantities, headcounts, prices, revenue, operating costs, and net amounts. It also retains location-match status, model year, source identifiers, notes, and original source data.

This is a data-model foundation. It does not yet provide an agriculture page, map layer, importer, or integrated agriculture assessment. Database installation and populated records have not been verified. Agriculture estimates must remain labeled as estimates and retain their source, year, units, and location uncertainty.

## Product vision and future outputs

The broader goal is to support five executive outputs:

| Output | Intended value | Current scope |
| --- | --- | --- |
| Priority areas | Identify locations for closer attention | Preliminary barangay coverage priorities exist |
| Critical needs and development gaps | Explain local needs using evidence | Indicator comparisons exist where data and benchmarks are available |
| Recommended interventions | Connect findings to suitable STI resources | Catalog-based suggestions exist; a complete recommendation process remains to be developed |
| Investment and resource priorities | Outline potential funding, expertise, equipment, and partnerships | A complete investment-planning module is not implemented |
| Expected outcomes and performance | Define measurable improvements and monitoring measures | A validated outcome projection and monitoring module is not implemented |

AI-assisted narrative synthesis and report export are also part of the broader direction. The current assessment uses formulas, database queries, and keyword matching; an AI service is not integrated into the active workflow.

## Evidence and interpretation principles

- Keep the source and collection/model year visible when presenting data.
- Treat unknown population and unavailable measurements as missing data rather than zero.
- Describe zero OpenStreetMap facility counts as "none recorded" and acknowledge incomplete mapping.
- Explain that national reference rates are comparisons with recorded national data, not official service standards.
- Keep modeled estimates, geographic proxies, and measured records clearly labeled.
- Do not promise interventions, budgets, eligibility, or outcomes that the available evidence does not establish.
- Recommendations remain advisory. Authorized decision-makers determine actual priorities and investments.

## Technical context

The application uses Laravel 12 and PHP for the backend, React 19 and TypeScript for the interface, Inertia.js to connect server-rendered page data with React, Tailwind CSS for styling, and Leaflet/OpenStreetMap for mapping. It uses a MySQL-oriented database with imported geographic and reference datasets. Some required tables depend on external data restoration rather than migrations alone.

## Context to include when asking ChatGPT for help

Use this document as the background for KonektaDOST. When proposing features, descriptions, designs, or implementation changes, distinguish existing functionality from planned functionality. Keep suggestions appropriate to Philippine community development and DOST planning. Identify the data needed for new assessments, label assumptions, and preserve uncertainty. Do not describe AI integration, agriculture assessment, complete administrative management, investment planning, outcome forecasting, or report export as already implemented.
