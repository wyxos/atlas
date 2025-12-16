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
        Schema::table('containers', function (Blueprint $table) {
            $table->string('action_type')->nullable()->after('referrer');
            $table->timestamp('blacklisted_at')->nullable()->after('action_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            $table->dropColumn(['action_type', 'blacklisted_at']);
        });
    }
};
