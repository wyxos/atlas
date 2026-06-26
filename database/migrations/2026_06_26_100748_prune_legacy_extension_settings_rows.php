<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'extension.settings')
            ->delete();
    }

    public function down(): void
    {
        // Legacy extension settings cannot be reconstructed after pruning.
    }
};
