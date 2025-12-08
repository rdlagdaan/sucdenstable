<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SugarType extends Model
{
    protected $table = 'sugar_type';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'sugar_type',     // char(2) / varchar(2)
        'description',    // varchar(15)
        'workstation_id', // inet
        'user_id',        // int
    ];

    protected $casts = [
        'user_id' => 'integer',
    ];
}
