<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            if (! Schema::hasColumn('equipments', 'project_wialon_group_id')) {
                $table->foreignId('project_wialon_group_id')
                    ->nullable()
                    ->constrained('project_wialon_groups')
                    ->nullOnDelete();

                $table->index(['project_wialon_group_id', 'active'], 'equip_project_wialon_group_active_idx');
            }

            if (! Schema::hasColumn('equipments', 'matched_wialon_group_id')) {
                $table->string('matched_wialon_group_id')->nullable()->index();
            }

            if (! Schema::hasColumn('equipments', 'matched_wialon_group_name')) {
                $table->string('matched_wialon_group_name')->nullable();
            }

            if (! Schema::hasColumn('equipments', 'excluded_from_dashboard')) {
                $table->boolean('excluded_from_dashboard')->default(false)->index();
            }

            if (! Schema::hasColumn('equipments', 'dashboard_exclusion_reason')) {
                $table->string('dashboard_exclusion_reason')->nullable();
            }
        });

        Schema::table('geofences', function (Blueprint $table): void {
            if (! Schema::hasColumn('geofences', 'normalized_name')) {
                $table->string('normalized_name')->nullable()->index();
            }
        });

        Schema::table('project_wialon_groups', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_wialon_groups', 'is_active')) {
                $table->boolean('is_active')->default(true)->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_wialon_groups', function (Blueprint $table): void {
            if (Schema::hasColumn('project_wialon_groups', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });

        Schema::table('geofences', function (Blueprint $table): void {
            if (Schema::hasColumn('geofences', 'normalized_name')) {
                $table->dropColumn('normalized_name');
            }
        });

        Schema::table('equipments', function (Blueprint $table): void {
            if (Schema::hasColumn('equipments', 'project_wialon_group_id')) {
                $table->dropConstrainedForeignId('project_wialon_group_id');
            }

            foreach ([
                'matched_wialon_group_id',
                'matched_wialon_group_name',
                'excluded_from_dashboard',
                'dashboard_exclusion_reason',
            ] as $column) {
                if (Schema::hasColumn('equipments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
