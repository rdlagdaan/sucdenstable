<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountMain extends Model
{
    protected $table = 'account_main';

    protected $fillable = [
        'main_acct_code','main_acct','company_id','workstation_id','user_id',
    ];

    public $timestamps = true;
}
