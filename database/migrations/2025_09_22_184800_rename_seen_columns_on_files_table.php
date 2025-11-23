<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            if (Schema::hasColumn('files', 'seen_preview_at')) {
                $table->renameColumn('seen_preview_at', 'previewed_at');
            }
            if (Schema::hasColumn('files', 'seen_file_at')) {
                $table->renameColumn('seen_file_at', 'seen_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            if (Schema::hasColumn('files', 'seen_preview_at')) {
                $table->renameColumn('previewed_at', 'seen_preview_at');
            }
            if (Schema::hasColumn('files', 'seen_at')) {
                $table->renameColumn('seen_at', 'seen_file_at');
            }
        });
    }
};
