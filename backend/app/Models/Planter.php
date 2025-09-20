<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Planter extends Model
{
    protected $table = 'planters_list';

    public $timestamps = true;

    protected $fillable = [
        'tin',
        'last_name',
        'first_name',
        'middle_name',
        'display_name',
        'company_id',
        'address',
        'type',
        'workstation_id',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Convenience accessor to auto-generate display_name if empty
    public function getDisplayNameAttribute($value)
    {
        if (!empty($value)) {
            return $value;
        }
        $mi = $this->middle_name ? ' ' . mb_substr($this->middle_name, 0, 1) . '.' : '';
        return trim("{$this->last_name}, {$this->first_name}{$mi}");
    }
}
