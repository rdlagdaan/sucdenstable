<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashDisbursementDetail extends Model
{
    protected $table = 'cash_disbursement_details';

    public $timestamps = false; 

    protected $fillable = [
        'transaction_id',
        'acct_code',
        'debit',
        'credit',
        'workstation_id',
        'company_id',
        'user_id',
    ];

    protected $casts = [
        'debit'  => 'float',
        'credit' => 'float',
    ];
}
