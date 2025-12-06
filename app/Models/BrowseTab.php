<?php

namespace App\Models;

use App\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Columns
 *
 * @property int id
 * @property int user_id
 * @property string label
 * @property array|null query_params
 * @property array|null file_ids
 * @property string|null next_cursor
 * @property string|null current_page
 * @property array|null items_data
 * @property int position
 * @property Carbon|null created_at
 * @property Carbon|null updated_at
 *
 * Relationships
 * @property User user
 *
 * Getters
 */
class BrowseTab extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'label',
        'query_params',
        'file_ids',
        'next_cursor',
        'current_page',
        'items_data',
        'position',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'query_params' => 'array',
            'file_ids' => 'array',
            'items_data' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * Get the user that owns the browse tab.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include tabs for a specific user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to order tabs by position.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }
}
