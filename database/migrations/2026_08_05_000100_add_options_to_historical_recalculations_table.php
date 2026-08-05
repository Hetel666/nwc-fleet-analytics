<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('historical_recalculations')
            || Schema::hasColumn('historical_recalculations', 'options_json')) {
            return;
        }

        Schema::table('historical_recalculations', function (Blueprint $table): void {
            $table->json('options_json')->nullable()->after('project_ids');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('historical_recalculations')
            || ! Schema::hasColumn('historical_recalculations', 'options_json')) {
            return;
        }

        Schema::table('historical_recalculations', function (Blueprint $table): void {
            $table->dropColumn('options_json');
        });
    }
};
