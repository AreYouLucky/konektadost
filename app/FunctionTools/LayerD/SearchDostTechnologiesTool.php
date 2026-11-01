<?php

namespace App\FunctionTools\LayerD;

use App\FunctionTools\Contracts\FunctionToolInterface;
use App\Models\DostProject;
use App\Models\DostTech;
use App\Models\Technology;
use Illuminate\Support\Facades\Schema;

/**
 * Layer D (D1) Function Tool: search_dost_technologies
 *
 * Searches DOST technologies and STI programs across three catalogs:
 * - `dost_techs` (18 masterlist technologies with mechanisms and outcomes)
 * - `technologies` (11 STI program catalog items with target audiences and tags)
 * - `dost_projects` (1,961 real agency projects across 10 DOST councils/institutes)
 *
 * Supports multi-criteria filtering: keyword, sector, agency, beneficiaries, tech_status.
 */
class SearchDostTechnologiesTool implements FunctionToolInterface
{
    public function name(): string
    {
        return 'search_dost_technologies';
    }

    public function layer(): string
    {
        return 'D';
    }

    public function description(): string
    {
        return 'Search DOST technology and STI program catalogs by keyword, sector, agency, problem addressed, application mechanism, intended beneficiaries, and technology readiness/availability status.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keyword' => [
                    'type' => 'string',
                    'description' => 'Text search across title, description, mechanism, objectives, application, and tags.',
                ],
                'sector' => [
                    'type' => 'string',
                    'description' => 'Filter by sector (e.g. Agriculture, Food, Health, Disaster/DRR, Manufacturing, IT, Energy).',
                ],
                'agency' => [
                    'type' => 'string',
                    'description' => 'Filter by DOST agency (e.g. FNRI, ITDI, PCHRD, PCIEERD, PCAARRD, ASTI, MIRDC, PNRI, PTRI, FPRDI, NRCP).',
                ],
                'beneficiaries' => [
                    'type' => 'string',
                    'description' => 'Filter by target beneficiaries or audience (e.g. MSMEs, Farmers, Communities, LGUs, Schools).',
                ],
                'tech_status' => [
                    'type' => 'string',
                    'enum' => ['available', 'limited', 'research_stage', 'unavailable', 'unknown'],
                    'description' => 'Filter by normalized technology availability or readiness status.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return (default 30, max 100).',
                ],
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
        $keyword = trim((string) ($args['keyword'] ?? ''));
        $sector = trim((string) ($args['sector'] ?? ''));
        $agency = trim((string) ($args['agency'] ?? ''));
        $beneficiaries = trim((string) ($args['beneficiaries'] ?? ''));
        $statusFilter = trim((string) ($args['tech_status'] ?? ''));
        $limit = max(1, min(100, (int) ($args['limit'] ?? 30)));

        $sourcesQueried = [];
        $records = [];
        $bySector = [];
        $byAgency = [];
        $byStatus = [];

        // 1. Query dost_techs (18 rows)
        if (Schema::hasTable('dost_techs')) {
            $sourcesQueried[] = 'dost_techs';
            $techQuery = DostTech::query();

            if ($keyword !== '') {
                $techQuery->where(function ($q) use ($keyword) {
                    $q->where('name', 'like', '%'.$keyword.'%')
                        ->orWhere('sector', 'like', '%'.$keyword.'%')
                        ->orWhere('mechanism', 'like', '%'.$keyword.'%')
                        ->orWhere('outcome', 'like', '%'.$keyword.'%');
                });
            }
            if ($sector !== '') {
                $techQuery->where('sector', 'like', '%'.$sector.'%');
            }
            if ($agency !== '') {
                $techQuery->where('agency', 'like', '%'.$agency.'%');
            }

            foreach ($techQuery->limit($limit)->get() as $tech) {
                $status = self::normalizeTechStatus($tech->status);
                if ($statusFilter !== '' && $status !== $statusFilter) {
                    continue;
                }

                $sec = (string) ($tech->sector ?: 'General');
                $agn = (string) ($tech->agency ?: 'DOST');
                $bySector[$sec] = ($bySector[$sec] ?? 0) + 1;
                $byAgency[$agn] = ($byAgency[$agn] ?? 0) + 1;
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

                $records[] = [
                    'source_catalog' => 'dost_techs',
                    'id' => (int) $tech->id,
                    'code' => $tech->code,
                    'title' => $tech->name,
                    'agency' => $tech->agency,
                    'sector' => $tech->sector,
                    'description' => $tech->mechanism,
                    'application_mechanism' => $tech->mechanism,
                    'expected_outcome' => $tech->outcome,
                    'intended_beneficiaries' => self::inferBeneficiaries($tech->sector, $tech->mechanism, $tech->name),
                    'deployment_clusters' => $tech->deployment_clusters,
                    'raw_status' => $tech->status,
                    'tech_status' => $status,
                    'budget_php' => null,
                ];
            }
        }

        // 2. Query technologies (11 rows)
        if (Schema::hasTable('technologies')) {
            $sourcesQueried[] = 'technologies';
            $tCols = Schema::getColumnListing('technologies');
            $tQuery = Technology::query();

            if ($keyword !== '') {
                $tQuery->where(function ($q) use ($keyword, $tCols) {
                    foreach (['name', 'title', 'category', 'description', 'target_audience', 'tags'] as $col) {
                        if (in_array($col, $tCols, true)) {
                            $q->orWhere($col, 'like', '%'.$keyword.'%');
                        }
                    }
                });
            }
            if ($sector !== '' && in_array('category', $tCols, true)) {
                $tQuery->where(function ($q) use ($sector) {
                    $q->where('category', 'like', '%'.$sector.'%')
                        ->orWhere('tags', 'like', '%'.$sector.'%');
                });
            }
            if ($agency !== '' && in_array('agency', $tCols, true)) {
                $tQuery->where('agency', 'like', '%'.$agency.'%');
            }
            if ($beneficiaries !== '' && in_array('target_audience', $tCols, true)) {
                $tQuery->where('target_audience', 'like', '%'.$beneficiaries.'%');
            }

            foreach ($tQuery->limit($limit)->get() as $t) {
                $rawStatus = $t->active_status ?? $t->status ?? 'Active';
                $status = self::normalizeTechStatus($rawStatus);
                if ($statusFilter !== '' && $status !== $statusFilter) {
                    continue;
                }

                $sec = (string) ($t->category ?: 'General');
                $agn = (string) ($t->agency ?: 'DOST');
                $bySector[$sec] = ($bySector[$sec] ?? 0) + 1;
                $byAgency[$agn] = ($byAgency[$agn] ?? 0) + 1;
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

                $records[] = [
                    'source_catalog' => 'technologies',
                    'id' => (int) $t->id,
                    'code' => $t->code,
                    'title' => $t->name,
                    'agency' => $t->agency,
                    'sector' => $t->category,
                    'description' => $t->description,
                    'application_mechanism' => $t->delivery_mode,
                    'expected_outcome' => $t->tags,
                    'intended_beneficiaries' => $t->target_audience ?: self::inferBeneficiaries($t->category, $t->description, $t->name),
                    'deployment_clusters' => null,
                    'raw_status' => $rawStatus,
                    'tech_status' => $status,
                    'budget_php' => null,
                ];
            }
        }

        // 3. Query dost_projects (1,961 rows)
        if (Schema::hasTable('dost_projects')) {
            $sourcesQueried[] = 'dost_projects';
            $pQuery = DostProject::query();

            if ($keyword !== '') {
                $pQuery->where(function ($q) use ($keyword) {
                    $q->where('project_title', 'like', '%'.$keyword.'%')
                        ->orWhere('program', 'like', '%'.$keyword.'%')
                        ->orWhere('objectives', 'like', '%'.$keyword.'%')
                        ->orWhere('description', 'like', '%'.$keyword.'%')
                        ->orWhere('sector', 'like', '%'.$keyword.'%')
                        ->orWhere('accomplishment', 'like', '%'.$keyword.'%');
                });
            }
            if ($sector !== '') {
                $pQuery->where('sector', 'like', '%'.$sector.'%');
            }
            if ($agency !== '') {
                $pQuery->where('agency', 'like', '%'.$agency.'%');
            }
            if ($beneficiaries !== '') {
                $pQuery->where(function ($q) use ($beneficiaries) {
                    $q->where('objectives', 'like', '%'.$beneficiaries.'%')
                        ->orWhere('description', 'like', '%'.$beneficiaries.'%')
                        ->orWhere('cooperating', 'like', '%'.$beneficiaries.'%');
                });
            }

            foreach ($pQuery->limit($limit)->get() as $proj) {
                $status = self::normalizeProjectStatus($proj->project_status);
                if ($statusFilter !== '' && $status !== $statusFilter) {
                    continue;
                }

                $sec = (string) ($proj->sector ?: 'Unspecified');
                $agn = (string) ($proj->agency ?: 'DOST');
                $bySector[$sec] = ($bySector[$sec] ?? 0) + 1;
                $byAgency[$agn] = ($byAgency[$agn] ?? 0) + 1;
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

                $records[] = [
                    'source_catalog' => 'dost_projects',
                    'id' => (int) $proj->id,
                    'code' => $proj->program ?: 'DOST-PROJ-'.$proj->id,
                    'title' => $proj->project_title,
                    'agency' => $proj->agency,
                    'sector' => $proj->sector,
                    'description' => $proj->description ?: $proj->objectives,
                    'application_mechanism' => $proj->program,
                    'expected_outcome' => $proj->accomplishment ?: $proj->objectives,
                    'intended_beneficiaries' => self::inferBeneficiaries($proj->sector, $proj->objectives ?: $proj->description, $proj->project_title),
                    'deployment_clusters' => $proj->location,
                    'raw_status' => $proj->project_status,
                    'tech_status' => $status,
                    'budget_php' => $proj->budget !== null ? (float) $proj->budget : null,
                    'implementing' => $proj->implementing,
                    'cooperating' => $proj->cooperating,
                ];
            }
        }

        // Rank by relevance: dost_techs & technologies catalogs first, then budget-backed projects
        usort($records, function ($a, $b) {
            $sourceRank = ['dost_techs' => 1, 'technologies' => 2, 'dost_projects' => 3];
            $rA = $sourceRank[$a['source_catalog']] ?? 4;
            $rB = $sourceRank[$b['source_catalog']] ?? 4;
            if ($rA !== $rB) {
                return $rA <=> $rB;
            }

            return ($b['budget_php'] ?? 0) <=> ($a['budget_php'] ?? 0);
        });

        $sliced = array_slice($records, 0, $limit);

        return [
            'total_matched' => count($records),
            'returned_count' => count($sliced),
            'sources_queried' => $sourcesQueried,
            'summary_facets' => [
                'by_sector' => $bySector,
                'by_agency' => $byAgency,
                'by_tech_status' => $byStatus,
            ],
            'records' => $sliced,
        ];
    }

    /**
     * Normalize raw status strings into standard technology availability status:
     * `available`, `limited`, `research_stage`, `unavailable`, `unknown`.
     */
    public static function normalizeTechStatus(?string $rawStatus): string
    {
        if ($rawStatus === null || trim($rawStatus) === '') {
            return 'unknown';
        }

        $lower = strtolower(trim($rawStatus));

        if (str_contains($lower, 'discontinued') || str_contains($lower, 'phased out') || str_contains($lower, 'unavailable')) {
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
            || str_contains($lower, 'completed')
            || str_contains($lower, 'comleted')
        ) {
            return 'available';
        }

        return 'unknown';
    }

    /**
     * Normalize project status (handling database quirk `"comleted"`).
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

    /**
     * Infer target beneficiaries from content keywords.
     */
    private static function inferBeneficiaries(?string $sector, ?string $text, ?string $title): string
    {
        $haystack = strtolower(($sector ?? '').' '.($text ?? '').' '.($title ?? ''));

        if (str_contains($haystack, 'farmer') || str_contains($haystack, 'crop') || str_contains($haystack, 'rice') || str_contains($haystack, 'agriculture')) {
            return 'Farmers & Agricultural Cooperatives';
        }
        if (str_contains($haystack, 'fisher') || str_contains($haystack, 'fish') || str_contains($haystack, 'aquaculture')) {
            return 'Fisherfolk & Coastal Communities';
        }
        if (str_contains($haystack, 'msme') || str_contains($haystack, 'enterprise') || str_contains($haystack, 'setup') || str_contains($haystack, 'industry')) {
            return 'MSMEs & Local Entrepreneurs';
        }
        if (str_contains($haystack, 'school') || str_contains($haystack, 'student') || str_contains($haystack, 'education') || str_contains($haystack, 'learning')) {
            return 'Students, Teachers & Academic Institutions';
        }
        if (str_contains($haystack, 'drr') || str_contains($haystack, 'disaster') || str_contains($haystack, 'hazard') || str_contains($haystack, 'flood')) {
            return 'LGUs & Disaster Response Units';
        }
        if (str_contains($haystack, 'health') || str_contains($haystack, 'nutrition') || str_contains($haystack, 'malnutrition') || str_contains($haystack, 'maternal')) {
            return 'Healthcare Workers, Mothers & Children';
        }

        return 'LGUs & General Community';
    }
}

