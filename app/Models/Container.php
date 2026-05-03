<?php

namespace App\Models;

use App\Enums\BlacklistPreviewedCountMode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Container extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'source',
        'source_id',
        'referrer',
        'action_type',
        'blacklist_previewed_count_mode',
        'blacklisted_at',
    ];

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class);
    }

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'blacklisted_at' => 'datetime',
        ];
    }

    /**
     * Default legacy/null rows to preserving previewed count.
     */
    protected function blacklistPreviewedCountMode(): Attribute
    {
        return Attribute::get(fn (?string $value): string => $value ?: BlacklistPreviewedCountMode::PRESERVE);
    }
}
