<?php

namespace App\Services;

use App\Models\File;

class LocalService extends BaseService
{
    public const string KEY = 'local';

    public const string SOURCE = 'Local';

    public const string LABEL = 'Local Files';

    /**
     * Fetch local files from database (will use Scout when installed).
     *
     * @return array{items: array<int, array<string, mixed>>, metadata: array{nextCursor: int|null}}
     */
    public function fetch(array $params = []): array
    {
        $this->params = $params;

        $page = (int) ($params['page'] ?? 1);
        $limit = (int) ($params['limit'] ?? 20);
        $source = $params['source'] ?? null; // Filter by source if provided

        // Build query
        $query = File::query();

        // Filter by source if provided and not 'all'
        if ($source && $source !== 'all') {
            $query->where('source', $source);
        }

        // Order by most recently downloaded/updated
        $query->orderBy('downloaded_at', 'desc')
            ->orderBy('updated_at', 'desc');

        // Get total count for pagination
        $total = $query->count();
        $totalPages = (int) ceil($total / $limit);

        // Paginate
        $files = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        // Transform files to items format
        $items = $files->map(function (File $file) {
            return $this->transformFileToItem($file);
        })->toArray();

        // Determine next cursor (page number)
        $nextCursor = $page < $totalPages ? $page + 1 : null;

        return [
            'items' => $items,
            'metadata' => [
                'nextCursor' => $nextCursor,
            ],
        ];
    }

    /**
     * Transform a File model to item format compatible with other services.
     */
    protected function transformFileToItem(File $file): array
    {
        $listingMetadata = $file->listing_metadata ?? [];
        $meta = $listingMetadata['meta'] ?? [];

        return [
            'id' => $file->id,
            'url' => $file->url ?? $file->thumbnail_url,
            'thumbnail_url' => $file->thumbnail_url ?? $file->url,
            'width' => $meta['width'] ?? $file->width ?? 0,
            'height' => $meta['height'] ?? $file->height ?? 0,
            'type' => $this->detectFileType($file),
            'hash' => $file->hash ?? '',
            'meta' => [
                'prompt' => $meta['prompt'] ?? $file->description ?? '',
                'width' => $meta['width'] ?? $file->width ?? 0,
                'height' => $meta['height'] ?? $file->height ?? 0,
            ],
            'source' => $file->source ?? 'Local',
            'referrer_url' => $file->referrer_url,
        ];
    }

    /**
     * Detect file type from MIME type or extension.
     */
    protected function detectFileType(File $file): string
    {
        $mimeType = $file->mime_type ?? '';
        $ext = $file->ext ?? '';

        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }
        if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'])) {
            return 'image';
        }
        if (in_array(strtolower($ext), ['mp4', 'webm', 'mov', 'avi'])) {
            return 'video';
        }

        return 'image'; // Default to image
    }

    /**
     * Return a normalized structure with files and next cursor.
     * For local mode, we transform File models to the format expected by Browser.
     */
    public function transform(array $response, array $params = []): array
    {
        $items = $response['items'] ?? [];
        $nextCursor = $response['metadata']['nextCursor'] ?? null;

        // Get file IDs from items and load File models with metadata
        $fileIds = array_filter(array_column($items, 'id'));
        $files = File::with('metadata')->whereIn('id', $fileIds)->get()->keyBy('id');

        // Transform files to the format expected by Browser (same as other services)
        $transformed = [];
        foreach ($items as $item) {
            $fileId = $item['id'] ?? null;
            if ($fileId && isset($files[$fileId])) {
                $file = $files[$fileId];
                $listingMetadata = $file->listing_metadata ?? [];
                $meta = $listingMetadata['meta'] ?? [];

                $transformed[] = [
                    'file' => [
                        'id' => $file->id, // Include file ID for Browser.php to match files in local mode
                        'referrer_url' => $file->referrer_url ?? "local://file/{$file->id}",
                        'url' => $file->url ?? $file->thumbnail_url,
                        'filename' => $file->filename,
                        'ext' => $file->ext,
                        'mime_type' => $file->mime_type,
                        'description' => $file->description,
                        'thumbnail_url' => $file->thumbnail_url ?? $file->url,
                        'listing_metadata' => $listingMetadata,
                        'source' => $file->source ?? 'Local',
                    ],
                    'metadata' => [
                        'file_referrer_url' => $file->referrer_url ?? "local://file/{$file->id}",
                        'payload' => [
                            'prompt' => $meta['prompt'] ?? $file->description ?? '',
                            'width' => $meta['width'] ?? $file->width ?? 0,
                            'height' => $meta['height'] ?? $file->height ?? 0,
                        ],
                        'created_at' => $file->created_at?->toDateTimeString(),
                        'updated_at' => $file->updated_at?->toDateTimeString(),
                    ],
                ];
            }
        }

        return [
            'files' => $transformed,
            'filter' => [
                ...$this->params,
                'next' => $nextCursor,
            ],
        ];
    }

    /**
     * Transform item to file format expected by Browser.
     * For local mode, we return File models directly (not transformed format).
     */
    protected function transformItemToFileFormat(array $item): File
    {
        $fileId = $item['id'] ?? null;
        $file = $fileId ? File::with('metadata')->find($fileId) : null;

        if (! $file) {
            throw new \RuntimeException("File with ID {$fileId} not found");
        }

        return $file;
    }

    public function defaultParams(): array
    {
        return [
            'limit' => 20,
            'source' => 'all', // Default to all sources
        ];
    }
}
