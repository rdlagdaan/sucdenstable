<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivingDetail extends Model
{
    protected $table = 'receiving_details';

    protected $fillable = [
        'receiving_entry_id','row','receipt_no','quedan_no','quantity','liens',
        'unit_cost','commission','storage','insurance','total_ap',
        'week_ending','date_issued','planter_name','planter_tin','item_no','mill',
        'selected_flag','processed_flag','updated_from','workstation_id','user_id',
    ];

    public $casts = [
        'quantity'    => 'decimal:2',
        'liens'       => 'decimal:2',
        'unit_cost'   => 'decimal:2',
        'commission'  => 'decimal:2',
        'storage'     => 'decimal:2',
        'insurance'   => 'decimal:2',
        'total_ap'    => 'decimal:2',
        'week_ending' => 'date',
        'date_issued' => 'date',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ReceivingEntry::class, 'receiving_entry_id');
    }
}
