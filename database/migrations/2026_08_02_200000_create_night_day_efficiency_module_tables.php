<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('night_day_efficiency_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historical_recalculation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('total_tasks')->default(0);
            $table->unsignedInteger('pending_tasks')->default(0);
            $table->unsignedInteger('running_tasks')->default(0);
            $table->unsignedInteger('completed_tasks')->default(0);
            $table->unsignedInteger('failed_tasks')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('night_day_efficiency_sync_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('night_day_efficiency_sync_runs')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->text('wialon_group_id')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('report_rows_received')->default(0);
            $table->unsignedInteger('eligible_units_count')->default(0);
            $table->unsignedInteger('facts_saved_count')->default(0);
            $table->unsignedInteger('missing_units_count')->default(0);
            $table->unsignedInteger('unmatched_report_rows')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'project_id', 'business_date'], 'night_day_eff_task_unique');
            $table->index(['business_date', 'status'], 'night_day_eff_task_date_status_idx');
        });

        Schema::create('night_day_efficiency_daily_facts', function (Blueprint $table): void {
            $table->id();
            $table->date('business_date')->index();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('wialon_group_id');
            $table->string('wialon_unit_id');
            $table->string('unit_name');
            $table->string('vehicle_type');
            $table->string('ownership', 20);
            $table->decimal('engine_hours_decimal', 10, 2)->default(0);
            $table->unsignedInteger('engine_seconds')->default(0);
            $table->string('engine_hours_raw')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->decimal('mileage_km', 12, 2)->nullable();
            $table->string('mileage_raw')->nullable();
            $table->string('efficiency_status', 32)->index();
            $table->unsignedBigInteger('source_report_template_id');
            $table->string('source_report_name');
            $table->unsignedInteger('source_table_index')->nullable();
            $table->foreignId('sync_run_id')->nullable()->constrained('night_day_efficiency_sync_runs')->nullOnDelete();
            $table->foreignId('sync_task_id')->nullable()->constrained('night_day_efficiency_sync_tasks')->nullOnDelete();
            $table->json('raw_row_json')->nullable();
            $table->timestamps();

            $table->unique(['business_date', 'project_id', 'wialon_unit_id'], 'night_day_eff_fact_unique');
            $table->index(['business_date', 'ownership', 'efficiency_status'], 'night_day_eff_date_owner_status_idx');
            $table->index(['business_date', 'project_id', 'vehicle_type'], 'night_day_eff_date_project_type_idx');
        });

        Schema::create('night_day_efficiency_unmatched_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('night_day_efficiency_sync_tasks')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('wialon_group_id');
            $table->string('wialon_unit_id')->nullable();
            $table->string('unit_name')->nullable();
            $table->string('reason', 64);
            $table->json('raw_row_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('night_day_efficiency_unmatched_rows');
        Schema::dropIfExists('night_day_efficiency_daily_facts');
        Schema::dropIfExists('night_day_efficiency_sync_tasks');
        Schema::dropIfExists('night_day_efficiency_sync_runs');
    }
};
