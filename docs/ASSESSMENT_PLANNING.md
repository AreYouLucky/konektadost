# Assessment evidence and project screening

`/location/assess` keeps its existing authenticated city-PSGC flow and admin-only numeric province/municipality/barangay flow. No new routes, geographic permissions, tables or imported-data writes are introduced.

Step 1 remains the first assessment section. It now shows measurement/comparison/follow-up counts, an evidence-status donut, stacked theme counts, a reference-index chart (national rate = 100), signed percentage gaps and available population/recorded-coverage charts. Reference-index bars stop at 200 with full text values retained. Density has the opposite pressure direction; neither the index nor the donut is a service-adequacy score. The comparison table stays visible, and full indicator descriptions, reasons and source notes are available in an expandable section.

The planning brief separates high-confidence comparison flags, provisional low-confidence comparisons, population-pressure context and modeled agriculture context. Review order uses confidence, then absolute percentage difference; it is not an allocation ranking. Missing measurements and references trigger evidence follow-up rather than project-need findings. The original city assessment now retains null city population rather than replacing it with a barangay sum. Child population stays separately labeled.

`Support/Assessment/ProjectCatalog` adds a `projectCatalog` page prop containing the small existing `dost_techs` reference catalog. Required fields are id/code/name; absent optional fields remain null. Missing and available-but-empty catalogs remain distinct. This catalog is NCR context: screening outside NCR does not establish applicability, eligibility, availability or deployment. It is separate from `dostInterventions`, which retains the earlier focus/location-text matches, and from map markers.

`resources/js/lib/assessment-planning.ts` implements display and screening rules, without changing assessor formulas or benchmarks. Whole words and phrases in catalog names, sectors and mechanisms match evidence themes. Agency and outcome text do not determine matches. Each displayed option retains the original catalog fields, exact matched terms, the triggering evidence/basis, agency, mechanism, catalog-stated outcome and local validation checks. A matching option does not prove that a technology remedies the gap: learning tools do not replace schools, telehealth does not replace hospitals/staff, network-consuming sensors do not add connectivity, and safety-facility counts do not establish flood risk. Agriculture themes use available modeled category entries, never inferred measured production.

`assessment-planning.tsx`, `assessment-visuals.tsx` and `assessment-charts.tsx` use the current white/navy/cyan styling, with amber for high-confidence flags and slate for lower-confidence/missing evidence. Catalog context and readiness checks remain advisory; no AI service, funding promise, cost/impact estimate, automatic project approval or export is added.

Checks:

```powershell
node --test tests/Frontend/AssessmentPlanning.test.cjs tests/Frontend/MapMarkerScope.test.cjs
php vendor/bin/phpunit tests/Feature/MapExplorerTest.php tests/Unit/PolygonAreaTest.php
node node_modules/typescript/bin/tsc --project tsconfig.map-explorer.json
npm.cmd run build
```

The PHP feature class creates an isolated in-memory SQLite connection. Do not run reference-database reset/fresh migrations to test this feature.
