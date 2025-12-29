<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceivingEntry extends Model
{
    protected $table = 'receiving_entry';

    public $timestamps = false;
    
    protected $fillable = [
        'company_id','receipt_no','pbn_number','receipt_date','item_number','mill','assoc_dues','others',
        'gl_account_key','no_insurance','insurance_week','no_storage','storage_week',
        'posted_flag','posted_by','selected_flag','processed_flag','workstation_id','user_id',
    ];

protected $casts = [
  'receipt_date'    => 'date',
  'insurance_week'  => 'date',
  'storage_week'    => 'date',
  'no_insurance'    => 'boolean',
  'no_storage'      => 'boolean',
  'posted_flag'     => 'boolean',
  'selected_flag'   => 'boolean',
  'processed_flag'  => 'boolean',
];


    public function details(): HasMany
    {
        return $this->hasMany(ReceivingDetail::class, 'receiving_entry_id');
    }
}
