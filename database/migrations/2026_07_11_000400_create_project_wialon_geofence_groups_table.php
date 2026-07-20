<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_wialon_geofence_groups')) {
            return;
        }

        Schema::create('project_wialon_geofence_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('wialon_resource_id');
            $table->string('wialon_resource_name')->nullable();
            $table->string('wialon_geofence_group_id');
            $table->string('name');
            $table->unsignedInteger('zones_count')->default(0);
            $table->timestamps();

            $table->unique(['wialon_resource_id', 'wialon_geofence_group_id'], 'project_wialon_geofence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_wialon_geofence_groups');
    }
};
