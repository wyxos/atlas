<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithListings;
use App\Models\File;
use App\Support\FilePreviewUrl;
use App\Support\PhotoContainers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
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
        $userId = $this->currentUserId();

        $query = File::search('*')
            ->where('mime_group', 'video')
            ->where('has_path', true);

        if ($source) {
            $query->where('source', $source);
        }

        $this->applySorting($query, $options);

        $paginator = $query->paginate($options->limit, $options->pageName, $options->page);

        $ids = $this->extractIdsFromPaginator($paginator);
        $models = $this->loadFilesByIds($ids);
        $reactions = $this->reactionsForUser($ids, $userId);

        $files = collect($ids)->map(function (int $id) use ($models, $reactions, $options) {
            $file = $models->get($id);
            if (! $file) {
                return null;
            }

            $remoteThumbnail = $file->thumbnail_url;
            $mime = (string) ($file->mime_type ?? '');
            $hasPath = (bool) $file->path;
            $original = $hasPath ? URL::temporarySignedRoute('files.view', now()->addMinutes(30), ['file' => $id]) : null;
            $localPreview = FilePreviewUrl::for($file);
            $thumbnail = $localPreview ?? $remoteThumbnail;
            $type = str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'audio/') ? 'audio' : 'other'));

            $payload = (array) optional($file->metadata)->payload;
            $width = (int) ($payload['width'] ?? 0);
            $height = (int) ($payload['height'] ?? 0);
            if ($width <= 0 && $height > 0) {
                $width = $height;
            }
            if ($height <= 0 && $width > 0) {
                $height = $width;
            }
            if ($width <= 0) {
                $width = 512;
            }
            if ($height <= 0) {
                $height = 512;
            }

            $reaction = $reactions[$id] ?? null;

            return [
                'id' => $id,
                'preview' => $thumbnail ?? $original,
                'original' => $original,
                'type' => $type,
                'width' => $width,
                'height' => $height,
                'page' => $options->page,
                'containers' => $this->buildContainers($file),
                'loved' => $reaction === 'love',
                'liked' => $reaction === 'like',
                'disliked' => $reaction === 'dislike',
                'funny' => $reaction === 'funny',
            ];
        })->filter()->values()->all();

        return [
            'files' => $files,
            'filter' => [
                'page' => $options->page,
                'next' => $paginator->hasMorePages() ? ($options->page + 1) : null,
                'limit' => $options->limit,
                'data_url' => route('reels.data'),
                // include the overall total number of matching files
                'total' => method_exists($paginator, 'total') ? (int) $paginator->total() : null,
                'sort' => $options->sort,
                'rand_seed' => $options->isRandom() ? $options->randSeed : null,
                'source' => $source,
            ],
        ];
    }

    protected function buildContainers(File $file): array
    {
        return PhotoContainers::forFile($file);
    }
}
