<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->unsignedInteger('previewed_count')->default(0)->after('seen_preview_at');
            $table->unsignedInteger('seen_count')->default(0)->after('seen_file_at');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['previewed_count', 'seen_count']);
        });
    }
};
