<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationSubModule extends Model
{
    protected $fillable = ['application_module_id', 'sub_module_name', 'component_path', 'sort_order'];

    public function module(): BelongsTo
    {
        return $this->belongsTo(ApplicationModule::class, 'application_module_id');
    }
}
