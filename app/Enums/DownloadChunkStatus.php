<?php

declare(strict_types=1);

namespace App\Enums;

final class DownloadChunkStatus
{
    public const PENDING = 'pending';

    public const DOWNLOADING = 'downloading';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';
}
