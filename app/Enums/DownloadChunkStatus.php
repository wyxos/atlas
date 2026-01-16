<?php

declare(strict_types=1);

namespace App\Enums;

final class DownloadChunkStatus
{
    public const PENDING = 'pending';

    public const DOWNLOADING = 'downloading';

    public const PAUSED = 'paused';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELED = 'canceled';
}
