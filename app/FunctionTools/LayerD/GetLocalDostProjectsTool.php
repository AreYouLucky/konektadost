<?php

namespace App\FunctionTools\LayerD;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\FunctionTools\Support\LocationResolver;
use App\Models\Barangay;
use App\Models\DostProject;
use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Layer D (D2) Function Tool: get_local_dost_projects
 *
 * Retrieves historical and ongoing DOST projects matching a municipality or province.
 * Clearly distinguishes historical projects from ongoing/active programs.
 * Note: Historical project participation in a locality does not guarantee future funding or eligibility.
 */
class GetLocalDostProjectsTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'get_local_dost_projects';
    }

    public function layer(): string
    {
        return 'D';
    }

    public function description(): string
    {
        return 'Retrieve historical and ongoing DOST projects matching a municipality or province, with agency, program, sector, timeline, budget, status classification, and accomplishment details.';
    }

    public function parameters(): array
    {
        return [
            ...LocationResolver::locationParameterSchema(),
            'agency' => [
                'type' => 'string',
                'description' => 'Optional filter by DOST agency (e.g. ITDI, PCAARRD, FNRI, MIRDC, PCIEERD, PCHRD, ASTI).',
            ],
            'sector' => [
                'type' => 'string',
                'description' => 'Optional filter by project sector.',
            ],
            'program' => [
                'type' => 'string',
                'description' => 'Optional filter by program code or title.',
            ],
            'status_type' => [
                'type' => 'string',
                'enum' => ['all', 'historical', 'ongoing'],
                'description' => 'Filter by project lifecycle stage (historical completed vs. active ongoing).',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of projects to return (default 50, max 200).',
            ],
        ];
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

        return $this->forLocation($location, $args);
    }

    /**
     * Retrieve local DOST projects for a resolved location model.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forLocation(Model $location, array $options = []): array
    {
        $agencyFilter = trim((string) ($options['agency'] ?? ''));
        $sectorFilter = trim((string) ($options['sector'] ?? ''));
        $programFilter = trim((string) ($options['program'] ?? ''));
        $statusType = trim((string) ($options['status_type'] ?? 'all'));
        $limit = max(1, min(200, (int) ($options['limit'] ?? 50)));

        $cityName = $location instanceof Barangay
            ? (string) ($location->municipality?->name ?? $location->brgy_name)
            : (string) ($location->name ?? '');
        $provinceName = match (true) {
            $location instanceof Municipality => (string) ($location->province_name ?? $location->provinceModel?->name ?? ''),
            $location instanceof Barangay => (string) ($location->municipality?->province_name ?? ''),
            $location instanceof Province => (string) $location->name,
            default => '',
        };

        $cityToken = $this->cleanLocationToken($cityName);
        $provToken = $this->cleanLocationToken($provinceName);

        $projects = [];
        $byAgency = [];
        $bySector = [];
        $byProgram = [];
        $byStatus = [];
        $totalBudget = 0.0;
        $historicalCount = 0;
        $ongoingCount = 0;

        if (Schema::hasTable('dost_projects') && ($cityToken !== '' || $provToken !== '')) {
            $query = DostProject::query()->where(function ($q) use ($cityToken, $provToken) {
                if ($cityToken !== '') {
                    $q->where('location', 'like', '%'.$cityToken.'%');
                }
                if ($provToken !== '') {
                    $q->orWhere('location', 'like', '%'.$provToken.'%');
                }
            });

            if ($agencyFilter !== '') {
                $query->where('agency', 'like', '%'.$agencyFilter.'%');
            }
            if ($sectorFilter !== '') {
                $query->where('sector', 'like', '%'.$sectorFilter.'%');
            }
            if ($programFilter !== '') {
                $query->where(function ($q) use ($programFilter) {
                    $q->where('program', 'like', '%'.$programFilter.'%')
                        ->orWhere('project_title', 'like', '%'.$programFilter.'%');
                });
            }

            $matchedRows = $query->limit($limit * 3)->get();

            foreach ($matchedRows as $proj) {
                $status = SearchDostTechnologiesTool::normalizeProjectStatus($proj->project_status);
                $isHistorical = str_contains(strtolower((string) $proj->project_status), 'complet')
                    || $status === 'available'
                    || ($proj->end_date instanceof \DateTimeInterface && $proj->end_date->format('Y-m-d') < date('Y-m-d'));

                $statusCategory = $isHistorical ? 'historical_completed' : 'active_ongoing';

                if ($statusType === 'historical' && ! $isHistorical) {
                    continue;
                }
                if ($statusType === 'ongoing' && $isHistorical) {
                    continue;
                }

                if ($isHistorical) {
                    $historicalCount++;
                } else {
                    $ongoingCount++;
                }

                $agency = (string) ($proj->agency ?: 'Unspecified');
                $sector = (string) ($proj->sector ?: 'Unspecified');
                $program = (string) ($proj->program ?: 'General');

                $byAgency[$agency] = ($byAgency[$agency] ?? 0) + 1;
                $bySector[$sector] = ($bySector[$sector] ?? 0) + 1;
                $byProgram[$program] = ($byProgram[$program] ?? 0) + 1;
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

                if ($proj->budget !== null && (float) $proj->budget > 0) {
                    $totalBudget += (float) $proj->budget;
                }

                $projects[] = [
                    'id' => (int) $proj->id,
                    'agency' => $proj->agency,
                    'program' => $proj->program,
                    'project_title' => $proj->project_title,
                    'sector' => $proj->sector,
                    'location' => $proj->location,
                    'status_category' => $statusCategory,
                    'raw_status' => $proj->project_status,
                    'normalized_status' => $status,
                    'budget_php' => $proj->budget !== null ? (float) $proj->budget : null,
                    'start_date' => $proj->start_date instanceof \DateTimeInterface
                        ? $proj->start_date->format('Y-m-d')
                        : ($proj->start_date !== null ? (string) $proj->start_date : null),
                    'end_date' => $proj->end_date instanceof \DateTimeInterface
                        ? $proj->end_date->format('Y-m-d')
                        : ($proj->end_date !== null ? (string) $proj->end_date : null),
                    'implementing' => $proj->implementing,
                    'cooperating' => $proj->cooperating,
                    'monitoring' => $proj->monitoring,
                    'accomplishment' => $proj->accomplishment,
                ];
            }
        }

        return [
            'location_level' => LocationResolver::levelOf($location),
            'location_id' => (int) $location->id,
            'location_name' => LocationResolver::nameOf($location),
            'search_tokens' => array_values(array_filter([$cityToken, $provToken])),
            'total_matched_projects' => count($projects),
            'historical_projects_count' => $historicalCount,
            'ongoing_projects_count' => $ongoingCount,
            'total_recorded_budget_php' => round($totalBudget, 2),
            'summary_facets' => [
                'by_agency' => $byAgency,
                'by_sector' => $bySector,
                'by_program' => $byProgram,
                'by_status' => $byStatus,
            ],
            'projects' => array_slice($projects, 0, $limit),
            'disclaimer' => 'Historical project participation in this locality does not guarantee future funding, eligibility, or program continuation.',
        ];
    }

    private function cleanLocationToken(string $rawName): string
    {
        $spaced = strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $rawName));

        return trim(str_ireplace(['city of', 'municipality of', 'city'], '', $spaced));
    }
}

