<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_feed_removal_run_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moderation_feed_removal_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('file_id');
            $table->timestamps();

            $table->unique(
                ['moderation_feed_removal_run_id', 'file_id'],
                'moderation_feed_removal_run_file_unique',
            );
            $table->index('file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_feed_removal_run_files');
    }
};
