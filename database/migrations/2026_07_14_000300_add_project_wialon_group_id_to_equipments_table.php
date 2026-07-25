<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('equipments', 'project_wialon_group_id')) {
            return;
        }

        Schema::table('equipments', function (Blueprint $table): void {
            $table->foreignId('project_wialon_group_id')
                ->nullable()
                ->after('project_id')
                ->constrained('project_wialon_groups')
                ->nullOnDelete();

            $table->index(['project_wialon_group_id', 'active'], 'equip_project_wialon_group_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->dropIndex('equip_project_wialon_group_active_idx');
            $table->dropConstrainedForeignId('project_wialon_group_id');
        });
    }
};
