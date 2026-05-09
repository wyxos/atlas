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
        if (! Schema::hasColumn('tabs', 'nickname') || Schema::hasColumn('tabs', 'custom_label')) {
            return;
        }

        Schema::table('tabs', function (Blueprint $table) {
            $table->renameColumn('nickname', 'custom_label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('tabs', 'custom_label') || Schema::hasColumn('tabs', 'nickname')) {
            return;
        }

        Schema::table('tabs', function (Blueprint $table) {
            $table->renameColumn('custom_label', 'nickname');
        });
    }
};
