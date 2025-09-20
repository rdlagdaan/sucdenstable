<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashReceiptDetails extends Model
{
    protected $table = 'cash_receipt_details';

    public $timestamps = true;

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
