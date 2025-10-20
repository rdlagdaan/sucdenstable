<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthList extends Model
{
    protected $table = 'month_list';
    public $timestamps = true; // created_at / updated_at exist
    protected $fillable = ['month_num','month_desc','workstation_id','user_id'];
}
