<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashDisbursement extends Model
{
    protected $table = 'cash_disbursement';

    protected $fillable = [
        'cd_no',
        'vend_id',
        'disburse_date',
        'disburse_amount',
        'pay_method',
        'bank_id',
        'check_ref_no',
        'explanation',
        'amount_in_words',
        'booking_no',
        'is_cancel',
        'workstation_id',
        'user_id',
        'company_id',
        'sum_debit',
        'sum_credit',
        'is_balanced',
    ];

    protected $casts = [
        'disburse_date'   => 'date',
        'disburse_amount' => 'float',
        'sum_debit'       => 'float',
        'sum_credit'      => 'float',
        'is_balanced'     => 'boolean',
    ];

    public function details()
    {
        return $this->hasMany(CashDisbursementDetail::class, 'transaction_id', 'id');
    }
}
