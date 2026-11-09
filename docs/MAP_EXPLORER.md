# Philippine map explorer

Open **`/admin/search-map`** as an administrator, or choose **Geographic Explorer** in the admin navigation. This page is a thin Inertia wrapper around the reusable `resources/js/components/philippine-map/` component. The original `/location` Leaflet map and assessment flow remain available.

Choose **Assessment** in the admin sidebar to open **`/admin/assessment`**, which uses a full-screen map layout. Select a city/municipality (or one of its barangays), choose a supported assessment focus, and use **View assessment** to open the existing results page. Its setup panel sits on the right on desktop and collapses on mobile. The start endpoint resolves the municipality's stored 10-digit `psg_code` on the server; locations with only GADM or alternate display codes cannot start an assessment. Both assessment routes require the admin role. Map selection URLs and local Back/Forward navigation work as in the explorer.

## Map experience

The right panel follows the **selected province, city/municipality, or barangay**. Overview uses compact white sections with navy headings, consistent padding and flat icons. Population leads with the recorded total, active collection year and available local figures; unknown population stays unavailable. DOST technology totals include an expandable list of all recorded sectors and a reference-pin caveat. Agriculture retains category icons and modeled-entry totals. The fixed header, tabs and assessment footer surround one scrolling content area; full charts and tables remain accessible. Its **Overview, Facilities, Labs, Agriculture and DOST tech** tabs combine tables and charts: facility sector/type totals, laboratory service counts, population figures, modeled agriculture category/type composition, annual finances and commodity totals. There are no search filters, individual-record browsers or pagination. Commodity tables scroll horizontally to retain unit prices, annual revenue, operating costs, net income and reporting counts without crowding the panel. The existing province-parent endpoints accept optional `level=province|municipality|barangay` and `location=<numeric ID>` query parameters. `GeographicScope` resolves verified parent paths and rejects foreign-province or unlinked selections; authorization and throttling remain unchanged. The resources endpoint reads only the selected boundary for polygon land area and does not return geometry.

Resource queries restrict municipality and barangay foreign keys, with verified barangay-parent fallback for records without a municipality. Agriculture uses real `barangay_id` links; original source IDs and suggested-parent IDs cannot add a record to a selected barangay. Its latest modeled year is resolved within the selected area. A scoped result cannot widen back to an ancestor. Population uses the active collection year and correct newest-release level (`Prov`/`Reg`, `City`/`Mun`, or `Bgy`); missing local data never borrows parent population. Requests are cancelled and data cleared on every namespaced selection change. A new location opens the summary and resets its scroll position.

The **Agriculture & livelihood** summary displays annual revenue, operating cost and net income totals, plus recorded areas grouped by area kind. Commodity summaries separate category/type, name/variant, unit and quantity basis, showing annual, marketable and sold quantities, recorded minimum/maximum unit prices, and annual financial totals. Prices are never added across products or units. SQL aggregates preserve decimal strings; negative net values and missing amounts remain distinct from zero. Each financial total reports how many records supplied that value; summed record areas can overlap and are not land-coverage measurements.

The landing page embeds this map for guests through the throttled `public-map.*` routes, which omit contact numbers and assessment results. **View location assessment** is shown to signed-in users only and navigates to the existing **`/location/assess?level=<level>&location=<numeric ID>&focus=general`** route and **`location-assessment.tsx`** page. This selected-scope mode is explicitly administrator-only; the original authenticated `city=<PSGC>` mode remains supported. `MapAssessmentReport` supplies the verified selected-area data and a return link that restores the Intelligence Map selection. Changing focus preserves the numeric scope. The page uses the administrator layout for map reports, and includes summary cards, signed benchmark-gap charts, per-indicator measured/reference graphs, population figures, facility tables/charts, modeled agriculture finances and commodity tables, local laboratory tests and DOST catalog context. Step 1 remains first, with charts and a comparison table before expandable indicator source cards, inside a rounded section with a shaded heading and soft shadows. Indicator cards retain their availability/confidence labels and use status-colored top borders; the responsive grid leaves more room for comparisons and source notes. Both report flows include an evidence/planning brief and a separate read-only NCR catalog for project screening; options show matched terms, source descriptions, agency and readiness checks. See [Assessment planning](ASSESSMENT_PLANNING.md). The original municipality report also includes barangay population/priority charts. Measured values stay visible when a benchmark is missing, and density shortfalls retain their opposite direction.

`Support/Map/SelectionAssessment` prepares local inventories and calls `LocationAssessor::assessSnapshot`, sharing the existing formulas, confidence rules, national comparisons and shortfall directions. Population uses the active year, density uses the selected polygon, and laboratory capability counts distinct local test/method pairs. Telecom requires verified membership; missing sources or unknown technology classifications do not become fabricated zero coverage. Benchmarks are descriptive references, not service standards. Agriculture remains modeled context outside the scored indicators. The existing paginated agriculture-records API remains available but is no longer used by this UI.

The **DOST tech** tab lists catalog technologies and recorded location text, with a sector chart. `DostTechnologyLocations` restricts the catalog to NCR and matches cluster text to candidate municipalities that already belong to the verified selected area. Cyan diamond CPU markers (`#00aeef`) are explicitly labeled **city reference points, not deployment coordinates**; stored city centers are never offset and missing/zero coordinates do not receive a marker. Province-wide NCR catalog entries remain province context without invented sites. A barangay match requires both city and barangay names in the text and receives no approximate marker. These are heuristic catalog matches requiring local verification, not evidence of implementation, availability or impact.

The map loads a national coordinate inventory once per mounted map through the protected **`GET /admin/search-map/markers`** endpoint. Without a province selection, all valid recorded facility, laboratory, cell-site and DOST reference pins are available. Selecting a province, municipality or barangay intersects coordinates with that selected boundary; polygon holes and separate islands are respected. Pins outside the area are removed immediately. Resource foreign keys are deliberately not used for marker membership because the current import contains stale location assignments. This is a read-only display change; right-panel inventories and assessment counts still use their stored links and can differ from coordinate-based marker counts. See [the marker diagnosis](MAP_MARKER_DIAGNOSIS.md).

If a selected barangay has no polygon, only records with an explicit source `barangay_psgc` exactly matching its verified 10-digit code can appear. When geometry, bounds and center are all absent, verified matching point extents provide camera framing. Other missing boundaries show no inferred pins. No polygons or database assignments are fabricated, and no national barangay geometry collection is loaded.

Open the toolbar's **Map markers and legend** pin button for a compact popup with four labeled checkbox groups: **DOST Laboratories**, **DOST Technologies**, **Cell Sites / Towers**, and **Local Facilities** (all facility sectors). Category counts reflect available records inside the current scope, even when unchecked. **Show all** and **Hide all** control every group. All start checked; preferences persist through selections and basemap retries. Visibility changes update map sources without navigation, additional resource requests or moving an already framed camera. The popup opens upward and closes when the appearance popup opens or Escape is pressed. There is no separate legend card beneath the explorer.

The panel has independent scrolling and a collapse/reopen control. Overseas laboratories are excluded. Counts retain ungeocoded records; pins use only stored numeric, finite, in-range coordinates and reject null/zero placeholders without inventing municipal-center positions.

Custom markers distinguish health (red cross), education (blue book), safety (amber shield), other facilities (blue-900 building), DOST / OneLab laboratories (cyan hexagonal flask), and DOST catalog reference points (cyan diamond CPU), matching the original Location Map's DOST symbols. Facilities cluster; DOST resources have their own unclustered source/layer above facility clusters so they remain visible at province/city scales. Marker labels avoid collisions, while the symbols remain visible. Clicking a facility cluster zooms in; clicking a DOST marker opens safe text details, listing all DOST records sharing that coordinate together. Laboratory popups include available code, contact, website and source-listed service counts; the right panel labels normalized service counts separately. Technologies include catalog agency, sector, status, mechanism and source-stated outcome. The full-screen Assessment Map also loads scoped resource pins. Pin updates and popup cleanup use the existing map instance and survive style retries without replacing the basemap.

The selected province/city/barangay polygon has a translucent green fill and a thicker contrast outline, with a white casing on streets and dark casing beneath a bright green line over imagery. It stays visible when **Map lines** is off; resting country/child outlines continue to follow that switch. The left explorer identifies the selected area and explicitly states when polygon geometry is unavailable; marker counts live in the toolbar popup. Valid selected geometry drives framing; no boundary is invented from a center point. Hybrid starts only when configured imagery is available; otherwise Explorer retains native land/water styling.

**Cell-site inventory and assessment counts** still load through the protected resources endpoint in its `telecom_sites` summary. `TelecomResources` shares its stored-membership query with the assessment; map display instead uses the national coordinate inventory and selected polygon. Explicit foreign municipality assignments take precedence over a conflicting barangay; only null municipality assignments fall back through verified numeric/GADM barangay parents. Missing/incompatible tables are unavailable, not a zero inventory. Raw coordinates must be numeric, finite, in range and not the zero/zero placeholder; unpinnable sites remain in totals. Cell-site popups retain network operator, tower company, technology/bands and source details without treating site counts as signal coverage.

`resource-marker-designs.ts` supplies both the canvas images and SVG legend for all seven categories. **Orange triangular radio-tower icons** represent cell sites; facilities keep their health cross, education book, safety shield or building icon. Nearby facilities form navy building groups, and nearby cell sites form separate orange tower groups, each with a count. Click a group to zoom into individual icons. DOST markers remain visible above these groups. The legend displays all categories and mapped-record counts. Clicking co-located icons lists all associated records using safe text nodes; no locations are shifted to separate overlapping symbols. The Overview tab includes cell-site totals and coordinate completeness.

Population uses only the configured active `collection_years` year, the newest place-description release, and a verified selected-location PSGC identity at the matching administrative level, including `Reg` records for Metro Manila. Unknown population remains null, and an absent current schema/active year has an explicit unavailable message. Earlier imports used legacy `census_year` population rows and lacked `collection_years` and `place_descriptions`; inspect the connected database rather than assuming that older shape still applies. This feature does not select a vintage automatically or migrate the database.

`population_breakdown` supplies verified child figures for population charts and tables: municipalities within a province, barangays within a municipality, or the selected barangay itself. Graphs show the top 10 available values; the table retains all child figures and explicitly marks unknowns. Child figures are not substituted for parent population and can be incomplete. Charts do not invent trends or combine unlike quantity units. Agriculture composition charts compare model-entry counts, not production or economic importance; all finance and quantity tables retain decimal strings while bar lengths are approximate.

On the Geographic Explorer page, selecting a province, city/municipality, or barangay by its boundary, list row, or search result triggers an agriculture request and loads the **Agriculture** section in the right-side province panel. It updates to the selected location while drilling down or returning to a parent, and disappears on returning to the national view. The right panel is collapsible on desktop and narrow maps; the left explorer is reserved for geographic selection. Requests are cancelled when switching provinces; city and barangay changes inside the same province load new local results.

The agriculture summary uses verified geographic foreign keys and the selected area's newest available `model_year`, with an explicit year/source label. Crops, livestock/poultry and fisheries use corresponding icons. Quantities stay grouped by type, unit and basis. These are modeled planning records, including synthetic scenarios, rather than measured production. Fisheries trading/handling must not be described as local catch. Locations without estimates and unavailable agriculture tables have distinct messages.

- One persistent MapLibre GL JS 6.16 map, using the already-installed dependency. Vite publishes its separate module worker as a local, hashed asset.
- OpenFreeMap Bright streets, Mapterhorn raster DEM with 1× elevation, light hillshade, sky/atmosphere, globe/flat projection, and 2D/3D controls.
- OpenFreeMap Bright retains its original land/water colors, low-zoom raster backdrop, and native place/water labels. Neighboring countries remain visible; there is no country mask or national green tint. The Map appearance menu includes a **Map lines** switch, off by default. It shows/hides ferry routes, native administrative boundaries, and resting database outlines together in any appearance. Hover and selected-location outlines remain available when off; the choice survives mode changes without moving or rebuilding the map. Resting fills remain transparent. Navigation is bounded to the surrounding Philippine region.
- Initial country bounds include Luzon, Visayas, Mindanao, Palawan, the northern islands, and western outlying islands. The national view is horizontally centered from map construction, with balanced side padding; selected locations use panel-aware padding. Minimum zoom defaults to 5; pass `minZoom={4.2}` for an approximately 300 km widest scale. The map starts flat and in 2D, without the decorative vignette/header. Mobile framing reserves space for the sheet and bottom toolbar.
- Province → city/municipality → barangay navigation from database records, via the floating list or boundary clicks. Selection fits actual geometry/bounds and gradually increases pitch.
- Hover and selection outlines, reduced-motion support, debounced keyboard-accessible search, parent breadcrumbs, shareable selection URLs, and local Back/Forward history without recreating the map.
- A floating Intelligence Map explorer on the left and an expandable mobile sheet, with bounded scrolling above the bottom controls. Marker visibility lives in a compact toolbar popup. The explorer menu and mobile sheet use solid white surfaces, navy headings and actions, flat icon badges, pale-blue selection cards and individually bordered location rows. Primary actions match the navy administrator sidebar. Geographic request cancellation, bounded geometry caches, source/layer updates, resize observation, and event/worker cleanup keep the map responsive.
- Map controls sit in a horizontal bottom-center toolbar, with tooltips and separate appearance and marker popups opening upward. The mobile sheet reserves space above it, and selected-location details stay inside the explorer, with province information in the separate right-side panel.
- Empty children, unlinked parents, invalid/missing polygons, session expiry, unavailable datasets, and tile failures receive explicit handling. Verified record coordinates/bounds provide fallback positioning; no polygons or geographic records are invented.

The composition follows the [official MapLibre hybrid/terrain example](https://maplibre.org/maplibre-gl-js/docs/examples/display-a-hybrid-satellite-map-with-terrain-elevation/), with imagery made optional rather than assuming the example imagery is authorized for the deployment. No MapTiler account or key is used.

## Reuse on another page

```tsx
import PhilippineMap from '@/components/philippine-map';

export default function YourPage() {
    return (
        <div className="space-y-6 p-6">
            <h1>Your dashboard</h1>
            <PhilippineMap className="h-[600px] rounded-2xl" title="Agriculture Map" />
        </div>
    );
}
```

Province data loads automatically; `provinces`, `geographyError`, and `satellite` can also come from Inertia props to preload/configure the map. Satellite configuration must still come from the server's validated public configuration.

| Prop | Default | Purpose |
| --- | --- | --- |
| `className` | 640px-high box | Tailwind sizing/rounding; supply an explicit height, e.g. `h-[600px]` or `h-full` in a parent with a height |
| `fullScreen` | `false` | Fill the viewport instead of fitting into page content |
| `title` | `Intelligence Map` | Explorer heading and accessible region label |
| `initialTerrain`, `initialGlobe` | `false` | Initial terrain/projection preferences; toolbar toggles still work |
| `minZoom` | `5` | Zoom-out floor; larger values allow less zooming out |
| `syncUrl` | `false` | Opt in to `level`/`location` query parameters and local Back/Forward history |
| `onSelectionChange` | none | Receive `(selectedLocationOrNull, administrativePath)` after selection changes |
| `selectionContent` | none | Page-owned content inside the left explorer/mobile sheet, below the selected location; opens the sheet on selection and shares its scroll area |
| `resourcePins` | empty array | National `MapResourcePin` inventory; the component narrows by selected geometry and checkbox visibility, preserving the map instance |
| `resourceLoading`, `resourceError`, `onRetryResources` | false / none | Loading, error and retry feedback inside the marker popup |
| `rightPanelWidth` | `0` | Reserve this many pixels plus spacing for a right information panel during selection framing at map widths of at least 1100px; does not rebuild the map or change the national view |
| `children` | none | Render custom overlays inside the map's positioned container |
| `api` | Admin geography API | Stable URL-builder object for other authorized endpoints using the same response shapes |

Embedded maps do not read or modify host query parameters/history by default. Multiple instances have independent state and unique accessibility IDs; only one map on a page should opt into `syncUrl`. The search-map page uses `<PhilippineMap {...props} className="absolute inset-0 h-full" syncUrl />` inside a relative flex container in the admin sidebar layout. This fills the desktop content area without covering navigation. The desktop breadcrumb header is hidden; the sidebar toggle sits by the logo and stays accessible in the collapsed rail. Mobile keeps a compact breadcrumb/toggle bar above the map. Use `fullScreen` only on pages that intentionally fill the viewport, such as the assessment map.

To connect an info panel, keep its selected record in the host page:

```tsx
import PhilippineMap, { type MapLocation } from '@/components/philippine-map';
import { useState } from 'react';

export default function MapWithDetails() {
    const [selected, setSelected] = useState<MapLocation | null>(null);

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <PhilippineMap className="h-[640px] rounded-2xl" onSelectionChange={setSelected} />
            <aside>{selected ? selected.name : 'Select a location to view details.'}</aside>
        </div>
    );
}
```

The default endpoints remain **admin-only**, regardless of which page hosts the component. Reuse on other admin pages requires no new routes. For another audience, provide an `api` object implementing the exported `GeographyApi` type and implement appropriately authorized endpoints; importing a component does not grant database access. Keep custom API objects stable across renders. The component owns no Inertia `<Head>` or application layout; the host page owns those.

## Satellite and hybrid configuration

The initial appearance is **Hybrid** when satellite configuration is available, otherwise **Explorer** (OpenFreeMap). Terrain is controlled separately. `.env.example` now configures the EOX 2016 source, and the local environment has it enabled. Satellite and hybrid buttons are exposed only when `MAP_SATELLITE_ENABLED=true`, an HTTPS tile URL containing `{z}`, `{x}`, and `{y}` is supplied, and attribution is present. The operator must configure a source authorized for the deployment. These settings are public browser configuration, so do not put secret credentials in the tile URL.

Configure the existing `.env` using the `MAP_SATELLITE_*` variables in `.env.example`, then clear cached configuration if necessary (`php artisan config:clear`). Reload the explorer.

The configured source is EOX 2016: EOX's [current licensing FAQ](https://cloudless.eox.at/documentation/faq) identifies that vintage as CC BY 4.0, with attribution, fair-use conditions, and no public endpoint uptime SLA. The [WMTS capabilities](https://tiles.maps.eox.at/wmts/1.0.0/WMTSCapabilities.xml) identify the `s2cloudless_3857` layer as the 2016 layer. Review deployment requirements before opting in; arrange an appropriate delivery agreement for higher traffic or availability needs.

Example for that source:

```dotenv
MAP_SATELLITE_ENABLED=true
MAP_SATELLITE_TILE_URL="https://tiles.maps.eox.at/wmts/1.0.0/s2cloudless_3857/default/g/{z}/{y}/{x}.jpg"
MAP_SATELLITE_ATTRIBUTION='EOxCloudless <a href="https://cloudless.eox.at/">by EOX IT Services GmbH</a> (Contains modified Copernicus Sentinel data 2016), <a href="https://creativecommons.org/licenses/by/4.0/">CC BY 4.0</a>'
MAP_SATELLITE_MAX_ZOOM=13
MAP_SATELLITE_TILE_SIZE=256
```

EOX's WMTS path places the **row (`y`) before the column (`x`)**. Preserve that order. Higher zooms will overscale the source imagery; the 2016 mosaic is historical imagery, not a current high-resolution aerial survey.

For another authorized provider, supply its exact tile template, attribution, maximum source zoom, and tile size. Hybrid displays raster imagery with transportation and place labels. Satellite displays imagery without the original basemap layers. Native land/building fills are hidden in both imagery modes so they cannot cover the raster. Explorer restores the original layer visibility. Switching these modes changes layer visibility without rebuilding the map, losing its camera, or dropping selection/terrain. Satellite tile errors return to the street map.

The official example's 2020 EOX endpoint is **not enabled by default**. EOX's FAQ explicitly says internal government agency use does not qualify for its newer layers' non-commercial access; obtain the relevant license before configuring those layers.

## Geographic API and data compatibility

All endpoints use authentication, the admin role, and read-only geography access. JSON endpoints are rate-limited.

| Endpoint | Response |
| --- | --- |
| `GET /admin/search-map` | Inertia page and lightweight province metadata |
| `GET /admin/search-map/geography?level=province` | Province metadata and valid province polygons |
| `GET /admin/search-map/geography?level=municipality&parent=<province ID>` | Only that province's municipalities and polygons |
| `GET /admin/search-map/geography?level=barangay&parent=<municipality ID>` | Only that municipality's barangays and polygons |
| `GET /admin/search-map/locations/<level>/<ID>` | Verified administrative path and selected boundary |
| `GET /admin/search-map/search?q=<name>` | Up to six matches per level with resolved parent paths; minimum two characters |
| `GET /admin/search-map/provinces/<province ID>/agriculture` | Latest modeled-year agriculture summary for the selected area within that province, with category/type icons supplied by the page, quantities grouped by unit/basis, and source metadata; admin only and rate-limited |
| `GET /admin/search-map/provinces/<province ID>/agriculture/records` | Latest-year source records with validated filters and server pagination; optional `level`/`location` scope plus `q`, `commodity`, `category`, `type`, `municipality`, `page`, `per_page` (max 50); admin only and rate-limited |
| `GET /admin/search-map/markers` | National valid-coordinate marker inventory, admin-only and rate-limited, private cache for 60 seconds |
| `GET /admin/search-map/provinces/<province ID>/resources` | Active-year population/child figures, facility/laboratory inventories, cell-site counts/pins, indicator snapshots and DOST catalog reference pins; admin only and rate-limited |

Selections use internal numeric IDs, namespaced by level, rather than assuming a complete PSGC column. `GeographyExplorer` supports `brgy_name`/`name`, `psg_code`/`psgc_code`/GADM identity, numeric parent links, and the imported GADM parent links when the newer columns are absent. Municipality membership follows actual stored parent relationships, including NCR and independent-city assignments. It does not infer parents from names or PSGC prefixes.

The inspected live database contains **81 provinces, 1,628 municipalities, and 41,929 barangays**. This describes the imported dataset, not a claim of current national administrative completeness. Its barangays use `municipality_gadm_id`; newer code elsewhere expects `municipality_id`. The explorer accommodates both schema versions without changing the database. Stored `[south, north, west, east]` bounds are converted to MapLibre longitude/latitude order.

Only current-level polygons are loaded: all province outlines, one province's municipalities, or one municipality's barangays. All 81 inspected province polygons and Quezon City's 141 linked barangay polygons were readable. Other records may have missing geometry, obsolete boundaries, incorrect source polygons, or unlinked parents. The explorer neither repairs nor silently reassigns those records. Blank or broken geometry falls back to valid bounds/coordinates; records without a verified parent chain are excluded from global search and cannot be selected through a fabricated hierarchy.

## Files and checks

To customize the map toolbar, edit `resources/js/components/philippine-map/partials/map-toolbar.tsx`. Its Tailwind classes define the dock, shared `MapTool` buttons/tooltips, and appearance/marker popups. The dock's `<aside>` sets position, spacing, background, and border; `MapTool` sets button sizes/colors and hover/active/focus states. `@[768px]/philippine-map:` utilities apply desktop styling when the map container reaches 768px, independently of the browser width. Other map styles live in `philippine-map.css`, scoped beneath `.philippine-explorer` and sized with container queries/units so they do not take over a host page.

- `app/Http/Controllers/Admin/SearchMapController.php`: page/configuration and geographic JSON endpoints.
- `app/Support/Map/GeographyExplorer.php`: schema-aware geography queries, metadata, validated polygons, search and path resolution.
- `app/Http/Controllers/Admin/ProvinceAgricultureController.php`, `app/Support/Map/ProvinceAgriculture.php`: read-only province agriculture endpoint and aggregation of modeled records.
- `resources/js/pages/admin/search-map/partials/province-agriculture-card.tsx`: selection-triggered request, category/type icons, source labels, and loading/error/empty states.
- `resources/js/pages/admin/search-map/use-province-agriculture.ts`, `agriculture-presentation.ts`, and `partials/province-agriculture-card.tsx`: cancellable scoped summaries, exact decimal presentation, financial totals and commodity price ranges.
- `app/Http/Controllers/Admin/ProvinceResourcesController.php`, `app/Support/Map/ProvinceResources.php`: read-only province population, facilities, laboratories, and verified-coordinate payloads.
- `resources/js/pages/admin/search-map/use-province-resources.ts`, `partials/province-resources-card.tsx`: cancellable scoped requests, population/service totals, and the inline assessment button.
- `app/Support/Map/SelectionAssessment.php`, `MapAssessmentReport.php`, `pages/location-assessment.tsx`, `components/assessment-charts.tsx`, and `components/data-bars.tsx`: shared assessment formulas, protected selected-area report props, comparison tables and accessible charts.
- `app/Support/Map/DostTechnologyLocations.php`, `partials/technology-details.tsx`, and `partials/population-details.tsx`: catalog location matches/reference pins and child population charts/tables.
- `app/Support/Map/MapMarkers.php`, `MapMarkersController.php`, `use-map-markers.ts` and `marker-scope.ts`: national coordinate payload, cancellable loading and local polygon intersection.
- `resources/js/components/philippine-map/use-resource-pins.ts`: canvas-generated category icons, clustered GeoJSON source/layers, safe popups, and event cleanup.
- `app/Support/Map/TelecomResources.php`, `resources/js/components/philippine-map/resource-marker-designs.ts`: verified cell-site inventory/pins and shared seven-category map/legend icon definitions.
- `resources/js/pages/admin/search-map/search-map.tsx`: Inertia title/metadata, admin sidebar layout, and a map filling the remaining content with URL synchronization.
- `resources/js/components/philippine-map/`: reusable component, Tailwind toolbar, search, map/geography hooks, public props/types, and scoped styling.
- `config/map.php`, `.env.example`: optional authorized imagery configuration.
- `routes/web.php`, `resources/js/components/app-sidebar-admin.tsx`: protected access and navigation entry.
- `tests/Feature/MapExplorerTest.php`: isolated in-memory SQLite tests; no reference-database resets or migrations.

```powershell
php vendor/bin/pest tests/Feature/MapExplorerTest.php
node node_modules/typescript/bin/tsc --project tsconfig.map-explorer.json
node --test tests/Frontend/MapMarkerScope.test.cjs
npm.cmd run build
```

The map has its own TypeScript check because the repository-wide check currently also reports pre-existing errors in `auth/login.tsx`, `auth/register.tsx`, `auth/reset-password.tsx` (`FormDataType`) and `welcome.tsx` (`plus-darker`). Those unrelated pages were outside this map task.

External style, glyph, vector, elevation, and optional imagery services require network access. An unavailable provider can limit terrain or tile rendering even while geographic lists/search continue working. The page requires WebGL/hardware acceleration; unsupported browsers receive an explanatory message.
