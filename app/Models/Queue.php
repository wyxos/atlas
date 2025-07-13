<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Columns
 * @property int id
 * @property int user_id
 * @property int|null active_file_id
 * @property int position
 * @property Carbon|null created_at
 * @property Carbon|null updated_at
 *
 * Relationships
 * @property User user
 * @property File|null activeFile
 *
 * Getters
 *
 */
class Queue extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'active_file_id',
        'position',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'active_file_id' => 'integer',
        'position' => 'integer',
    ];

    /**
     * Get the user that owns the queue.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the active file in the queue.
     */
    public function activeFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'active_file_id');
    }

    /**
     * Get the files associated with this queue.
     */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class)->withPivot('position')->orderBy('position');
    }
}
