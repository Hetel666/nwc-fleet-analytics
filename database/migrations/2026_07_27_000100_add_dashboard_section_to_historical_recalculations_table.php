<?php

use App\Models\HistoricalRecalculation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('historical_recalculations')
            || Schema::hasColumn('historical_recalculations', 'dashboard_section')) {
            return;
        }

        Schema::table('historical_recalculations', function (Blueprint $table): void {
            $table->string('dashboard_section', 64)
                ->default(HistoricalRecalculation::SECTION_DAILY_AVERAGES)
                ->after('status')
                ->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('historical_recalculations')
            || ! Schema::hasColumn('historical_recalculations', 'dashboard_section')) {
            return;
        }

        Schema::table('historical_recalculations', function (Blueprint $table): void {
            $table->dropColumn('dashboard_section');
        });
    }
};
