<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIndexesForPerformanceOptimization extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('files', function (Blueprint $table) {
            $table->index(['referrer_url']);
        });

        Schema::table('containers', function (Blueprint $table) {
            $table->index(['source', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['referrer_url']);
        });

        Schema::table('containers', function (Blueprint $table) {
            $table->dropIndex(['source', 'source_id']);
        });
    }
}
