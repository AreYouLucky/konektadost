<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A telecommunication base station, cell site or tower node.
 *
 * Sites are delivered per city as workbooks, so `source` records which
 * workbook a row came from and `source_record_id` is that workbook's own site
 * id. Together they identify a site uniquely, which is what makes a re-import
 * an update rather than a duplicate.
 *
 * The operator and the tower company are kept apart. The workbook column mixes
 * them, so `operator_raw` preserves what the sheet actually said, `operator` is
 * the tenant network derived from the MNC, and `tower_company` is set only when
 * the site sits on an independent tower.
 */
class TelecomSite extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'district',
        'barangay_name',
        'barangay_psgc',
        'barangay_psgc_code',
        'barangay_id',
        'municipality_id',
        'tower_company',
        'operator',
        'operator_raw',
        'is_colocated',
        'mcc',
        'mnc',
        'lac',
        'cell_id',
        'latitude',
        'longitude',
        'technologies',
        'bands',
        'structure_type',
        'coverage_area',
        'source_checked',
    ];

    /**
     * The barangay this site sits in.
     *
     * Null when the site sits in a barangay the geography tables do not hold,
     * which is a real gap rather than an absent site. `barangay_psgc10` still
     * carries the PSA identity in that case.
     *
     * @return BelongsTo<Barangay, $this>
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id');
    }

    /**
     * The city or municipality this site sits in.
     *
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'municipality_id');
    }

    /**
     * Restrict to sites that carry coordinates.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeGeocoded(Builder $query): void
    {
        $query->whereNotNull('latitude')->whereNotNull('longitude');
    }

    /**
     * Restrict to one workbook, e.g. "qc_cellsites".
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOfSource(Builder $query, string $source): void
    {
        $query->where('source', $source);
    }

    /**
     * Restrict to one or more tenant networks, e.g. "Globe Telecom".
     *
     * @param  Builder<$this>  $query
     * @param  array<int, string>|string  $operators
     */
    public function scopeOfOperator(Builder $query, array|string $operators): void
    {
        $query->whereIn('operator', (array) $operators);
    }

    /**
     * Restrict to one district, e.g. "District 1".
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOfDistrict(Builder $query, string $district): void
    {
        $query->where('district', $district);
    }

    /**
     * The frequency bands this site carries.
     *
     * @return list<string>
     */
    public function bandList(): array
    {
        return is_array($this->bands) ? array_values($this->bands) : [];
    }

    /**
     * The radio technologies this site supports.
     *
     * @return list<string>
     */
    public function technologyList(): array
    {
        return is_array($this->technologies) ? array_values($this->technologies) : [];
    }

    /**
     * Whether this site carries a given technology, e.g. "5G NR".
     */
    public function supports(string $technology): bool
    {
        foreach ($this->technologyList() as $supported) {
            if (mb_strtolower($supported) === mb_strtolower($technology)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this site carries a given frequency band, e.g. "n78".
     */
    public function carriesBand(string $band): bool
    {
        foreach ($this->bandList() as $carried) {
            if (mb_strtolower($carried) === mb_strtolower($band)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this site is hosted on an independent tower.
     */
    public function isTowerCo(): bool
    {
        return $this->tower_company !== null && trim((string) $this->tower_company) !== '';
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
            'is_colocated' => 'boolean',
            'technologies' => 'array',
            'bands' => 'array',
            'source_checked' => 'date',
        ];
    }
}
