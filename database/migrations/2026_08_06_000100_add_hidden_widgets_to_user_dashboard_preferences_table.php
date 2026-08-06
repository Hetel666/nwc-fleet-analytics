<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_dashboard_preferences')
            || Schema::hasColumn('user_dashboard_preferences', 'hidden_widgets')) {
            return;
        }

        Schema::table('user_dashboard_preferences', function (Blueprint $table): void {
            $table->json('hidden_widgets')->nullable()->after('kpi_size');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_dashboard_preferences')
            || ! Schema::hasColumn('user_dashboard_preferences', 'hidden_widgets')) {
            return;
        }

        Schema::table('user_dashboard_preferences', function (Blueprint $table): void {
            $table->dropColumn('hidden_widgets');
        });
    }
};
