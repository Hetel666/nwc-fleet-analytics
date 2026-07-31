<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('daytime_efficiency_facts')) {
            return;
        }

        Schema::create('daytime_efficiency_facts', function (Blueprint $table): void {
            $table->id();
            $table->date('fact_date');
            $table->foreignId('equipment_id')->constrained('equipments')->cascadeOnDelete();
            $table->string('wialon_unit_id')->nullable();
            $table->string('unit_name_snapshot');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_name_snapshot')->nullable();
            $table->string('ownership_type', 20);
            $table->foreignId('equipment_type_id')->nullable()->constrained('equipment_types')->nullOnDelete();
            $table->string('equipment_type_canonical', 80);
            $table->string('wialon_equipment_type')->nullable();
            $table->string('wialon_vendor')->nullable();
            $table->string('model_name')->nullable();
            $table->string('manufacturer_name')->nullable();
            $table->string('year', 20)->nullable();
            $table->string('report_resource_id')->nullable();
            $table->string('report_template_id')->nullable();
            $table->string('report_template_name')->nullable();
            $table->string('source_group_id')->nullable();
            $table->boolean('report_row_found')->default(false);
            $table->text('raw_engine_hours')->nullable();
            $table->decimal('engine_hours_decimal', 12, 4)->nullable();
            $table->unsignedBigInteger('engine_hours_seconds')->nullable();
            $table->text('raw_idling')->nullable();
            $table->decimal('idling_hours', 12, 4)->nullable();
            $table->text('raw_mileage')->nullable();
            $table->decimal('mileage_adjusted', 14, 3)->nullable();
            $table->timestamp('beginning_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('category', 50);
            $table->string('detail_status', 50);
            $table->string('parse_status', 30);
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['fact_date', 'equipment_id'], 'daytime_efficiency_fact_unit_unique');
            $table->index(['fact_date', 'ownership_type'], 'daytime_efficiency_date_owner_idx');
            $table->index(['fact_date', 'project_id'], 'daytime_efficiency_date_project_idx');
            $table->index(['fact_date', 'category'], 'daytime_efficiency_date_category_idx');
            $table->index(['equipment_type_id', 'category'], 'daytime_efficiency_type_category_idx');
            $table->index('wialon_unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daytime_efficiency_facts');
    }
};
