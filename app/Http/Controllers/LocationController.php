<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\CollectionYear;
use App\Models\DostTech;
use App\Models\Facility;
use App\Models\Laboratory;
use App\Models\Municipality;
use App\Models\PlaceDescription;
use App\Models\Province;
use App\Models\TelecomSite;
use App\Support\Assessment\IndicatorCatalog;
use App\Support\Assessment\AssessmentAiService;
use App\Support\Assessment\MunicipalityAssessmentData;
use App\Support\Assessment\ProjectCatalog;
use App\Support\Map\MapAssessmentReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    /**
     * Show the service area map.
     *
     * Province -> municipality drill-down driven entirely by query params, so
     * the map state is linkable and the browser back button works.
     */
    public function show(Request $request): Response
    {
        // Query params carry PSGC codes (psg_code), not GADM ids.
        $provincePsgCode = $request->string('province')->trim()->toString();
        $cityPsgCode = $request->string('city')->trim()->toString();

        // An unknown province code renders as no selection rather than
        // erroring, so a stale bookmark still lands on a working map.
        $province = $provincePsgCode === ''
            ? null
            : Province::query()->where('psg_code', $provincePsgCode)->first();

        return Inertia::render('location-map', [
            'provinces' => Province::toGeoJsonCollection([
                'psg_code',
                'name',
                'variant_name',
                'english_type',
                'cc_code',
                'center_lat',
                'center_lng',
            ]),
            'selectedProvince' => $province?->psg_code,
            // Only ever built for the one province being drilled into, so this
            // stays small (17 KB for Metro Manila, ~165 KB worst case).
            //
            // Deliberately NOT Inertia::optional(): optional props are dropped
            // from full page loads, which would leave a bookmarked or shared
            // /location?province=... link rendering with no cities at all.
            'municipalities' => $province === null
                ? null
                : Municipality::toGeoJsonCollectionForProvince((int) $province->id, [
                    'psg_code',
                    'name',
                    'type',
                    'province_name',
                    'center_lat',
                    'center_lng',
                ]),
            'selectedCity' => $cityPsgCode !== '' ? $cityPsgCode : null,
            'mapMarkers' => $this->buildMapMarkers($province, $cityPsgCode),
        ]);
    }

    /**
     * Assess a selected city or municipality.
     *
     * Everything on this page is computed by the assessor from source data.
     * Dimensions without a dataset are reported as unavailable rather than
     * scored, so a gap in the data is never presented as a finding.
     */
    public function assess(Request $request): Response|RedirectResponse
    {
        if ($request->hasAny(['level', 'location'])) {
            return Inertia::render('location-assessment', app(MapAssessmentReport::class)->props($request, deferMunicipality: true));
        }
        // The city param carries the municipality's psg_code.
        $cityPsgCode = $request->string('city')->trim()->toString();

        $municipality = Municipality::query()
            ->where('psg_code', $cityPsgCode)
            ->first();

        if ($municipality === null) {
            return to_route('location.show')
                ->with('error', 'Select a city or municipality first.');
        }

        $focus = $request->string('focus')->trim()->toString();
        $focus = $focus === '' ? IndicatorCatalog::DIMENSION_GENERAL : $focus;
        app(MunicipalityAssessmentData::class)->validateFocus($focus);

        return Inertia::render('location-assessment', [
            'pendingAssessment' => [
                'url' => route('location.assess.data', ['city' => $municipality->psg_code, 'focus' => $focus]),
                'name' => $municipality->name,
                'province' => $municipality->province_name,
                'layout' => 'user',
                'backUrl' => route('location.show', [
                    'province' => $municipality->provinceModel?->psg_code, 'city' => $municipality->psg_code,
                ]),
            ],
        ]);
    }

    public function assessmentData(Request $request): JsonResponse
    {
        if ($request->hasAny(['level', 'location'])) {
            $props = app(MapAssessmentReport::class)->props($request);

            return response()->json($props)->header('Cache-Control', 'private, no-store');
        }

        $municipality = Municipality::query()->where('psg_code', $request->string('city')->trim()->toString())->firstOrFail();
        $focus = $request->string('focus')->trim()->toString();
        $focus = $focus === '' ? IndicatorCatalog::DIMENSION_GENERAL : $focus;

        return response()->json([
            ...app(MunicipalityAssessmentData::class)->props($municipality, $focus),
            'localLaboratories' => $this->buildLocalLaboratories($municipality),
            'dostInterventions' => $this->buildDostInterventions($municipality, $focus),
            'projectCatalog' => app(ProjectCatalog::class)->summary(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function assessmentAi(Request $request, AssessmentAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'city' => ['required', 'string', 'regex:/^\d{10}$/'],
            'focus' => ['sometimes', 'string'],
        ]);
        $focus = $validated['focus'] ?? IndicatorCatalog::DIMENSION_GENERAL;
        app(MunicipalityAssessmentData::class)->validateFocus($focus);
        $municipality = Municipality::query()->where('psg_code', $validated['city'])->firstOrFail();

        // Always recompute trusted server evidence; never accept client-supplied findings.
        $result = $ai->analyze(app(MunicipalityAssessmentData::class)->props($municipality, $focus));

        return response()->json($result, $result['status'] === 'ready' ? 200 : 503)
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Build the barangay-level population and priority breakdown for a municipality.
     *
     * @return array{
     *     summary: array<string, mixed>,
     *     barangays: list<array<string, mixed>>
     * }
     */
    private function buildBarangayAssessment(Municipality $municipality, ?int $cityPopulation, string $focus): array
    {
        $hasFacilities = Schema::hasTable('facilities');
        $hasTelecomSites = Schema::hasTable('telecom_sites');
        $prefix = str_starts_with((string) $municipality->psg_code, '13806')
            ? substr((string) $municipality->psg_code, 0, 5)
            : substr((string) $municipality->psg_code, 0, 7);

        $telecomByBarangayId = [];
        $telecomByPsgc = [];

        if ($hasTelecomSites) {
            $telecomRows = TelecomSite::query()
                ->select(['id', 'barangay_id', 'barangay_psgc'])
                ->where(function ($q) use ($municipality, $prefix) {
                    $q->where('municipality_id', $municipality->id);
                    if ($prefix !== '') {
                        $q->orWhere('barangay_psgc', 'like', $prefix.'%');
                    }
                })
                ->get();

            foreach ($telecomRows as $site) {
                if ($site->barangay_id !== null) {
                    $bid = (int) $site->barangay_id;
                    $telecomByBarangayId[$bid] = ($telecomByBarangayId[$bid] ?? 0) + 1;
                } elseif (! empty($site->barangay_psgc)) {
                    $psgc = (string) $site->barangay_psgc;
                    $telecomByPsgc[$psgc] = ($telecomByPsgc[$psgc] ?? 0) + 1;
                }
            }
        }

        $barangayQuery = Barangay::query()
            ->select(['id', 'municipality_id', 'psg_code', 'brgy_name', 'area_km2'])
            ->where('municipality_id', $municipality->id)
            ->with(['populationFigure', 'placeDescription']);

        if ($hasFacilities) {
            $barangayQuery->withCount([
                'facilities as health_facilities_count' => fn ($q) => $q->where('sector', 'health'),
                'facilities as schools_count' => fn ($q) => $q->where('sector', 'education'),
                'facilities as safety_facilities_count' => fn ($q) => $q->where('sector', 'safety'),
            ]);
        }

        $barangays = $barangayQuery->get();

        // Fallback to 10-digit psg_code prefix match on barangays if municipality_id was not linked yet.
        if ($barangays->isEmpty() && $prefix !== '') {
            $fallbackQuery = Barangay::query()
                ->select(['id', 'municipality_id', 'psg_code', 'brgy_name', 'area_km2'])
                ->where('psg_code', 'like', $prefix.'%')
                ->with(['populationFigure', 'placeDescription']);

            if ($hasFacilities) {
                $fallbackQuery->withCount([
                    'facilities as health_facilities_count' => fn ($q) => $q->where('sector', 'health'),
                    'facilities as schools_count' => fn ($q) => $q->where('sector', 'education'),
                    'facilities as safety_facilities_count' => fn ($q) => $q->where('sector', 'safety'),
                ]);
            }

            $barangays = $fallbackQuery->get();
        }

        $rawRows = [];

        if ($barangays->isNotEmpty()) {
            foreach ($barangays as $brgy) {
                $fig = $brgy->populationFigure;
                $pop = $fig?->population;
                $area = $brgy->area_km2 !== null && $brgy->area_km2 > 0 ? (float) $brgy->area_km2 : null;
                $healthCount = (int) ($brgy->health_facilities_count ?? 0);
                $schoolsCount = (int) ($brgy->schools_count ?? 0);
                $safetyCount = (int) ($brgy->safety_facilities_count ?? 0);
                $psgCode = (string) $brgy->psg_code;
                $telecomCount = ($telecomByBarangayId[(int) $brgy->id] ?? 0) + ($telecomByPsgc[$psgCode] ?? 0);

                $rawRows[] = [
                    'psg_code' => $psgCode,
                    'name' => trim((string) ($brgy->brgy_name ?: $brgy->placeDescription?->name ?: $brgy->psg_code)),
                    'population' => $pop !== null ? (int) $pop : null,
                    'area_km2' => $area !== null ? round($area, 4) : null,
                    'density' => ($pop !== null && $area !== null && $area > 0) ? round($pop / $area, 2) : null,
                    'urban_rural' => match (strtoupper((string) $brgy->placeDescription?->urban_rural)) {
                        'U' => 'Urban',
                        'R' => 'Rural',
                        default => null,
                    },
                    'health_facilities_count' => $healthCount,
                    'schools_count' => $schoolsCount,
                    'safety_facilities_count' => $safetyCount,
                    'telecom_sites_count' => $telecomCount,
                    'total_facilities_count' => $healthCount + $schoolsCount + $safetyCount + $telecomCount,
                ];
            }
        } elseif ($prefix !== '' && Schema::hasTable('place_descriptions') && Schema::hasTable('population_figures')) {
            // Fallback when the `barangays` table has no rows for this prefix:
            // read the place directly from place_descriptions (level = Bgy) and
            // left join the figure for the active collection year.
            //
            // The year condition lives in the join rather than the WHERE, so a
            // barangay with no figure for the active year still appears as
            // "unmeasured" instead of dropping out of the breakdown.
            $activeYear = CollectionYear::activeYear();
            $latestReleaseId = PlaceDescription::latestReleaseId();

            $placeQuery = PlaceDescription::query()
                // Both tables carry psg_code, so every bare column in the
                // WHERE has to name its table or MySQL refuses the query.
                ->where('place_descriptions.level', PlaceDescription::LEVEL_BARANGAY)
                ->where('place_descriptions.psg_code', 'like', $prefix.'%')
                ->leftJoin('population_figures', function ($join) use ($activeYear) {
                    $join->on('population_figures.place_description_id', '=', 'place_descriptions.id')
                        ->where('population_figures.year', $activeYear);
                })
                ->select([
                    'place_descriptions.psg_code',
                    'place_descriptions.name',
                    'place_descriptions.urban_rural',
                    'population_figures.population',
                ]);

            if ($latestReleaseId > 0) {
                $placeQuery->where('place_descriptions.release_id', $latestReleaseId);
            }

            foreach ($placeQuery->get() as $fig) {
                $psgCode = (string) $fig->psg_code;
                $telecomCount = $telecomByPsgc[$psgCode] ?? 0;

                $rawRows[] = [
                    'psg_code' => $psgCode,
                    'name' => trim((string) $fig->name),
                    'population' => $fig->population !== null ? (int) $fig->population : null,
                    'area_km2' => null,
                    'density' => null,
                    'urban_rural' => match (strtoupper((string) $fig->urban_rural)) {
                        'U' => 'Urban',
                        'R' => 'Rural',
                        default => null,
                    },
                    'health_facilities_count' => 0,
                    'schools_count' => 0,
                    'safety_facilities_count' => 0,
                    'telecom_sites_count' => $telecomCount,
                    'total_facilities_count' => $telecomCount,
                ];
            }
        }

        $sumPop = array_sum(array_map(fn ($r) => $r['population'] ?? 0, $rawRows));
        $denominatorPop = ($cityPopulation !== null && $cityPopulation > 0) ? $cityPopulation : $sumPop;

        $populatedRows = array_values(array_filter($rawRows, fn ($r) => ($r['population'] ?? 0) > 0));
        $avgBarangayPop = count($populatedRows) > 0
            ? $sumPop / count($populatedRows)
            : 0;

        $rows = [];
        $highPriorityCount = 0;
        $underservedBarangaysCount = 0;
        $underservedPopulation = 0;
        $urbanCount = 0;
        $ruralCount = 0;

        foreach ($rawRows as $row) {
            if ($row['urban_rural'] === 'Urban') {
                $urbanCount++;
            } elseif ($row['urban_rural'] === 'Rural') {
                $ruralCount++;
            }

            $pop = $row['population'];
            $share = ($pop !== null && $denominatorPop > 0)
                ? round($pop / $denominatorPop * 100, 2)
                : null;

            $relevantFacilities = match ($focus) {
                IndicatorCatalog::DIMENSION_HEALTH => $row['health_facilities_count'],
                IndicatorCatalog::DIMENSION_EDUCATION => $row['schools_count'],
                IndicatorCatalog::DIMENSION_CONNECTIVITY => $row['telecom_sites_count'],
                IndicatorCatalog::DIMENSION_DRR_SAFETY => $row['safety_facilities_count'],
                default => $row['total_facilities_count'],
            };

            if ($pop === null) {
                $priority = 'unmeasured';
                $priorityReason = 'No matched population figure.';
            } elseif ($relevantFacilities === 0 && $pop >= $avgBarangayPop) {
                $priority = 'high';
                $priorityReason = 'Above-average population with 0 recorded local facilities.';
                $highPriorityCount++;
                $underservedBarangaysCount++;
                $underservedPopulation += $pop;
            } elseif ($relevantFacilities === 0) {
                $priority = 'moderate';
                $priorityReason = '0 recorded local facilities.';
                $underservedBarangaysCount++;
                $underservedPopulation += $pop;
            } else {
                $priority = 'covered';
                $priorityReason = "{$relevantFacilities} recorded local ".($relevantFacilities === 1 ? 'facility' : 'facilities').'.';
            }

            $rows[] = [
                ...$row,
                'population_share' => $share,
                'priority_level' => $priority,
                'priority_reason' => $priorityReason,
            ];
        }

        // Default sort: highest population first, then name alphabetically.
        usort($rows, function (array $a, array $b): int {
            $popCmp = ($b['population'] ?? -1) <=> ($a['population'] ?? -1);

            return $popCmp !== 0 ? $popCmp : strcasecmp($a['name'], $b['name']);
        });

        $mostPopulous = $rows[0] ?? null;
        if ($mostPopulous !== null && $mostPopulous['population'] === null) {
            $mostPopulous = null;
        }

        $byDensity = array_values(array_filter($rows, fn ($r) => $r['density'] !== null));
        usort($byDensity, fn ($a, $b) => ($b['density'] ?? 0) <=> ($a['density'] ?? 0));
        $highestDensity = $byDensity[0] ?? null;

        return [
            'summary' => [
                'total_barangays' => count($rows),
                'total_population' => $sumPop,
                'average_population' => count($populatedRows) > 0 ? (int) round($avgBarangayPop) : null,
                'urban_count' => $urbanCount,
                'rural_count' => $ruralCount,
                'high_priority_count' => $highPriorityCount,
                'underserved_barangays_count' => $underservedBarangaysCount,
                'underserved_population' => $underservedPopulation,
                'underserved_population_share' => $denominatorPop > 0
                    ? round($underservedPopulation / $denominatorPop * 100, 1)
                    : null,
                'most_populous' => $mostPopulous ? [
                    'name' => $mostPopulous['name'],
                    'population' => $mostPopulous['population'],
                    'population_share' => $mostPopulous['population_share'],
                ] : null,
                'highest_density' => $highestDensity ? [
                    'name' => $highestDensity['name'],
                    'density' => $highestDensity['density'],
                    'population' => $highestDensity['population'],
                ] : null,
            ],
            'barangays' => $rows,
        ];
    }

    /**
     * Accredited DOST / OneLab laboratories located in the municipality and their services.
     *
     * @return list<array<string, mixed>>
     */
    private function buildLocalLaboratories(Municipality $municipality): array
    {
        if (! Schema::hasTable('laboratories')) {
            return [];
        }

        $hasLabServices = Schema::hasTable('lab_services');

        $query = $municipality->laboratories();
        if ($hasLabServices) {
            $query->with('services');
        }

        return $query->get()->map(function (Laboratory $lab) use ($hasLabServices) {
            $services = $hasLabServices
                ? $lab->services
                    ->map(fn ($s) => [
                        'test_name' => $s->service_name ?? $s->test_name ?? $s->name ?? 'Service',
                        'method' => $s->description ?? $s->method ?? null,
                        'reference' => $s->reference ?? null,
                        'fee' => $s->fee !== null ? (float) $s->fee : null,
                    ])
                    ->unique(fn ($s) => $s['test_name'].'|'.$s['method'])
                    ->values()
                : collect();

            return [
                'id' => $lab->id,
                'agency_name' => $lab->agency_name,
                'code' => $lab->code,
                'city' => $lab->city,
                'website' => $lab->website,
                'contact_information' => $lab->contact_information,
                'total_services' => $services->count() ?: (int) ($lab->services_count ?? 0),
                'services' => $services->take(12)->all(),
            ];
        })->values()->all();
    }

    /**
     * DOST technologies from dost_techs matched to the selected assessment focus and location.
     *
     * @return list<array<string, mixed>>
     */
    private function buildDostInterventions(Municipality $municipality, string $focus): array
    {
        if (! Schema::hasTable('dost_techs')) {
            return [];
        }

        $cityName = strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', (string) $municipality->name));
        $cityToken = trim(str_ireplace(['city of', 'city'], '', $cityName));

        $sectorKeywords = match ($focus) {
            IndicatorCatalog::DIMENSION_HEALTH => ['health', 'nutrition', 'medical', 'water', 'sanitation', 'biomedical'],
            IndicatorCatalog::DIMENSION_EDUCATION => ['education', 'learning', 'school', 'stem', 'starbooks', 'training'],
            IndicatorCatalog::DIMENSION_STI => ['testing', 'lab', 'calibration', 'research', 'innovation', 'msme', 'manufacturing', 'setup', 'food', 'ict'],
            IndicatorCatalog::DIMENSION_POPULATION => ['livelihood', 'community', 'drr', 'disaster', 'water', 'food', 'health'],
            'economy_and_livelihood' => ['msme', 'livelihood', 'food', 'enterprise', 'setup', 'manufacturing'],
            'agriculture_fisheries_food' => ['agriculture', 'urban farming', 'food', 'aquaculture', 'hydroponics'],
            'infrastructure_and_connectivity' => ['ict', 'connectivity', 'digital', 'smart', 'sensor', 'energy'],
            'environment_climate_disaster' => ['drr', 'disaster', 'climate', 'flood', 'weather', 'environment', 'waste', 'water'],
            default => [],
        };

        return DostTech::query()
            ->get()
            ->map(function (DostTech $tech) use ($cityToken, $sectorKeywords) {
                $haystack = strtolower(implode(' ', [
                    $tech->sector,
                    $tech->name,
                    $tech->mechanism,
                    $tech->outcome,
                ]));

                $clusters = (string) $tech->deployment_clusters;
                $matchesCity = $cityToken !== '' && stripos($clusters, $cityToken) !== false;

                $matchesFocus = $sectorKeywords === [];
                foreach ($sectorKeywords as $kw) {
                    if (str_contains($haystack, $kw)) {
                        $matchesFocus = true;
                        break;
                    }
                }

                return [
                    'id' => $tech->id,
                    'code' => $tech->code,
                    'name' => $tech->name,
                    'agency' => $tech->agency,
                    'sector' => $tech->sector,
                    'deployment_clusters' => $tech->deployment_clusters,
                    'mechanism' => $tech->mechanism,
                    'status' => $tech->status,
                    'outcome' => $tech->outcome,
                    'matches_city_cluster' => $matchesCity,
                    'matches_focus' => $matchesFocus,
                ];
            })
            ->filter(fn (array $t) => $t['matches_focus'] || $t['matches_city_cluster'])
            ->sortByDesc(fn (array $t) => ($t['matches_city_cluster'] ? 2 : 0) + ($t['matches_focus'] ? 1 : 0))
            ->values()
            ->all();
    }

    /**
     * Build map markers for DOST Laboratories, DOST Technologies, and local infrastructure.
     *
     * @return array{
     *     dost_labs: list<array<string, mixed>>,
     *     dost_techs: list<array<string, mixed>>,
     *     facilities: list<array<string, mixed>>,
     *     telecom_sites: list<array<string, mixed>>
     * }
     */
    private function buildMapMarkers(?Province $province, string $cityPsgCode): array
    {
        $selectedCity = $cityPsgCode !== ''
            ? Municipality::query()->where('psg_code', $cityPsgCode)->first()
            : null;

        return [
            'dost_labs' => $this->buildLabMarkers($province, $selectedCity),
            'dost_techs' => [],
            'facilities' => $this->buildFacilityMarkers($province, $selectedCity),
            'telecom_sites' => $this->buildTelecomMarkers($province, $selectedCity),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLabMarkers(?Province $province, ?Municipality $selectedCity): array
    {
        if (! Schema::hasTable('laboratories')) {
            return [];
        }

        $query = Laboratory::query()
            ->inPhilippines()
            ->with('municipalityModel:id,psg_code,name,province_id,center_lat,center_lng');

        if ($selectedCity !== null) {
            $cityName = strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', (string) $selectedCity->name));
            $cityToken = trim(str_ireplace(['city of', 'city'], '', $cityName));

            $query->where(function ($q) use ($selectedCity, $cityToken) {
                $q->where('municipality_id', $selectedCity->id);
                if ($cityToken !== '') {
                    $q->orWhere('city', 'like', '%'.$cityToken.'%');
                }
            });
        } elseif ($province !== null) {
            $isNcr = str_starts_with((string) $province->psg_code, '13');
            $provToken = trim((string) $province->name);

            $query->where(function ($q) use ($province, $isNcr, $provToken) {
                $q->whereHas('municipalityModel', fn ($mq) => $mq->where('province_id', $province->id));
                if ($provToken !== '') {
                    $q->orWhere('province', 'like', '%'.$provToken.'%');
                }
                if ($isNcr) {
                    $q->orWhere('region', 'like', '%NCR%')
                        ->orWhere('province', 'like', '%Metro Manila%');
                }
            });
        }

        $labs = $query->get();
        $markers = [];

        foreach ($labs as $index => $lab) {
            $lat = $lab->latitude !== null ? (float) $lab->latitude : null;
            $lng = $lab->longitude !== null ? (float) $lab->longitude : null;

            if (($lat === null || $lng === null) && $lab->municipalityModel !== null) {
                $mLat = $lab->municipalityModel->center_lat;
                $mLng = $lab->municipalityModel->center_lng;
                if ($mLat !== null && $mLng !== null) {
                    $angle = ($index * 137.5) * (M_PI / 180);
                    $radius = 0.0028;
                    $lat = round((float) $mLat + ($radius * cos($angle)), 6);
                    $lng = round((float) $mLng + ($radius * sin($angle)), 6);
                }
            }

            if (($lat === null || $lng === null) && $selectedCity !== null && $selectedCity->center_lat !== null && $selectedCity->center_lng !== null) {
                $angle = ($index * 137.5) * (M_PI / 180);
                $radius = 0.0028;
                $lat = round((float) $selectedCity->center_lat + ($radius * cos($angle)), 6);
                $lng = round((float) $selectedCity->center_lng + ($radius * sin($angle)), 6);
            }

            if ($lat === null || $lng === null) {
                continue;
            }

            $markers[] = [
                'id' => 'lab-'.$lab->id,
                'category' => 'dost_lab',
                'title' => (string) ($lab->agency_name ?: ($lab->code ?: 'DOST Laboratory')),
                'code' => $lab->code,
                'subtitle' => trim(implode(', ', array_filter([$lab->city, $lab->province]))),
                'services_count' => (int) ($lab->services_count ?? 0),
                'website' => $lab->website,
                'contact' => $lab->contact_information,
                'lat' => $lat,
                'lng' => $lng,
            ];
        }

        return $markers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildDostTechMarkers(?Province $province, ?Municipality $selectedCity): array
    {
        if (! Schema::hasTable('dost_techs')) {
            return [];
        }

        $techs = DostTech::query()->get();
        if ($techs->isEmpty()) {
            return [];
        }

        if ($selectedCity !== null) {
            $candidateCities = collect([$selectedCity]);
        } elseif ($province !== null) {
            $candidateCities = Municipality::query()
                ->where('province_id', $province->id)
                ->whereNotNull('center_lat')
                ->whereNotNull('center_lng')
                ->get(['id', 'psg_code', 'name', 'center_lat', 'center_lng']);
        } else {
            // NCR municipalities where dost_techs deployment clusters are anchored.
            $candidateCities = Municipality::query()
                ->where('psg_code', 'like', '13%')
                ->whereNotNull('center_lat')
                ->whereNotNull('center_lng')
                ->get(['id', 'psg_code', 'name', 'center_lat', 'center_lng']);

            if ($candidateCities->isEmpty()) {
                $candidateCities = Municipality::query()
                    ->whereNotNull('center_lat')
                    ->whereNotNull('center_lng')
                    ->limit(30)
                    ->get(['id', 'psg_code', 'name', 'center_lat', 'center_lng']);
            }
        }

        $markers = [];
        $cityCounters = [];

        foreach ($techs as $tech) {
            $clustersRaw = strtolower((string) $tech->deployment_clusters);
            $isRegionWide = str_contains($clustersRaw, 'all ncr')
                || str_contains($clustersRaw, 'metro manila')
                || str_contains($clustersRaw, 'region-wide');

            foreach ($candidateCities as $mun) {
                if ($mun->center_lat === null || $mun->center_lng === null) {
                    continue;
                }

                $cityName = strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', (string) $mun->name));
                $cityToken = trim(str_ireplace(['city of', 'city'], '', $cityName));
                $asciiToken = str_replace(['ñ', 'Ñ'], 'n', $cityToken);

                $matches = $isRegionWide
                    || ($cityToken !== '' && str_contains($clustersRaw, $cityToken))
                    || ($asciiToken !== '' && str_contains($clustersRaw, $asciiToken));

                if (! $matches) {
                    continue;
                }

                $slot = $cityCounters[$mun->id] ?? 0;
                $cityCounters[$mun->id] = $slot + 1;

                // Arrange DOST tech markers in a clean golden-angle spiral around the municipality center.
                $angle = ($slot * 137.5) * (M_PI / 180);
                $radius = 0.0042 + ($slot * 0.00045);
                $lat = round((float) $mun->center_lat + ($radius * cos($angle)), 6);
                $lng = round((float) $mun->center_lng + ($radius * sin($angle)), 6);

                $markers[] = [
                    'id' => 'tech-'.$tech->id.'-'.$mun->psg_code,
                    'category' => 'dost_tech',
                    'title' => (string) $tech->name,
                    'code' => $tech->code,
                    'agency' => $tech->agency,
                    'sector' => $tech->sector,
                    'status' => $tech->status,
                    'city' => $mun->name,
                    'deployment_clusters' => $tech->deployment_clusters,
                    'mechanism' => $tech->mechanism,
                    'outcome' => $tech->outcome,
                    'lat' => $lat,
                    'lng' => $lng,
                ];
            }
        }

        return $markers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFacilityMarkers(?Province $province, ?Municipality $selectedCity): array
    {
        if (! Schema::hasTable('facilities')) {
            return [];
        }

        $query = Facility::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        if ($selectedCity !== null) {
            $query->where('municipality_id', $selectedCity->id);
        } elseif ($province !== null) {
            $query->whereHas('municipalityModel', fn ($mq) => $mq->where('province_id', $province->id));
        } else {
            return [];
        }

        return $query
            ->limit(300)
            ->get(['id', 'name', 'sector', 'facility_type', 'level', 'address', 'latitude', 'longitude'])
            ->map(fn (Facility $f) => [
                'id' => 'fac-'.$f->id,
                'category' => 'facility',
                'title' => (string) ($f->name ?: ucfirst((string) $f->facility_type)),
                'sector' => $f->sector,
                'facility_type' => $f->facility_type,
                'level' => $f->level,
                'subtitle' => $f->address,
                'lat' => (float) $f->latitude,
                'lng' => (float) $f->longitude,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildTelecomMarkers(?Province $province, ?Municipality $selectedCity): array
    {
        if (! Schema::hasTable('telecom_sites')) {
            return [];
        }

        $query = TelecomSite::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        if ($selectedCity !== null) {
            $prefix = str_starts_with((string) $selectedCity->psg_code, '13806')
                ? substr((string) $selectedCity->psg_code, 0, 5)
                : substr((string) $selectedCity->psg_code, 0, 7);

            $query->where(function ($q) use ($selectedCity, $prefix) {
                $q->where('municipality_id', $selectedCity->id);
                if ($prefix !== '') {
                    $q->orWhere('barangay_psgc', 'like', $prefix.'%');
                }
            });
        } elseif ($province !== null) {
            $provPrefix = substr((string) $province->psg_code, 0, 4);

            $query->where(function ($q) use ($province, $provPrefix) {
                $q->whereHas('municipality', fn ($mq) => $mq->where('province_id', $province->id));
                if ($provPrefix !== '') {
                    $q->orWhere('barangay_psgc', 'like', $provPrefix.'%');
                }
            });
        }

        return $query
            ->limit(350)
            ->get(['id', 'name', 'operator', 'tower_company', 'barangay_name', 'technologies', 'latitude', 'longitude'])
            ->map(fn (TelecomSite $t) => [
                'id' => 'tel-'.$t->id,
                'category' => 'telecom',
                'title' => (string) ($t->name ?: ($t->operator ?: 'Telecom Cell Site')),
                'operator' => $t->operator,
                'tower_company' => $t->tower_company,
                'subtitle' => $t->barangay_name,
                'technologies' => is_array($t->technologies) ? implode(', ', $t->technologies) : (string) $t->technologies,
                'lat' => (float) $t->latitude,
                'lng' => (float) $t->longitude,
            ])
            ->values()
            ->all();
    }
}
