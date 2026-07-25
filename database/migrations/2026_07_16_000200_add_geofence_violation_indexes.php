<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geofence_events', function (Blueprint $table): void {
            $table->index(['status', 'return_at', 'exit_at'], 'geofence_events_status_return_exit_idx');
            $table->index(['geofence_id', 'outside_minutes'], 'geofence_events_geofence_minutes_idx');
        });
    }

    public function down(): void
    {
        Schema::table('geofence_events', function (Blueprint $table): void {
            $table->dropIndex('geofence_events_status_return_exit_idx');
            $table->dropIndex('geofence_events_geofence_minutes_idx');
        });
    }
};
