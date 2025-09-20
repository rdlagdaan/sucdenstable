<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashSalesDetail extends Model
{
    protected $table = 'cash_sales_details';

    public $timestamps = false; // table has no created_at/updated_at per your fields

    protected $fillable = [
        'transaction_id','acct_code','debit','credit',
        'workstation_id','user_id','company_id',
    ];

    protected $casts = [
        'debit' => 'float',
        'credit' => 'float',
    ];
}
