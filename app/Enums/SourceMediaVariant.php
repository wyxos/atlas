<?php

namespace App\Enums;

use App\Models\File;

enum SourceMediaVariant: string
{
    case Original = 'original';
    case Preview = 'preview';

    public function currentUrl(File $file): ?string
    {
        $url = match ($this) {
            self::Original => $file->url ?? $file->preview_url,
            self::Preview => $file->preview_url ?? $file->url,
        };

        return is_string($url) && trim($url) !== '' ? $url : null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $variant): string => $variant->value, self::cases());
    }
}
