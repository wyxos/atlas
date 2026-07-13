<?php

namespace App\Services\SourceMedia;

use App\Enums\SourceMediaUrlPolicy;
use App\Enums\SourceMediaVariant;
use App\Models\File;
use App\Models\User;
use Carbon\CarbonImmutable;

interface SourceMediaRefreshResolver
{
    public function supports(File $file): bool;

    public function mediaUrlPolicy(File $file): SourceMediaUrlPolicy;

    public function mediaUrlExpiresAt(File $file, SourceMediaVariant $variant): ?CarbonImmutable;

    public function resolve(File $file, User $user): ?ResolvedSourceMedia;
}
