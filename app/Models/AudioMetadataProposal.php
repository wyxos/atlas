<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioMetadataProposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'audio_metadata_run_id',
        'file_id',
        'reviewed_by',
        'provider',
        'status',
        'confidence',
        'current_values',
        'proposed_values',
        'changes',
        'evidence',
        'reviewed_at',
        'applied_at',
        'ignored_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'current_values' => 'array',
            'proposed_values' => 'array',
            'changes' => 'array',
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
            'ignored_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AudioMetadataRun::class, 'audio_metadata_run_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
