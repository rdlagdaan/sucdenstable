<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsersEmployee extends Model
{
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

    public $timestamps = false; // Assuming you use `date_created` and `time_stamp` instead of `created_at`/`updated_at`
}
