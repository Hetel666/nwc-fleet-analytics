<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('equipment_daily_stats')) {
            return;
        }

        Schema::table('equipment_daily_stats', function (Blueprint $table): void {
            if (! Schema::hasColumn('equipment_daily_stats', 'daytime_hours')) {
                $table->decimal('daytime_hours', 8, 2)->nullable()->after('worked_hours');
                $table->index('daytime_hours');
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'total_hours')) {
                $table->decimal('total_hours', 8, 2)->nullable()->after('overtime_hours');
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'day_status')) {
                $table->string('day_status', 50)->nullable()->after('total_hours')->index();
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'has_overtime')) {
                $table->boolean('has_overtime')->nullable()->after('day_status')->index();
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'data_available')) {
                $table->boolean('data_available')->nullable()->after('has_overtime')->index();
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'daytime_data_available')) {
                $table->boolean('daytime_data_available')->nullable()->after('data_available');
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'overtime_data_available')) {
                $table->boolean('overtime_data_available')->nullable()->after('daytime_data_available');
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'report_resource_id')) {
                $table->string('report_resource_id')->nullable()->after('calculation_status');
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'report_template_id')) {
                $table->string('report_template_id')->nullable()->after('report_resource_id');
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'source_group_id')) {
                $table->string('source_group_id')->nullable()->after('report_template_id')->index();
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'source_intervals_json')) {
                $table->json('source_intervals_json')->nullable()->after('source_group_id');
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'calculated_at')) {
                $table->timestamp('calculated_at')->nullable()->after('source_intervals_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('equipment_daily_stats')) {
            return;
        }

        Schema::table('equipment_daily_stats', function (Blueprint $table): void {
            $columns = [
                'daytime_hours',
                'total_hours',
                'day_status',
                'has_overtime',
                'data_available',
                'daytime_data_available',
                'overtime_data_available',
                'report_resource_id',
                'report_template_id',
                'source_group_id',
                'source_intervals_json',
                'calculated_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('equipment_daily_stats', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
