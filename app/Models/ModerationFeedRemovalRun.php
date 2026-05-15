<?php

namespace App\Models;

use App\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModerationFeedRemovalRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'phase',
        'chunk_size',
        'active_rule_count',
        'rules_hash',
        'scanned_count',
        'skipped_no_prompt_count',
        'matched_count',
        'updated_count',
        'started_at',
        'finished_at',
        'applied_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'chunk_size' => 'integer',
            'active_rule_count' => 'integer',
            'scanned_count' => 'integer',
            'skipped_no_prompt_count' => 'integer',
            'matched_count' => 'integer',
            'updated_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matchedFiles(): HasMany
    {
        return $this->hasMany(ModerationFeedRemovalRunFile::class);
    }
}
