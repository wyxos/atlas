<?php

namespace App\Models;

use App\Enums\DownloadChunkStatus;
use App\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Columns
 *
 * @property int id
 * @property int download_transfer_id
 * @property int index
 * @property int range_start
 * @property int range_end
 * @property int bytes_downloaded
 * @property string status
 * @property string|null part_path
 * @property string|null error
 * @property Carbon|null started_at
 * @property Carbon|null finished_at
 * @property Carbon|null failed_at
 * @property Carbon|null created_at
 * @property Carbon|null updated_at
 *
 * Relationships
 * @property DownloadTransfer transfer
 *
 * Getters
 */
class DownloadChunk extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'download_transfer_id',
        'index',
        'range_start',
        'range_end',
        'bytes_downloaded',
        'status',
        'part_path',
        'error',
        'started_at',
        'finished_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'index' => 'integer',
            'range_start' => 'integer',
            'range_end' => 'integer',
            'bytes_downloaded' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(DownloadTransfer::class, 'download_transfer_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            DownloadChunkStatus::COMPLETED,
            DownloadChunkStatus::FAILED,
        ], true);
    }
}
