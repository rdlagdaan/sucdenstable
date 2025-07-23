<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationUser extends Model
{
    protected $fillable = ['user_employee_id', 'application_sub_module_id'];

    public function user()
    {
        return $this->belongsTo(UsersEmployee::class, 'user_employee_id');
    }

    public function subModule()
    {
        return $this->belongsTo(ApplicationSubModule::class, 'application_sub_module_id');
    }
}
