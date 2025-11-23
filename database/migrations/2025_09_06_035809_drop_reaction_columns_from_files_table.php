<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn([
                'liked',
                'liked_at',
                'disliked',
                'disliked_at',
                'loved',
                'loved_at',
                'funny',
                'laughed_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->boolean('liked')->default(false);
            $table->timestamp('liked_at')->nullable();
            $table->boolean('disliked')->default(false);
            $table->timestamp('disliked_at')->nullable();
            $table->boolean('loved')->default(false);
            $table->timestamp('loved_at')->nullable();
            $table->boolean('funny')->default(false);
            $table->timestamp('laughed_at')->nullable();
        });
    }
};
