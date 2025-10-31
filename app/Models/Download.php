<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Download extends Model
{
    use HasFactory;

    protected $fillable = [
        'file_id',
        'status',
        'progress',
        'bytes_downloaded',
        'bytes_total',
        'job_id',
        'error',
        'started_at',
        'paused_at',
        'completed_at',
        'cancel_requested_at',
        'canceled_at',
    ];

    protected $casts = [
        'file_id' => 'integer',
        'progress' => 'integer',
        'bytes_downloaded' => 'integer',
        'bytes_total' => 'integer',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
