<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\Plugin\PluginServiceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class FileController extends Controller
{
    public function __construct(private PluginServiceResolver $serviceResolver) {}

    public function index(Request $request): Response
    {
        // Inputs
        $rawQ = trim((string) $request->query('q', ''));
        $scoutQuery = $rawQ ?? '*';
        $sort = in_array($request->query('sort'), ['latest', 'oldest'], true) ? $request->query('sort') : 'latest';
        $origin = in_array($request->query('origin'), ['local', 'online', 'both'], true) ? $request->query('origin') : 'both';
        $perPage = $this->resolvePerPage($request);

        // IMPORTANT: Always use Scout/Typesense for listing and filtering — NEVER fall back to Eloquent here.
        // Large datasets (300k+ files) must rely on the search engine for filtering/pagination.
        $builder = $this->newSearchBuilder($scoutQuery);
        // Filter by new blacklisted flag in the search index
        $builder->where('blacklisted', false);

        // Origin filter via indexed boolean field
        if ($origin === 'local') {
            $builder->where('has_path', true);
        } elseif ($origin === 'online') {
            $builder->where('has_path', false);
        }

        // Sort by created_at in the search index. Avoid DB fallback (see note above).
        $builder->orderBy('created_at', $sort === 'latest' ? 'desc' : 'asc');

        $files = $builder->paginate($perPage)->withQueryString();
        // Attach derived url (prefer signed route for private path; fallback to model url)
        $files->setCollection(
            $files->getCollection()->map(function (File $file) {
                $derivedUrl = $file->path
                    ? URL::temporarySignedRoute('files.view', now()->addMinutes(5), ['file' => $file->id])
                    : ($file->url ?: null);

                return [
                    'id' => $file->id,
                    'filename' => $file->filename,
                    'mime_type' => $file->mime_type,
                    'size' => $file->size,
                    'created_at' => optional($file->created_at)->toIso8601String(),
                    'url' => $derivedUrl,
                    'thumbnail_url' => $file->thumbnail_url,
                    'has_path' => (bool) $file->path,
                ];
            })
        );

        return Inertia::render('files/Index', [
            'files' => $files,
            'filters' => [
                'q' => $rawQ,
                'sort' => $sort,
                'origin' => $origin,
                'limit' => $perPage,
            ],
        ]);
    }

    protected function newSearchBuilder(string $query)
    {
        return File::search($query);
    }

    private function resolvePerPage(Request $request): int
    {
        $allowed = [20, 40, 60, 100, 200];
        $limit = (int) $request->query('limit', 20);

        if (! in_array($limit, $allowed, true)) {
            return 20;
        }

        return $limit;
    }

    public function view(Request $request, File $file): SymfonyResponse
    {
        // The 'signed' middleware already validates the signature.
        $path = $file->path;
        abort_unless($path, 404);

        $headers = [];
        if (! empty($file->mime_type)) {
            $headers['Content-Type'] = $file->mime_type;
        }

        // Serve from atlas_app first (downloads/consolidated), then atlas.
        foreach (['atlas_app', 'atlas'] as $diskName) {
            try {
                $disk = Storage::disk($diskName);
                // For local disks we can safely build the absolute path and serve the file inline.
                $absolute = $disk->path($path);
                if (is_file($absolute)) {
                    $disposition = 'inline; filename="'.$file->filename.'"';

                    return response()->file($absolute, array_merge($headers, ['Content-Disposition' => $disposition]));
                }
            } catch (\Throwable $e) {
                // Try next disk
            }
        }

        abort(404);
    }

    /**
     * Proxy originals for hosts that block cross-origin hotlinking. Thumbnails remain direct..
     */
    public function remote(Request $request, File $file): SymfonyResponse
    {
        $url = $file->url;
        abort_unless($url, 404);

        $service = $this->serviceResolver->resolveBySource((string) $file->source);
        if (! $service) {
            abort(404);
        }

        $shouldProxy = method_exists($service, 'shouldProxyOriginal')
            ? (bool) $service->shouldProxyOriginal($file)
            : $service::hotlinkProtected();

        if (! $shouldProxy) {
            abort(403, 'Service does not require proxying');
        }

        if (! method_exists($service, 'proxyOriginal')) {
            abort(501, 'Service does not implement proxyOriginal');
        }

        try {
            $response = $service->proxyOriginal($request, $file);
        } catch (\Throwable $e) {
            report($e);

            abort(502, 'Failed to fetch remote');
        }

        if ($response instanceof SymfonyResponse) {
            return $response;
        }

        return response($response);
    }

    public function destroy(File $file): JsonResponse
    {
        // Only allow deletion of files with local paths
        if (! $file->path) {
            return response()->json(['message' => 'Cannot delete files without local storage paths'], 403);
        }

        // Delete the file from storage (attempt both disks; ignore if missing)
        foreach (['atlas_app', 'atlas'] as $diskName) {
            try {
                Storage::disk($diskName)->delete($file->path);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Delete the database record
        $file->delete();

        return response()->json(['message' => 'File deleted successfully']);
    }

    /**
     * Clear local path and downloaded flags; delete file from disk if present.
     */
    public function purgeLocal(File $file): JsonResponse
    {
        // Best-effort delete from known disks
        if (! empty($file->path)) {
            foreach (['atlas_app', 'atlas'] as $diskName) {
                try {
                    Storage::disk($diskName)->delete($file->path);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        // Unset local path and downloaded flags
        $file->forceFill([
            'path' => null,
            'downloaded' => false,
            'downloaded_at' => null,
            'download_progress' => 0,
        ])->saveQuietly();

        return response()->json(['ok' => true, 'id' => $file->id]);
    }

    /**
     * Placeholder endpoint for file reactions (no side effects).
     * Request payload: { type: 'love'|'like'|'dislike'|'funny', state?: bool }
     */
    public function react(Request $request, File $file): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:love,like,dislike,funny',
            'state' => 'nullable|boolean',
        ]);

        return response()->json([
            'id' => $file->id,
            'type' => $validated['type'],
            'state' => array_key_exists('state', $validated) ? (bool) $validated['state'] : null,
            'placeholder' => true,
        ]);
    }
}
