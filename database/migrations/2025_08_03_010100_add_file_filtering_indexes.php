<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFileFilteringIndexes extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('files', function (Blueprint $table) {
            // Add indexes for the boolean filtering columns
            $table->index(['loved']);
            $table->index(['liked']);
            $table->index(['disliked']);
            $table->index(['funny']);
            
            // Add indexes for the datetime filtering columns
            $table->index(['seen_preview_at']);
            $table->index(['seen_file_at']);
            
            // Add a composite index for the common filtering pattern
            $table->index(['loved', 'liked', 'disliked', 'funny', 'seen_preview_at', 'seen_file_at'], 'files_filtering_composite_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['loved']);
            $table->dropIndex(['liked']);
            $table->dropIndex(['disliked']);
            $table->dropIndex(['funny']);
            $table->dropIndex(['seen_preview_at']);
            $table->dropIndex(['seen_file_at']);
            $table->dropIndex('files_filtering_composite_index');
        });
    }
}
