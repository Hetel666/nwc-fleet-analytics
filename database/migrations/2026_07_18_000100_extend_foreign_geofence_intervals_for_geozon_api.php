<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('unit_foreign_geofence_intervals')) {
            return;
        }

        Schema::table('project_wialon_groups', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_wialon_groups', 'is_active')) {
                $table->boolean('is_active')->default(true)->index()->after('ownership_type');
            }
        });

        Schema::table('geofences', function (Blueprint $table): void {
            if (! Schema::hasColumn('geofences', 'normalized_name')) {
                $table->string('normalized_name')->nullable()->index()->after('name');
            }
        });

        Schema::table('unit_foreign_geofence_intervals', function (Blueprint $table): void {
            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'wialon_unit_id')) {
                $table->string('wialon_unit_id')->nullable()->index()->after('unit_id');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'source_group_id')) {
                $table->string('source_group_id')->nullable()->index()->after('wialon_unit_id');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'source_group_name')) {
                $table->string('source_group_name')->nullable()->after('source_group_id');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'source_group_ids_json')) {
                $table->json('source_group_ids_json')->nullable()->after('source_group_name');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'ownership_type')) {
                $table->string('ownership_type', 20)->nullable()->index()->after('source_group_ids_json');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'home_project_name')) {
                $table->string('home_project_name')->nullable()->after('home_project_id');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'home_geofence_ids_json')) {
                $table->json('home_geofence_ids_json')->nullable()->after('home_geofence_id');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'home_geofence_names_json')) {
                $table->json('home_geofence_names_json')->nullable()->after('home_geofence_ids_json');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'foreign_project_name')) {
                $table->string('foreign_project_name')->nullable()->after('foreign_project_id');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'foreign_geofence_name')) {
                $table->string('foreign_geofence_name')->nullable()->after('foreign_geofence_id');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'report_from')) {
                $table->timestamp('report_from')->nullable()->index()->after('left_longitude');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'report_to')) {
                $table->timestamp('report_to')->nullable()->index()->after('report_from');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'report_resource_id')) {
                $table->string('report_resource_id')->nullable()->after('report_to');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'report_template_id')) {
                $table->string('report_template_id')->nullable()->after('report_resource_id');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'report_table_name')) {
                $table->string('report_table_name')->nullable()->after('report_template_id');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'reported_project')) {
                $table->string('reported_project')->nullable()->after('report_table_name');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'project_mismatch')) {
                $table->boolean('project_mismatch')->default(false)->index()->after('reported_project');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'match_method')) {
                $table->string('match_method', 40)->nullable()->after('project_mismatch');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'match_status')) {
                $table->string('match_status', 40)->nullable()->index()->after('match_method');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'reason')) {
                $table->string('reason', 80)->nullable()->index()->after('match_status');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'source')) {
                $table->string('source', 40)->default('local_position')->index()->after('reason');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'unique_key')) {
                $table->string('unique_key')->nullable()->unique()->after('source');
            }

            if (! Schema::hasColumn('unit_foreign_geofence_intervals', 'calculated_at')) {
                $table->timestamp('calculated_at')->nullable()->after('unique_key');
            }
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE unit_foreign_geofence_intervals MODIFY unit_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE unit_foreign_geofence_intervals MODIFY foreign_project_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE unit_foreign_geofence_intervals MODIFY foreign_geofence_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE unit_foreign_geofence_intervals MODIFY last_position_at TIMESTAMP NULL');
            DB::statement('ALTER TABLE unit_foreign_geofence_intervals MODIFY entered_latitude DECIMAL(10,7) NULL');
            DB::statement('ALTER TABLE unit_foreign_geofence_intervals MODIFY entered_longitude DECIMAL(10,7) NULL');
        }
    }

    public function down(): void
    {
        // Kept intentionally non-destructive because this migration can run on production data.
    }
};
