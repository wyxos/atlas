<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_feed_removal_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->index();
            $table->string('phase')->nullable()->index();
            $table->unsignedInteger('chunk_size')->default(500);
            $table->unsignedInteger('active_rule_count')->default(0);
            $table->string('rules_hash', 64)->nullable();
            $table->unsignedBigInteger('scanned_count')->default(0);
            $table->unsignedBigInteger('skipped_no_prompt_count')->default(0);
            $table->unsignedBigInteger('matched_count')->default(0);
            $table->unsignedBigInteger('updated_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_feed_removal_runs');
    }
};
