<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashPurchaseDetail extends Model
{
    protected $table = 'cash_purchase_details';
    public $timestamps = false;

    protected $fillable = [
        'transaction_id','acct_code','debit','credit',
        'workstation_id','user_id','company_id',
    ];

    protected $casts = [
        'debit'  => 'float',
        'credit' => 'float',
    ];
}
