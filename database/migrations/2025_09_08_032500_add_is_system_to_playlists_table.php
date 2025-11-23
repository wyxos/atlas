<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_smart');
        });

        try {
            DB::table('playlists')
                ->whereIn('name', ['All songs', 'Favorites', 'Liked', 'Disliked', 'Funny', 'Unrated'])
                ->update(['is_system' => true]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
