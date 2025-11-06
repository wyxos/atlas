<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithListings;
use App\Models\File;
use App\Support\FilePreviewUrl;
use App\Support\PhotoContainers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class ReelsUnratedController extends Controller
{
    use InteractsWithListings;

    public function index()
    {
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
            'allowed_sorts' => ['newest', 'oldest', 'random'],
            'default_sort' => 'newest',
        ]);

        $userId = $this->currentUserId();
        $mimeType = $this->requestedMimeType();
        $fileId = $this->requestedFileId();
        $sourceId = $this->requestedSourceId();

        $query = File::search('*')
            ->where('mime_group', 'video')
            ->where('blacklisted', false);

        if ($mimeType) {
            $query->where('mime_type', $mimeType);
        }

        if ($fileId) {
            $query->where('id', (string) $fileId);
        }

        if ($sourceId) {
            $query->where('source_id', (string) $sourceId);
        }

        $this->applySorting($query, $options, null, 'created_at');

        if ($userId) {
            $query->whereNotIn('reacted_user_ids', [(string) $userId]);
        }

        $paginator = $query->paginate($options->limit, $options->pageName, $options->page);

        $ids = $this->extractIdsFromPaginator($paginator);
        $models = $this->loadFilesByIds($ids);

        $files = collect($ids)
            ->map(function (int $id) use ($models, $options) {
                $file = $models->get($id);
                if (! $file) {
                    return null;
                }

                $remoteThumbnail = $file->thumbnail_url;
                $mime = (string) ($file->mime_type ?? '');
                $hasPath = (bool) $file->path;
                $original = $hasPath ? URL::temporarySignedRoute('files.view', now()->addMinutes(30), ['file' => $id]) : $file->url;
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

                return [
                    'id' => $id,
                    'preview' => $original ?? $thumbnail,
                    'original' => $original,
                    'type' => $type,
                    'width' => $width,
                    'height' => $height,
                    'page' => $options->page,
                    'containers' => PhotoContainers::forFile($file),
                    'loved' => false,
                    'liked' => false,
                    'disliked' => false,
                    'funny' => false,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'files' => $files,
            'filter' => $this->buildListingFilter($options, $paginator),
        ];
    }
}
