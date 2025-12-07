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
        Schema::create('browse_tab_file', function (Blueprint $table) {
            $table->id();
            $table->foreignId('browse_tab_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->timestamps();

            // Ensure unique combination of browse_tab_id and file_id
            $table->unique(['browse_tab_id', 'file_id']);
            // Index for ordering
            $table->index(['browse_tab_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('browse_tab_file');
    }
};
