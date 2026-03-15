<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->deduplicateContainersByTypeSourceAndSourceId();

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

    private function deduplicateContainersByTypeSourceAndSourceId(): void
    {
        $duplicateGroups = DB::table('containers')
            ->select([
                'type',
                'source',
                'source_id',
                DB::raw('MIN(id) as canonical_id'),
            ])
            ->groupBy(['type', 'source', 'source_id'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $canonicalId = (int) $group->canonical_id;
            $duplicateIds = DB::table('containers')
                ->where('type', $group->type)
                ->where('source', $group->source)
                ->where('source_id', $group->source_id)
                ->where('id', '<>', $canonicalId)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values();

            if ($duplicateIds->isEmpty()) {
                continue;
            }

            DB::table('container_file')
                ->whereIn('container_id', $duplicateIds)
                ->orderBy('id')
                ->chunkById(1000, static function (Collection $rows) use ($canonicalId): void {
                    $records = $rows
                        ->map(static fn ($row): array => [
                            'container_id' => $canonicalId,
                            'file_id' => (int) $row->file_id,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ])
                        ->values()
                        ->all();

                    if ($records !== []) {
                        DB::table('container_file')->insertOrIgnore($records);
                    }
                }, 'id');

            DB::table('container_file')
                ->whereIn('container_id', $duplicateIds)
                ->delete();

            DB::table('containers')
                ->whereIn('id', $duplicateIds)
                ->delete();
        }
    }
};
