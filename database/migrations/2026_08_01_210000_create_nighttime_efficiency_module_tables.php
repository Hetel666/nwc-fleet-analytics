<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nighttime_efficiency_sync_runs')) {
            Schema::create('nighttime_efficiency_sync_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('historical_recalculation_id')->nullable();
                $table->date('date_from');
                $table->date('date_to');
                $table->string('status', 32)->default('pending');
                $table->unsignedInteger('total_tasks')->default(0);
                $table->unsignedInteger('pending_tasks')->default(0);
                $table->unsignedInteger('running_tasks')->default(0);
                $table->unsignedInteger('completed_tasks')->default(0);
                $table->unsignedInteger('failed_tasks')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('created_by')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->unique('historical_recalculation_id', 'night_eff_run_history_unique');
                $table->index('status', 'night_eff_run_status_idx');
                $table->foreign('historical_recalculation_id', 'night_eff_run_history_fk')
                    ->references('id')->on('historical_recalculations')->nullOnDelete();
                $table->foreign('created_by', 'night_eff_run_creator_fk')
                    ->references('id')->on('users')->nullOnDelete();
            });
        } else {
            $indexes = collect(Schema::getIndexes('nighttime_efficiency_sync_runs'))->pluck('name');
            $foreignKeys = collect(Schema::getForeignKeys('nighttime_efficiency_sync_runs'))->pluck('name');

            Schema::table('nighttime_efficiency_sync_runs', function (Blueprint $table) use ($indexes, $foreignKeys): void {
                if (! $indexes->contains('night_eff_run_history_unique')) {
                    $table->unique('historical_recalculation_id', 'night_eff_run_history_unique');
                }
                if (! $indexes->contains('night_eff_run_status_idx')) {
                    $table->index('status', 'night_eff_run_status_idx');
                }
                if (! $foreignKeys->contains('night_eff_run_history_fk')) {
                    $table->foreign('historical_recalculation_id', 'night_eff_run_history_fk')
                        ->references('id')->on('historical_recalculations')->nullOnDelete();
                }
                if (! $foreignKeys->contains('night_eff_run_creator_fk')) {
                    $table->foreign('created_by', 'night_eff_run_creator_fk')
                        ->references('id')->on('users')->nullOnDelete();
                }
            });
        }

        Schema::create('nighttime_efficiency_sync_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('nighttime_efficiency_sync_runs')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('shift_date');
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

            $table->unique(['run_id', 'project_id', 'shift_date'], 'night_eff_task_unique');
            $table->index(['shift_date', 'status'], 'night_eff_task_date_status_idx');
        });

        Schema::create('nighttime_efficiency_daily_facts', function (Blueprint $table): void {
            $table->id();
            $table->date('shift_date')->index();
            $table->timestamp('shift_started_at');
            $table->timestamp('shift_ended_at');
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
            $table->unsignedInteger('evening_engine_seconds')->nullable();
            $table->unsignedInteger('morning_engine_seconds')->nullable();
            $table->decimal('mileage_km', 12, 2)->nullable();
            $table->string('mileage_raw')->nullable();
            $table->string('efficiency_status', 32)->index();
            $table->unsignedBigInteger('source_report_template_id');
            $table->string('source_report_name');
            $table->unsignedInteger('source_table_index')->nullable();
            $table->string('source_mode', 32)->default('single_cross_midnight');
            $table->json('source_parts_json')->nullable();
            $table->foreignId('sync_run_id')->nullable()->constrained('nighttime_efficiency_sync_runs')->nullOnDelete();
            $table->foreignId('sync_task_id')->nullable()->constrained('nighttime_efficiency_sync_tasks')->nullOnDelete();
            $table->json('raw_row_json')->nullable();
            $table->timestamps();

            $table->unique(['shift_date', 'project_id', 'wialon_unit_id'], 'night_eff_fact_unique');
            $table->index(['shift_date', 'ownership', 'efficiency_status'], 'night_eff_date_owner_status_idx');
            $table->index(['shift_date', 'project_id', 'vehicle_type'], 'night_eff_date_project_type_idx');
        });

        Schema::create('nighttime_efficiency_unmatched_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('nighttime_efficiency_sync_tasks')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('shift_date');
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
        Schema::dropIfExists('nighttime_efficiency_unmatched_rows');
        Schema::dropIfExists('nighttime_efficiency_daily_facts');
        Schema::dropIfExists('nighttime_efficiency_sync_tasks');
        Schema::dropIfExists('nighttime_efficiency_sync_runs');
    }
};
