<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabServices extends Model
{
    use HasFactory;

    protected $table = 'lab_services';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'laboratory_id',
        'test_name',
        'method',
        'reference',
        'fee',
        'source_record_id',
        'source',
    ];

    /**
     * The laboratory offering this service/test.
     *
     * @return BelongsTo<Laboratory, $this>
     */
    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(Laboratory::class, 'laboratory_id');
    }

    /**
     * Whether this service/test is offered free of charge.
     */
    public function isFree(): bool
    {
        return $this->fee === null || $this->fee === 0;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee' => 'integer',
        ];
    }
}

