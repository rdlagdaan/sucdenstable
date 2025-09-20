<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MillRateHistory extends Model
{
    protected $table = 'mill_rate_history';

    protected $fillable = [
        'mill_id',
        'company_id',
        'valid_from',
        'valid_to',
        'insurance_rate',
        'storage_rate',
        'days_free',
        'market_value',
        'ware_house',
        'shippable_flag',
        'workstation_id',
        'user_id',
    ];

    protected $casts = [
        'valid_from'      => 'date',
        'valid_to'        => 'date',
        'insurance_rate'  => 'decimal:4',
        'storage_rate'    => 'decimal:4',
        'days_free'       => 'integer',
        'market_value'    => 'decimal:4',
        'shippable_flag'  => 'boolean',
    ];

    public function mill(): BelongsTo
    {
        return $this->belongsTo(MillList::class, 'mill_id', 'mill_id');
    }
}
