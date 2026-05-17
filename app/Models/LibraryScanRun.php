<?php

namespace App\Models;

use App\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryScanRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'mode',
        'parser_filter',
        'status',
        'phase',
        'files_found',
        'files_imported',
        'files_duplicate',
        'files_processed',
        'files_failed',
        'files_canceled',
        'started_at',
        'scan_completed_at',
        'finished_at',
        'paused_at',
        'canceled_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'files_found' => 'integer',
            'files_imported' => 'integer',
            'files_duplicate' => 'integer',
            'files_processed' => 'integer',
            'files_failed' => 'integer',
            'files_canceled' => 'integer',
            'started_at' => 'datetime',
            'scan_completed_at' => 'datetime',
            'finished_at' => 'datetime',
            'paused_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(LibraryScanItem::class);
    }
}
