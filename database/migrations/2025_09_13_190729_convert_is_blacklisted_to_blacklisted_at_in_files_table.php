<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // Add new nullable timestamp column
            $table->timestamp('blacklisted_at')->nullable()->after('seen_file_at');
        });

        // Backfill: when is_blacklisted = 1, set blacklisted_at to now()
        DB::table('files')->where('is_blacklisted', true)->update(['blacklisted_at' => now()]);

        Schema::table('files', function (Blueprint $table) {
            // Drop the old boolean column
            if (Schema::hasColumn('files', 'is_blacklisted')) {
                $table->dropColumn('is_blacklisted');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // Re-introduce the old boolean column defaulting to false
            $table->boolean('is_blacklisted')->default(false)->after('seen_file_at');
        });

        // Backfill boolean from timestamp: if blacklisted_at is not null => true
        DB::table('files')->whereNotNull('blacklisted_at')->update(['is_blacklisted' => true]);

        Schema::table('files', function (Blueprint $table) {
            // Drop the timestamp column
            if (Schema::hasColumn('files', 'blacklisted_at')) {
                $table->dropColumn('blacklisted_at');
            }
        });
    }
};
