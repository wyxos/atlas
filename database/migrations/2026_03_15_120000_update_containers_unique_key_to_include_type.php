<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            $table->dropUnique('containers_source_source_id_unique');
            $table->unique(['type', 'source', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            $table->dropUnique('containers_type_source_source_id_unique');
            $table->unique(['source', 'source_id']);
        });
    }
};
