<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MillRate extends Model
{
    protected $table = 'mill_rate_history';
    protected $fillable = [
        'mill_record_id','mill_id','crop_year',
        'insurance_rate','storage_rate','days_free','market_value',
        'ware_house','shippable_flag','locked','workstation_id','user_id',
    ];

    protected $casts = [
        'shippable_flag' => 'boolean',
        'locked' => 'integer',
    ];
}
