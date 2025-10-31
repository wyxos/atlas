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
        // Drop cover_file table first due to foreign key constraints
        Schema::dropIfExists('cover_file');

        // Then drop covers table
        Schema::dropIfExists('covers');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate covers table first
        Schema::create('covers', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('hash')->index();
            $table->timestamps();
        });

        // Then recreate cover_file table with foreign key constraints
        Schema::create('cover_file', function (Blueprint $table) {
            $table->foreignId('cover_id')->constrained()->onDelete('cascade');
            $table->foreignId('file_id')->constrained()->onDelete('cascade');
            $table->primary(['cover_id', 'file_id']);
            $table->timestamps();
        });
    }
};
