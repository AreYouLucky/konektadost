<?php

namespace App\Support\Assessment;

use App\FunctionTools\LayerD\GetLocalDostProjectsTool;
use App\FunctionTools\LayerD\SearchDostTechnologiesTool;
use Illuminate\Database\Eloquent\Model;

/**
 * Backward-compatible facade delegating directly to Layer D Function Tool classes:
 * - SearchDostTechnologiesTool (D1: search_dost_technologies)
 * - GetLocalDostProjectsTool (D2: get_local_dost_projects)
 */
class DostCatalogToolService
{
    /**
     * Tool: search_dost_technologies
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function searchDostTechnologies(array $filters = []): array
    {
        return app(SearchDostTechnologiesTool::class)->execute($filters);
    }

    /**
     * Tool: get_local_dost_projects
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function getLocalDostProjects(Model $location, array $options = []): array
    {
        return app(GetLocalDostProjectsTool::class)->forLocation($location, $options);
    }

    /**
     * Normalize technology status string.
     */
    public static function normalizeTechStatus(?string $rawStatus): string
    {
        return SearchDostTechnologiesTool::normalizeTechStatus($rawStatus);
    }

    /**
     * Normalize project status string.
     */
    public static function normalizeProjectStatus(?string $rawStatus): string
    {
        return SearchDostTechnologiesTool::normalizeProjectStatus($rawStatus);
    }
}
