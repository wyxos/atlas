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
        Schema::create('audio_track_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('play_count')->default(0);
            $table->unsignedInteger('skip_count')->default(0);
            $table->timestamp('last_played_at')->nullable();
            $table->timestamp('last_skipped_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'file_id'], 'audio_track_stats_user_file_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audio_track_stats');
    }
};
