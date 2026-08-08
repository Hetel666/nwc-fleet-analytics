<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('monthly_efficiency_unit_geofence_facts')) {
            return;
        }

        Schema::create('monthly_efficiency_unit_geofence_facts', function (Blueprint $table): void {
            $table->id();
            $table->date('stat_date');
            $table->foreignId('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->string('wialon_unit_id', 64)->index();
            $table->string('unit_name');
            $table->string('registration_number')->nullable()->index();
            $table->string('vehicle_type')->nullable()->index();
            $table->string('ownership_type', 20)->nullable()->index();
            $table->string('segment_type', 20)->index();
            $table->string('geofence_name')->nullable()->index();
            $table->decimal('engine_hours_decimal', 12, 2)->default(0);
            $table->unsignedInteger('engine_seconds')->default(0);
            $table->decimal('mileage_km', 12, 2)->default(0);
            $table->unsignedInteger('visits_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('source_report_template_id')->nullable();
            $table->string('source_report_name')->nullable();
            $table->json('raw_row_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['stat_date', 'wialon_unit_id', 'segment_type', 'geofence_name', 'source_report_name'],
                'monthly_eff_unit_geo_unique',
            );
            $table->index(['stat_date', 'ownership_type', 'segment_type'], 'monthly_eff_unit_geo_date_owner_type_idx');
            $table->index(['wialon_unit_id', 'segment_type', 'stat_date'], 'monthly_eff_unit_geo_unit_type_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_efficiency_unit_geofence_facts');
    }
};
