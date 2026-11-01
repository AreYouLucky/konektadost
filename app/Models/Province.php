<?php

namespace App\Models;

use App\Support\GeoJson;
use Database\Factories\ProvinceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    /** @use HasFactory<ProvinceFactory> */
    use GeoJson, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'psg_code',
        'description',
        'name',
        'type',
        'english_type',
        'cc_code',
        'center_lat',
        'center_lng',
        'bounds',
        'geometry',
    ];

    /**
     * Municipalities belonging to this province.
     *
     * @return HasMany<Municipality, $this>
     */
    public function municipalities(): HasMany
    {
        return $this->hasMany(Municipality::class);
    }

    /**
     * The PSA PSGC record for this province, joined on 10-digit psg_code.
     *
     * Scoped to the newest release, because a psg_code is only unique within
     * a release. Level covers both `Prov` and `Reg`: PSA publishes Metro
     * Manila as a region (1300000000 is `Reg`), yet it is stored in
     * `provinces` because the map drills into it like one.
     *
     * @return BelongsTo<PlaceDescription, $this>
     */
    public function placeDescription(): BelongsTo
    {
        return $this->belongsTo(PlaceDescription::class, 'psg_code', 'psg_code')
            ->whereIn('place_descriptions.level', [PlaceDescription::LEVEL_PROVINCE, PlaceDescription::LEVEL_REGION])
            ->where('place_descriptions.release_id', PlaceDescription::latestReleaseId());
    }

    /**
     * The population figure for the active collection year.
     *
     * Level lives on `place_descriptions`, so the figure table can only be
     * reached through that join; `population_figures` itself carries just the
     * place id, the code, the year and the number.
     *
     * @return BelongsTo<PopulationFigure, $this>
     */
    public function populationFigure(): BelongsTo
    {
        return $this->belongsTo(PopulationFigure::class, 'psg_code', 'psg_code')
            ->whereIn(
                'population_figures.place_description_id',
                PlaceDescription::levelIdsQuery([PlaceDescription::LEVEL_PROVINCE, PlaceDescription::LEVEL_REGION])
            )
            ->where('population_figures.year', CollectionYear::activeYear());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'center_lat' => 'float',
            'center_lng' => 'float',
            'area_km2' => 'float',
        ];
    }
}
