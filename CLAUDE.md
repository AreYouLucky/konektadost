# KonektaDOST — Project Guide

Snapshot of the system as scanned on 2026-11-05. Where this file and the older docs disagree, this file was checked against the code and the live database. `AGENTS.md` and `WEBSITE_README.md` are partly out of date (see §10). `README.md` was rewritten on 2026-11-06 (installation, running on other devices, structure, usage and user manual).

## 1. Goal

KonektaDOST is a decision-support platform for DOST (Department of Science and Technology, Philippines). It answers one main question:

> **For a selected location, which DOST technologies and STI programs should be integrated there, why, and how?**

A user picks a city or municipality (or a province or barangay). The system measures local conditions, compares them with national reference rates, finds gaps, ranks barangays, matches gaps against the DOST catalog, checks feasibility, and drafts an action plan with the **5 Executive Outputs**:

1. Priority Areas
2. Critical Needs and Development Gaps
3. Recommended STI Interventions
4. Investment and Resource Priorities
5. Expected Outcomes and Performance

Plus a sixth section in chat plans: the **Action Plan Matrix** (Activities | Objectives | Results | Timeline | Budget/Resources | Responsible).

Core principle: **all numbers come from deterministic function tools. The AI only orchestrates, explains and drafts.** Missing data is never treated as zero. Recommendations are advisory drafts until the LGU and DOST validate them.

## 2. Stack and commands

- **Backend:** Laravel 12, PHP 8.2–8.5, Inertia v2, Ziggy (`route()` available in TSX), Pest 3.
- **Frontend:** React 19 + TypeScript, Vite 6, Tailwind 4, Radix/shadcn-style UI in `resources/js/components/ui/`, lucide icons, MapLibre GL (intelligence map), Leaflet (service-area map), jsPDF (PDF exports with bundled Noto Sans).
- **DB:** MySQL `konektadost`. Domain tables are **imported from a dump, not migrated**. Migrations only cover users/cache/jobs, a few additive tables and the planning-chat tables.
- **AI:** a Responses API reached only through `Support/Ai/ResponsesClient`. Provider `services.ai.provider` (`AI_PROVIDER`) is **GATES** (LLM gateway, `services.gates`: `GATES_API_KEY`, `GATES_BASE_URL` = gateway origin without `/v1`, `GATES_MODEL` default `openai.gpt-6-luna`) once `GATES_API_KEY` is set, otherwise **OpenAI** (`services.openai`, `OPENAI_API_KEY`, `OPENAI_MODEL`). GATES contract (SDK reference in `gates_llm-0.2.0/`): `POST {base}/v1/responses`, a fresh `X-Request-ID` per generation (reuse → 409 `duplicate_request`), positive `max_output_tokens` (credit is reserved from it), 2 MB body limit, `store`/`include`/stored-response options dropped. `php artisan ai:check` verifies URL, key and model for free; `--generate` / `--tools` run tiny paid checks. `phpunit.xml` pins `AI_PROVIDER=openai` so tests never reach the gateway. There is also a legacy Gemini narrative path (`AiNarrativeService`, `services.gemini`) that chat skips.

```bash
composer dev                    # serve + queue + vite
npm run build                   # production build (≈1.5 min)
npx tsc --noEmit                # typecheck
npx eslint <files>; npx prettier --check resources/
./vendor/bin/pint <files>       # PHP style
php artisan read-cache:clear    # after editing data directly in MySQL or changing formulas
php artisan test --compact <file>
node --test tests/Frontend/*.test.cjs
```

**Test environment caveat:** `phpunit.xml` points at a MySQL `testing` DB. Tests that use `RefreshDatabase` currently fail before running, because the planning-chat migration's foreign key to `municipalities` cannot be created (that table is never migrated). Some feature tests instead build their own in-memory SQLite schema. Never run `migrate:fresh`, `reset` or `refresh` against `konektadost`.

The platform is Windows. Use the PowerShell or Git Bash tool, and use `npm.cmd` if `npm` misbehaves in PowerShell.

## 3. Live data (`konektadost`, counted 2026-11-05)

| Table | Rows | Notes |
|---|---|---|
| `provinces` → `municipalities` → `barangays` | 84 / 1,642 / 42,010 | numeric FKs plus 10-digit `psg_code`; `geometry`, `bounds`, `center_lat/lng`, `area_km2` (computed from polygons) |
| `place_descriptions` | 43,766 | `level` (Reg/Prov/City/Mun/Bgy), `city_class`, `income_class`, `urban_rural` |
| `population_figures`, `collection_years` | 83,901 | 2020 (barangay) and 2024 (active year); growth comes from `MunicipalityProfileService` |
| `facilities` | 19,659 | `sector`: health 12,776 · education 5,122 · safety 1,761 |
| `laboratories` / `lab_services` | 69 / 2,846 | lab_services columns: `service_name`, `description`, `reference`, `fee` |
| `telecom_sites` | 155 | `technologies` is a JSON array (incl. 5G); sparse national coverage |
| `roads` (+ `road_barangay`) | 96,673 | `road_class`, `surface_type`, `road_condition`, `length_m`, `municipality_id`; no Eloquent model, read in `Support/Map/LocationRoads` and the B5 tool |
| `agriculture_categories/types/count_estimates` | 3 / 8 / 9,632 | **modeled** estimates (qty, revenue, cost, net per year), not observed yields |
| `dost_projects` | 1,961 | `agency` column: MIRDC 467, PNRI 453, ITDI 439, PCHRD 187, PTRI 92, ASTI 85, PCIEERD 83, FPRDI 60, NRCP 54, FNRI 41. `project_status` has 1,322 rows spelled **`"comleted"`**, and 306 are null. `sector`/`program` are mostly null |
| `dost_techs` | 18 | **deployable technology catalog** (code e.g. `SETUP-FOOD-01`, name, agency, sector, deployment_clusters, mechanism, status, outcome); NCR-scoped reference, with one sector per row |
| `technologies` | 11 | **STI program catalog** (STARBOOKS, DOSTv, …; category, target_audience, delivery_mode, active_status) |
| `planning_conversations/messages/action_plans` | — | chat history and versioned plan snapshots (migrated) |
| `assessment_action_plans` | — | versioned snapshots of the action plan generated on `/location/assess` (migration `2026_11_06_000400`); per user + level + location, de-duplicated by a content hash that ignores `generatedAt`; no FK to the imported geography tables |
| `users` | 4 | `role`: `admin` / `user` / `technician` |

The matching catalog is small: **29 entries** (18 `dost_techs` plus 11 `technologies`). Historical `dost_projects` are background evidence and must never be presented as available technologies.

Removed models: `Benchmark`, `EvidenceSource`, `PsgcRelease`. Reference rates are computed dynamically from the domain tables in `LocationAssessor::computeAllDynamicBenchmarks()`. Some old migrations still create a `benchmarks` table and other tables, but the code no longer uses them.

## 4. Architecture map

```
app/
  FunctionTools/                 ← the deterministic engine (33 tools, Layers A–F)
    Contracts/FunctionToolInterface.php   name/layer/description/parameters/definition/execute
    FunctionToolRegistry.php     registry, execute() (read-cached), executeLayerA..F(location)
    LayerA..F/*.php              one class per tool
    Support/LocationResolver.php resolves location args → Province|Municipality|Barangay
    Support/LocationNameLookup.php finds a province, city/municipality or barangay by name
    Support/SpatialMetrics.php   Haversine and spatial helpers
  Support/
    Assessment/   LocationAssessor (indicators vs dynamic benchmarks), IndicatorCatalog (dimensions),
                  AssessmentFunctionTools (older 14-tool facade + computeLocationBundle),
                  AssessmentAiService (OpenAI structured interpretation of an assessment),
                  ProjectCatalog (dost_techs summary), MunicipalityAssessmentData, GapAssessmentDiagnostics,
                  service classes for the older layer facades (DatasetAndAsset, SectorAnalysis, Prioritization, DostCatalogTool)
    Ai/           PlanningChatService (OpenAI tool-calling loop over the registry), PlanningChatHistory
                  (DB persistence and plan versioning), PlanningConversationVisuals (charts from tool output),
                  AiNarrativeService (legacy Gemini)
    Map/          GeographyExplorer (levels/search/selection), MapAssessmentReport (assessment page props),
                  ProvinceResources, ProvinceAgriculture, MapMarkers, LocationRoads, DostTechnologyLocations
    Caching/ReadCache.php        versioned file cache; auto-invalidated on DB writes
    Dashboard/UserDashboardData.php   user dashboard props (platform counts + own planning activity)
    Assessment/TargetAreaRanking.php  province → ranked cities/municipalities (population need + sector deficits vs province median; sectors recorded in <50% of LGUs are not scored)
    Import/, Psgc/, Geo/, Geometry/   importers, PSGC parsing/matching, polygon area
  Http/Controllers/
    LocationController           /location (service-area map), /location/assess (+ /data JSON, /ai POST)
    PlanningChatController       /planning-chat/* (GeoAgent assistant)
    UserDashboardController      /dashboard
    Admin/SearchMapController    intelligence map pages + geography JSON, used by both admin and user routes
```

### Function tool layers (`FunctionToolRegistry::TOOL_CLASSES`)

| Layer | Tools |
|---|---|
| **A – Data retrieval** | `get_municipality_profile`, `get_population_statistics`, `get_available_datasets`, `get_local_assets`, `get_telecom_infrastructure`, `get_geographic_data_availability`, `get_city_by_name`, `get_province_by_name`, `get_barangay_by_name` |
| **B – Sector analysis** | `calculate_indicators`, `analyze_health_access`, `analyze_education_access`, `analyze_digital_connectivity`, `analyze_road_accessibility`, `analyze_agriculture`, `analyze_sti_laboratory_capacity`, `analyze_safety_and_drrm` |
| **C – Gaps & priority** | `detect_development_gaps` (severity 0–100), `identify_underserved_areas`, `calculate_priority_scores` (barangay composite 0–100), `compare_locations` (two provinces, cities/municipalities or barangays, same references) |
| **D – Catalog** | `search_dost_technologies`, `get_local_dost_projects` |
| **E – Matching & feasibility** | `match_dost_interventions`, `assess_intervention_suitability`, `assess_implementation_feasibility`, `identify_implementation_partners`, `estimate_intervention_requirements` |
| **F – Plan & outputs** | `generate_intervention_plan`, `define_performance_indicators`, `estimate_intervention_outcomes`, `conduct_risk_assessment`, `generate_action_plan` |

**How technology matching works (E1, the heart of the goal).** Each gap from C1 is mapped to a domain and scored against every catalog row using rules plus keywords. It is not an embedding or AI similarity.
- `Technology`: base 20, sector/category +35, keywords up to +25, local context +15, active +10.
- `DostTech`: base 20, sector +35, keywords up to +25, agriculture context +15, active +10.
- `DostProject`: base 15, sector +30, keywords up to +20, cap 95.

The threshold is ≥40 (≥45 for projects). In `executeLayerE` only the **top** match is evaluated by E2–E5, and only for municipalities. Suitability = 0.30 need + 0.20 reach + 0.15 resource + 0.20 infrastructure + 0.15 institutional. Several E/F outputs contain fixed heuristics: a 12% beneficiary target, a 25,000 population fallback, a 50% unpaved-road assumption, and rule-assigned confidence values. The chat prompt tells the model to treat these as proposals. Full formulas are in `FUNCTION_TOOLS_README.md`.

## 5. User-facing flows

| Route | Page (`resources/js/pages/…`) | Notes |
|---|---|---|
| `/` | `welcome.tsx` | landing: only the header and the intelligence map. Guests use the throttled `public-map.*` routes (`/public-map/*`), which omit contact numbers and assessment results, and see no assessment button; `/intelligence-map/*` stays login-only |
| `/dashboard` (`user.dashboard.index`) | `users/dashboard/user-dashboard.tsx` | location quick-start (municipality search → assessment), workflow, coverage KPIs, catalog browser, project charts, own plans and conversations |
| `/intelligence-map` | `users/intellegence-map/intellegence-map.tsx` → `components/intelligence-map.tsx` | MapLibre explorer; URL `?level=&location=`; the province resource card links to an assessment with `map=user` |
| `/location` | `location-map.tsx` | Leaflet service-area map (`?province=&city=` PSGC); hidden from the user sidebar, route still live |
| `/location/assess?city=<psgc>&focus=` | `location-assessment.tsx` + `location-assessment/*` | deferred load from `/location/assess/data`; tabs for overview, sector needs, priority areas, DOST catalog, labs; PDF report and action-plan PDF export; AI interpretation via POST `/location/assess/ai` |
| `/location/assess?level=&location=&map=` | same page | province, municipality or barangay scope through `MapAssessmentReport` |
| Assessment action plan saving | `location-assessment/action-plan-export.tsx`, `saved-action-plan.tsx`, `lib/assessment-plan-store.ts` | opening the plan modal or downloading its PDF POSTs the `ActionPlanSnapshot` to `/location/assess/plans` (`AssessmentActionPlanController`, `Support/Assessment/AssessmentActionPlans`); the location is resolved server-side. Saved plans are listed with chat plans (`kind: chat|assessment`) in the sidebar and dashboard and open through `usePlanningChat().openAssessmentPlan(id)` |
| GeoAgent ("Talk to GeoAgent" floating button and sidebar) | `components/planning-chat.tsx` | `PlanningChatProvider` is mounted in `AppShell`. `usePlanningChat()` exposes `show()` and `openPlan(city, planId)`. The window event `planning-plans-updated` refreshes the plan lists |
| `/admin/*` (`role:admin`) | `admin/dashboard.tsx`, `admin/assessment.tsx`, `admin/search-map/*` | separate admin sidebar (`app-sidebar-admin.tsx`); data-import routes are commented out |
| `/admin/data-entries/{resource}` (`role:admin`) | `admin/data-entries/index.tsx`, `resource.tsx` | CRUD for every sector + users, driven by `Support/DataEntries/DataEntryRegistry` (one definition per sector; `DataEntryController` + one generic page). Entries without a chosen location get municipality/barangay from their coordinates (`LocationFromCoordinates`, same polygon rule as `resources:relink-locations`) |

The user layout is `layouts/app/app-sidebar-layout.tsx` with `components/app-sidebar.tsx`. The brand colour is `#087dcc`, with a white, slate and blue-50 palette. The DOST logo is `resources/images/dost-logo-horizontal.png` (black text, for light backgrounds; `storage/app/` is git-ignored, so it is bundled through Vite instead): use `components/dost-logo.tsx` (`full` or cropped `mark`) in the UI and `lib/dost-logo.ts` (`loadDostLogoDataUrl`, `drawPdfLogo`) in PDFs.

## 6. AI layer (implemented)

- **Planning chat** (`PlanningChatService`): uses the configured Responses API (GATES or OpenAI) with function calling over **all 33 registry tools** (incl. `compare_locations`, rendered as a `comparison` visual). Location arguments (`municipality_id`, `psg_code`, `gaps`, `custom_weights`, …) are stripped from the schemas and injected by the server. Limits: max 5 rounds and 12 calls, a 150 s budget, `store=false`, and the last 4 turns plus the latest saved draft as context. A reply that contains all 5 numbered headings is auto-saved as a new plan version (`PlanningChatHistory::isActionPlan`). The system prompt in `instructions()` focuses plans on **technology-to-location assignments**.
- **Assessment interpretation** (`AssessmentAiService`): a structured-schema OpenAI call over compact assessment evidence. It is validated and cached per evidence hash, with a lock against duplicate calls.
- Without a configured provider (GATES key + base URL, or `OPENAI_API_KEY`), both return an `unavailable` status. The deterministic pages still work. GATES error codes (`budget_exhausted`, `model_not_allowed`, `rate_limit_exceeded`, `request_too_large`, …) map to specific messages in `ResponsesClient::gatesFailureMessage`; failures log only status, code and request ID.

## 7. Conventions and gotchas

- Join geography by numeric FK or 10-digit `psg_code`. Assessment entry by `city` requires a 10-digit PSGC.
- Normalize `"comleted"` → completed wherever project status is shown or filtered. Examples: `SearchDostTechnologiesTool::normalizeStatus`, `UserDashboardData::normalizeStatus`.
- Missing ≠ zero: no tower recorded is not proof of no signal; no road data is not proof of isolation. Agriculture figures are modeled. Benchmarks are descriptive comparisons, not official standards.
- Wrap expensive computation in `app(ReadCache::class)->remember(name, params, fn)`. Results containing `error` are not cached. Planning data uses the `planning` group.
- Guard domain-table reads with `Schema::hasTable` and `hasColumn` where tests or fresh installs may lack the dump. These lookups are memoized per request (`Support/Database/CachedSchemaBuilder`, bound to `db.schema` in `AppServiceProvider`; any DDL clears it), so they are cheap to repeat.
- Resource location links (`facilities`, `laboratories`, `telecom_sites` → `municipality_id`/`barangay_id`) were rebuilt from coordinates on 2026-11-06 with `php artisan resources:relink-locations` (dry run by default; `--apply` writes a backup CSV to `storage/app/diagnostics/`; `--restore=<csv>` undoes it). Before that they used the legacy `old_municipalities`/`old_barangays` numbering (old 959 = Quezon City, current 959 = Maribojoc), so inventories and assessments counted almost nothing while the map showed every pin. Their foreign keys now point at the current tables (migrations `2026_11_06_000200`/`000300`). Re-run the command after re-importing any of these tables.
- Lookup indexes for the imported tables live in migration `2026_11_06_000100` (`barangays.municipality_id`, `population_figures(psg_code, year)`, `facilities(barangay_id, sector)`, …). A cold municipality assessment takes ~4–6 s (Quezon City was ~44 s without them).
- Every planning query must be scoped by `user_id` (and `municipality_id` for chat).
- Send state-changing `fetch` calls through `fetchWithCsrf` (`lib/csrf.ts`). The `<meta name="csrf-token">` is rendered only on a full page load and goes stale after an Inertia login/logout, which used to surface as "Your session has expired"; the helper uses the `XSRF-TOKEN` cookie and retries a 419 once via `GET /csrf-token`.
- Never put credentials in `VITE_` variables. Responses carrying user data use `Cache-Control: private, no-store`.
- Debris to ignore or clean: `resources/js/pages/hatdog.tsx` (joke page), `resources/js/pages/dashboard.tsx` (old placeholder, no longer routed), and the misspelled folder `pages/users/intellegence-map/`.

## 8. Reference docs in the repo

- `INSTRUCTION.md`: the master spec for Layers A–F and the executive outputs.
- `FUNCTION_TOOLS_README.md`: formulas, worked examples, and scoring for every tool (most accurate of the docs).
- `AI_USAGE_README.md`: evidence rules for any AI output.
- `docs/PLANNING_CHAT.md`, `docs/ASSESSMENT_PLANNING.md`, `docs/assessment-reports.md`, `docs/MAP_EXPLORER.md`, `docs/caching.md`.

## 9. Improvement backlog (toward "which DOST technology fits this location")

1. **Catalog depth.** Only 29 matchable entries, and `dost_techs` is NCR-scoped with one sector each. Expand the catalog, add multi-sector tags, target beneficiaries, prerequisites (power, connectivity, host facility, minimum population) and regional availability. Matching quality is capped by this table.
2. **Prerequisite-aware matching.** E1 scores relevance only. Feasibility (E3) runs for just the top match. Score every shortlisted technology against its prerequisites (telecom sites, roads, labs within X km, host facilities) and rank by a combined *need × fit × feasibility* score.
3. **Barangay-level siting.** Join the C3 priority scores with technology-specific site criteria, so the tool can propose host barangays rather than leaving every site "for validation".
4. **Use project history as evidence.** `dost_projects.location`, `sector` and `program` are mostly free text or null. Geocode and normalise them to show "similar technologies deployed nearby" as evidence of uptake.
5. **Replace the E/F fixed heuristics** (12% beneficiaries, 25k fallback, 50% unpaved) with data-driven values or explicit "not estimated" states.
6. **Comparison view.** Compare two or three municipalities, or two technologies for one location, side by side.
7. **Fix the test DB.** Give the planning migration a guard, or create `municipalities` in the test setup, so that `RefreshDatabase` tests run again.
8. **Refresh the stale docs.** `AGENTS.md` (14 tools → 29; agriculture rows 10,452 → 9,632; `roads` missing).

## 10. Known doc drift

- AGENTS.md says the AI layer is "Next". It is implemented (planning chat and assessment AI).
- The admin dashboard text references `benchmarks:compute`, which no longer applies.

## 11. Assessment UI building blocks (added 2026-11-05)

- `components/assessment-method/charts.tsx`: chart primitives (StackedBars, Waterfall, SeverityScale, BipartiteFlow, Scatter, FlowSteps, CompareBars). Colors come from `.viz-palette` CSS variables in `resources/css/app.css` (validated categorical order, separate dark steps).
- `components/assessment-method/how-computed-dialog.tsx`: the "How was this computed?" modal (full pipeline plus a step-by-step worked example with the location's real numbers per layer).
- `components/assessment-method/layer-graphs.tsx`: per-tab graphs (sector scorecard, gap severity mix, barangay scatter and score drivers, need→technology flow, relevance breakdown).
- `lib/assessment-targets.ts`: `buildTargetAreas()` gives top 5/10 target areas (province → `targetAreas` prop; city → barangay priority scores), with drivers and best-fit technologies. Used by `pages/location-assessment/target-areas.tsx` and the action plan (`targets`, `charts[2]`, PDF section 1).
- `pages/location-assessment/action-plan-export.tsx`: action plan modal (graphs, 12-week timeline, matrix) with Download PDF.
