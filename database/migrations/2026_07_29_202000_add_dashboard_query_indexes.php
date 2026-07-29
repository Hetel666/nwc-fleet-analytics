<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing(
            'equipments',
            'equip_project_type_owner_active_idx',
            ['project_id', 'equipment_type_id', 'ownership_type', 'active']
        );
        $this->addIndexIfMissing(
            'equipments',
            'equip_type_owner_idx',
            ['equipment_type_id', 'ownership_type']
        );
        $this->addIndexIfMissing(
            'equipment_daily_stats',
            'eds_date_project_owner_idx',
            ['stat_date', 'project_id', 'ownership_type']
        );
        $this->addIndexIfMissing(
            'equipment_daily_stats',
            'eds_date_unit_project_idx',
            ['stat_date', 'equipment_id', 'project_id']
        );
    }

    public function down(): void
    {
        $this->dropIndexIfPresent('equipments', 'equip_project_type_owner_active_idx');
        $this->dropIndexIfPresent('equipments', 'equip_type_owner_idx');
        $this->dropIndexIfPresent('equipment_daily_stats', 'eds_date_project_owner_idx');
        $this->dropIndexIfPresent('equipment_daily_stats', 'eds_date_unit_project_idx');
    }

    private function addIndexIfMissing(string $table, string $name, array $columns): void
    {
        if ($this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndexIfPresent(string $table, string $name): void
    {
        if (! $this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }
};
