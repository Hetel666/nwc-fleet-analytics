<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('historical_recalculation_tasks')) {
            return;
        }

        Schema::create('historical_recalculation_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('historical_recalculation_id');
            $table->string('status', 32)->default('pending')->index();
            $table->string('operation', 32);
            $table->date('stat_date')->nullable();
            $table->foreignId('project_id')->nullable();
            $table->string('ownership_type', 20)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('equipment_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['historical_recalculation_id', 'operation', 'stat_date', 'project_id', 'ownership_type'],
                'hrt_run_scope_unique'
            );
            $table->index(['project_id', 'ownership_type', 'stat_date'], 'hrt_project_owner_date_idx');
            $table->index(['status', 'stat_date'], 'hrt_status_date_idx');
            $table->foreign('historical_recalculation_id', 'hrt_run_fk')
                ->references('id')
                ->on('historical_recalculations')
                ->cascadeOnDelete();
            $table->foreign('project_id', 'hrt_project_fk')
                ->references('id')
                ->on('projects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_recalculation_tasks');
    }
};
