<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CropYear extends Model
{
    // Your table is singular
    protected $table = 'crop_year';

    // Primary key is the default 'id'
    protected $primaryKey = 'id';

    public $timestamps = true;

    // Allow mass-assignment for all fields you set in controller
    protected $fillable = [
        'crop_year',
        'begin_year',
        'end_year',
        'company_id',
        'active_flag',
        'workstation_id',
        'user_number',
    ];

    // Optional: basic casts
    protected $casts = [
        'company_id'   => 'integer',
        'active_flag'  => 'integer',
    ];
}
