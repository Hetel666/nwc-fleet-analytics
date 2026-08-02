<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_visibility_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('dashboard_code')->unique();
            $table->string('section_code');
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('display_order')->default(999);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['section_code', 'display_order']);
            $table->index('is_visible');
        });

        Schema::create('dashboard_status_visibility_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('dashboard_type');
            $table->string('status_code');
            $table->boolean('is_visible')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['dashboard_type', 'status_code'], 'dashboard_status_visibility_unique');
            $table->index('is_visible');
        });

        Schema::create('dashboard_configuration_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->string('entity_code');
            $table->json('old_value_json')->nullable();
            $table->json('new_value_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_code']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_configuration_audit_logs');
        Schema::dropIfExists('dashboard_status_visibility_settings');
        Schema::dropIfExists('dashboard_visibility_settings');
    }
};
