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
        Schema::table('file_metadata', function (Blueprint $table) {
            // Add indexes for performance
            $table->index('is_review_required');
            $table->index('is_extracted');
            $table->index(['file_id', 'is_review_required']); // Composite index for joins
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('file_metadata', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex(['file_metadata_is_review_required_index']);
            $table->dropIndex(['file_metadata_is_extracted_index']);
            $table->dropIndex(['file_metadata_file_id_is_review_required_index']);
        });
    }
};
