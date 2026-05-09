<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('download_transfers', 'attempt')) {
            return;
        }

        Schema::table('download_transfers', function (Blueprint $table) {
            $table->unsignedInteger('attempt')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('download_transfers', 'attempt')) {
            return;
        }

        Schema::table('download_transfers', function (Blueprint $table) {
            $table->dropColumn('attempt');
        });
    }
};
