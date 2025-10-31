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
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // Origin of file ("local", "NAS", "YouTube", "Booru", etc.)
            $table->string('source_id')->nullable(); // Unique ID from source (if available)
            $table->string('url')->nullable();
            $table->string('referrer_url')->nullable(); // Page URL where the file was discovered
            $table->string('path')->nullable(); // Local/NAS file path
            $table->string('filename'); // File name
            $table->string('ext')->nullable(); // File extension
            $table->unsignedBigInteger('size')->nullable(); // File size in bytes
            $table->string('mime_type')->nullable(); // MIME type (e.g., video/mp4)
            $table->string('hash')->nullable(); // Full file hash (e.g., SHA256, up to 64 chars)
            $table->string('title')->nullable(); // Title (from source or filename)
            $table->text('description')->nullable(); // Description (from source or optional)
            $table->string('thumbnail_url')->nullable(); // URL for thumbnail/preview
            $table->json('tags')->nullable(); // Tags (array from source, or user)
            $table->unsignedBigInteger('parent_id')->nullable(); // Parent file (for chapters, albums, etc.)
            $table->string('chapter')->nullable(); // Chapter/episode/etc. (if applicable)
            $table->timestamp('seen_preview_at')->nullable(); // When preview was last shown
            $table->timestamp('seen_file_at')->nullable(); // When file was viewed
            $table->boolean('is_blacklisted')->default(false); // Hide from view
            $table->string('blacklist_reason')->nullable(); // Reason for blacklist
            $table->boolean('liked')->default(false); // Marked as favorite/liked
            $table->timestamp('liked_at')->nullable(); // When liked
            $table->boolean('disliked')->default(false); // Marked as disliked
            $table->timestamp('disliked_at')->nullable(); // When disliked
            $table->boolean('loved')->default(false); // Added to favorites
            $table->timestamp('loved_at')->nullable(); // When added to favorites
            $table->boolean('downloaded')->default(false); // Downloaded to local/NAS
            $table->timestamp('downloaded_at')->nullable(); // When download completed
            $table->integer('download_progress')->default(0); // % downloaded
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
