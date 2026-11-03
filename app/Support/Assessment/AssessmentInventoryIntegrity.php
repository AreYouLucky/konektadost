<?php

namespace App\Support\Assessment;

use App\Models\Municipality;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only checks. Conflicting records require verified reassignment, never inferred remapping. */
class AssessmentInventoryIntegrity
{
    private const INDICATORS = [
        'facilities' => ['health_facilities_per_100k', 'hospitals_per_100k', 'pharmacies_per_100k', 'schools_per_100k', 'elementary_schools_per_100k', 'safety_facilities_per_100k'],
        'laboratories' => ['laboratories_per_million', 'laboratory_tests_per_million'],
        'telecom_sites' => ['telecom_sites_per_100k', 'telecom_5g_share'],
    ];

    public function forMunicipality(Municipality $municipality): array
    {
        return app(AssessmentComputationCache::class)->remember(self::class, $municipality, [], function () use ($municipality) {
            $bounds = $municipality->bounds;
            $bounds = is_string($bounds) ? json_decode($bounds, true) : $bounds;
            $hasBounds = is_array($bounds) && array_is_list($bounds) && count($bounds) === 4
                && count(array_filter($bounds, 'is_numeric')) === 4 && $bounds[0] < $bounds[1] && $bounds[2] < $bounds[3];
            $inventories = [];
            foreach (self::INDICATORS as $table => $indicators) {
                if (! Schema::hasColumns($table, ['municipality_id'])) {
                    continue;
                }
                $query = DB::table($table)->where('municipality_id', $municipality->id);
                $total = (clone $query)->count();
                $outsideBounds = 0;
                if ($hasBounds && Schema::hasColumns($table, ['latitude', 'longitude'])) {
                    // Small tolerance accommodates minor geocoding differences near boundaries.
                    $outsideBounds = (clone $query)->whereNotNull('latitude')->whereNotNull('longitude')
                        ->where(function ($query) use ($bounds) {
                            $query->where('latitude', '<', (float) $bounds[0] - 0.02)
                                ->orWhere('latitude', '>', (float) $bounds[1] + 0.02)
                                ->orWhere('longitude', '<', (float) $bounds[2] - 0.02)
                                ->orWhere('longitude', '>', (float) $bounds[3] + 0.02);
                        })->count();
                }
                $parentConflicts = 0;
                if (Schema::hasColumn($table, 'barangay_id') && Schema::hasColumns('barangays', ['id', 'municipality_id'])) {
                    $parentConflicts = (clone $query)->whereIn('barangay_id', DB::table('barangays')->select('id')
                        ->whereNotNull('municipality_id')->where('municipality_id', '<>', $municipality->id))->count();
                }
                $conflicted = $outsideBounds > 0 || $parentConflicts > 0;
                $inventories[] = [
                    'dataset' => $table, 'linked_records' => $total, 'outside_bounds_count' => $outsideBounds,
                    'barangay_parent_conflicts' => $parentConflicts, 'has_conflicts' => $conflicted,
                    'indicator_keys' => $indicators,
                    'reason' => $conflicted
                        ? "{$table}: {$outsideBounds} linked records have coordinates outside the municipality bounds and {$parentConflicts} reference a barangay belonging to another municipality. These checks may overlap. Local rates are withheld until the location links are verified."
                        : null,
                ];
            }

            return ['has_conflicts' => collect($inventories)->contains('has_conflicts', true), 'inventories' => $inventories];
        });
    }

    public function indicatorProblemsFor(Municipality $municipality): array
    {
        $problems = [];
        foreach ($this->forMunicipality($municipality)['inventories'] as $inventory) {
            if ($inventory['has_conflicts']) {
                foreach ($inventory['indicator_keys'] as $key) {
                    $problems[$key] = $inventory['reason'];
                }
            }
        }

        return $problems;
    }
}
