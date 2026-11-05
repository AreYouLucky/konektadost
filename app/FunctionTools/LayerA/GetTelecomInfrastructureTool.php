<?php

namespace App\FunctionTools\LayerA;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Barangay;
use App\Models\Municipality;
use App\Models\TelecomSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Layer A (A5) Function Tool: get_telecom_infrastructure
 *
 * Retrieves TelecomSite records, operators, tower companies, colocated site counts,
 * radio technologies (2G, 3G, 4G, 5G), frequency bands, structure types,
 * barangay distribution, and unmapped site flags.
 */
class GetTelecomInfrastructureTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'get_telecom_infrastructure';
    }

    public function layer(): string
    {
        return 'A';
    }

    public function description(): string
    {
        return 'Retrieve telecommunications cell sites (TelecomSite), operators, tower companies, 2G/3G/4G/5G radio technologies, frequency bands, colocation status, and barangay-level distribution for a municipality.';
    }

    public function parameters(): array
    {
        $schema = LocationResolver::locationParameterSchema();
        $schema['properties']['include_site_records'] = [
            'type' => 'boolean',
            'description' => 'Whether to include the individual TelecomSite records (default: true).',
        ];

        return $schema;
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'layer' => $this->layer(),
            'description' => $this->description(),
            'parameters' => $this->parameters(),
        ];
    }

    public function execute(array $args): array
    {
        $location = LocationResolver::resolve($args);
        $includeSiteRecords = (bool) ($args['include_site_records'] ?? true);

        return $this->forLocation($location, $includeSiteRecords);
    }

    /**
     * Retrieve telecom infrastructure details for a resolved location model.
     *
     * @return array<string, mixed>
     */
    public function forLocation(Model $location, bool $includeSiteRecords = true): array
    {
        $level = LocationResolver::levelOf($location);
        $psgCode = (string) ($location->psg_code ?? '');

        if (! Schema::hasTable('telecom_sites')) {
            return [
                'location_level' => $level,
                'location_id' => (int) $location->id,
                'location_name' => LocationResolver::nameOf($location),
                'psg_code' => $psgCode !== '' ? $psgCode : null,
                'dataset_status' => 'unavailable',
                'notes' => 'The `telecom_sites` table is not present in the database.',
                'summary' => [
                    'total_sites' => 0,
                    'geocoded_sites' => 0,
                    'colocated_sites' => 0,
                    'independent_tower_sites' => 0,
                    'mapped_to_barangay_count' => 0,
                    'unmapped_barangay_count' => 0,
                    'sites_with_5g' => 0,
                    'sites_with_4g' => 0,
                    'sites_with_3g' => 0,
                    'sites_with_2g' => 0,
                    'share_5g_pct' => null,
                    'share_4g_pct' => null,
                ],
                'by_operator' => [],
                'by_tower_company' => [],
                'by_technology' => [],
                'by_band' => [],
                'by_structure_type' => [],
                'barangay_distribution' => null,
                'records' => [],
            ];
        }

        $datasetsTool = app(GetAvailableDatasetsTool::class);
        $sites = $datasetsTool->telecomQueryFor($location)->get();
        $totalSites = $sites->count();

        $barangayMap = [];
        if (
            $location instanceof Municipality
            && Schema::hasTable('barangays')
            && Schema::hasColumn('barangays', 'municipality_id')
            && Schema::hasColumn('barangays', 'brgy_name')
        ) {
            $barangayMap = Barangay::query()
                ->where('municipality_id', $location->id)
                ->get(['id', 'psg_code', 'brgy_name'])
                ->keyBy('id')
                ->all();
        }

        $geocoded = 0;
        $colocated = 0;
        $towerCoSites = 0;
        $mappedBgyCount = 0;
        $unmappedBgyCount = 0;
        $with5g = 0;
        $with4g = 0;
        $with3g = 0;
        $with2g = 0;

        $byOperator = [];
        $byTowerCompany = [];
        $byTechnology = [];
        $byBand = [];
        $byStructureType = [];
        $sitesByBarangayId = [];
        $unmappedSites = [];
        $records = [];

        foreach ($sites as $site) {
            /** @var TelecomSite $site */
            if ($site->latitude !== null && $site->longitude !== null) {
                $geocoded++;
            }

            if ($site->is_colocated) {
                $colocated++;
            }

            if ($site->isTowerCo()) {
                $towerCoSites++;
                $tc = trim((string) $site->tower_company);
                $byTowerCompany[$tc] = ($byTowerCompany[$tc] ?? 0) + 1;
            }

            $op = trim((string) ($site->operator ?: 'Unspecified'));
            $byOperator[$op] = ($byOperator[$op] ?? 0) + 1;

            $struct = trim((string) ($site->structure_type ?: 'Unspecified'));
            $byStructureType[$struct] = ($byStructureType[$struct] ?? 0) + 1;

            $techs = $site->technologyList();
            $has5g = false;
            $has4g = false;
            $has3g = false;
            $has2g = false;

            foreach ($techs as $t) {
                $tClean = trim((string) $t);
                if ($tClean === '') {
                    continue;
                }
                $byTechnology[$tClean] = ($byTechnology[$tClean] ?? 0) + 1;

                $upper = strtoupper($tClean);
                if (str_contains($upper, '5G')) {
                    $has5g = true;
                }
                if (str_contains($upper, '4G') || str_contains($upper, 'LTE')) {
                    $has4g = true;
                }
                if (str_contains($upper, '3G') || str_contains($upper, 'UMTS') || str_contains($upper, 'WCDMA') || str_contains($upper, 'HSPA')) {
                    $has3g = true;
                }
                if (str_contains($upper, '2G') || str_contains($upper, 'GSM')) {
                    $has2g = true;
                }
            }

            if ($has5g) {
                $with5g++;
            }
            if ($has4g) {
                $with4g++;
            }
            if ($has3g) {
                $with3g++;
            }
            if ($has2g) {
                $with2g++;
            }

            foreach ($site->bandList() as $band) {
                $bClean = trim((string) $band);
                if ($bClean !== '') {
                    $byBand[$bClean] = ($byBand[$bClean] ?? 0) + 1;
                }
            }

            if ($site->barangay_id !== null) {
                $mappedBgyCount++;
                $bId = (int) $site->barangay_id;
                $sitesByBarangayId[$bId] = ($sitesByBarangayId[$bId] ?? 0) + 1;
            } else {
                $unmappedBgyCount++;
                $unmappedSites[] = [
                    'id' => (int) $site->id,
                    'name' => $site->name,
                    'barangay_name_raw' => $site->barangay_name,
                    'barangay_psgc_raw' => $site->barangay_psgc,
                    'operator' => $site->operator,
                    'latitude' => $site->latitude,
                    'longitude' => $site->longitude,
                ];
            }

            if ($includeSiteRecords) {
                $bgyModel = $site->barangay_id !== null ? ($barangayMap[(int) $site->barangay_id] ?? null) : null;
                $records[] = [
                    'id' => (int) $site->id,
                    'name' => $site->name,
                    'district' => $site->district,
                    'barangay_id' => $site->barangay_id !== null ? (int) $site->barangay_id : null,
                    'barangay_name' => $bgyModel?->brgy_name ?? $site->barangay_name,
                    'barangay_psgc' => $bgyModel?->psg_code ?? $site->barangay_psgc,
                    'operator' => $site->operator,
                    'tower_company' => $site->tower_company,
                    'is_colocated' => (bool) $site->is_colocated,
                    'technologies' => $techs,
                    'bands' => $site->bandList(),
                    'structure_type' => $site->structure_type,
                    'latitude' => $site->latitude !== null ? (float) $site->latitude : null,
                    'longitude' => $site->longitude !== null ? (float) $site->longitude : null,
                    'is_unmapped_barangay' => $site->barangay_id === null,
                ];
            }
        }

        arsort($byOperator);
        arsort($byTowerCompany);
        arsort($byTechnology);
        arsort($byBand);
        arsort($byStructureType);

        $barangayDistribution = null;
        if ($location instanceof Municipality) {
            $totalBarangays = count($barangayMap);
            $withSitesList = [];
            $withoutSitesList = [];

            foreach ($barangayMap as $bId => $bModel) {
                $cnt = $sitesByBarangayId[$bId] ?? 0;
                $entry = [
                    'barangay_id' => (int) $bId,
                    'psg_code' => (string) $bModel->psg_code,
                    'name' => (string) $bModel->brgy_name,
                    'sites_count' => $cnt,
                ];

                if ($cnt > 0) {
                    $withSitesList[] = $entry;
                } else {
                    $withoutSitesList[] = [
                        'barangay_id' => (int) $bId,
                        'psg_code' => (string) $bModel->psg_code,
                        'name' => (string) $bModel->brgy_name,
                    ];
                }
            }

            usort($withSitesList, fn ($a, $b) => $b['sites_count'] <=> $a['sites_count']);

            $barangayDistribution = [
                'total_barangays' => $totalBarangays,
                'barangays_with_sites_count' => count($withSitesList),
                'barangays_without_sites_count' => count($withoutSitesList),
                'barangay_coverage_pct' => $totalBarangays > 0
                    ? round((count($withSitesList) / $totalBarangays) * 100, 1)
                    : null,
                'sites_by_barangay' => $withSitesList,
                'barangays_without_sites' => $withoutSitesList,
                'unmapped_barangay_sites' => $unmappedSites,
            ];
        }

        $datasetStatus = $totalSites === 0
            ? 'empty'
            : ($unmappedBgyCount > 0 ? 'partial' : 'available');

        return [
            'location_level' => $level,
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'psg_code' => $psgCode !== '' ? $psgCode : null,
            'dataset_status' => $datasetStatus,
            'notes' => $totalSites > 0
                ? sprintf(
                    '%d telecom site(s) recorded locally (%d with 5G, %d colocated, %d missing barangay_id).',
                    $totalSites,
                    $with5g,
                    $colocated,
                    $unmappedBgyCount
                )
                : '0 telecom sites found in `telecom_sites` for this location. Because the national table currently holds 155 rows across 3 imported Bohol municipalities, treat 0 as unimported data unless verified.',
            'summary' => [
                'total_sites' => $totalSites,
                'geocoded_sites' => $geocoded,
                'colocated_sites' => $colocated,
                'independent_tower_sites' => $towerCoSites,
                'mapped_to_barangay_count' => $mappedBgyCount,
                'unmapped_barangay_count' => $unmappedBgyCount,
                'sites_with_5g' => $with5g,
                'sites_with_4g' => $with4g,
                'sites_with_3g' => $with3g,
                'sites_with_2g' => $with2g,
                'share_5g_pct' => $totalSites > 0 ? round(($with5g / $totalSites) * 100, 1) : null,
                'share_4g_pct' => $totalSites > 0 ? round(($with4g / $totalSites) * 100, 1) : null,
            ],
            'by_operator' => $byOperator,
            'by_tower_company' => $byTowerCompany,
            'by_technology' => $byTechnology,
            'by_band' => $byBand,
            'by_structure_type' => $byStructureType,
            'barangay_distribution' => $barangayDistribution,
            'records' => $includeSiteRecords ? $records : [],
        ];
    }
}
