<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_wialon_groups')) {
            return;
        }

        Schema::create('project_wialon_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('wialon_group_id')->unique();
            $table->string('name');
            $table->string('ownership_type', 20)->index();
            $table->timestamps();

            $table->index(['project_id', 'ownership_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_wialon_groups');
    }
};
