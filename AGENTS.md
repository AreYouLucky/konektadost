# KonektaDOST Agent Guide

## 1. Project Purpose (from `INSTRUCTION.md`)
**konektaDOST** is an evidence-to-intervention geospatial intelligence and decision-support platform for the Department of Science and Technology (DOST).
- It assesses the demographic, geographic, socioeconomic, infrastructure, agricultural, and STI conditions of a selected municipality and its barangays.
- It calculates deterministic indicators and compares them against **dynamically computed benchmarks** from the live domain tables (not static pre-computed benchmark rows).
- It identifies structured development gaps/opportunities, scores priority barangays, matches needs against the DOST technology/project inventory (`DostProject`, `DostTech`, `Technology`), evaluates implementation feasibility, and generates an **Action Plan** with the **5 Executive Outputs**:
  1. **Priority Areas**
  2. **Critical Needs and Development Gaps**
  3. **Recommended STI Interventions**
  4. **Investment and Resource Priorities**
  5. **Expected Outcomes and Performance**

## 2. Active Models (`app/Models/`) & Live MySQL Database (`konektadost`)
Work strictly with the **17 models** in `app/Models/` (`Benchmark`, `EvidenceSource`, and `PsgcRelease` were intentionally removed; compute reference rates dynamically from the domain tables):
1. `Province` (`provinces`, 84 rows) -> `Municipality` (`municipalities`, 1,642 rows) -> `Barangay` (`barangays`, 42,010 rows): Linked by numeric foreign keys and 10-digit `psg_code`. Carry `geometry`, `bounds`, `center_lat`, `center_lng`, and `area_km2`.
2. `PlaceDescription` (`place_descriptions`, 43,766 rows): Holds `psg_code`, `level` (`Reg`, `Prov`, `City`, `Mun`, `Bgy`), `city_class`, `income_class`, `urban_rural`, and `release_id`.
3. `PopulationFigure` (`population_figures`, 83,901 rows) & `CollectionYear` (`collection_years`, active year = `2024`): Holds `2020` (40,135 barangay rows) and `2024` (43,766 LGU + barangay rows). Use `MunicipalityProfileService` to compute 2020 -> 2024 population change and growth rates.
4. `Facility` (`facilities`, 19,659 rows): Health (`health`), education (`education`), and safety (`safety`) facilities with `facility_type`, `level`, `latitude`, `longitude`, `municipality_id`, `barangay_id`.
5. `Laboratory` (`laboratories`, 69 rows) & `LabServices` (`lab_services`, 2,846 rows): Accredited testing/calibration laboratories. Note: the live `lab_services` table columns are `service_name`, `description`, `reference`, `fee`.
6. `TelecomSite` (`telecom_sites`, 155 rows): Cell sites with `operator`, `tower_company`, `technologies` (JSON array including 5G), `bands`, `municipality_id`, `barangay_id`, `barangay_psgc`.
7. `AgricultureCategory` (3 rows) -> `AgricultureType` (8 rows) -> `AgricultureCountEstimate` (10,452 rows): Annual estimates with `commodity_name`, `area_sqm`, `estimated_quantity_year`, `estimated_revenue_php_year`, `estimated_operating_cost_php_year`, `estimated_net_php_year`.
8. `DostProject` (`dost_projects`, 1,961 rows): Real DOST projects across 10 agencies (`MIRDC`, `PNRI`, `ITDI`, `PCHRD`, `PTRI`, `ASTI`, `PCIEERD`, `FPRDI`, `NRCP`, `FNRI`) with `program`, `project_title`, `objectives`, `description`, `project_status` (note: 1,322 rows are spelled `"comleted"`), `accomplishment`, `location`, `sector`, `budget`, `start_date`, `end_date`, `funding`, `implementing`, `cooperating`, `monitoring`.
9. `DostTech` (`dost_techs`, 18 rows) & `Technology` (`technologies`, 11 rows): DOST technology and STI program catalogs.
10. `User` (`users`, 4 rows).

## 3. Implementation Status (Step-by-Step)
- **Step 1 (Completed):**
  - PHP 8.5 compatibility fixed in `config/database.php`.
  - Removed deleted model references (`PsgcRelease`, `Benchmark`) and added dynamic table-computed benchmarks in `LocationAssessor.php`.
  - Implemented `app/Support/Assessment/MunicipalityProfileService.php` (`getMunicipalityProfile` & `getPopulationStatistics`) and wired it into `LocationController.php`, `MapAssessmentReport.php`, and `resources/js/pages/location-assessment.tsx`.
- **Step 2 (Next):**
  - Fix `LabServices.php` & `MapAssessmentReport.php` to read `service_name` and `description` from `lab_services`.
  - Implement Dataset Availability Audit (`get_local_assets` / `get_available_datasets`) and expand domain indicators (Agriculture, Population Growth, Facility Proximity).
- **Step 3:** Structured Development Gaps & Barangay Priority Scoring (`PrioritizationService`).
- **Step 4:** Unified DOST Technology & Project Matching (`DostProject` + `DostTech` + `Technology`) with `tech_status` rules and Feasibility Assessment.
- **Step 5:** Action Plan Generator, Investment Priorities, and Expected Outcomes & Performance Indicators.

