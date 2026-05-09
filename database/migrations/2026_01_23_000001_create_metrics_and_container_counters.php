<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metrics')) {
            Schema::create('metrics', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('description')->nullable();
                $table->unsignedBigInteger('value')->default(0);
                $table->timestamps();
            });
        }

        $counterColumns = [
            'files_total',
            'files_downloaded',
            'files_blacklisted',
            'files_favorited',
        ];

        $missingColumns = collect($counterColumns)
            ->filter(fn (string $column): bool => ! Schema::hasColumn('containers', $column))
            ->values()
            ->all();

        if ($missingColumns !== []) {
            Schema::table('containers', function (Blueprint $table) use ($missingColumns) {
                foreach ($missingColumns as $column) {
                    $table->unsignedBigInteger($column)->default(0);
                }
            });
        }

        foreach ($counterColumns as $column) {
            Schema::whenTableDoesntHaveIndex('containers', "containers_{$column}_index", static function (Blueprint $table) use ($column): void {
                $table->index($column);
            });
        }
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
