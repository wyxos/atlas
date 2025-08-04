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
        Schema::create('moderation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable(); // Optional name for the rule
            $table->enum('type', ['contains', 'contains-combo']); // Rule type
            $table->json('terms'); // Terms to match
            $table->enum('match', ['any', 'all'])->default('any'); // Match mode for 'contains' type
            $table->json('unless')->nullable(); // Terms that exempt from blocking
            $table->json('with_terms')->nullable(); // Additional terms for 'contains-combo' type
            $table->enum('action', ['block', 'flag', 'warn'])->default('block'); // Action to take
            $table->boolean('active')->default(true); // Whether rule is active
            $table->text('description')->nullable(); // Optional description
            $table->timestamps();
            
            $table->index(['type', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moderation_rules');
    }
};
