<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DashboardDataImport extends Model
{
    public const MODULE_DAILY_EFFICIENCY = 'daily_efficiency';

    public const MODULE_MONTHLY_EFFICIENCY = 'monthly_efficiency';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_READY = 'ready';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_IMPORTING = 'importing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'original_file_names' => 'array',
            'stored_files' => 'array',
            'summary_json' => 'array',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(DashboardDataImportRow::class);
    }

    /** @return array<string, string> */
    public static function modules(): array
    {
        return [
            self::MODULE_DAILY_EFFICIENCY => 'Ümumi effektivlik və orta göstəricilər',
            self::MODULE_MONTHLY_EFFICIENCY => 'Aylıq effektivlik',
        ];
    }

    public function moduleLabel(): string
    {
        return self::modules()[$this->module] ?? $this->module;
    }

    public function canBeConfirmed(): bool
    {
        return $this->status === self::STATUS_READY && $this->accepted_rows > 0 && $this->rejected_rows === 0;
    }
}
