<?php

namespace App\Models;

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
        'blacklisted_at',
    ];

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blacklisted_at' => 'datetime',
        ];
    }
}
