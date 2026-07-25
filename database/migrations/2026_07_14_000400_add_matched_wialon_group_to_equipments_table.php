<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('equipments', 'matched_wialon_group_id')) {
            return;
        }

        Schema::table('equipments', function (Blueprint $table): void {
            $table->string('matched_wialon_group_id')->nullable()->after('project_wialon_group_id')->index();
            $table->string('matched_wialon_group_name')->nullable()->after('matched_wialon_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->dropColumn(['matched_wialon_group_id', 'matched_wialon_group_name']);
        });
    }
};
