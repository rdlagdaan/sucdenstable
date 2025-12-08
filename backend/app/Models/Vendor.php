<?php
// app/Models/Vendor.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $table = 'vendor_list';
    protected $fillable = [
        'vend_code','vend_name','company_id','vendor_tin','vendor_address',
        'vatable','workstation_id','user_id',
    ];
}
