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
        Schema::create('covers', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->unsignedBigInteger('coverable_id');
            $table->string('coverable_type');
            $table->string('hash')->unique();
            $table->timestamps();

            // Add index for polymorphic relationship
            $table->index(['coverable_id', 'coverable_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('covers');
    }
};
