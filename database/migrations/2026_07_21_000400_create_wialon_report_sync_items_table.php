<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wialon_report_sync_items')) {
            return;
        }

        Schema::create('wialon_report_sync_items', function (Blueprint $table): void {
            $table->id();
            $table->string('sync_type', 64);
            $table->date('report_date');
            $table->string('wialon_group_id', 64);
            $table->string('wialon_group_name')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('rows_received')->default(0);
            $table->unsignedInteger('rows_saved')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->string('run_id', 64)->nullable()->index();
            $table->timestamps();

            $table->unique(['sync_type', 'report_date', 'wialon_group_id'], 'wrsi_type_date_group_unique');
            $table->index(['sync_type', 'status', 'next_retry_at'], 'wrsi_type_status_retry_idx');
            $table->index(['report_date', 'wialon_group_id'], 'wrsi_date_group_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wialon_report_sync_items');
    }
};
