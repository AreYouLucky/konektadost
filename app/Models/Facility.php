<?php

namespace App\Models;

use Database\Factories\FacilityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Facility extends Model
{
    /** @use HasFactory<FacilityFactory> */
    use HasFactory;

    public const SECTOR_HEALTH = 'health';

    public const SECTOR_EDUCATION = 'education';

    public const SECTOR_SAFETY = 'safety';

    /**
     * Facility types treated as healthcare availability.
     *
     * @var list<string>
     */
    public const HEALTHCARE_TYPES = [
        'hospital',
        'clinic',
        'medical_clinic',
        'dental_clinic',
        'pharmacy',
        'health_post',
        'birthing_centre',
        'blood_bank',
        'dialysis',
        'hyperbaric_chamber',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'address',
        'municipality_id',
        'barangay_id',
        'description',
        'facility_type',
        'sector',
        'level',
        'operator',
        'capacity',
        'capacity_no',
        'latitude',
        'longitude',
    ];

    /**
     * The municipality this facility sits in.
     *
     * Named "municipalityModel" for consistency with Municipality and
     * PopulationFigure. `facilities` has no plain `municipality` string column,
     * so this name is only a convention here, not a workaround.
     *
     * @return BelongsTo<Municipality, $this>
     */
    public function municipalityModel(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'municipality_id');
    }

    /**
     * The barangay this facility sits in.
     *
     * @return BelongsTo<Barangay, $this>
     */
    public function barangayModel(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id');
    }

    /**
     * Restrict to a sector: health, education or safety.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOfSector(Builder $query, string $sector): void
    {
        $query->where('sector', $sector);
    }

    /**
     * Restrict to one or more facility types (e.g. 'hospital', 'school').
     *
     * @param  Builder<$this>  $query
     * @param  array<int, string>|string  $types
     */
    public function scopeOfType(Builder $query, array|string $types): void
    {
        $query->whereIn('facility_type', (array) $types);
    }

    /**
     * Restrict to education facilities at a level (elementary, high_school, college).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOfLevel(Builder $query, string $level): void
    {
        $query->where('level', $level);
    }

    /**
     * Restrict to facilities that carry coordinates.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeGeocoded(Builder $query): void
    {
        $query->whereNotNull('latitude')->whereNotNull('longitude');
    }

    /**
     * Whether this facility counts towards healthcare availability.
     */
    public function isHealthcare(): bool
    {
        return $this->sector === self::SECTOR_HEALTH;
    }

    /**
     * Whether this facility counts as a school.
     */
    public function isSchool(): bool
    {
        return $this->facility_type === 'school' || $this->sector === self::SECTOR_EDUCATION;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            // Populated on only 464 of 19,659 rows, so it is usually null.
            'capacity_no' => 'integer',
        ];
    }
}
