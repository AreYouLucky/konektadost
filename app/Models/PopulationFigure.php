<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One population figure for one place in one collection year.
 *
 * The table is deliberately narrow: `place_description_id` says which place,
 * `year` says which census/collection, `population` is the number. Everything
 * geographic (name, level, urban/rural) stays on `place_descriptions`, so the
 * same place can carry several years without repeating its attributes.
 */
class PopulationFigure extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'place_description_id',
        'psg_code',
        'year',
        'population'
    ];

    /**
     * The place this figure describes.
     *
     * @return BelongsTo<PlaceDescription, $this>
     */
    public function placeDescription(): BelongsTo
    {
        return $this->belongsTo(PlaceDescription::class, 'place_description_id');
    }

    /**
     * The collection year the application shows by default.
     *
     * Reads the single row marked `is_active` in `collection_years`; null
     * means nobody has marked a year, and every figure then reports as
     * unavailable rather than guessing which year to show.
     */
    public static function activeYear(): ?int
    {
        return CollectionYear::activeYear();
    }

    /**
     * Restrict to the active collection year.
     *
     * With no active year the constraint becomes `year is null`, which
     * matches no row: an unknown year is "no data", not "all years".
     *
     * @param  Builder<self>  $query
     */
    public function scopeForActiveYear(Builder $query): void
    {
        $query->where('year', static::activeYear());
    }

    /**
     * Restrict to a single collection year.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOfYear(Builder $query, int $year): void
    {
        $query->where('year', $year);
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
            'population' => 'integer',
        ];
    }
}
