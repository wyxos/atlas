<?php

namespace App\Models;

use App\Model;
use App\Services\FileItemFormatter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Columns
 *
 * @property int id
 * @property int user_id
 * @property string label
 * @property array|null query_params
 * @property int position
 * @property bool is_active
 * @property Carbon|null created_at
 * @property Carbon|null updated_at
 *
 * Relationships
 * @property User user
 * @property \Illuminate\Database\Eloquent\Collection<int, File> files
 *
 * Getters
 */
class BrowseTab extends Model
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Database\Factories\BrowseTabFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'label',
        'query_params',
        'position',
        'is_active',
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
            'position' => 'integer',
            'is_active' => 'boolean',
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
     * Get the files associated with this browse tab.
     */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'browse_tab_file')
            ->withPivot('position')
            ->orderByPivot('position');
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

    /**
     * Format files into items structure for frontend.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\File>  $files
     * @return array<int, array<string, mixed>>
     */
    public static function formatFilesToItems($files, int $page = 1): array
    {
        return FileItemFormatter::format($files, $page);
    }
}
