<?php

namespace App\Models;

use App\Enums\LibraryScanItemStatus;
use App\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryScanItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'library_scan_run_id',
        'file_id',
        'original_path',
        'imported_path',
        'hash',
        'mime_type',
        'size',
        'status',
        'phase',
        'progress',
        'duplicate',
        'parser',
        'parser_queued_at',
        'error_code',
        'error_message',
        'error_context',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'progress' => 'integer',
            'duplicate' => 'boolean',
            'parser_queued_at' => 'datetime',
            'error_context' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(LibraryScanRun::class, 'library_scan_run_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function mediaTasks(): HasMany
    {
        return $this->hasMany(LibraryScanMediaTask::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, LibraryScanItemStatus::terminal(), true);
    }
}
