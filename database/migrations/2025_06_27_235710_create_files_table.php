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
            $table->text('url')->nullable();
            $table->text('referrer_url')->nullable(); // Page URL where the file was discovered
            $table->text('path')->nullable(); // Local/NAS file path
            $table->text('filename'); // File name
            $table->string('ext')->nullable(); // File extension
            $table->unsignedBigInteger('size')->nullable(); // File size in bytes
            $table->string('mime_type')->nullable(); // MIME type (e.g., video/mp4)
            $table->string('hash')->nullable(); // Full file hash (e.g., SHA256, up to 64 chars)
            $table->text('title')->nullable(); // Title (from source or filename)
            $table->text('description')->nullable(); // Description (from source or optional)
            $table->text('preview_url')->nullable(); // URL for preview (remote)
            $table->text('preview_path')->nullable(); // Local path for preview (image or video)
            $table->text('poster_path')->nullable(); // Local path for video poster
            $table->json('tags')->nullable(); // Tags (array from source, or user)
            $table->unsignedBigInteger('parent_id')->nullable(); // Parent file (for chapters, albums, etc.)
            $table->string('chapter')->nullable(); // Chapter/episode/etc. (if applicable)
            $table->timestamp('previewed_at')->nullable(); // When preview was last shown
            $table->unsignedInteger('previewed_count')->default(0);
            $table->timestamp('seen_at')->nullable(); // When file was viewed
            $table->unsignedInteger('seen_count')->default(0);
            $table->timestamp('blacklisted_at')->nullable(); // When file was blacklisted
            $table->string('blacklist_reason')->nullable(); // Reason for blacklist
            $table->boolean('not_found')->default(false);
            $table->json('listing_metadata')->nullable();
            $table->json('detail_metadata')->nullable();
            $table->boolean('downloaded')->default(false); // Downloaded to local/NAS
            $table->timestamp('downloaded_at')->nullable(); // When download completed
            $table->integer('download_progress')->default(0); // % downloaded
            $table->timestamps();

            // Indexes
            $table->index('mime_type');
            $table->index('not_found');
            $table->index(['mime_type', 'size']); // Composite index for size calculations
            $table->unique('referrer_url');
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
