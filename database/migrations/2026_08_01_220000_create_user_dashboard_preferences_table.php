<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_dashboard_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('layout', 32)->default('standard');
            $table->string('theme', 16)->default('system');
            $table->string('density', 16)->default('comfortable');
            $table->string('sidebar_state', 16)->default('expanded');
            $table->string('donut_legend_position', 16)->default('right');
            $table->string('table_density', 16)->default('comfortable');
            $table->string('kpi_size', 16)->default('medium');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_dashboard_preferences');
    }
};
