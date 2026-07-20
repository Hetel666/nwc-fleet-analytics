<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('historical_recalculations')) {
            return;
        }

        Schema::create('historical_recalculations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('signature', 64)->index();
            $table->string('status', 32)->default('pending')->index();
            $table->string('operation', 32);
            $table->string('scope', 32);
            $table->date('date_from');
            $table->date('date_to');
            $table->string('timezone', 64)->default('Asia/Baku');
            $table->boolean('force')->default(false);
            $table->json('project_ids')->nullable();
            $table->unsignedInteger('total_tasks')->default(0);
            $table->unsignedInteger('completed_tasks')->default(0);
            $table->unsignedInteger('failed_tasks')->default(0);
            $table->unsignedInteger('cancelled_tasks')->default(0);
            $table->unsignedInteger('processed_objects')->default(0);
            $table->string('batch_id')->nullable()->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['date_from', 'date_to'], 'hr_period_idx');
            $table->index(['status', 'created_at'], 'hr_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_recalculations');
    }
};
