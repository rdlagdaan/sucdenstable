<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountCode extends Model
{
    protected $table = 'account_code';
    public $timestamps = false;

    protected $fillable = [
        'acct_number','main_acct','main_acct_code','acct_code','acct_desc',
        'fs','acct_group','acct_group_sub1','acct_group_sub2','normal_bal','acct_type',
        'cash_disbursement_flag','bank_id','vessel_flag','booking_no','ap_ar',
        'active_flag','exclude','workstation_id','user_id','company_id'
    ];
}
