<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MillList extends Model
{
    protected $table = 'mill_list';
    // ✅ remove $primaryKey override so the PK is 'id'
    public $timestamps = true;

    protected $fillable = [
        'mill_id',        // include if you mass-assign it
        'mill_name',
        'prefix',
        'company_id',
        'workstation_id',
        'user_id',
    ];

    // (optional but safe)
    protected $casts = [
        'mill_id'    => 'string',
        'mill_name'  => 'string',
        'prefix'     => 'string',
        'company_id' => 'integer',
    ];

    public function rates(): HasMany
    {
        return $this->hasMany(MillRateHistory::class, 'mill_id', 'mill_id');
    }
}
