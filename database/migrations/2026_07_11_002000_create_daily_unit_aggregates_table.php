<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_unit_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('unit_id');
            $table->foreignId('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('equipment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ownership_type', 20)->index();
            $table->decimal('engine_hours', 10, 2)->default(0);
            $table->decimal('mileage', 12, 2)->default(0);
            $table->decimal('geofence_outside_hours', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['date', 'unit_id']);
            $table->index(['date', 'project_id']);
            $table->index(['date', 'equipment_type_id']);
            $table->index(['date', 'ownership_type']);
            $table->index(['project_id', 'equipment_type_id', 'ownership_type', 'date'], 'dua_project_type_owner_date_idx');
            $table->index(['equipment_id', 'date']);
        });

        Schema::table('equipment_daily_stats', function (Blueprint $table): void {
            $table->index(['stat_date', 'project_id', 'ownership_type'], 'eds_date_project_owner_idx');
            $table->index(['stat_date', 'equipment_id', 'project_id'], 'eds_date_unit_project_idx');
        });

        Schema::table('equipments', function (Blueprint $table): void {
            $table->index(['project_id', 'equipment_type_id', 'ownership_type', 'active'], 'equip_project_type_owner_active_idx');
            $table->index(['equipment_type_id', 'ownership_type'], 'equip_type_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::table('equipments', function (Blueprint $table): void {
            $table->dropIndex('equip_project_type_owner_active_idx');
            $table->dropIndex('equip_type_owner_idx');
        });

        Schema::table('equipment_daily_stats', function (Blueprint $table): void {
            $table->dropIndex('eds_date_project_owner_idx');
            $table->dropIndex('eds_date_unit_project_idx');
        });

        Schema::dropIfExists('daily_unit_aggregates');
    }
};
