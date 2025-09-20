<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashReceipts extends Model
{
    protected $table = 'cash_receipts';

    protected $fillable = [
        'cr_no',
        'cust_id',
        'receipt_date',
        'receipt_amount',
        'pay_method',
        'bank_id',
        'collection_receipt',
        'details',
        'amount_in_words',
        'is_cancel',
        'workstation_id',
        'user_id',
        'company_id',
        'sum_debit',
        'sum_credit',
        'is_balanced',
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'receipt_amount' => 'float',
        'sum_debit' => 'float',
        'sum_credit' => 'float',
        'is_balanced' => 'boolean',
    ];

    public function details()
    {
        return $this->hasMany(CashReceiptDetails::class, 'transaction_id', 'id');
    }
}
