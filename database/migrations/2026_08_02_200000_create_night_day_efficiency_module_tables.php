<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('night_day_efficiency_sync_runs')) {
            Schema::create('night_day_efficiency_sync_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('historical_recalculation_id')->nullable();
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
                $table->unsignedBigInteger('created_by')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->unique('historical_recalculation_id', 'night_day_eff_run_hist_unique');
                $table->index('status', 'night_day_eff_run_status_idx');
                $table->foreign('historical_recalculation_id', 'night_day_eff_run_hist_fk')->references('id')->on('historical_recalculations')->nullOnDelete();
                $table->foreign('created_by', 'night_day_eff_run_user_fk')->references('id')->on('users')->nullOnDelete();
            });
        } else {
            $this->ensureSyncRunIndexesAndForeignKeys();
        }

        if (! Schema::hasTable('night_day_efficiency_sync_tasks')) {
            Schema::create('night_day_efficiency_sync_tasks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id');
                $table->unsignedBigInteger('project_id');
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
                $table->foreign('run_id', 'night_day_eff_task_run_fk')->references('id')->on('night_day_efficiency_sync_runs')->cascadeOnDelete();
                $table->foreign('project_id', 'night_day_eff_task_project_fk')->references('id')->on('projects')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('night_day_efficiency_daily_facts')) {
            Schema::create('night_day_efficiency_daily_facts', function (Blueprint $table): void {
                $table->id();
                $table->date('business_date')->index();
                $table->unsignedBigInteger('project_id');
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
                $table->unsignedBigInteger('sync_run_id')->nullable();
                $table->unsignedBigInteger('sync_task_id')->nullable();
                $table->json('raw_row_json')->nullable();
                $table->timestamps();

                $table->unique(['business_date', 'project_id', 'wialon_unit_id'], 'night_day_eff_fact_unique');
                $table->index(['business_date', 'ownership', 'efficiency_status'], 'night_day_eff_date_owner_status_idx');
                $table->index(['business_date', 'project_id', 'vehicle_type'], 'night_day_eff_date_project_type_idx');
                $table->foreign('project_id', 'night_day_eff_fact_project_fk')->references('id')->on('projects')->cascadeOnDelete();
                $table->foreign('sync_run_id', 'night_day_eff_fact_run_fk')->references('id')->on('night_day_efficiency_sync_runs')->nullOnDelete();
                $table->foreign('sync_task_id', 'night_day_eff_fact_task_fk')->references('id')->on('night_day_efficiency_sync_tasks')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('night_day_efficiency_unmatched_rows')) {
            Schema::create('night_day_efficiency_unmatched_rows', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('task_id');
                $table->unsignedBigInteger('project_id');
                $table->date('business_date');
                $table->string('wialon_group_id');
                $table->string('wialon_unit_id')->nullable();
                $table->string('unit_name')->nullable();
                $table->string('reason', 64);
                $table->json('raw_row_json')->nullable();
                $table->timestamps();

                $table->foreign('task_id', 'night_day_eff_unmatched_task_fk')->references('id')->on('night_day_efficiency_sync_tasks')->cascadeOnDelete();
                $table->foreign('project_id', 'night_day_eff_unmatched_project_fk')->references('id')->on('projects')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('night_day_efficiency_unmatched_rows');
        Schema::dropIfExists('night_day_efficiency_daily_facts');
        Schema::dropIfExists('night_day_efficiency_sync_tasks');
        Schema::dropIfExists('night_day_efficiency_sync_runs');
    }

    private function ensureSyncRunIndexesAndForeignKeys(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->addIndexIfMissing('night_day_efficiency_sync_runs', 'night_day_eff_run_hist_unique',
            'ALTER TABLE night_day_efficiency_sync_runs ADD UNIQUE night_day_eff_run_hist_unique (historical_recalculation_id)');
        $this->addIndexIfMissing('night_day_efficiency_sync_runs', 'night_day_eff_run_status_idx',
            'ALTER TABLE night_day_efficiency_sync_runs ADD INDEX night_day_eff_run_status_idx (status)');
        $this->addForeignIfMissing('night_day_efficiency_sync_runs', 'night_day_eff_run_hist_fk',
            'ALTER TABLE night_day_efficiency_sync_runs ADD CONSTRAINT night_day_eff_run_hist_fk FOREIGN KEY (historical_recalculation_id) REFERENCES historical_recalculations (id) ON DELETE SET NULL');
        $this->addForeignIfMissing('night_day_efficiency_sync_runs', 'night_day_eff_run_user_fk',
            'ALTER TABLE night_day_efficiency_sync_runs ADD CONSTRAINT night_day_eff_run_user_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL');
    }

    private function addIndexIfMissing(string $table, string $index, string $sql): void
    {
        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();

        if (! $exists) {
            DB::statement($sql);
        }
    }

    private function addForeignIfMissing(string $table, string $constraint, string $sql): void
    {
        $exists = DB::table('information_schema.referential_constraints')
            ->where('constraint_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->exists();

        if (! $exists) {
            DB::statement($sql);
        }
    }
};
