<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unit_foreign_geofence_intervals')) {
            return;
        }

        Schema::create('unit_foreign_geofence_intervals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->string('wialon_unit_id')->nullable()->index();
            $table->string('source_group_id')->nullable()->index();
            $table->string('source_group_name')->nullable();
            $table->json('source_group_ids_json')->nullable();
            $table->string('ownership_type', 20)->nullable()->index();
            $table->foreignId('home_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('home_project_name')->nullable();
            $table->foreignId('home_geofence_id')->nullable()->constrained('geofences')->nullOnDelete();
            $table->json('home_geofence_ids_json')->nullable();
            $table->json('home_geofence_names_json')->nullable();
            $table->foreignId('foreign_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('foreign_project_name')->nullable();
            $table->foreignId('foreign_geofence_id')->nullable()->constrained('geofences')->nullOnDelete();
            $table->string('foreign_geofence_name')->nullable();
            $table->timestamp('entered_at');
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('last_position_at')->nullable();
            $table->decimal('entered_latitude', 10, 7)->nullable();
            $table->decimal('entered_longitude', 10, 7)->nullable();
            $table->decimal('left_latitude', 10, 7)->nullable();
            $table->decimal('left_longitude', 10, 7)->nullable();
            $table->timestamp('report_from')->nullable()->index();
            $table->timestamp('report_to')->nullable()->index();
            $table->string('report_resource_id')->nullable();
            $table->string('report_template_id')->nullable();
            $table->string('report_table_name')->nullable();
            $table->string('reported_project')->nullable();
            $table->boolean('project_mismatch')->default(false)->index();
            $table->string('match_method', 40)->nullable();
            $table->string('match_status', 40)->nullable()->index();
            $table->string('reason', 80)->nullable()->index();
            $table->string('source', 40)->default('local_position')->index();
            $table->string('unique_key')->nullable()->unique();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'foreign_project_id'], 'ufgi_status_foreign_project_idx');
            $table->index(['unit_id', 'status'], 'ufgi_unit_status_idx');
            $table->index(['entered_at', 'last_position_at'], 'ufgi_entered_last_position_idx');
            $table->index(['source', 'entered_at', 'left_at'], 'ufgi_source_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_foreign_geofence_intervals');
    }
};
