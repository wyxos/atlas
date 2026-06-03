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
        Schema::table('albums', function (Blueprint $table) {
            $table->string('release_label')->nullable()->after('normalized_name');
            $table->string('catalog_number')->nullable()->after('release_label');
            $table->string('barcode')->nullable()->after('catalog_number');
            $table->string('release_date', 32)->nullable()->after('barcode');
            $table->string('release_country', 16)->nullable()->after('release_date');
            $table->string('musicbrainz_release_id', 64)->nullable()->after('release_country');
            $table->string('discogs_release_id', 64)->nullable()->after('musicbrainz_release_id');

            $table->index('musicbrainz_release_id', 'albums_musicbrainz_release_id_idx');
            $table->index('discogs_release_id', 'albums_discogs_release_id_idx');
        });

        Schema::table('album_file', function (Blueprint $table) {
            $table->string('track_number', 32)->nullable()->after('file_id');
            $table->string('disc_number', 32)->nullable()->after('track_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('album_file', function (Blueprint $table) {
            $table->dropColumn(['track_number', 'disc_number']);
        });

        Schema::table('albums', function (Blueprint $table) {
            $table->dropIndex('albums_musicbrainz_release_id_idx');
            $table->dropIndex('albums_discogs_release_id_idx');
            $table->dropColumn([
                'release_label',
                'catalog_number',
                'barcode',
                'release_date',
                'release_country',
                'musicbrainz_release_id',
                'discogs_release_id',
            ]);
        });
    }
};
