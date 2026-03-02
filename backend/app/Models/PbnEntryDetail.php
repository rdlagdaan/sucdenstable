<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PbnEntryDetail extends Model
{
    use HasFactory;

    protected $table = 'pbn_entry_details';

    protected $fillable = [
    'pbn_entry_id',
    'row',

    // keep pbn_number for compatibility (equals po_number)
    'pbn_number',

    // ✅ NEW
    'particulars',

    // existing
    'mill_code',
    'mill',
    'quantity',

    // ✅ renamed
    'price',

    // existing
    'commission',
    'cost',
    'total_commission',

    // ✅ NEW computed
    'handling_fee',
    'handling',

    // totals
    'total_cost',

    // flags/audit
    'selected_flag',
    'delete_flag',
    'workstation_id',
    'user_id',
    'company_id',
];

    public $timestamps = false;
}

