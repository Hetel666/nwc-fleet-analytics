<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efficiency_daily_facts', function (Blueprint $table): void {
            $table->dropUnique('eff_daily_project_unit_date_unique');
            $table->unique(
                ['business_date', 'project_id', 'wialon_unit_id', 'source_report_name'],
                'eff_daily_project_unit_source_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('efficiency_daily_facts', function (Blueprint $table): void {
            $table->dropUnique('eff_daily_project_unit_source_unique');
            $table->unique(['business_date', 'project_id', 'wialon_unit_id'], 'eff_daily_project_unit_date_unique');
        });
    }
};
