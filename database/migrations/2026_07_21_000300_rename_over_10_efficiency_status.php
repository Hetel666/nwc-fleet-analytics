<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('equipment_daily_stats')
            ->where('day_status', 'over_10_day_hours')
            ->update(['day_status' => 'over_10_hours']);
    }

    public function down(): void
    {
        DB::table('equipment_daily_stats')
            ->where('day_status', 'over_10_hours')
            ->update(['day_status' => 'over_10_day_hours']);
    }
};
