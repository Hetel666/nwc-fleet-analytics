<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wialon_resources')) {
            Schema::create('wialon_resources', function (Blueprint $table): void {
                $table->id();
                $table->string('wialon_resource_id', 64)->unique();
                $table->string('name');
                $table->string('account_id', 64)->nullable()->index();
                $table->unsignedInteger('report_templates_count')->default(0);
                $table->unsignedInteger('geofences_count')->default(0);
                $table->unsignedInteger('geofence_groups_count')->default(0);
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('missing_since')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('last_synced_at')->nullable()->index();
                $table->json('raw_metadata_json')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wialon_unit_groups')) {
            Schema::create('wialon_unit_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('wialon_group_id', 64)->unique();
                $table->string('name');
                $table->string('resource_id', 64)->nullable()->index();
                $table->string('account_id', 64)->nullable()->index();
                $table->unsignedInteger('units_count')->default(0);
                $table->foreignId('linked_project_id')->nullable()->constrained('projects')->nullOnDelete();
                $table->string('ownership_type', 20)->nullable()->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('missing_since')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('last_synced_at')->nullable()->index();
                $table->json('raw_metadata_json')->nullable();
                $table->timestamps();

                $table->index(['linked_project_id', 'ownership_type']);
            });
        }

        if (! Schema::hasTable('wialon_units')) {
            Schema::create('wialon_units', function (Blueprint $table): void {
                $table->id();
                $table->string('wialon_unit_id', 64)->unique();
                $table->string('name');
                $table->string('equipment_type_name')->nullable()->index();
                $table->string('ownership_type', 20)->nullable()->index();
                $table->string('unique_id')->nullable()->index();
                $table->string('imei')->nullable()->index();
                $table->foreignId('linked_project_id')->nullable()->constrained('projects')->nullOnDelete();
                $table->foreignId('local_equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('missing_since')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('last_synced_at')->nullable()->index();
                $table->json('raw_metadata_json')->nullable();
                $table->timestamps();

                $table->index(['linked_project_id', 'ownership_type']);
            });
        }

        if (! Schema::hasTable('wialon_unit_group_members')) {
            Schema::create('wialon_unit_group_members', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('wialon_unit_group_id')->constrained('wialon_unit_groups')->cascadeOnDelete();
                $table->foreignId('wialon_unit_id')->nullable()->constrained('wialon_units')->nullOnDelete();
                $table->string('wialon_group_id', 64)->index();
                $table->string('wialon_unit_item_id', 64)->index();
                $table->timestamp('last_synced_at')->nullable()->index();
                $table->timestamps();

                $table->unique(['wialon_group_id', 'wialon_unit_item_id'], 'wialon_unit_group_member_unique');
            });
        }

        if (! Schema::hasTable('wialon_geofence_groups')) {
            Schema::create('wialon_geofence_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('wialon_geofence_group_id', 64);
                $table->string('name');
                $table->string('resource_id', 64)->index();
                $table->string('resource_name')->nullable();
                $table->unsignedInteger('geofences_count')->default(0);
                $table->foreignId('linked_project_id')->nullable()->constrained('projects')->nullOnDelete();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('missing_since')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('last_synced_at')->nullable()->index();
                $table->json('raw_metadata_json')->nullable();
                $table->timestamps();

                $table->unique(['resource_id', 'wialon_geofence_group_id'], 'wialon_geofence_group_resource_unique');
            });
        }

        if (! Schema::hasTable('wialon_geofences')) {
            Schema::create('wialon_geofences', function (Blueprint $table): void {
                $table->id();
                $table->string('wialon_geofence_id', 64);
                $table->string('name');
                $table->string('resource_id', 64)->index();
                $table->string('resource_name')->nullable();
                $table->string('geofence_group_id', 64)->nullable()->index();
                $table->string('zone_type', 64)->nullable()->index();
                $table->decimal('area', 14, 2)->nullable();
                $table->decimal('perimeter', 14, 2)->nullable();
                $table->string('color', 32)->nullable();
                $table->foreignId('linked_project_id')->nullable()->constrained('projects')->nullOnDelete();
                $table->foreignId('local_geofence_id')->nullable()->constrained('geofences')->nullOnDelete();
                $table->boolean('is_home_geofence')->default(false)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('missing_since')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('last_synced_at')->nullable()->index();
                $table->json('raw_geometry_json')->nullable();
                $table->json('raw_metadata_json')->nullable();
                $table->timestamps();

                $table->unique(['resource_id', 'wialon_geofence_id'], 'wialon_geofence_resource_unique');
                $table->index(['linked_project_id', 'is_home_geofence']);
            });
        }

        if (! Schema::hasTable('wialon_geofence_group_members')) {
            Schema::create('wialon_geofence_group_members', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('wialon_geofence_group_id')->constrained('wialon_geofence_groups')->cascadeOnDelete();
                $table->foreignId('wialon_geofence_id')->nullable()->constrained('wialon_geofences')->nullOnDelete();
                $table->string('resource_id', 64)->index();
                $table->string('wialon_geofence_group_item_id', 64)->index();
                $table->string('wialon_geofence_item_id', 64)->index();
                $table->timestamp('last_synced_at')->nullable()->index();
                $table->timestamps();

                $table->unique(['resource_id', 'wialon_geofence_group_item_id', 'wialon_geofence_item_id'], 'wialon_geofence_group_member_unique');
            });
        }

        if (! Schema::hasTable('wialon_report_templates')) {
            Schema::create('wialon_report_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('wialon_template_id', 64);
                $table->string('name');
                $table->string('resource_id', 64)->index();
                $table->string('resource_name')->nullable();
                $table->string('report_type', 64)->nullable()->index();
                $table->json('tables_json')->nullable();
                $table->json('used_by_modules_json')->nullable();
                $table->string('usage_status', 40)->default('unused')->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('missing_since')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->timestamp('last_synced_at')->nullable()->index();
                $table->json('raw_metadata_json')->nullable();
                $table->timestamps();

                $table->unique(['resource_id', 'wialon_template_id'], 'wialon_report_template_resource_unique');
            });
        }

        if (! Schema::hasTable('wialon_catalog_sync_runs')) {
            Schema::create('wialon_catalog_sync_runs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('sync_type', 40)->default('manual')->index();
                $table->json('sections_json');
                $table->string('status', 40)->default('queued')->index();
                $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('added_count')->default(0);
                $table->unsignedInteger('updated_count')->default(0);
                $table->unsignedInteger('deactivated_count')->default(0);
                $table->unsignedInteger('error_count')->default(0);
                $table->unsignedInteger('duration_ms')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('last_heartbeat_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wialon_catalog_sync_items')) {
            Schema::create('wialon_catalog_sync_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('wialon_catalog_sync_run_id')->constrained('wialon_catalog_sync_runs')->cascadeOnDelete();
                $table->string('section', 64)->index();
                $table->string('item_type', 64)->index();
                $table->string('wialon_id', 128)->nullable()->index();
                $table->string('name')->nullable();
                $table->string('action', 40)->index();
                $table->string('status', 40)->default('completed')->index();
                $table->text('error')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();

                $table->index(['wialon_catalog_sync_run_id', 'section'], 'wialon_catalog_sync_items_run_section_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wialon_catalog_sync_items');
        Schema::dropIfExists('wialon_catalog_sync_runs');
        Schema::dropIfExists('wialon_report_templates');
        Schema::dropIfExists('wialon_geofence_group_members');
        Schema::dropIfExists('wialon_geofences');
        Schema::dropIfExists('wialon_geofence_groups');
        Schema::dropIfExists('wialon_unit_group_members');
        Schema::dropIfExists('wialon_units');
        Schema::dropIfExists('wialon_unit_groups');
        Schema::dropIfExists('wialon_resources');
    }
};
