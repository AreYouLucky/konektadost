<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\Assessment\AssessmentReferenceData;
use Illuminate\Support\Facades\Schema;

/**
 * A population collection year the application can show.
 *
 * Exactly one row carries `is_active = 1`; that row decides which year every
 * population lookup resolves to, so "what year is the app showing" is a
 * question with one answer instead of a guess per query.
 */
class CollectionYear extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'year',
        'description',
        'is_active',
    ];

    /**
     * The active year, resolved once per request.
     *
     * Every population relation filters on it, so memoizing keeps a page load
     * from re-querying `collection_years` for each relationship.
     */
    private static ?int $activeYear = null;

    private static bool $activeYearResolved = false;

    /**
     * The year marked active, or null when none is.
     *
     * Null is a real answer: with no active year the app reports population
     * as unavailable instead of silently picking the newest data.
     */
    public static function activeYear(): ?int
    {
        if (! Schema::hasTable('collection_years')) {
            return null;
        }

        return app(AssessmentReferenceData::class)->remember('active_population_year', function () {
            $year = static::query()
                ->where('is_active', 1)
                ->orderByDesc('year')
                ->value('year');

            return $year === null ? null : (int) $year;
        });
    }

    /**
     * Forget the memoized year (used by tests that change the active row).
     */
    public static function flushActiveYearCache(): void
    {
        app(AssessmentReferenceData::class)->forget('active_population_year');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
