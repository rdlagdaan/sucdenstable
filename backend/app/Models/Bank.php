<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    protected $table = 'bank'; // exact table name you gave

    protected $fillable = [
        'bank_id',
        'bank_name',
        'bank_address',
        'bank_account_number',
        'workstation_id',
        'user_id',
        'company_id',
    ];

    public $timestamps = true; // created_at / updated_at
}
