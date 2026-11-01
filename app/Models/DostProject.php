<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DostProject extends Model
{

    protected $table = 'dost_projects';

    protected $fillable = [
        'agency',
        'program',
        'project_title',
        'objectives',
        'description',
        'project_type',
        'project_status',
        'accomplishment',
        'source_url',
        'start_date',
        'end_date',
        'last_verified_year',
        'last_verified_at',
        'location',
        'sector',
        'budget',
        'program_leader',
        'project_leader',
        'project_staff',
        'funding',
        'implementing',
        'cooperating',
        'monitoring'
    ];




}
