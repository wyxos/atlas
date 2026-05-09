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
        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // user, post, manga, etc
            $table->string('source'); // CivitAI, etc
            $table->string('source_id');
            $table->string('referrer')->nullable();
            $table->string('action_type')->nullable();
            $table->timestamp('blacklisted_at')->nullable();
            $table->unsignedBigInteger('files_total')->default(0);
            $table->unsignedBigInteger('files_downloaded')->default(0);
            $table->unsignedBigInteger('files_blacklisted')->default(0);
            $table->unsignedBigInteger('files_favorited')->default(0);
            $table->string('blacklist_previewed_count_mode')->nullable();
            $table->timestamps();

            // Add unique constraint for source and source_id combination
            $table->unique(['type', 'source', 'source_id']);
            $table->index(['source', 'source_id']);
            $table->index('files_total');
            $table->index('files_downloaded');
            $table->index('files_blacklisted');
            $table->index('files_favorited');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('containers');
    }
};
