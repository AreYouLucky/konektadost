<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgricultureType extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'category_id',
        'type_name',
    ];

    /**
     * @return BelongsTo<AgricultureCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AgricultureCategory::class, 'category_id');
    }

    /**
     * @return HasMany<AgricultureCountEstimate, $this>
     */
    public function countEstimates(): HasMany
    {
        return $this->hasMany(AgricultureCountEstimate::class, 'agriculture_type_id');
    }
}
