<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('statistic_backfill_items')) {
            return;
        }

        Schema::create('statistic_backfill_items', function (Blueprint $table): void {
            $table->id();
            $table->date('stat_date');
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('ownership_type', 20);
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('equipment_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['stat_date', 'project_id', 'ownership_type'], 'sbi_date_project_owner_unique');
            $table->index(['project_id', 'ownership_type', 'stat_date'], 'sbi_project_owner_date_idx');
            $table->index(['status', 'stat_date'], 'sbi_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistic_backfill_items');
    }
};
