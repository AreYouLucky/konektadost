<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modeled annual agriculture estimates, retaining source data and location provenance.
 */
class AgricultureCountEstimate extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'record_code',
        'category_id',
        'agriculture_type_id',
        'commodity_name',
        'commodity_variant',
        'activity_type',
        'production_method',
        'area_sqm',
        'area_kind',
        'estimated_quantity_year',
        'estimated_marketable_quantity_year',
        'estimated_sold_quantity_year',
        'quantity_unit',
        'quantity_basis',
        'average_headcount',
        'estimated_unit_price_php',
        'estimated_revenue_php_year',
        'estimated_operating_cost_php_year',
        'estimated_net_php_year',
        'province_id',
        'municipality_id',
        'barangay_id',
        'suggested_parent_barangay_id',
        'source_barangay_id',
        'source_barangay_name',
        'location_match_status',
        'model_year',
        'data_source_type',
        'source_table',
        'source_record_id',
        'notes',
        'source_data_json',
    ];

    /**
     * @return BelongsTo<AgricultureCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AgricultureCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<AgricultureType, $this>
     */
    public function agricultureType(): BelongsTo
    {
        return $this->belongsTo(AgricultureType::class, 'agriculture_type_id');
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    /**
     * @return BelongsTo<Municipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'municipality_id');
    }

    /**
     * @return BelongsTo<Barangay, $this>
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id');
    }

    /**
     * @return BelongsTo<Barangay, $this>
     */
    public function suggestedParentBarangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'suggested_parent_barangay_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'area_sqm' => 'decimal:4',
            'estimated_quantity_year' => 'decimal:4',
            'estimated_marketable_quantity_year' => 'decimal:4',
            'estimated_sold_quantity_year' => 'decimal:4',
            'average_headcount' => 'integer',
            'estimated_unit_price_php' => 'decimal:4',
            'estimated_revenue_php_year' => 'decimal:4',
            'estimated_operating_cost_php_year' => 'decimal:4',
            'estimated_net_php_year' => 'decimal:4',
            'model_year' => 'integer',
            'source_data_json' => 'array',
        ];
    }
}
