<?php

namespace App\Services\SourceMedia;

use App\Models\File;
use App\Models\FileMetadata;
use App\Models\User;
use App\Services\Library\LibraryIndexSyncDispatcher;
use Throwable;

final class SourceMediaRefreshService
{
    public function __construct(
        private readonly DeviantArtSourceMediaRefreshResolver $deviantArtResolver,
        private readonly LibraryIndexSyncDispatcher $libraryIndexSyncDispatcher,
    ) {}

    public function supports(File $file): bool
    {
        return $this->resolverFor($file) !== null;
    }

    public function refresh(File $file, User $user): SourceMediaRefreshResult
    {
        $resolver = $this->resolverFor($file);
        if (! $resolver) {
            return new SourceMediaRefreshResult(
                supported: false,
                changed: false,
                message: 'This file source does not support media refresh.',
                file: $file,
            );
        }

        try {
            $media = $resolver->resolve($file, $user);
        } catch (Throwable $exception) {
            report($exception);

            return new SourceMediaRefreshResult(
                supported: true,
                changed: false,
                message: 'Unable to refresh source media from the provider.',
                file: $file->fresh() ?? $file,
            );
        }

        if (! $media) {
            return new SourceMediaRefreshResult(
                supported: true,
                changed: false,
                message: 'No refreshed source media was available.',
                file: $file->fresh() ?? $file,
            );
        }

        $changed = $this->applyMedia($file, $media);
        $refreshedFile = $file->fresh() ?? $file;

        return new SourceMediaRefreshResult(
            supported: true,
            changed: $changed,
            message: $changed ? 'Source media refreshed.' : 'Source media is already current.',
            file: $refreshedFile,
        );
    }

    private function resolverFor(File $file): ?SourceMediaRefreshResolver
    {
        foreach ($this->resolvers() as $resolver) {
            if ($resolver->supports($file)) {
                return $resolver;
            }
        }

        return null;
    }

    /**
     * @return list<SourceMediaRefreshResolver>
     */
    private function resolvers(): array
    {
        return [
            $this->deviantArtResolver,
        ];
    }

    private function applyMedia(File $file, ResolvedSourceMedia $media): bool
    {
        $updates = [
            'url' => $media->url,
        ];

        if ($media->previewUrl !== null) {
            $updates['preview_url'] = $media->previewUrl;
        }

        if ($media->size !== null) {
            $updates['size'] = $media->size;
        }

        if ($media->ext !== null) {
            $updates['ext'] = $media->ext;
        }

        if ($media->mimeType !== null) {
            $updates['mime_type'] = $media->mimeType;
        }

        if ($media->listingMetadata !== []) {
            $updates['listing_metadata'] = $media->listingMetadata;
        }

        $changed = false;
        foreach ($updates as $key => $value) {
            if ($file->{$key} !== $value) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            $file->forceFill($updates)->save();
        }

        $metadataChanged = $this->applyMetadata($file, $media);

        if ($changed || $metadataChanged) {
            $this->libraryIndexSyncDispatcher->files([(int) $file->id]);
        }

        return $changed || $metadataChanged;
    }

    private function applyMetadata(File $file, ResolvedSourceMedia $media): bool
    {
        if ($media->metadataPayload === []) {
            return false;
        }

        $metadata = FileMetadata::query()->firstOrNew([
            'file_id' => $file->id,
        ]);

        if ($metadata->exists && $metadata->payload === $media->metadataPayload) {
            return false;
        }

        $metadata->payload = $media->metadataPayload;
        $metadata->save();

        return true;
    }
}
