<?php

namespace App\Services;

use App\Models\File;

interface RestoresSourceMetadata
{
    public function supportsListingMetadataRestore(File $file): bool;

    public function supportsDetailMetadataRestore(File $file): bool;
}
