<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashPurchase extends Model
{
    protected $table = 'cash_purchase';

    protected $fillable = [
        'cp_no','vendor_id','invoice_no','purchase_date','purchase_amount',
        'explanation','is_cancel','workstation_id','user_id','company_id',
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
