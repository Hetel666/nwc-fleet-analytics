<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('equipment_daily_stats') || Schema::hasColumn('equipment_daily_stats', 'overtime_hours')) {
            return;
        }

        Schema::table('equipment_daily_stats', function (Blueprint $table): void {
            $table->decimal('overtime_hours', 8, 2)->nullable()->after('worked_hours');
            $table->index('overtime_hours');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('equipment_daily_stats') || ! Schema::hasColumn('equipment_daily_stats', 'overtime_hours')) {
            return;
        }

        Schema::table('equipment_daily_stats', function (Blueprint $table): void {
            $table->dropIndex(['overtime_hours']);
            $table->dropColumn('overtime_hours');
        });
    }
};
