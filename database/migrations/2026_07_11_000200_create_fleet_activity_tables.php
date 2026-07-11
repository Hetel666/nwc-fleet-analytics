<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->date('stat_date');
            $table->foreignId('equipment_id')->constrained('equipments')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ownership_type', 20)->index();
            $table->decimal('worked_hours', 8, 2)->default(0);
            $table->decimal('distance_km', 10, 2)->default(0);
            $table->decimal('utilization_percent', 5, 2)->default(0);
            $table->unsignedInteger('geofence_exit_count')->default(0);
            $table->unsignedInteger('outside_geofence_minutes')->default(0);
            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->string('calculation_source')->nullable();
            $table->string('calculation_status')->nullable();
            $table->timestamps();

            $table->unique(['stat_date', 'equipment_id']);
            $table->index('stat_date');
            $table->index('project_id');
            $table->index('equipment_id');
        });

        Schema::create('geofence_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipments')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('geofence_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('exit_at');
            $table->timestamp('return_at')->nullable();
            $table->unsignedInteger('outside_minutes')->default(0);
            $table->unsignedInteger('max_distance_meters')->nullable();
            $table->string('status', 20)->default('outside')->index();
            $table->timestamps();

            $table->index(['project_id', 'exit_at']);
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('geofence_events');
        Schema::dropIfExists('equipment_daily_stats');
    }
};
