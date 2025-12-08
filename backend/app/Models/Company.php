<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $table = 'companies';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    // Your table only has id, company_name, logo
    public $timestamps = false;

    protected $fillable = ['company_name', 'logo'];
}
