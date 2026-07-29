<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceViolationReportRow extends Model
{
    public const REPORT_NAME = 'Geofence Pozuntuları api';

    public const MINIMUM_DURATION_SECONDS = 10_800;

    protected $fillable = [
        'report_name',
        'period_key',
        'equipment_id',
        'project_id',
        'wialon_unit_id',
        'equipment_name',
        'equipment_type',
        'ownership_type',
        'project_name',
        'last_project_geofence',
        'exited_at',
        'last_confirmed_at',
        'ended_at',
        'outside_duration_seconds',
        'last_location',
        'is_active',
        'report_generated_at',
        'source_payload',
    ];

    protected function casts(): array
    {
        return [
            'exited_at' => 'datetime',
            'last_confirmed_at' => 'datetime',
            'ended_at' => 'datetime',
            'outside_duration_seconds' => 'integer',
            'is_active' => 'boolean',
            'report_generated_at' => 'datetime',
            'source_payload' => 'array',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->is_active ? 'Aktiv pozuntu' : 'Tamamlanmış pozuntu';
    }

    public function getDurationLabelAttribute(): string
    {
        $seconds = max(0, (int) $this->outside_duration_seconds);
        $days = intdiv($seconds, 86_400);
        $hours = intdiv($seconds % 86_400, 3_600);
        $minutes = intdiv($seconds % 3_600, 60);
        $remainingSeconds = $seconds % 60;
        $parts = [];

        if ($days > 0) {
            $parts[] = $days.' gün';
        }

        $parts[] = $hours.' saat';
        $parts[] = $minutes.' dəqiqə';

        if ($remainingSeconds > 0) {
            $parts[] = $remainingSeconds.' saniyə';
        }

        return implode(' ', $parts);
    }
}
