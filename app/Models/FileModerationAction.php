<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FileModerationAction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'file_id',
        'action_type',
        'moderation_rule_id',
        'moderation_rule_name',
    ];

    protected function casts(): array
    {
        return [
            'file_id' => 'integer',
            'moderation_rule_id' => 'integer',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}

