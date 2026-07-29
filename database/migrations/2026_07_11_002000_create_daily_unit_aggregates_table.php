<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_unit_aggregates')) {
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
        }

        if (! Schema::hasTable('daily_unit_aggregates')) {
            return;
        }

        // Existing production databases may already have the performance indexes
        // from a manual rollout, so this migration only creates the table.
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_unit_aggregates');
    }
};
