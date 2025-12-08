<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mill extends Model
{
    protected $table = 'mill_list';
    protected $fillable = [
        'mill_id','mill_name','prefix','company_id','workstation_id','user_id',
    ];
}
