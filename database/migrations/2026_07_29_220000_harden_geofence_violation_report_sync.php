<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geofence_violation_report_rows', function (Blueprint $table): void {
            $table->foreignId('project_wialon_group_id')
                ->nullable()
                ->after('project_id')
                ->constrained('project_wialon_groups')
                ->nullOnDelete();
            $table->timestamp('report_period_from')->nullable()->after('is_active')->index();
            $table->timestamp('report_period_to')->nullable()->after('report_period_from')->index();
            $table->index(
                ['project_wialon_group_id', 'exited_at', 'last_confirmed_at'],
                'geofence_violation_group_period_idx'
            );
        });

        Schema::create('geofence_violation_sync_items', function (Blueprint $table): void {
            $table->id();
            $table->string('checkpoint_key', 40)->unique();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('project_wialon_group_id')
                ->nullable()
                ->constrained('project_wialon_groups')
                ->nullOnDelete();
            $table->string('wialon_group_id', 64)->index();
            $table->string('wialon_group_name')->nullable();
            $table->string('ownership_type', 20)->nullable()->index();
            $table->timestamp('report_period_from');
            $table->timestamp('report_period_to');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('source_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('malformed_rows')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['project_id', 'report_period_from', 'report_period_to'],
                'geofence_violation_sync_project_period_idx'
            );
            $table->index(
                ['status', 'completed_at'],
                'geofence_violation_sync_status_completed_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofence_violation_sync_items');

        Schema::table('geofence_violation_report_rows', function (Blueprint $table): void {
            $table->dropIndex('geofence_violation_group_period_idx');
            $table->dropIndex(['report_period_from']);
            $table->dropIndex(['report_period_to']);
            $table->dropConstrainedForeignId('project_wialon_group_id');
            $table->dropColumn(['report_period_from', 'report_period_to']);
        });
    }
};
