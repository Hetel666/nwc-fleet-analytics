<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('equipments', 'excluded_from_dashboard')) {
            return;
        }

        Schema::table('equipments', function (Blueprint $table): void {
            $table->boolean('excluded_from_dashboard')->default(false)->index()->after('active');
            $table->string('dashboard_exclusion_reason')->nullable()->after('excluded_from_dashboard');
        });
    }

    public function down(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->dropIndex(['excluded_from_dashboard']);
            $table->dropColumn(['excluded_from_dashboard', 'dashboard_exclusion_reason']);
        });
    }
};
