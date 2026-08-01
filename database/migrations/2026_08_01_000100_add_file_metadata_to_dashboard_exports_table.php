<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboard_exports', function (Blueprint $table): void {
            $table->string('mime_type', 128)->nullable()->after('file_name');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_exports', function (Blueprint $table): void {
            $table->dropColumn(['mime_type', 'file_size']);
        });
    }
};
