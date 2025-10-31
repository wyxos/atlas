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
        Schema::dropIfExists('moderation_rules');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-create a minimal table footprint on rollback
        Schema::create('moderation_rules', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
