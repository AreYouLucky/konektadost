<?php

namespace App\Models;

use App\Support\GeoJson;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Barangay extends Model
{
    use GeoJson, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'municipality_id',
        'psg_code',
        'brgy_name',
        'bounds',
        'geometry',
        'center_lat',
        'center_lng',
        'area_km2',
    ];

    /**
     * The municipality this barangay belongs to.
     *
     * The link is stored in barangays.municipality_id, which was derived from
     * the PSGC code: a barangay's code is its municipality's code with the
     * three-digit barangay suffix appended, so dropping that suffix identifies
     * the parent.
     *
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'municipality_id');
    }

    /**
     * The PSA PSGC record for this barangay, joined on 10-digit psg_code.
     *
     * Scoped to barangay level and the newest release, because a psg_code is
     * only unique within a release.
     *
     * @return BelongsTo<PlaceDescription, $this>
     */
    public function placeDescription(): BelongsTo
    {
        return $this->belongsTo(PlaceDescription::class, 'psg_code', 'psg_code')
            ->where('place_descriptions.level', PlaceDescription::LEVEL_BARANGAY)
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
                PlaceDescription::levelIdsQuery(PlaceDescription::LEVEL_BARANGAY)
            )
            ->where('population_figures.year', CollectionYear::activeYear());
    }

    /**
     * Facilities (health, education, safety) located in this barangay.
     *
     * @return HasMany<Facility, $this>
     */
    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class, 'barangay_id');
    }

    /**
     * Accredited laboratories located in this barangay.
     *
     * @return HasMany<Laboratory, $this>
     */
    public function laboratories(): HasMany
    {
        return $this->hasMany(Laboratory::class, 'barangay_id');
    }

    /**
     * Telecommunication base stations / cell sites located in this barangay.
     *
     * @return HasMany<TelecomSite, $this>
     */
    public function telecomSites(): HasMany
    {
        return $this->hasMany(TelecomSite::class, 'barangay_id');
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
