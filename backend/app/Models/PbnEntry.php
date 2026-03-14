<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PbnEntry extends Model
{
    use HasFactory;

    protected $table = 'pbn_entry';

protected $fillable = [
    // identity
    'po_number',
    'pbn_number',

    // main fields
    'pbn_date',
    'sugar_type',
    'crop_year',
    'vend_code',
    'vendor_name',

    // new fields
    'note',
    'terms',

    // flags / audit
    'company_id',
    'delete_flag', 'delete_by',
    'posted_flag', 'posted_by',
    'close_flag',  'close_by',
    'visible_flag',
    'workstation_id',
    'user_id',
];

    public function details()
    {
        return $this->hasMany(PbnEntryDetail::class, 'pbn_entry_id');
    }
}
