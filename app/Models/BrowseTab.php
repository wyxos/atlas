<?php

namespace App\Models;

use App\Model;
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
     * Format files into items structure for frontend (similar to Browser.php).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\File>  $files
     * @param  int  $page
     * @return array<int, array<string, mixed>>
     */
    public static function formatFilesToItems($files, int $page = 1): array
    {
        return $files->map(function (File $file, int $index) use ($page) {
            $metadata = $file->metadata?->payload ?? [];
            $listingMetadata = $file->listing_metadata ?? [];

            return [
                'id' => $file->id, // Database file ID
                'width' => (int) ($metadata['width'] ?? 500),
                'height' => (int) ($metadata['height'] ?? 500),
                'src' => $file->thumbnail_url ?? $file->url, // Use thumbnail for masonry grid, fallback to original
                'originalUrl' => $file->url, // Keep original URL for full-size viewing
                'thumbnail' => $file->thumbnail_url,
                'type' => str_starts_with($file->mime_type ?? '', 'video/') ? 'video' : 'image',
                'page' => $page,
                'key' => "{$page}-{$file->id}", // Combined key for unique identification
                'index' => $index,
                'notFound' => false,
            ];
        })->values()->all();
    }
}
