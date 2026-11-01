<?php

namespace App\Support\Assessment;

use App\Models\Barangay;
use App\Models\DostProject;
use App\Models\DostTech;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Technology;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Layer D Function Tools:
 * - search_dost_technologies
 * - get_local_dost_projects
 *
 * Pure deterministic queries across DostTech (18 rows), Technology (11 rows),
 * and DostProject (1,961 rows across 10 DOST agencies).
 */
class DostCatalogToolService
{
    /**
     * Tool: search_dost_technologies
     *
     * Queries DostTech, Technology, and DostProject with optional filters:
     * - keyword (string)
     * - sector (string)
     * - agency (string)
     * - tech_status ('available'|'limited'|'research_stage'|'unavailable'|'unknown')
     * - limit (int, default 30)
     *
     * @param  array{keyword?: string|null, sector?: string|null, agency?: string|null, tech_status?: string|null, limit?: int|null}  $filters
     * @return array{
     *     total_matched: int,
     *     sources_queried: list<string>,
     *     records: list<array<string, mixed>>
     * }
     */
    public function searchDostTechnologies(array $filters = []): array
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $sector = trim((string) ($filters['sector'] ?? ''));
        $agency = trim((string) ($filters['agency'] ?? ''));
        $statusFilter = trim((string) ($filters['tech_status'] ?? ''));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 30)));

        $sources = [];
        $records = [];

        // 1. dost_techs (18 rows)
        if (Schema::hasTable('dost_techs')) {
            $sources[] = 'dost_techs';
            $query = DostTech::query();

            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword) {
                    $q->where('name', 'like', '%'.$keyword.'%')
                        ->orWhere('sector', 'like', '%'.$keyword.'%')
                        ->orWhere('mechanism', 'like', '%'.$keyword.'%')
                        ->orWhere('outcome', 'like', '%'.$keyword.'%');
                });
            }
            if ($sector !== '') {
                $query->where('sector', 'like', '%'.$sector.'%');
            }
            if ($agency !== '') {
                $query->where('agency', 'like', '%'.$agency.'%');
            }

            foreach ($query->limit($limit)->get() as $tech) {
                $normalizedStatus = self::normalizeTechStatus($tech->status);
                if ($statusFilter !== '' && $normalizedStatus !== $statusFilter) {
                    continue;
                }

                $records[] = [
                    'source_table' => 'dost_techs',
                    'id' => (int) $tech->id,
                    'code' => $tech->code,
                    'title' => $tech->name,
                    'agency' => $tech->agency,
                    'sector' => $tech->sector,
                    'description' => $tech->mechanism,
                    'expected_outcome' => $tech->outcome,
                    'location_scope' => $tech->deployment_clusters,
                    'raw_status' => $tech->status,
                    'tech_status' => $normalizedStatus,
                    'budget_php' => null,
                ];
            }
        }

        // 2. technologies (11 rows)
        if (Schema::hasTable('technologies')) {
            $sources[] = 'technologies';
            $cols = Schema::getColumnListing('technologies');
            $query = Technology::query();

            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword, $cols) {
                    foreach (['name', 'title', 'sector', 'description', 'mechanism'] as $col) {
                        if (in_array($col, $cols, true)) {
                            $q->orWhere($col, 'like', '%'.$keyword.'%');
                        }
                    }
                });
            }
            if ($sector !== '' && in_array('sector', $cols, true)) {
                $query->where('sector', 'like', '%'.$sector.'%');
            }
            if ($agency !== '' && in_array('agency', $cols, true)) {
                $query->where('agency', 'like', '%'.$agency.'%');
            }

            foreach ($query->limit($limit)->get() as $row) {
                $rawStatus = $row->status ?? $row->tech_status ?? null;
                $normalizedStatus = self::normalizeTechStatus($rawStatus);
                if ($statusFilter !== '' && $normalizedStatus !== $statusFilter) {
                    continue;
                }

                $records[] = [
                    'source_table' => 'technologies',
                    'id' => (int) $row->id,
                    'code' => $row->code ?? null,
                    'title' => $row->name ?? $row->title ?? 'Technology',
                    'agency' => $row->agency ?? null,
                    'sector' => $row->sector ?? null,
                    'description' => $row->description ?? $row->mechanism ?? null,
                    'expected_outcome' => $row->outcome ?? null,
                    'location_scope' => $row->deployment_clusters ?? $row->location ?? null,
                    'raw_status' => $rawStatus,
                    'tech_status' => $normalizedStatus,
                    'budget_php' => null,
                ];
            }
        }

        // 3. dost_projects (1,961 rows across 10 DOST agencies)
        if (Schema::hasTable('dost_projects')) {
            $sources[] = 'dost_projects';
            $query = DostProject::query();

            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword) {
                    $q->where('project_title', 'like', '%'.$keyword.'%')
                        ->orWhere('program', 'like', '%'.$keyword.'%')
                        ->orWhere('objectives', 'like', '%'.$keyword.'%')
                        ->orWhere('description', 'like', '%'.$keyword.'%')
                        ->orWhere('sector', 'like', '%'.$keyword.'%');
                });
            }
            if ($sector !== '') {
                $query->where('sector', 'like', '%'.$sector.'%');
            }
            if ($agency !== '') {
                $query->where('agency', 'like', '%'.$agency.'%');
            }

            foreach ($query->limit($limit)->get() as $proj) {
                $normalizedStatus = self::normalizeProjectStatus($proj->project_status);
                if ($statusFilter !== '' && $normalizedStatus !== $statusFilter) {
                    continue;
                }

                $records[] = [
                    'source_table' => 'dost_projects',
                    'id' => (int) $proj->id,
                    'code' => $proj->program ?: 'DOST-PROJ-'.$proj->id,
                    'title' => $proj->project_title,
                    'agency' => $proj->agency,
                    'sector' => $proj->sector,
                    'description' => $proj->description ?: $proj->objectives,
                    'expected_outcome' => $proj->accomplishment ?: $proj->objectives,
                    'location_scope' => $proj->location,
                    'raw_status' => $proj->project_status,
                    'tech_status' => $normalizedStatus,
                    'budget_php' => $proj->budget !== null ? (float) $proj->budget : null,
                    'implementing' => $proj->implementing,
                    'cooperating' => $proj->cooperating,
                ];
            }
        }

        $sliced = array_slice($records, 0, $limit);

        return [
            'total_matched' => count($records),
            'sources_queried' => $sources,
            'records' => $sliced,
        ];
    }

    /**
     * Tool: get_local_dost_projects
     *
     * Retrieves historical and ongoing DOST projects (`dost_projects`) and catalog
     * deployments (`dost_techs`) whose location text matches the selected municipality
     * or province.
     *
     * @return array<string, mixed>
     */
    public function getLocalDostProjects(Model $location): array
    {
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
        $byStatus = [];
        $totalBudget = 0.0;

        if (Schema::hasTable('dost_projects') && ($cityToken !== '' || $provToken !== '')) {
            $query = DostProject::query()->where(function ($q) use ($cityToken, $provToken) {
                if ($cityToken !== '') {
                    $q->where('location', 'like', '%'.$cityToken.'%');
                }
                if ($provToken !== '') {
                    $q->orWhere('location', 'like', '%'.$provToken.'%');
                }
            });

            $matchedRows = $query->limit(100)->get();

            foreach ($matchedRows as $proj) {
                $agency = (string) ($proj->agency ?: 'Unspecified');
                $status = self::normalizeProjectStatus($proj->project_status);
                $byAgency[$agency] = ($byAgency[$agency] ?? 0) + 1;
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
                    'accomplishment' => $proj->accomplishment,
                ];
            }
        }

        return [
            'search_tokens' => array_values(array_filter([$cityToken, $provToken])),
            'total_matched_projects' => count($projects),
            'total_recorded_budget_php' => round($totalBudget, 2),
            'by_agency' => $byAgency,
            'by_status' => $byStatus,
            'projects' => array_slice($projects, 0, 30),
        ];
    }

    /**
     * Normalize `dost_techs.status` / `technologies.status` into Instruction Step 6 `tech_status` values:
     * `available`, `limited`, `research_stage`, `unavailable`, `discontinued`, `unknown`.
     */
    public static function normalizeTechStatus(?string $rawStatus): string
    {
        if ($rawStatus === null || trim($rawStatus) === '') {
            return 'unknown';
        }

        $lower = strtolower(trim($rawStatus));

        if (str_contains($lower, 'discontinued') || str_contains($lower, 'phased out')) {
            return 'discontinued';
        }
        if (str_contains($lower, 'unavailable')) {
            return 'unavailable';
        }
        if (str_contains($lower, 'pilot') || str_contains($lower, 'limited') || str_contains($lower, 'selected')) {
            return 'limited';
        }
        if (str_contains($lower, 'r&d') || str_contains($lower, 'research') || str_contains($lower, 'prototype') || str_contains($lower, 'testing')) {
            return 'research_stage';
        }
        if (
            str_contains($lower, 'available')
            || str_contains($lower, 'deployed')
            || str_contains($lower, 'operational')
            || str_contains($lower, 'active')
            || str_contains($lower, 'commercial')
            || str_contains($lower, 'continuing')
            || str_contains($lower, 'completed')
            || str_contains($lower, 'comleted')
        ) {
            return 'available';
        }

        return 'unknown';
    }

    /**
     * Normalize `dost_projects.project_status` (note: 1,322 rows in DB are spelled `"comleted"`).
     */
    public static function normalizeProjectStatus(?string $rawStatus): string
    {
        if ($rawStatus === null || trim($rawStatus) === '') {
            return 'unknown';
        }

        $lower = strtolower(trim($rawStatus));

        if ($lower === 'comleted' || str_contains($lower, 'complet')) {
            return 'available';
        }
        if (str_contains($lower, 'on-going') || str_contains($lower, 'ongoing') || str_contains($lower, 'new')) {
            return 'research_stage';
        }
        if (str_contains($lower, 'terminated') || str_contains($lower, 'deferred') || str_contains($lower, 'suspended')) {
            return 'unavailable';
        }

        return self::normalizeTechStatus($rawStatus);
    }

    private function cleanLocationToken(string $rawName): string
    {
        $spaced = strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $rawName));

        return trim(str_ireplace(['city of', 'municipality of', 'city'], '', $spaced));
    }
}

