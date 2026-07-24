<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('engine_hours_report_unit_days')) {
            return;
        }

        Schema::create('engine_hours_report_unit_days', function (Blueprint $table): void {
            $table->id();
            $table->date('stat_date');
            $table->foreignId('equipment_id')->constrained('equipments')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('equipment_type_id')->nullable()->constrained('equipment_types')->nullOnDelete();
            $table->string('ownership_type', 20)->index();
            $table->string('wialon_unit_id', 64)->nullable()->index();
            $table->string('unit_name')->nullable();
            $table->string('vehicle_type')->nullable();
            $table->decimal('engine_hours', 8, 2)->nullable()->index();
            $table->string('engine_hours_source', 64)->default('wialon_engine_hours_report')->index();
            $table->string('parse_status', 64)->default('ok')->index();
            $table->string('report_resource_id', 64)->nullable();
            $table->string('report_template_id', 64)->nullable();
            $table->string('report_template_name')->nullable();
            $table->string('source_table')->nullable();
            $table->unsignedInteger('engine_hours_column_index')->nullable();
            $table->string('engine_hours_column_label')->nullable();
            $table->json('source_group_ids_json')->nullable();
            $table->json('raw_value_json')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['equipment_id', 'stat_date'], 'ehrud_equipment_date_unique');
            $table->index(['stat_date', 'ownership_type'], 'ehrud_date_ownership_idx');
            $table->index(['project_id', 'stat_date'], 'ehrud_project_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_hours_report_unit_days');
    }
};
