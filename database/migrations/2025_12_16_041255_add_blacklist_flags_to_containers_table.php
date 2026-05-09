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
        $addActionType = ! Schema::hasColumn('containers', 'action_type');
        $addBlacklistedAt = ! Schema::hasColumn('containers', 'blacklisted_at');

        if (! $addActionType && ! $addBlacklistedAt) {
            return;
        }

        Schema::table('containers', function (Blueprint $table) use ($addActionType, $addBlacklistedAt) {
            if ($addActionType) {
                $table->string('action_type')->nullable()->after('referrer');
            }

            if ($addBlacklistedAt) {
                $table->timestamp('blacklisted_at')->nullable()->after('action_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $dropColumns = collect(['action_type', 'blacklisted_at'])
            ->filter(fn (string $column): bool => Schema::hasColumn('containers', $column))
            ->values()
            ->all();

        if ($dropColumns === []) {
            return;
        }

        Schema::table('containers', function (Blueprint $table) use ($dropColumns) {
            $table->dropColumn($dropColumns);
        });
    }
};
