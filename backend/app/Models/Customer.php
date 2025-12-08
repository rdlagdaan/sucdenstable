<?php
// app/Models/Customer.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customer_list';
    protected $fillable = ['cust_id','cust_name','company_id','workstation_id','user_id'];
}
