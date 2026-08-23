<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_data_imports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('module', 40)->index();
            $table->date('date_from');
            $table->date('date_to');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ownership_type', 20)->nullable()->index();
            $table->string('status', 32)->default('validating')->index();
            $table->json('original_file_names');
            $table->json('stored_files');
            $table->string('checksum', 64)->index();
            $table->unsignedInteger('source_rows')->default(0);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('written_rows')->default(0);
            $table->unsignedInteger('deleted_rows')->default(0);
            $table->json('summary_json')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['module', 'date_from', 'date_to'], 'dashboard_import_module_period_idx');
        });

        Schema::create('dashboard_data_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dashboard_data_import_id')->constrained('dashboard_data_imports')->cascadeOnDelete();
            $table->unsignedInteger('file_index')->default(0);
            $table->string('file_name');
            $table->string('sheet_name')->nullable();
            $table->unsignedInteger('source_row_number')->nullable();
            $table->string('row_key', 191)->nullable()->index();
            $table->string('status', 24)->index();
            $table->text('message')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['dashboard_data_import_id', 'status'], 'dashboard_import_rows_import_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_data_import_rows');
        Schema::dropIfExists('dashboard_data_imports');
    }
};
