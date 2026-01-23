<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });

        Schema::table('containers', function (Blueprint $table) {
            $table->unsignedBigInteger('files_total')->default(0);
            $table->unsignedBigInteger('files_downloaded')->default(0);
            $table->unsignedBigInteger('files_blacklisted')->default(0);
            $table->unsignedBigInteger('files_favorited')->default(0);

            $table->index('files_total');
            $table->index('files_downloaded');
            $table->index('files_blacklisted');
            $table->index('files_favorited');
        });
    }

    public function down(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            $table->dropIndex(['files_total']);
            $table->dropIndex(['files_downloaded']);
            $table->dropIndex(['files_blacklisted']);
            $table->dropIndex(['files_favorited']);
            $table->dropColumn([
                'files_total',
                'files_downloaded',
                'files_blacklisted',
                'files_favorited',
            ]);
        });

        Schema::dropIfExists('metrics');
    }
};
