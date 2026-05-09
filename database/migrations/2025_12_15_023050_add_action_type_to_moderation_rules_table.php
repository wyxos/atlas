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
        if (Schema::hasColumn('moderation_rules', 'action_type')) {
            return;
        }

        Schema::table('moderation_rules', function (Blueprint $table) {
            $table->string('action_type')->default('blacklist')->after('nsfw');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('moderation_rules', 'action_type')) {
            return;
        }

        Schema::table('moderation_rules', function (Blueprint $table) {
            $table->dropColumn('action_type');
        });
    }
};
