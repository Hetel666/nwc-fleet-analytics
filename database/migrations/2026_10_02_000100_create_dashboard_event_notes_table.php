<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_event_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('dashboard_key', 80)->index();
            $table->string('event_type', 80)->index();
            $table->date('event_date')->nullable()->index();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->string('wialon_unit_id')->nullable()->index();
            $table->string('unit_name')->nullable();
            $table->string('event_status', 80)->nullable()->index();
            $table->text('note')->nullable();
            $table->string('investigation_status', 40)->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['dashboard_key', 'event_type', 'event_date'], 'dashboard_event_notes_scope_idx');
            $table->index(['project_id', 'event_date'], 'dashboard_event_notes_project_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_event_notes');
    }
};
