<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wialon_sync_checkpoints')) {
            Schema::create('wialon_sync_checkpoints', function (Blueprint $table): void {
                $table->id();
                $table->string('checkpoint_key', 100)->unique();
                $table->string('sync_type', 64)->index();
                $table->date('report_date')->nullable()->index();
                $table->unsignedBigInteger('project_id')->nullable()->index();
                $table->string('ownership_type', 20)->nullable();
                $table->string('wialon_group_id', 64)->nullable();
                $table->string('status', 32)->index();
                $table->unsignedInteger('equipment_count')->default(0);
                $table->json('payload')->nullable();
                $table->timestamp('completed_at')->nullable()->index();
                $table->timestamps();

                $table->index(
                    ['sync_type', 'report_date', 'status'],
                    'wsc_type_date_status_idx'
                );
            });
        }

        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('key', 'like', 'wialon_daily_engine_sync:%')
            ->orderBy('id')
            ->chunkById(500, function ($settings): void {
                foreach ($settings as $setting) {
                    $payload = json_decode((string) $setting->value, true);
                    $payload = is_array($payload) ? $payload : [];
                    $completedAt = $payload['synced_at'] ?? $setting->updated_at ?? now();

                    DB::table('wialon_sync_checkpoints')->updateOrInsert(
                        ['checkpoint_key' => $setting->key],
                        [
                            'sync_type' => 'daily_engine_stats',
                            'status' => (string) ($payload['status'] ?? 'unknown'),
                            'equipment_count' => (int) ($payload['equipment_count'] ?? 0),
                            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                            'completed_at' => $completedAt,
                            'created_at' => $setting->created_at ?? now(),
                            'updated_at' => $setting->updated_at ?? now(),
                        ]
                    );

                    DB::table('settings')->where('id', $setting->id)->delete();
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('wialon_sync_checkpoints');
    }
};
