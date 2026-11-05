<?php

namespace App\Models;

use App\Support\GeoJson;
use Database\Factories\MunicipalityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Municipality extends Model
{
    /** @use HasFactory<MunicipalityFactory> */
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
        'province_id',
        'province_name',
        'type',
        'center_lat',
        'center_lng',
        'area_km2',
        'bounds',
        'geometry',
    ];

    /**
     * The province this municipality belongs to.
     *
     * Named "provinceModel" because the table also has a plain `province`
     * string column, which would shadow a relation named "province".
     *
     * @return BelongsTo<Province, $this>
     */
    public function provinceModel(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    /**
     * The PSA PSGC record for this municipality, joined on 10-digit psg_code.
     *
     * Scoped to City/Mun level and the newest release, because a psg_code is
     * only unique within a release.
     *
     * @return BelongsTo<PlaceDescription, $this>
     */
    public function placeDescription(): BelongsTo
    {
        return $this->belongsTo(PlaceDescription::class, 'psg_code', 'psg_code')
            ->whereIn('place_descriptions.level', PlaceDescription::LGU_LEVELS)
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
                PlaceDescription::levelIdsQuery(PlaceDescription::LGU_LEVELS)
            )
            ->where('population_figures.year', CollectionYear::activeYear());
    }

    /**
     * Barangays belonging to this municipality.
     *
     * Joined on barangays.municipality_id, which was derived from the PSGC
     * code (a barangay's code is its municipality's code plus a three-digit
     * suffix, so dropping the suffix identifies the parent).
     *
     * @return HasMany<Barangay, $this>
     */
    public function barangays(): HasMany
    {
        return $this->hasMany(Barangay::class, 'municipality_id');
    }

    /**
     * Facilities (health, education, safety) located in this municipality.
     *
     * @return HasMany<Facility, $this>
     */
    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class, 'municipality_id');
    }

    /**
     * Accredited laboratories located in this municipality.
     *
     * @return HasMany<Laboratory, $this>
     */
    public function laboratories(): HasMany
    {
        return $this->hasMany(Laboratory::class, 'municipality_id');
    }

    /**
     * Telecommunication base stations / cell sites located in this municipality.
     *
     * @return HasMany<TelecomSite, $this>
     */
    public function telecomSites(): HasMany
    {
        return $this->hasMany(TelecomSite::class, 'municipality_id');
    }

    /**
     * Population figures describing this municipality across every collection
     * year, matched on 10-digit psg_code and scoped to City/Mun level.
     *
     * @return HasMany<PopulationFigure, $this>
     */
    public function populationFigures(): HasMany
    {
        return $this->hasMany(PopulationFigure::class, 'psg_code', 'psg_code')
            ->whereIn(
                'population_figures.place_description_id',
                PlaceDescription::levelIdsQuery(PlaceDescription::LGU_LEVELS)
            );
    }

    /**
     * Alias for populationFigures() for backwards compatibility.
     *
     * @return HasMany<PopulationFigure, $this>
     */
    public function municipalityPopulationFigures(): HasMany
    {
        return $this->populationFigures();
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
