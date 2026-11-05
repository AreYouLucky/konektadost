<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One DOST technology / intervention in the NCR catalogue.
 *
 * The reference row behind the per-barangay deployment records (the
 * masterlist's other sheet): `code` is the program/project code, e.g.
 * SETUP-FOOD-01, that those records point back to.
 */
class DostTech extends Model
{
    /**
     * The table this model reads from.
     *
     * Set explicitly because the default pluralization of "DostTech" would
     * produce "dost_teches", not the intended "dost_techs".
     */
    protected $table = 'dost_techs';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'agency',
        'sector',
        'deployment_clusters',
        'mechanism',
        'status',
        'outcome',
    ];
}
