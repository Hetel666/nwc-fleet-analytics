<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalRecalculationTask extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'historical_recalculation_id',
        'status',
        'operation',
        'stat_date',
        'project_id',
        'ownership_type',
        'attempts',
        'equipment_count',
        'error_message',
        'started_at',
        'completed_at',
        'last_heartbeat_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $task): void {
            $task->scope_key = self::makeScopeKey(
                (string) $task->operation,
                $task->stat_date,
                $task->project_id,
                $task->ownership_type
            );
        });
    }

    public static function makeScopeKey(
        string $operation,
        DateTimeInterface|string|null $statDate,
        int|string|null $projectId,
        ?string $ownershipType
    ): string {
        $date = $statDate instanceof DateTimeInterface
            ? $statDate->format('Y-m-d')
            : substr((string) ($statDate ?? ''), 0, 10);

        return hash('sha256', implode('|', [
            $operation,
            $date,
            $projectId === null ? '' : (string) $projectId,
            $ownershipType ?? '',
        ]));
    }

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(HistoricalRecalculation::class, 'historical_recalculation_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
