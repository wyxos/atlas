<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioTrackStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'file_id',
        'last_played_at',
        'last_skipped_at',
        'play_count',
        'skip_count',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'last_played_at' => 'datetime',
            'last_skipped_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
