<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardEventNote extends Model
{
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_JUSTIFIED = 'justified';
    public const STATUS_CONFIRMED = 'confirmed_violation';
    public const STATUS_SYSTEM_ERROR = 'system_error';

    protected $fillable = [
        'event_key',
        'dashboard_key',
        'event_type',
        'event_date',
        'project_id',
        'equipment_id',
        'wialon_unit_id',
        'unit_name',
        'event_status',
        'note',
        'investigation_status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function investigationStatusLabels(): array
    {
        return [
            self::STATUS_INVESTIGATING => 'Araşdırılır',
            self::STATUS_JUSTIFIED => 'Əsaslandırıldı',
            self::STATUS_CONFIRMED => 'Təsdiqlənmiş pozuntu',
            self::STATUS_SYSTEM_ERROR => 'Sistem xətası',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
