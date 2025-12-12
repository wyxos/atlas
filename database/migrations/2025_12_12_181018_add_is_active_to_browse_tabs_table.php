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
        Schema::table('browse_tabs', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->after('position');

            // Ensure only one active tab per user
            // We'll use a unique index on (user_id, is_active) where is_active = true
            // This is handled in the model/controller logic since MySQL doesn't support partial unique indexes easily
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('browse_tabs', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
