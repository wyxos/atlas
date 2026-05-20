<?php

namespace App\Services\SourceMedia;

use App\Models\File;
use App\Models\User;

interface SourceMediaRefreshResolver
{
    public function supports(File $file): bool;

    public function resolve(File $file, User $user): ?ResolvedSourceMedia;
}
