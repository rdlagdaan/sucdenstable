<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlantersList extends Model
{
    protected $table = 'planters_list';
    public $timestamps = true;

    protected $fillable = [
        'tin','last_name','first_name','middle_name','display_name',
        'company_id','address','type','workstation_id','user_id'
    ];
}
