<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_moderation_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('file_id');
            $table->string('action_type', 32);
            $table->unsignedBigInteger('moderation_rule_id')->nullable();
            $table->string('moderation_rule_name')->nullable();
            $table->timestamps();

            $table->unique(['file_id', 'action_type']);
            $table->index(['action_type', 'moderation_rule_id']);

            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_moderation_actions');
    }
};
