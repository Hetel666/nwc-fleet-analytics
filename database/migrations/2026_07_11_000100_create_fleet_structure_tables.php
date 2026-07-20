<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('code')->nullable()->index();
                $table->text('description')->nullable();
                $table->boolean('active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('equipment_types')) {
            Schema::create('equipment_types', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('equipments')) {
            Schema::create('equipments', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('registration_number')->nullable();
                $table->string('wialon_unit_id')->unique();
                $table->foreignId('equipment_type_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
                $table->string('ownership_type', 20)->index();
                $table->string('calculation_mode', 30)->default('engine_hours');
                $table->decimal('planned_daily_hours', 5, 2)->default(10);
                $table->boolean('active')->default(true)->index();
                $table->timestamp('last_synced_at')->nullable();
                $table->json('last_position_json')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'ownership_type']);
            });
        }

        if (! Schema::hasTable('geofences')) {
            Schema::create('geofences', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->string('wialon_geofence_id')->nullable()->index();
                $table->json('geometry_json')->nullable();
                $table->boolean('active')->default(true)->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('geofences');
        Schema::dropIfExists('equipments');
        Schema::dropIfExists('equipment_types');
        Schema::dropIfExists('projects');
    }
};
