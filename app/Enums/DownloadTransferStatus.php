<?php

declare(strict_types=1);

namespace App\Enums;

final class DownloadTransferStatus
{
    public const PENDING = 'pending';

    public const QUEUED = 'queued';

    public const PREPARING = 'preparing';

    public const DOWNLOADING = 'downloading';

    public const ASSEMBLING = 'assembling';

    public const PREVIEWING = 'previewing';

    public const PAUSED = 'paused';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELED = 'canceled';
}
