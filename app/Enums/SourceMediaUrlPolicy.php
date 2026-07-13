<?php

namespace App\Enums;

enum SourceMediaUrlPolicy: string
{
    case Stable = 'stable';
    case Expiring = 'expiring';

    public function usesAtlasResolver(): bool
    {
        return $this !== self::Stable;
    }
}
