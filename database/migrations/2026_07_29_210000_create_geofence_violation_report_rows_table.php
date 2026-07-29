<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofence_violation_report_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('report_name')->index();
            $table->string('period_key')->unique();
            $table->foreignId('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('wialon_unit_id')->nullable()->index();
            $table->string('equipment_name');
            $table->string('equipment_type')->index();
            $table->string('ownership_type', 20)->nullable()->index();
            $table->string('project_name')->nullable();
            $table->string('last_project_geofence')->nullable();
            $table->timestamp('exited_at')->index();
            $table->timestamp('last_confirmed_at')->index();
            $table->timestamp('ended_at')->nullable()->index();
            $table->unsignedBigInteger('outside_duration_seconds')->index();
            $table->string('last_location', 1000)->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('report_generated_at')->nullable()->index();
            $table->json('source_payload')->nullable();
            $table->timestamps();

            $table->index(
                ['report_name', 'exited_at', 'last_confirmed_at'],
                'geofence_violation_report_period_idx'
            );
            $table->index(
                ['report_name', 'is_active', 'equipment_type'],
                'geofence_violation_report_status_type_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofence_violation_report_rows');
    }
};
