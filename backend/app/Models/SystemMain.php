<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemMain extends Model
{
    protected $fillable = ['system_id', 'system_name', 'sort_order'];

    public function modules(): HasMany
    {
        return $this->hasMany(ApplicationModule::class, 'system_main_id');
    }
}
