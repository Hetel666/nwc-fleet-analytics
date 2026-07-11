<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectWialonGroup extends Model
{
    protected $fillable = [
        'project_id',
        'wialon_group_id',
        'name',
        'ownership_type',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
