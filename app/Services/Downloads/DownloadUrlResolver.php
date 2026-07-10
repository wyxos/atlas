<?php

namespace App\Services\Downloads;

use App\Models\File;

final class DownloadUrlResolver
{
    public function __construct(
        private readonly DeviantArtDownloadUrlResolver $deviantArtResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $runtimeContext
     */
    public function resolve(File $file, array $runtimeContext = []): ResolvedDownloadUrl
    {
        if (YtDlpUnsupportedUrlFallback::isEstablishedForFile($file)) {
            $nativeUrl = YtDlpUnsupportedUrlFallback::nativeUrl($file);
            if ($nativeUrl !== null) {
                return new ResolvedDownloadUrl($nativeUrl);
            }
        }

        foreach ($this->resolvers() as $resolver) {
            if (! $resolver->supports($file)) {
                continue;
            }

            $resolved = $resolver->resolve($file, $runtimeContext);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return new ResolvedDownloadUrl((string) $file->url);
    }

    /**
     * @return list<SourceDownloadUrlResolver>
     */
    private function resolvers(): array
    {
        return [
            $this->deviantArtResolver,
        ];
    }
}
