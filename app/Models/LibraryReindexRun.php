<?php

namespace App\Models;

use App\Model;

class LibraryReindexRun extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'status',
        'phase',
        'suffix',
        'files_alias',
        'files_collection',
        'reactions_alias',
        'reactions_collection',
        'files_total',
        'files_indexed',
        'reactions_total',
        'reactions_indexed',
        'started_at',
        'finished_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'files_total' => 'integer',
            'files_indexed' => 'integer',
            'reactions_total' => 'integer',
            'reactions_indexed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function activeStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_RUNNING,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }
}
