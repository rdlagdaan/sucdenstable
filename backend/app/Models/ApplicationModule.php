<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationModule extends Model
{
    protected $fillable = ['system_main_id', 'module_name', 'controller', 'sort_order'];

    public function systemMain(): BelongsTo
    {
        return $this->belongsTo(SystemMain::class, 'system_main_id');
    }

    public function subModules(): HasMany
    {
        return $this->hasMany(ApplicationSubModule::class, 'application_module_id');
    }
}
