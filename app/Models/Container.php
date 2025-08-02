<?php

namespace App\Models;

use App\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Columns
 * @property int id
 * @property string type
 * @property string source
 * @property string source_id
 * @property Carbon|null created_at
 * @property Carbon|null updated_at
 *
 * Relationships
 * @property-read \Illuminate\Database\Eloquent\Collection|File[] $files
 *
 * Getters
 *
 */
class Container extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'source',
        'source_id',
        'referrer',
    ];

    /**
     * Get the files that belong to this container.
     */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class);
    }
}
