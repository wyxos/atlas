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
            $table->string('type'); // 'love' | 'like' | 'funny'
            $table->timestamps();

            $table->unique(['user_id', 'file_id']);
            $table->index(['type']);
            $table->index(['file_id', 'user_id'], 'reactions_file_user_idx');
            $table->index(['file_id', 'user_id', 'type'], 'reactions_file_user_type_idx');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
