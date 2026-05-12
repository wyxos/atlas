<?php

namespace App\Models;

use App\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaProcessorTask extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'file_id',
        'library_scan_media_task_id',
        'operation',
        'status',
        'phase',
        'progress',
        'processor_url',
        'storage_profile',
        'atlas_instance',
        'input_path',
        'output_paths',
        'options',
        'result',
        'attempts',
        'submitted_at',
        'started_at',
        'completed_at',
        'failed_at',
        'last_event_at',
        'error_code',
        'error_message',
        'error_context',
    ];

    protected function casts(): array
    {
        return [
            'output_paths' => 'array',
            'options' => 'array',
            'result' => 'array',
            'error_context' => 'array',
            'attempts' => 'integer',
            'progress' => 'integer',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function libraryScanMediaTask(): BelongsTo
    {
        return $this->belongsTo(LibraryScanMediaTask::class);
    }
}
