<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AudioMetadataRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'scope',
        'source_filter',
        'status',
        'total_files',
        'processed_files',
        'proposal_count',
        'failed_files',
        'options',
        'started_at',
        'finished_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'total_files' => 'integer',
            'processed_files' => 'integer',
            'proposal_count' => 'integer',
            'failed_files' => 'integer',
            'options' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AudioMetadataProposal::class);
    }
}
