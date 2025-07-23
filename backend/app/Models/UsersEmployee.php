<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;



class UsersEmployee extends Authenticatable 
{
    use HasApiTokens, Notifiable;
    
    protected $table = 'users_employees';

    protected $fillable = [
        'username',
        'email_address',
        'password',
        'salt',
        'activation_code',
        'forgotten_password_code',
        'forgotten_password_time',
        'remember_code',
        'date_created',
        'created_by',
        'last_login',
        'role_id',
        'status',
        'active',
        'first_name',
        'last_name',
        'middle_name',
        'time_stamp'
    ];

    protected $hidden = [
        'password',
        'remember_code',
    ];

    public $timestamps = false; // Assuming you use `date_created` and `time_stamp` instead of `created_at`/`updated_at`

    // If you're using a non-standard password column:
    public function getAuthPassword()
    {
        return $this->password;
    }    

}
