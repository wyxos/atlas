<?php

namespace App\Services;

use App\Events\FileMarkedNotFound;
use App\Models\File;
use App\Services\Library\LibraryIndexSyncDispatcher;
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

    public function reconcilePreviewFailure(File $file): array
    {
        $fileId = $file->id;

        if (! $this->supportsRemoteNotFoundCheck($file)) {
            return $this->result($fileId, (bool) $file->not_found);
        }

        $result = Cache::lock("file-not-found:{$fileId}", self::CHECK_LOCK_TTL_SECONDS)
            ->get(function () use ($file, $fileId): array {
                $file = File::query()->find($file->id);

                if (! $file || ! $this->supportsRemoteNotFoundCheck($file)) {
                    return $this->result($fileId, false);
                }

                if (! $this->bothRemoteUrlsReturnNotFound($file)) {
                    return $this->result($file->id, (bool) $file->not_found);
                }

                $wasMarkedNotFound = (bool) $file->not_found;

                $affectedTabsByUser = DB::transaction(function () use ($file, $wasMarkedNotFound): array {
                    $affectedTabsByUser = app(TabFileService::class)->detachFileFromAllTabs($file->id);

                    if (! $wasMarkedNotFound) {
                        $file->forceFill([
                            'not_found' => true,
                        ])->save();

                        app(MetricsService::class)->applyNotFoundMark($file, $wasMarkedNotFound);
                        app(LibraryIndexSyncDispatcher::class)->files([$file->id]);
                    }

                    return $affectedTabsByUser;
                });

                if (! $wasMarkedNotFound) {
                    $file->refresh();
                }

                foreach ($affectedTabsByUser as $affectedTabs) {
                    event(new FileMarkedNotFound(
                        userId: $affectedTabs['user_id'],
                        fileId: $file->id,
                        tabIds: $affectedTabs['tab_ids'],
                    ));
                }

                return $this->result($file->id, true, $affectedTabsByUser);
            });

        return is_array($result)
            ? $result
            : $this->result($fileId, (bool) $file->not_found);
    }

    /**
     * @return array{file_id: int, not_found: bool, affected_tabs_by_user: array<int, array{user_id: int, tab_ids: array<int, int>}>, supported: bool}
     */
    public function reconcileRedownloadSourceCheck(File $file): array
    {
        $fileId = (int) $file->id;

        if (! $this->supportsRedownloadSourceCheck($file)) {
            return $this->result($fileId, (bool) $file->not_found, supported: false);
        }

        $result = Cache::lock("file-redownload-not-found:{$fileId}", self::CHECK_LOCK_TTL_SECONDS)
            ->get(function () use ($file, $fileId): array {
                $file = File::query()->find($file->id);

                if (! $file || ! $this->supportsRedownloadSourceCheck($file)) {
                    return $this->result($fileId, false, supported: false);
                }

                if (! $this->redownloadSourceReturnsNotFound($file)) {
                    return $this->result((int) $file->id, (bool) $file->not_found, supported: true);
                }

                $wasMarkedNotFound = (bool) $file->not_found;
                if (! $wasMarkedNotFound) {
                    $file->forceFill([
                        'not_found' => true,
                    ])->save();

                    app(MetricsService::class)->applyNotFoundMark($file, $wasMarkedNotFound);
                    app(LibraryIndexSyncDispatcher::class)->files([(int) $file->id]);
                    $file->refresh();
                }

                return $this->result((int) $file->id, true, supported: true);
            });

        return is_array($result)
            ? $result
            : $this->result($fileId, (bool) $file->not_found, supported: true);
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

    private function supportsRedownloadSourceCheck(File $file): bool
    {
        if (strtolower(trim((string) $file->source)) === 'local') {
            return false;
        }

        if (! (bool) $file->downloaded || ! $file->path) {
            return false;
        }

        return $this->redownloadSourceCheckUrls($file) !== [];
    }

    private function bothRemoteUrlsReturnNotFound(File $file): bool
    {
        return $this->urlReturnsNotFound($file->preview_url)
            && $this->urlReturnsNotFound($file->url);
    }

    private function redownloadSourceReturnsNotFound(File $file): bool
    {
        $urls = $this->redownloadSourceCheckUrls($file);
        if ($urls === []) {
            return false;
        }

        foreach ($urls as $url) {
            if (! $this->urlReturnsNotFound($url)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function redownloadSourceCheckUrls(File $file): array
    {
        $referrerUrl = $this->validHttpUrl($file->referrer_url);
        if ($referrerUrl !== null) {
            return [$referrerUrl];
        }

        $urls = [];
        foreach ([$file->preview_url, $file->url] as $url) {
            $url = $this->validHttpUrl($url);
            if ($url !== null && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
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

    private function validHttpUrl(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    private function result(int $fileId, bool $notFound, array $affectedTabsByUser = [], bool $supported = true): array
    {
        return [
            'file_id' => $fileId,
            'not_found' => $notFound,
            'affected_tabs_by_user' => $affectedTabsByUser,
            'supported' => $supported,
        ];
    }
}
