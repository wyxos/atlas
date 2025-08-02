<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // Convert columns that can contain long data from external sources
            $table->text('filename')->change(); // Long filenames from various sources
            $table->text('url')->nullable()->change(); // Long URLs with embedded parameters/filenames
            $table->text('referrer_url')->nullable()->change(); // Long referrer URLs from various sources
            $table->text('title')->nullable()->change(); // Currently stores AI prompts (design choice)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // Revert back to string columns (may cause data truncation!)
            $table->string('filename', 255)->change();
            $table->string('url', 255)->nullable()->change();
            $table->string('referrer_url', 255)->nullable()->change();
            $table->string('title', 255)->nullable()->change();
        });
    }
};
