<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PbnEntryDetail extends Model
{
    use HasFactory;

    protected $table = 'pbn_entry_details';

    protected $fillable = [
        'pbn_entry_id', 'row', 'pbn_number', 'mill_code', 'mill', 'quantity', 'unit_cost', 'commission', 'cost', 'total_cost', 'selected_flag', 'delete_flag', 'workstation_id', 'user_id'

    ];

    public $timestamps = false;
}

