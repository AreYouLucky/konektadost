# KonektaDOST

For a standalone website description to share with ChatGPT, see [Website Description](WEBSITE_README.md).

For the MapLibre geographic explorer at `/admin/search-map`, see [Map Explorer](docs/MAP_EXPLORER.md), including terrain, optional licensed imagery, and geographic API setup.

For the current function tools, assessment formulas, and worked examples such as cell sites per population, see [Function Tools and Assessment Calculations](FUNCTION_TOOLS_README.md).

**AI-powered geospatial decision-support system for DOST.**

KonektaDOST helps DOST decision-makers identify community needs, assess development
gaps, prioritise locations, and identify evidence-based science, technology and
innovation (STI) interventions. A user selects a location on an interactive map, runs
an assessment, and reviews five executive outputs:

1. **Priority Areas** — where should DOST focus?
2. **Critical Needs and Development Gaps** — what problems or unmet needs require attention?
3. **Recommended Interventions** — what STI projects could address those needs?
4. **Investment and Resource Priorities** — what resources, expertise, partnerships or funding may be required?
5. **Expected Outcomes and Performance** — what measurable improvements should result?

Recommendations are **advisory**. Authorised decision-makers determine actual priorities
and investments.

---

## Status

This is currently a **data-first prototype**. What exists today:

| Area | State |
|---|---|
| Interactive map with province → municipality drill-down | Working |
| Eloquent models for all 7 domain tables | Working |
| Barangay-level population figures (2024 census) | Loaded |
| Health, education and safety facility locations | Loaded |
| Accredited laboratory capability (tests, methods, fees) | Loaded |
| Computed land area for all 43,638 geographies | Working |
| Location assessment with measured indicators vs benchmarks | Working |
| Priority Areas / Critical Gaps outputs | Partly — indicators only, no ranking |
| Recommended Interventions (output 3) | Not built |
| Investment Priorities (output 4) | Not built |
| Expected Outcomes (output 5) | Not built |
| AI narrative synthesis | Not built |
| Report export | Not built |

Assessment dimensions currently backed by real data: **population and social needs**,
**health**, **education**, and **STI capacity** (partially). Environment, agriculture,
infrastructure/digital, and connectivity have **no data yet** and must be reported as
unavailable rather than scored.

---

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ |
| Composer | 2.x |
| Node.js | 20+ |
| MySQL | 8.0+ (or MariaDB 10.6+) |
| Laravel | 12 |
| React | 19 |

---

## Installation

```bash
composer install
npm install
cp .env.example .env        # Windows: copy .env.example .env
```

Set your database credentials in `.env`. `.env.example` is already configured for
MySQL — the only key you will normally need to change is `DB_PASSWORD`.

### 1. Import the database — required

> **The seven domain tables are not created by migrations.**
>
> `provinces`, `municipalities`, `barangays`, `population_figures`, `facilities`,
> `laboratories` and `facility_tests` contain imported reference data and have no
> migration files. A fresh clone without the data dump is a **non-functional
> application** — the map renders nothing and every model throws *table not found*.

Obtain `konektadost.sql` from the project maintainers, then:

```bash
mysql -u root konektadost < konektadost.sql
```

**Do not commit the dump.** It is roughly 80 MB and will permanently bloat every clone.

### 2. Run migrations

```bash
php artisan migrate
```

### 3. Compute land area

```bash
php artisan geo:compute-area              # add --dry-run to preview
```

Fills `area_km2` on all geography tables from the stored GeoJSON. Takes about a minute.
A dump produced after this step already contains the values, in which case skip it.

### 4. Compute reference rates

```bash
php artisan benchmarks:compute            # add --dry-run to preview
```

Derives the national reference rate for every indicator from the loaded data and
stores it in the `benchmarks` table. Without this, indicators are measured but
cannot be judged against anything. Takes a few minutes.

### 5. Finish setup

```bash
npm run build          # or: npm run dev
php artisan storage:link
```

### 6. Create a login

The database ships with existing accounts only. Create your own:

```bash
php artisan tinker
>>> \App\Models\User::create(['name' => 'Your Name', 'email' => 'you@example.com', 'password' => bcrypt('secret')]);
```

Then visit `/login` and open **Service Area Map** from the sidebar.

---

## Data model

| Table | Rows | Notes |
|---|---|---|
| `provinces` | 81 | GADM level 1, MultiPolygon GeoJSON |
| `municipalities` | 1,628 | GADM level 2 |
| `barangays` | 41,929 | GADM level 3 |
| `population_figures` | 43,557 | 2024 census, one row per area |
| `facilities` | 19,659 | Health, education and safety locations |
| `laboratories` | 69 | Accredited lab capability |
| `facility_tests` | 2,846 | Tests, methods, references and fees |

### Conventions

These two rules hold throughout and any new dataset should follow them.

**1. `gadm_id` is the join key.** It is the only field that is unique and non-null
across all three geography tables, and it is what the denormalised columns already
reference:

- `population_figures.area_ref` → `municipalities.gadm_id` / `barangays.gadm_id`
- `barangays.municipality_gadm_id` → `municipalities.gadm_id`
- `population_figures.municipality_id` → the parent municipality, populated on **every**
  row including barangay-level rows

Do **not** use `psgc_code` as a key. It is not unique (see Known Issues) and the
`provinces` table does not have the column at all. Where a human-facing Philippine
identifier is needed, store `psgc_corr` alongside `gadm_id`.

**2. Datasets are versioned and sourced.** `population_figures` is keyed on
`(area_level, area_ref, vintage)` and carries `source`, `source_url` and `match_status`
so that every figure can be traced and every failure to resolve an area is recorded.

### Naming caveat

`municipalities`, `barangays` and `population_figures` all have a plain string column
named `province` or `municipality`. In Eloquent a real column always wins over a
same-named relation, so those relations are named `provinceModel()` and
`municipalityModel()`. Read the raw columns with `getAttribute('province')`.

Names in the source data have spaces stripped (`QuezonCity`, `MetropolitanManila`). The
GeoJSON output de-spaces them for display; raw column values are untouched.

---

## Land area

`area_km2` is **computed from the GeoJSON polygons**, not imported.

Barangay-level land area is not published by any Philippine agency, so a single
consistent method was applied to all three levels. Area uses the spherical-excess
approximation with a mean Earth radius of 6371.0088 km, summing every part of a
MultiPolygon and subtracting holes.

Validation against known values:

| Check | Result |
|---|---|
| 1° × 1° box at the equator | 12,363 km² (true ~12,310) |
| Quezon City polygon | **148.78 km² vs 148.66 official — 0.08% error** |
| Total, all provinces | 296,963 km² vs PSA 300,000 km² — **0.99% under** |
| Province vs sum of its municipalities | 72 of 81 provinces agree to within 0.5% |

### Do not mix this with PSA land area

PSA publishes land area in Statistical Yearbook Table 1.1 (population by region and
province). These values are **administrative area**, which for island provinces includes
surrounding marine waters, so they are not the same measurement:

- **Sulu** — 1,559 km² of landmass vs 4,547 km² PSA administrative area
- The PSA table also contains outright errors (**Lanao del Sur** is listed at 15,055 km²,
  which is roughly the size of the entire BARMM region)

PSA's land area is published **only for census 2020**; the 2024 column is blank.

Computed areas are the default in this application. Where a PSA figure is quoted in an
output, label it explicitly as administrative area.

---

## Computations

Every figure this application reports is produced by one of the formulas below.
Nothing is estimated, modelled or inferred. Each carries its source and vintage.

### 1. Land area (`geo:compute-area`)

Spherical-excess approximation over the stored GeoJSON:

```
A = R² / 2 · Σ ( Δλ · (2 + sin φ₁ + sin φ₂) )
```

- `R` = 6371.0088 km (mean Earth radius)
- `Δλ` = longitude difference between consecutive ring vertices, in radians
- `φ` = latitude of each vertex, in radians
- Summed per ring, then **all parts of a MultiPolygon are added and holes
  (rings after the first in each polygon) are subtracted**
- Stored as `area_km2`, rounded to 4 decimal places

Validation: a 1° × 1° equatorial box returns 12,363 km² (true ≈ 12,310); the Quezon
City polygon returns 148.78 km² against 148.66 official.

### 2. Density

```
density = population / area_km2          (rounded to 2 dp, persons per km²)
```

Uses only `matched` population rows. Returns nothing if `area_km2` is null or zero.

### 3. Facility and laboratory rates

```
rate per 100,000 = count / population × 100,000        (rounded to 3 dp)
rate per million = count / population × 1,000,000      (rounded to 3 dp)
```

`count` is the number of facilities or laboratories assigned to the location.
Applied to: health facilities, hospitals, pharmacies, schools, elementary schools,
laboratories, and distinct laboratory tests.

### 4. Distinct laboratory tests

`facility_tests` holds 2,846 rows but only 2,556 distinct
`(laboratory_id, test_name, method)` combinations, because the unique index is on
`(laboratory_id, source_record_id)`. Tests are therefore counted as **distinct
`(test_name, method)` pairs**, not raw rows, so duplicate source records do not
overstate capability.

### 5. National reference rate (`benchmarks:compute`)

```
national rate = total facilities nationwide / total matched population × multiplier
```

`total` counts **all** facilities of that type in the country, including any not
assigned to a municipality. The denominator is the summed population of `matched`
municipality-level figures (111,325,162 for the current vintage), not the PSA
national total, so numerator and denominator cover the same areas.

National density is the exception — density has no total to divide, so:

```
national density = total matched population / Σ area_km2 of all municipalities
```

### 6. Median and 90th percentile

Computed across the per-area rate distribution, for areas with a matched population
figure (1,612 of 1,628 municipalities):

```
median = sorted_rates[ floor(n / 2) ]
p90    = sorted_rates[ floor(n × 0.9) ]
```

These are **context only**, never the comparison benchmark. The median for hospitals
and pharmacies is exactly `0.00`, because more than half of all municipalities have
neither — comparing against zero would make every area look adequate.

### 7. Gap against benchmark

```
gap % = (value − benchmark) / benchmark × 100
```

Null when the benchmark is zero or null.

### 8. Shortfall rule

```
shortfall = ( higherIsBetter AND value <  benchmark )
         OR ( NOT higherIsBetter AND value > benchmark )
```

Only density sets `higherIsBetter = false`. This is deliberate: density *above* the
national rate signals **pressure on services**, not surplus capacity. So a densely
populated municipality is flagged, which is the opposite direction to every other
indicator.

A shortfall is never claimed without **both** a measured value and a benchmark.

### 9. Confidence rules

An indicator is marked `confidence: low` when either condition holds. These exist
because the underlying data has known gaps, and a confident wrong finding is worse
than an explicit uncertainty.

| Condition | Meaning |
|---|---|
| `volunteerSourced AND value == 0` | A zero from OpenStreetMap means nothing is *recorded*, not that nothing exists |
| `coverageCheck share > coverageWarnAbove` | The source field this indicator depends on is mostly blank for this area |

Current coverage check: `elementary_schools_per_100k` uses
`unknown_education_share` = share of the area's education facilities whose `level`
is null, empty, or `'unknown'`, with a threshold of **0.20 (20%)**.

```
unknown_education_share = unclassified education facilities / all education facilities
```

### 10. Population selection

Only `match_status = 'matched'` rows are used. In the current dataset this is an
exact partition: all 41,764 matched rows carry a population and all 1,793
`needs_review` rows carry `NULL`. Treating that null as zero would understate every
rate, so an area without a matched figure reports **no data** rather than a score.

### 11. Land area versus PSA land area

These are different measurements and are never mixed. PSA reports *administrative*
area, which for island provinces includes surrounding marine waters — Sulu reads
4,547 km² there against 1,559 km² of actual landmass. The PSA table also contains
outright errors (Lanao del Sur is listed at 15,055 km²). Computed polygon area is
the default throughout.

---

## Known data issues

Documented so they are not rediscovered. None are fixed yet.

**1. Duplicate PSGC and a stray polygon outside Quezon.**
`municipalities.id = 1294` is `Hinunangan` filed under Quezon with `psgc_code = 86403`,
which is also used by the legitimate `Hinunangan, Southern Leyte`. Its polygon sits at
latitude 10.42–10.47, roughly **450 km south of Quezon**, overlapping Southern Leyte.
It appears as parts 0 and 1 of the Quezon province MultiPolygon. Rendering Quezon
province as-is shows a detached landmass in the Leyte Sea.

**2. 1,793 areas (4.1%) have no population figure.**
`match_status` and population are an exact partition: every `matched` row has a figure
and every `needs_review` row has `population = NULL`. Breakdown: 1,223 `unmatched_parent`
(often placeholder names such as `Barangay307`), 568 `unmatched_name`, 2 `ambiguous`.
Always query with `PopulationFigure::matched()` and treat a null population as
**unknown, never zero**.

**3. Unmapped island area in 9 provinces.**
For coastal and island provinces the municipality polygons do not fully cover the
province polygon: Laguna **+896.7 km² (33%)**, Lanao del Sur +386.8, Batangas +241.0,
Oriental Mindoro +76.7, Surigao del Norte +72.7, Agusan del Norte +72.1, Camarines Sur
+38.5, Sultan Kudarat +32.2, Maguindanao +32.0. Likely minor islands assigned to no LGU.

**4. Facilities are 100% OpenStreetMap, not official statistics.**
`facilities.source = osm_hot` for all 19,659 rows. OSM coverage tracks urbanisation and
volunteer activity, not need. **293 of 1,628 municipalities have zero recorded
facilities**, which cannot be distinguished from "not mapped". Always phrase as *no
facilities recorded*, never *no facilities*.

**5. Duplicate laboratory tests.**
`facility_tests` has 2,846 rows but only 2,556 distinct `(laboratory_id, test_name,
method)` combinations. Also 5 laboratories are flagged `is_outside_philippines` and 17
have a null `municipality_id`.

---

## Adding a dataset

Follow the `population_figures` pattern so the evidence layer works uniformly:

```
area_ref      GADM id of the area the row describes
vintage       or retrieved_at, for time series
source        short source key
source_url    where it came from
match_status  matched | ambiguous | unmatched_name | unmatched_parent
```

Never present a value without its source and date. Distinguish measured findings from
estimates and geographic proxies — a confidently stated finding the data cannot support
is worse than an explicit gap.

To fetch official PSA statistics programmatically, use the PXWeb API at
`openstat.psa.gov.ph` (the main `psa.gov.ph` site blocks scripted requests with HTTP 403).
The MGB ULAP-NGA geoportal now requires a token.

---

## Map

`/location` — click a province to drill into its cities and municipalities, or use the
dropdowns. The selected area appears in the side panel with its PSGC code, GADM ID
(copyable) and coordinates.

State lives in the URL (`/location?province=PHL.47_1&city=PHL.47.14_1`), so selections
are shareable and browser Back moves between levels. Drill-down uses Inertia partial
reloads, so only the municipalities for the selected province are fetched — the
initial province payload is ~1.1 MB (~320 KB gzipped) and each drill-down is ~21 KB.

Map tiles are OpenStreetMap; no API key is required.

---

## Assessment

`/location/assess?city=<gadm_id>&focus=<dimension>` — the assessment page.

The user selects a city or municipality, chooses a focus (general, or one of the
ten framework dimensions), and the system **measures** indicators from source data
and compares them to the national reference rate.

Currently assessed indicators:

| Indicator | Unit | Dimension |
|---|---|---|
| `population_density` | persons per km² | Population and social needs |
| `health_facilities_per_100k` | per 100,000 | Health |
| `hospitals_per_100k` | per 100,000 | Health |
| `pharmacies_per_100k` | per 100,000 | Health |
| `schools_per_100k` | per 100,000 | Education |
| `elementary_schools_per_100k` | per 100,000 | Education |
| `laboratories_per_million` | per million | STI capacity |
| `laboratory_tests_per_million` | per million | STI capacity |

### Rules the assessor enforces

These exist so the system cannot produce a confident wrong finding:

- **A count is not a finding.** Every indicator is a rate compared to a benchmark.
  A raw count is never presented as a gap.
- **Benchmarks are the national rate**, computed as all facilities of that type
  divided by total population. This is *not* an official standard or service norm,
  and the assessment page says so.
- **Zero from OpenStreetMap means "none recorded".** A zero is reported as low
  confidence with an explanation, because crowd-sourced data records an absence in
  the map, not an absence in the area. This is the single most likely source of a
  false positive.
- **Sparse source fields downgrade confidence.** Indicators that depend on a
  partially populated field carry a coverage check. Quezon City, for example,
  reports 0.097 elementary schools per 100,000 against a national 1.42 — but 47% of
  its education facilities have no recorded level, so the result is marked low
  confidence rather than presented as a 93% shortfall.
- **Unmatched population rows are never counted.** They carry a null population;
  treating that as zero would understate every rate. Areas without a matched figure
  report "no data" rather than a score.
- **Unsupported dimensions report nothing.** Choosing Environment, Agriculture,
  Infrastructure or Economy returns no indicators and an explicit statement that no
  dataset is available.

### Layout

```
app/Support/Assessment/
  Indicator.php          one indicator definition (key, unit, dimension, provenance)
  IndicatorCatalog.php   the 8 indicators and the 10 framework dimensions
  IndicatorResult.php    one measurement + confidence + shortfall judgement
  LocationAssessor.php   computes indicators for a location
app/Console/Commands/ComputeBenchmarkCommand.php   benchmarks:compute
```

Indicators are declared once in `IndicatorCatalog`. The assessor, the benchmark
command and the UI all read from it, so an indicator's key, unit, dimension and
provenance cannot drift apart.

---

## Tests

```bash
php artisan test
```

78 tests covering the GeoJSON layer, area computation, the domain models, and the
assessment logic including the low-confidence and no-data paths.

---

## Project structure

```
app/
  Console/Commands/ComputeAreaCommand.php       land area computation
  Console/Commands/ComputeBenchmarkCommand.php   national reference rates
  Http/Controllers/LocationController.php       map, drill-down and assessment
  Models/                                       Province, Municipality, Barangay,
                                                PopulationFigure, Facility, Laboratory,
                                                FacilityTest, Benchmark
  Support/GeoJson.php                           FeatureCollection builder
  Support/Geometry/PolygonArea.php              spherical polygon area
  Support/Assessment/                           indicator definitions and assessor
database/migrations/                            schema only — no domain tables
resources/js/components/service-area-map.tsx    Leaflet map (lazy-loaded)
resources/js/pages/location-map.tsx             map page
resources/js/pages/location-assessment.tsx      assessment page
tests/                                          Feature and Unit
```

## DB

use this link for DB backup

    https://drive.google.com/drive/folders/1DeV1EEtjhX0eiyQcfD88kQ4VtfwfBkIV?usp=sharing  
