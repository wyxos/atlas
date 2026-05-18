<?php

namespace App\Services\Downloads;

use App\Models\File;

interface SourceDownloadUrlResolver
{
    public function supports(File $file): bool;

    /**
     * @param  array<string, mixed>  $runtimeContext
     */
    public function resolve(File $file, array $runtimeContext = []): ?ResolvedDownloadUrl;
}
