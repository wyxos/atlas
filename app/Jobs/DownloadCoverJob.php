<?php

namespace App\Jobs;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Cover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DownloadCoverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $type, public int $id, public string $url) {}

    public function handle(): void
    {
        $model = $this->type === 'artist' ? Artist::find($this->id) : Album::find($this->id);
        if (! $model) {
            return;
        }

        $ext = $this->guessExtensionFromUrl($this->url) ?? 'jpg';
        $dir = $this->type === 'artist' ? 'covers/artists/'.$this->id : 'covers/albums/'.$this->id;
        $path = $dir.'/cover.'.$ext;

        // Skip if already downloaded
        $existing = $model->covers()->first();
        if ($existing && $existing->path && Storage::disk('atlas_app')->exists($existing->path)) {
            return;
        }

        $resp = Http::timeout(20)->get($this->url);
        if (! $resp->ok()) {
            return;
        }
        $bytes = $resp->body();
        if (! is_string($bytes) || $bytes === '') {
            return;
        }

        $hash = sha1($bytes);

        $duplicate = $this->findCoverByHash($hash);
        if ($duplicate && ! $this->belongsToModel($duplicate, $model)) {
            return;
        }

        Storage::disk('atlas_app')->put($path, $bytes);

        try {
            $this->upsertCover($model, $path, $hash);
        } catch (QueryException $exception) {
            if (! $this->handleDuplicateHashException($exception, $hash, $model, $path)) {
                throw $exception;
            }
        }
    }

    private function guessExtensionFromUrl(string $url): ?string
    {
        $lower = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        foreach (['.jpg', '.jpeg', '.png', '.webp'] as $ext) {
            if (str_ends_with($lower, $ext)) {
                return ltrim($ext, '.');
            }
        }

        return null;
    }

    protected function upsertCover(Model $model, string $path, string $hash): void
    {
        $model->covers()->updateOrCreate(
            ['hash' => $hash],
            ['path' => $path]
        );
    }

    protected function handleDuplicateHashException(QueryException $exception, string $hash, Model $model, string $path): bool
    {
        if (! $this->isDuplicateHashException($exception)) {
            return false;
        }

        $existing = $this->findCoverByHash($hash);

        if (! $existing) {
            return false;
        }

        if ($this->belongsToModel($existing, $model) && $existing->path !== $path) {
            $existing->update(['path' => $path]);
        }

        return true;
    }

    protected function findCoverByHash(string $hash): ?Cover
    {
        return Cover::query()->where('hash', $hash)->first();
    }

    protected function belongsToModel(Cover $cover, Model $model): bool
    {
        return $cover->coverable_id === $model->getKey()
            && $cover->coverable_type === $model->getMorphClass();
    }

    protected function isDuplicateHashException(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo ?? [];

        if (($errorInfo[0] ?? null) === '23000' && ($errorInfo[1] ?? null) === 1062) {
            return true;
        }

        return str_contains((string) $exception->getMessage(), 'covers_hash_unique');
    }
}
