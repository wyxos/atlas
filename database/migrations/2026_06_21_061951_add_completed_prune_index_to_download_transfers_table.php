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
        Schema::table('download_transfers', function (Blueprint $table) {
            $table->index(['status', 'finished_at', 'id'], 'download_transfers_completed_prune_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('download_transfers', function (Blueprint $table) {
            $table->dropIndex('download_transfers_completed_prune_index');
        });
    }
};
