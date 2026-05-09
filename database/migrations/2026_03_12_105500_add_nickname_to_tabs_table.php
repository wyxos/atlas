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
        if (Schema::hasColumn('tabs', 'nickname') || Schema::hasColumn('tabs', 'custom_label')) {
            return;
        }

        Schema::table('tabs', function (Blueprint $table) {
            $table->string('custom_label')->nullable()->after('label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $column = Schema::hasColumn('tabs', 'custom_label')
            ? 'custom_label'
            : (Schema::hasColumn('tabs', 'nickname') ? 'nickname' : null);

        if ($column === null) {
            return;
        }

        Schema::table('tabs', function (Blueprint $table) use ($column) {
            $table->dropColumn($column);
        });
    }
};
