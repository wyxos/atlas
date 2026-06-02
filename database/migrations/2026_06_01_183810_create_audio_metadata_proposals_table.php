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
        Schema::create('audio_metadata_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_metadata_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->json('current_values');
            $table->json('proposed_values');
            $table->json('changes');
            $table->json('evidence')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->timestamps();

            $table->index(['file_id', 'status', 'created_at'], 'audio_metadata_proposals_file_status_created_idx');
            $table->index(['audio_metadata_run_id', 'created_at'], 'audio_metadata_proposals_run_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audio_metadata_proposals');
    }
};
