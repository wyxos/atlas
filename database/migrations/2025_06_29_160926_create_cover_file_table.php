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
        Schema::create('cover_file', function (Blueprint $table) {
            $table->foreignId('cover_id')->constrained()->onDelete('cascade');
            $table->foreignId('file_id')->constrained()->onDelete('cascade');
            $table->primary(['cover_id', 'file_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cover_file');
    }
};
