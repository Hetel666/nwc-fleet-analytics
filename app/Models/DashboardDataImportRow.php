<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardDataImportRow extends Model
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DUPLICATE = 'duplicate';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(DashboardDataImport::class, 'dashboard_data_import_id');
    }
}
