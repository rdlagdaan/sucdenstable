<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerList extends Model
{
    protected $table = 'customer_list';
    public $timestamps = false;

    protected $fillable = [
        'cust_id','cust_name','company_id','workstation_id','user_id'
    ];
}
