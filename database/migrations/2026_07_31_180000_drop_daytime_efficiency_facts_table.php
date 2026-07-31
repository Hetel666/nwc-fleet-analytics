<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('daytime_efficiency_facts');
    }

    public function down(): void
    {
        if (Schema::hasTable('daytime_efficiency_facts')) {
            return;
        }

        Schema::create('daytime_efficiency_facts', function (Blueprint $table): void {
            $table->id();
            $table->date('fact_date');
            $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('equipment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ownership_type', 20);
            $table->string('category', 40);
            $table->decimal('engine_hours', 10, 2)->default(0);
            $table->decimal('idling_hours', 10, 2)->default(0);
            $table->decimal('mileage_km', 12, 2)->default(0);
            $table->timestamp('beginning_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('vendor')->nullable();
            $table->string('model')->nullable();
            $table->string('year')->nullable();
            $table->string('avg_speed')->nullable();
            $table->string('report_template_name')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['fact_date', 'equipment_id'], 'daytime_efficiency_fact_unit_unique');
            $table->index(['fact_date', 'ownership_type'], 'daytime_efficiency_date_owner_idx');
            $table->index(['fact_date', 'project_id'], 'daytime_efficiency_date_project_idx');
            $table->index(['fact_date', 'category'], 'daytime_efficiency_date_category_idx');
            $table->index(['equipment_type_id', 'category'], 'daytime_efficiency_type_category_idx');
        });
    }
};
