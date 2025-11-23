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
            // Add indexes for performance (not_found field already exists)
            $table->index('mime_type');
            $table->index('not_found');
            $table->index(['mime_type', 'size']); // Composite index for size calculations
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // Drop indexes only
            $table->dropIndex(['files_mime_type_index']);
            $table->dropIndex(['files_not_found_index']);
            $table->dropIndex(['files_mime_type_size_index']);
        });
    }
};
