<?php

namespace App\Services\SourceMedia;

use App\Models\File;
use App\Models\User;

interface SourceWatchRefreshResolver
{
    public function supports(File $file): bool;

    public function watch(File $file, User $user): bool;
}
