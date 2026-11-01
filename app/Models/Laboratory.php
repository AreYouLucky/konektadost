<?php

namespace App\Models;

use Database\Factories\LaboratoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Laboratory extends Model
{
    /** @use HasFactory<LaboratoryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'agency_name',
        'code',
        'source',
        'source_record_id',
        'source_url',
        'country',
        'region',
        'province',
        'city',
        'latitude',
        'longitude',
        'municipality_id',
        'barangay_id',
        'website',
        'contact_information',
        'services_count',
        'services',
        'has_coordinates',
        'is_outside_philippines',
    ];

    /**
     * The municipality this laboratory sits in.
     *
     * Named "municipalityModel" because the table also has plain `city` and
     * `province` string columns describing the same thing in raw form.
     *
     * @return BelongsTo<Municipality, $this>
     */
    public function municipalityModel(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'municipality_id');
    }

    /**
     * The barangay this laboratory sits in.
     *
     * @return BelongsTo<Barangay, $this>
     */
    public function barangayModel(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id');
    }

    /**
     * Services/tests this laboratory performs, each with a method, reference and fee.
     *
     * @return HasMany<LabServices, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(LabServices::class, 'laboratory_id');
    }

    /**
     * Alias for services() used by LocationAssessor.
     *
     * @return HasMany<LabServices, $this>
     */
    public function tests(): HasMany
    {
        return $this->services();
    }

    /**
     * Restrict to laboratories located within the Philippines.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInPhilippines(Builder $query): void
    {
        $query->where('is_outside_philippines', false);
    }

    /**
     * Restrict to laboratories carrying coordinates.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeGeocoded(Builder $query): void
    {
        $query->where('has_coordinates', true);
    }

    /**
     * Whether this laboratory has any usable location assignment.
     *
     * Useful for filtering before per-area capability analysis, since 17 of 69
     * laboratories carry no municipality.
     */
    public function isLocated(): bool
    {
        return $this->municipality_id !== null;
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
            'has_coordinates' => 'boolean',
            'is_outside_philippines' => 'boolean',
            'services_count' => 'integer',
            // Denormalised JSON copy of the rows in facility_tests. Prefer the
            // tests() relation; this exists only for source fidelity.
            'services' => 'array',
        ];
    }
}
