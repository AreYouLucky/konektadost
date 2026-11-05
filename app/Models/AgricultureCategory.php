<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgricultureCategory extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'category_code',
        'category_name',
        'description',
    ];

    /**
     * @return HasMany<AgricultureType, $this>
     */
    public function types(): HasMany
    {
        return $this->hasMany(AgricultureType::class, 'category_id');
    }

    /**
     * @return HasMany<AgricultureCountEstimate, $this>
     */
    public function countEstimates(): HasMany
    {
        return $this->hasMany(AgricultureCountEstimate::class, 'category_id');
    }
}
