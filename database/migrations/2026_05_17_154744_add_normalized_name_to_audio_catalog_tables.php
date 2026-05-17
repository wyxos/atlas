<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addNormalizedNameColumn('artists');
        $this->addNormalizedNameColumn('albums');

        $this->backfillNormalizedNames('artists');
        $this->backfillNormalizedNames('albums');

        $this->deduplicateCatalog('artists', 'artist_file', 'artist_id');

        Schema::whenTableDoesntHaveIndex('artists', 'artists_normalized_name_unique', static function (Blueprint $table): void {
            $table->unique('normalized_name', 'artists_normalized_name_unique');
        });

        Schema::whenTableDoesntHaveIndex('albums', 'albums_normalized_name_index', static function (Blueprint $table): void {
            $table->index('normalized_name', 'albums_normalized_name_index');
        });
    }

    public function down(): void
    {
        Schema::whenTableHasIndex('artists', 'artists_normalized_name_unique', static function (Blueprint $table): void {
            $table->dropUnique('artists_normalized_name_unique');
        });

        Schema::whenTableHasIndex('albums', 'albums_normalized_name_index', static function (Blueprint $table): void {
            $table->dropIndex('albums_normalized_name_index');
        });

        Schema::whenTableHasIndex('albums', 'albums_normalized_name_unique', static function (Blueprint $table): void {
            $table->dropUnique('albums_normalized_name_unique');
        });

        if (Schema::hasColumn('artists', 'normalized_name')) {
            Schema::table('artists', static function (Blueprint $table): void {
                $table->dropColumn('normalized_name');
            });
        }

        if (Schema::hasColumn('albums', 'normalized_name')) {
            Schema::table('albums', static function (Blueprint $table): void {
                $table->dropColumn('normalized_name');
            });
        }
    }

    private function addNormalizedNameColumn(string $tableName): void
    {
        if (Schema::hasColumn($tableName, 'normalized_name')) {
            return;
        }

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->string('normalized_name')->nullable();
        });
    }

    private function backfillNormalizedNames(string $tableName): void
    {
        DB::table($tableName)
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($tableName): void {
                foreach ($rows as $row) {
                    DB::table($tableName)
                        ->where('id', $row->id)
                        ->update([
                            'normalized_name' => $this->normalizeName((string) $row->name),
                        ]);
                }
            });
    }

    private function deduplicateCatalog(string $tableName, string $pivotTable, string $pivotKey): void
    {
        $duplicateGroups = DB::table($tableName)
            ->select([
                'normalized_name',
                DB::raw('MIN(id) as canonical_id'),
            ])
            ->whereNotNull('normalized_name')
            ->groupBy('normalized_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $canonicalId = (int) $group->canonical_id;
            $duplicateIds = DB::table($tableName)
                ->where('normalized_name', $group->normalized_name)
                ->where('id', '<>', $canonicalId)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values();

            if ($duplicateIds->isEmpty()) {
                continue;
            }

            $records = DB::table($pivotTable)
                ->whereIn($pivotKey, $duplicateIds)
                ->select(['file_id'])
                ->distinct()
                ->get()
                ->map(static fn ($row): array => [
                    $pivotKey => $canonicalId,
                    'file_id' => (int) $row->file_id,
                ])
                ->values()
                ->all();

            if ($records !== []) {
                DB::table($pivotTable)->insertOrIgnore($records);
            }

            DB::table($pivotTable)
                ->whereIn($pivotKey, $duplicateIds)
                ->delete();

            DB::table($tableName)
                ->whereIn('id', $duplicateIds)
                ->delete();
        }
    }

    private function normalizeName(string $name): string
    {
        $normalized = preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '';

        return trim($normalized);
    }
};
