<?php

namespace App\Services\SourceMedia;

use App\Models\File;
use App\Models\User;
use Throwable;

final class SourceWatchRefreshService
{
    public function __construct(
        private readonly DeviantArtSourceWatchRefreshResolver $deviantArtResolver,
        private readonly SourceMediaRefreshService $sourceMediaRefreshes,
    ) {}

    public function supports(File $file): bool
    {
        return $this->resolverFor($file) !== null;
    }

    public function supportsUnwatch(File $file): bool
    {
        return $this->resolverFor($file) !== null;
    }

    public function watchAndRefresh(File $file, User $user): SourceWatchRefreshResult
    {
        $resolver = $this->resolverFor($file);
        if (! $resolver) {
            return new SourceWatchRefreshResult(
                supported: false,
                watched: false,
                changed: false,
                message: 'This file source does not support source account watching.',
                file: $file,
            );
        }

        try {
            $watched = $resolver->watch($file, $user);
        } catch (Throwable $exception) {
            report($exception);

            return new SourceWatchRefreshResult(
                supported: true,
                watched: false,
                changed: false,
                message: 'Unable to watch the source account. Reconnect the provider in Settings if this keeps failing.',
                file: $file->fresh() ?? $file,
            );
        }

        if (! $watched) {
            return new SourceWatchRefreshResult(
                supported: true,
                watched: false,
                changed: false,
                message: 'Unable to watch the source account. Reconnect the provider in Settings if this keeps failing.',
                file: $file->fresh() ?? $file,
            );
        }

        $refresh = $this->sourceMediaRefreshes->refresh($file, $user);
        if (! $refresh->supported) {
            return new SourceWatchRefreshResult(
                supported: true,
                watched: true,
                changed: false,
                message: 'Source account watched, but this file source does not support media refresh.',
                file: $file->fresh() ?? $file,
            );
        }

        return new SourceWatchRefreshResult(
            supported: true,
            watched: true,
            changed: $refresh->changed,
            message: $refresh->changed
                ? 'Source account watched and media refreshed.'
                : 'Source account watched. Source media is already current.',
            file: $refresh->file ?? $file->fresh() ?? $file,
        );
    }

    public function unwatch(File $file, User $user): SourceUnwatchResult
    {
        $resolver = $this->resolverFor($file);
        if (! $resolver) {
            return new SourceUnwatchResult(
                supported: false,
                unwatched: false,
                message: 'This file source does not support source account watching.',
                file: $file,
            );
        }

        try {
            $unwatched = $resolver->unwatch($file, $user);
        } catch (Throwable $exception) {
            report($exception);

            return new SourceUnwatchResult(
                supported: true,
                unwatched: false,
                message: 'Unable to unwatch the source account. Reconnect the provider in Settings if this keeps failing.',
                file: $file->fresh() ?? $file,
            );
        }

        return new SourceUnwatchResult(
            supported: true,
            unwatched: $unwatched,
            message: $unwatched
                ? 'Source account unwatched.'
                : 'Unable to unwatch the source account. It may not currently be watched.',
            file: $file->fresh() ?? $file,
        );
    }

    private function resolverFor(File $file): ?SourceWatchRefreshResolver
    {
        foreach ($this->resolvers() as $resolver) {
            if ($resolver->supports($file)) {
                return $resolver;
            }
        }

        return null;
    }

    /**
     * @return list<SourceWatchRefreshResolver>
     */
    private function resolvers(): array
    {
        return [
            $this->deviantArtResolver,
        ];
    }
}
