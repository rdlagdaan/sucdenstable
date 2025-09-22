<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneralAccounting extends Model
{
    protected $table = 'general_accounting';

    protected $fillable = [
        'ga_no',
        'gen_acct_date',
        'gen_acct_amount',
        'explanation',
        'amount_in_words',
        'is_cancel',
        'type',
        'workstation_id',
        'user_id',
        'company_id',
        'sum_debit',
        'sum_credit',
        'is_balanced',
    ];

    protected $casts = [
        'gen_acct_date' => 'date',
        'gen_acct_amount' => 'float',
        'sum_debit' => 'float',
        'sum_credit' => 'float',
        'is_balanced' => 'boolean',
    ];

    public function details()
    {
        return $this->hasMany(GeneralAccountingDetail::class, 'transaction_id', 'id');
    }
}
