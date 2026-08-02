<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardConfigurationAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_user_id',
        'action',
        'entity_type',
        'entity_code',
        'old_value_json',
        'new_value_json',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'old_value_json' => 'array',
            'new_value_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
