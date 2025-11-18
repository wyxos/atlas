<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithListings;
use App\Models\File;
use App\Support\FileListingFormatter;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

class ReelsController extends Controller
{
    use InteractsWithListings;

    public function index()
    {
        // Initial page render with first batch via data() to keep one source of truth
        $payload = $this->getData();

        return Inertia::render('reels/Index', $payload);
    }

    public function data(): JsonResponse
    {
        return response()->json($this->getData());
    }

    protected function getData(): array
    {
        $options = $this->resolveListingOptions([
            'allowed_sorts' => ['newest', 'random'],
            'default_sort' => 'newest',
        ]);

        $source = $this->normalizeSource(request('source'));
        $mimeType = $this->requestedMimeType();
        $fileId = $this->requestedFileId();
        $sourceId = $this->requestedSourceId();
        $userId = $this->currentUserId();

        $query = File::search('*')
            ->where('mime_group', 'video')
            ->where('has_path', true);

        if ($source) {
            $query->where('source', $source);
        }

        if ($mimeType) {
            $query->where('mime_type', $mimeType);
        }

        if ($fileId) {
            $query->where('id', (string) $fileId);
        }

        if ($sourceId) {
            $query->where('source_id', (string) $sourceId);
        }

        $this->applySorting($query, $options);

        $paginator = $query->paginate($options->limit, $options->pageName, $options->page);

        $ids = $this->extractIdsFromPaginator($paginator);
        $models = $this->loadFilesByIds($ids);
        $reactions = $this->reactionsForUser($ids, $userId);

        $serviceCache = [];

        $files = collect($ids)->map(function (int $id) use ($models, $reactions, $options, &$serviceCache) {
            $file = $models->get($id);

            return FileListingFormatter::format(
                $file,
                $reactions,
                $options->page,
                null, // No remote URL decorator needed for reels (all files have path)
                $serviceCache
            );
        })->filter()->values()->all();

        return [
            'files' => $files,
            'filter' => $this->buildListingFilter($options, $paginator, [
                'source' => $source,
            ]),
            'sources' => $this->getDistinctSources('video'),
        ];
    }
}
