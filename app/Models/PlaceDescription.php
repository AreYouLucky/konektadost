<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Support\Assessment\AssessmentReferenceData;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A PSA PSGC place record for one release.
 *
 * `place_descriptions` carries geographic *identity* and PSA attributes
 * (name, level, income class, urban/rural). The numbers live next door in
 * `population_figures`, one row per collection year, joined by
 * `place_description_id`. Splitting them is what lets a single place keep a
 * 2020 figure and a 2024 figure without duplicating the place itself.
 */
class PlaceDescription extends Model
{
    public const LEVEL_PROVINCE = 'Prov';

    public const LEVEL_REGION = 'Reg';

    public const LEVEL_CITY = 'City';

    public const LEVEL_MUNICIPALITY = 'Mun';

    public const LEVEL_BARANGAY = 'Bgy';

    public const LGU_LEVELS = [
        self::LEVEL_CITY,
        self::LEVEL_MUNICIPALITY,
    ];

    /** Row was resolved to the correct area by GADM id. */
    public const MATCH_MATCHED = 'matched';

    /** More than one candidate area matched the name. */
    public const MATCH_AMBIGUOUS = 'ambiguous';

    /** No area carried the expected name. */
    public const MATCH_UNMATCHED_NAME = 'unmatched_name';

    /** Name matched but the parent (province/municipality) did not. */
    public const MATCH_UNMATCHED_PARENT = 'unmatched_parent';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'release_id',
        'psg_code',
        'name',
        'correspondence_code',
        'level',
        'old_name',
        'city_class',
        'income_class',
        'urban_rural',
        'status',
        'parent_correspondence_code',
    ];

    /**
     * Newest release present, resolved once per request.
     *
     * Every level-scoped relation filters on it, so memoizing keeps a single
     * page load from issuing the same `max()` once per relationship.
     */
    private static ?int $latestReleaseId = null;

    public static function latestReleaseId(): int
    {
        if (! Schema::hasTable('place_descriptions')) {
            return 0;
        }

        return app(AssessmentReferenceData::class)->remember('latest_psgc_release', fn () => (int) static::query()->max('release_id'));
    }

    public static function flushLatestReleaseCache(): void
    {
        app(AssessmentReferenceData::class)->forget('latest_psgc_release');
    }

    /**
     * Population figures recorded for this place, one per collection year.
     *
     * @return HasMany<PopulationFigure, $this>
     */
    public function populationFigures(): HasMany
    {
        return $this->hasMany(PopulationFigure::class, 'place_description_id');
    }

    /**
     * The figure for the active collection year.
     *
     * @return BelongsTo<PopulationFigure, $this>
     */
    public function populationFigure(): BelongsTo
    {
        return $this->belongsTo(PopulationFigure::class, 'place_description_id')
            ->where('population_figures.year', CollectionYear::activeYear());
    }

    /**
     * Restrict to a single geographic level ("Prov", "City", "Mun", "Bgy").
     *
     * @param  Builder<self>  $query
     */
    public function scopeOfLevel(Builder $query, string $level): void
    {
        $query->where('level', $level);
    }

    /**
     * Restrict to the newest release's rows.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLatestRelease(Builder $query): void
    {
        $query->where('release_id', self::latestReleaseId());
    }

    /**
     * The ids of the newest release's rows at a level, for a `whereIn`
     * subquery on `population_figures.place_description_id`.
     *
     * Level is a property of the place, not of the figure, so the figure's
     * table can only be filtered through this join.
     *
     * @param  string|string[]  $levels
     * @return Builder<self>
     */
    public static function levelIdsQuery(string|array $levels): Builder
    {
        return static::query()
            ->select('id')
            ->whereIn('level', (array) $levels)
            ->where('release_id', self::latestReleaseId());
    }
}
