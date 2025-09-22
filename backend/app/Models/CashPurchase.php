<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashPurchase extends Model
{
    protected $table = 'cash_purchase';

    protected $fillable = [
        'cp_no',
        'vend_id',          // <-- IMPORTANT
        'purchase_date',
        'explanation',
        'sugar_type',
        'crop_year',
        'mill_id',
        'booking_no',
        'purchase_amount',
        'is_cancel',
        'company_id',
        'workstation_id',
        'user_id',
        'sum_debit',
        'sum_credit',
        'is_balanced',
    ];

    protected $casts = [
        'purchase_date'   => 'date',
        'purchase_amount' => 'float',
    ];

    public function details()
    {
        return $this->hasMany(CashPurchaseDetail::class, 'transaction_id', 'id');
    }
}
