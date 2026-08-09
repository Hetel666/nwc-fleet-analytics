<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $table = 'monthly_efficiency_unit_geofence_facts';

    public function up(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table): void {
            if (! Schema::hasColumn($this->table, 'project_id')) {
                $table->unsignedBigInteger('project_id')->nullable()->after('equipment_id');
            }

            if (! Schema::hasColumn($this->table, 'project_wialon_group_id')) {
                $table->unsignedBigInteger('project_wialon_group_id')->nullable()->after('project_id');
            }

            if (! Schema::hasColumn($this->table, 'wialon_group_id')) {
                $table->string('wialon_group_id', 64)->nullable()->after('project_wialon_group_id');
            }
        });

        $this->addIndexIfMissing('monthly_eff_unit_geo_project_date_type_idx', ['project_id', 'stat_date', 'segment_type']);
        $this->addIndexIfMissing('monthly_eff_unit_geo_project_group_date_idx', ['project_wialon_group_id', 'stat_date']);
        $this->addIndexIfMissing('monthly_eff_unit_geo_wialon_group_date_idx', ['wialon_group_id', 'stat_date']);
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table)) {
            return;
        }

        $this->dropIndexIfExists('monthly_eff_unit_geo_project_date_type_idx');
        $this->dropIndexIfExists('monthly_eff_unit_geo_project_group_date_idx');
        $this->dropIndexIfExists('monthly_eff_unit_geo_wialon_group_date_idx');

        Schema::table($this->table, function (Blueprint $table): void {
            if (Schema::hasColumn($this->table, 'project_id')) {
                $table->dropColumn('project_id');
            }

            if (Schema::hasColumn($this->table, 'project_wialon_group_id')) {
                $table->dropColumn('project_wialon_group_id');
            }

            if (Schema::hasColumn($this->table, 'wialon_group_id')) {
                $table->dropColumn('wialon_group_id');
            }
        });
    }

    /** @param array<int, string> $columns */
    private function addIndexIfMissing(string $name, array $columns): void
    {
        if ($this->indexExists($name)) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $name): void
    {
        if (! $this->indexExists($name)) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
        });
    }

    private function indexExists(string $name): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $result = DB::selectOne(
                'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$this->table, $name],
            );

            return (int) ($result->aggregate ?? 0) > 0;
        }

        if ($driver === 'sqlite') {
            $result = DB::selectOne("SELECT COUNT(*) AS aggregate FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]);

            return (int) ($result->aggregate ?? 0) > 0;
        }

        return false;
    }
};
