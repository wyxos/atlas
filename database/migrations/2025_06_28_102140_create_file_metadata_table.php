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
        Schema::create('file_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->unique()->constrained()->onDelete('cascade');
            $table->json('payload')->nullable();
            $table->boolean('is_review_required')->default(false);
            $table->boolean('is_extracted')->default(false);
            $table->timestamps();

            // Indexes
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
        Schema::dropIfExists('file_metadata');
    }
};
