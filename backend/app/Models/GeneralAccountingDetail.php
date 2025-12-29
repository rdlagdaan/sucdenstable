<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneralAccountingDetail extends Model
{
    protected $table = 'general_accounting_details';

    public $timestamps = false; // as per your schema

    protected $fillable = [
        'transaction_id',
        'acct_code',
        'debit',
        'credit',
        'workstation_id',
        'user_id',
        'company_id',
    ];

    protected $casts = [
        'debit' => 'float',
        'credit' => 'float',
    ];
}
