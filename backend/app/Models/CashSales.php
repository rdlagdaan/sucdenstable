<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashSales extends Model
{
    protected $table = 'cash_sales';

    protected $fillable = [
        'cs_no','cust_id','si_no','sales_date','sales_amount',
        'pay_method','bank_id','check_ref_no','explanation',
        'amount_in_words','booking_no','is_cancel',
        'workstation_id','user_id','company_id',
    ];

    protected $casts = [
        'sales_date' => 'date',
        'sales_amount' => 'float',
    ];

    public function details()
    {
        return $this->hasMany(CashSalesDetail::class, 'transaction_id', 'id');
    }
}
