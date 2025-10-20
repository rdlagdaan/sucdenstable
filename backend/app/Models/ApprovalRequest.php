<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalRequest extends Model
{
  protected $fillable = [
    'company_id','subject_type','subject_id','action',
    'requested_by','reason','status','approved_by',
    'response_message','approval_token','expires_at'
  ];

  protected $casts = ['expires_at' => 'datetime'];
}
