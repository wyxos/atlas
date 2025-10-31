<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // 'love' | 'like' | 'dislike' | 'funny'
            $table->timestamps();

            $table->unique(['user_id', 'file_id']);
            $table->index(['type']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
