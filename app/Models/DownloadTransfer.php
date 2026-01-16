<?php

namespace App\Models;

use App\Enums\DownloadTransferStatus;
use App\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Columns
 *
 * @property int id
 * @property int file_id
 * @property string url
 * @property string domain
 * @property string status
 * @property int|null bytes_total
 * @property int bytes_downloaded
 * @property int last_broadcast_percent
 * @property string|null batch_id
 * @property Carbon|null queued_at
 * @property Carbon|null started_at
 * @property Carbon|null finished_at
 * @property Carbon|null failed_at
 * @property string|null error
 * @property Carbon|null created_at
 * @property Carbon|null updated_at
 *
 * Relationships
 * @property File file
 * @property \Illuminate\Database\Eloquent\Collection<int, DownloadChunk> chunks
 *
 * Getters
 */
class DownloadTransfer extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'file_id',
        'url',
        'domain',
        'status',
        'bytes_total',
        'bytes_downloaded',
        'last_broadcast_percent',
        'batch_id',
        'queued_at',
        'started_at',
        'finished_at',
        'failed_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'bytes_total' => 'integer',
            'bytes_downloaded' => 'integer',
            'last_broadcast_percent' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DownloadChunk::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            DownloadTransferStatus::COMPLETED,
            DownloadTransferStatus::FAILED,
            DownloadTransferStatus::CANCELED,
        ], true);
    }
}
