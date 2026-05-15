<?php

namespace App\Models;

use App\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModerationFeedRemovalRunFile extends Model
{
    protected $fillable = [
        'moderation_feed_removal_run_id',
        'file_id',
    ];

    protected function casts(): array
    {
        return [
            'moderation_feed_removal_run_id' => 'integer',
            'file_id' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ModerationFeedRemovalRun::class, 'moderation_feed_removal_run_id');
    }
}
