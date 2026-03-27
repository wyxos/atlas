<?php

namespace App\Services;

use App\Events\FileMarkedNotFound;
use App\Models\File;
use App\Support\CivitAiMediaUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FileNotFoundService
{
    private const int CHECK_LOCK_TTL_SECONDS = 30;

    private const int REQUEST_TIMEOUT_SECONDS = 10;

    /**
     * Reconcile a client-reported preview load failure with authoritative backend checks.
     */
    public function reconcilePreviewFailure(File $file): void
    {
        if (! $this->supportsRemoteNotFoundCheck($file)) {
            return;
        }

        Cache::lock("file-not-found:{$file->id}", self::CHECK_LOCK_TTL_SECONDS)
            ->get(function () use ($file): void {
                $file = File::query()->find($file->id);

                if (! $file || ! $this->supportsRemoteNotFoundCheck($file)) {
                    return;
                }

                if (! $this->bothRemoteUrlsReturnNotFound($file)) {
                    return;
                }

                $wasMarkedNotFound = (bool) $file->not_found;

                $affectedTabsByUser = DB::transaction(function () use ($file, $wasMarkedNotFound): array {
                    $affectedTabsByUser = app(TabFileService::class)->detachFileFromAllTabs($file->id);

                    if (! $wasMarkedNotFound) {
                        $file->forceFill([
                            'not_found' => true,
                        ])->save();

                        app(MetricsService::class)->incrementMetric(MetricsService::KEY_FILES_NOT_FOUND, 1);
                    }

                    return $affectedTabsByUser;
                });

                if (! $wasMarkedNotFound) {
                    $file->refresh();
                    $file->loadMissing(['metadata', 'reactions']);
                    $file->searchable();
                }

                foreach ($affectedTabsByUser as $affectedTabs) {
                    event(new FileMarkedNotFound(
                        userId: $affectedTabs['user_id'],
                        fileId: $file->id,
                        tabIds: $affectedTabs['tab_ids'],
                    ));
                }
            });
    }

    private function supportsRemoteNotFoundCheck(File $file): bool
    {
        if (strtolower(trim((string) $file->source)) !== 'civitai') {
            return false;
        }

        if ($file->downloaded || $file->path || $file->preview_path) {
            return false;
        }

        return CivitAiMediaUrl::isMediaUrl($file->preview_url)
            && CivitAiMediaUrl::isMediaUrl($file->url);
    }

    private function bothRemoteUrlsReturnNotFound(File $file): bool
    {
        return $this->urlReturnsNotFound($file->preview_url)
            && $this->urlReturnsNotFound($file->url);
    }

    private function urlReturnsNotFound(?string $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        try {
            $headResponse = $this->request('head', $url);

            if ($headResponse->status() === 404) {
                return true;
            }

            if (! in_array($headResponse->status(), [403, 405, 501], true)) {
                return false;
            }

            return $this->request('get', $url)->status() === 404;
        } catch (ConnectionException) {
            return false;
        }
    }

    private function request(string $method, string $url): Response
    {
        return Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withOptions([
                'allow_redirects' => true,
            ])
            ->send($method, $url);
    }
}
