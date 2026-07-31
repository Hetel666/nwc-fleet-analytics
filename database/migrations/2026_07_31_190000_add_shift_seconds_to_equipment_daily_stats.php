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
            if (! Schema::hasColumn('equipment_daily_stats', 'daytime_seconds')) {
                $table->unsignedInteger('daytime_seconds')->nullable()->after('daytime_hours')->index();
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'overtime_seconds')) {
                $table->unsignedInteger('overtime_seconds')->nullable()->after('overtime_hours')->index();
            }

            if (! Schema::hasColumn('equipment_daily_stats', 'total_seconds')) {
                $table->unsignedInteger('total_seconds')->nullable()->after('total_hours')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('equipment_daily_stats')) {
            return;
        }

        Schema::table('equipment_daily_stats', function (Blueprint $table): void {
            foreach (['daytime_seconds', 'overtime_seconds', 'total_seconds'] as $column) {
                if (Schema::hasColumn('equipment_daily_stats', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
