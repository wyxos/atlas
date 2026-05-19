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
        Schema::create('file_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source')->unique();
            $table->unsignedBigInteger('total_file_count')->default(0);
            $table->unsignedBigInteger('active_file_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['active_file_count', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_sources');
    }
};
