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
            $table->text('path')->nullable()->change();
            $table->text('referrer_url')->nullable()->change();
            $table->text('url')->nullable()->change();
            $table->text('title')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('path')->nullable()->change();
            $table->string('referrer_url')->nullable()->change();
            $table->string('url')->nullable()->change();
            $table->string('title')->nullable()->change();
        });
    }
};
