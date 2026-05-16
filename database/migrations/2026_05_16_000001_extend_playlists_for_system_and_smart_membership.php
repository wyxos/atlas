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
            $table->string('slug', 191)->nullable();
            $table->string('kind', 32)->default('manual');
            $table->string('membership_mode', 32)->default('manual');
            $table->json('membership_rules')->nullable();
            $table->string('source_key', 191)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_editable')->default(true);
            $table->boolean('is_deletable')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['user_id', 'slug'], 'playlists_user_slug_unique');
            $table->index(['user_id', 'kind', 'sort_order'], 'playlists_user_kind_sort_idx');
            $table->index(['user_id', 'source_key'], 'playlists_user_source_idx');
            $table->index('membership_mode', 'playlists_membership_mode_idx');
        });

        DB::table('playlists')
            ->where('is_system', true)
            ->update([
                'kind' => 'system',
                'membership_mode' => 'rules',
                'is_editable' => false,
                'is_deletable' => false,
            ]);

        DB::table('playlists')
            ->where('is_smart', true)
            ->where('is_system', false)
            ->update([
                'kind' => 'smart',
                'membership_mode' => 'rules',
            ]);

        DB::table('playlists')
            ->whereNull('membership_rules')
            ->whereNotNull('smart_parameters')
            ->update(['membership_rules' => DB::raw('smart_parameters')]);

        Schema::table('file_playlist', function (Blueprint $table) {
            $table->unsignedInteger('position')->nullable();
            $table->index(['playlist_id', 'position'], 'file_playlist_playlist_position_idx');
        });
    }

    public function down(): void
    {
        Schema::table('file_playlist', function (Blueprint $table) {
            $table->dropIndex('file_playlist_playlist_position_idx');
            $table->dropColumn('position');
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropUnique('playlists_user_slug_unique');
            $table->dropIndex('playlists_user_kind_sort_idx');
            $table->dropIndex('playlists_user_source_idx');
            $table->dropIndex('playlists_membership_mode_idx');

            $table->dropColumn([
                'slug',
                'kind',
                'membership_mode',
                'membership_rules',
                'source_key',
                'description',
                'is_editable',
                'is_deletable',
                'sort_order',
            ]);
        });
    }
};
