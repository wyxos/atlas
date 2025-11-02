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
        Schema::table('files', function (Blueprint $table) {
            $table->renameColumn('laughed_at', 'funny');
            $table->renameColumn('laughed_at_at', 'laughed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->renameColumn('funny', 'laughed_at');
            $table->renameColumn('laughed_at', 'laughed_at_at');
        });
    }
};
