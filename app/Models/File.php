<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Reaction;

class File extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'auto_disliked',
        'path',
        'filename',
        'downloaded',
        'downloaded_at',
        'blacklisted_at',
        'blacklist_reason',
        'thumbnail_path',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'listing_metadata' => 'array',
            'detail_metadata' => 'array',
            'previewed_at' => 'datetime',
            'seen_at' => 'datetime',
            'blacklisted_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'not_found' => 'boolean',
            'downloaded' => 'boolean',
            'auto_disliked' => 'boolean',
        ];
    }

    /**
     * Generate a subfolder-based storage path for a file.
     *
     * This creates a hierarchical directory structure to prevent having
     * millions of files in a single directory, which can cause performance issues.
     *
     * Structure: {type}/{hash[0:2]}/{hash[2:4]}/{filename}
     * Example: images/ab/cd/abcdef123456.jpg
     *
     * @param  string  $type  The file type directory (images, audio, videos)
     * @param  string  $filename  The filename
     * @param  string|null  $hash  Optional hash to use for subfolder generation (falls back to filename hash)
     * @return string The storage path relative to storage/app/private
     */
    public static function generateStoragePath(string $type, string $filename, ?string $hash = null): string
    {
        // Use provided hash or generate one from filename
        $hashValue = $hash ?? hash('sha256', $filename);

        // Take first 4 characters for 2-level subfolder structure (256^2 = 65,536 possible folders)
        // This distributes files evenly and keeps folder sizes manageable
        $subfolder1 = substr($hashValue, 0, 2);
        $subfolder2 = substr($hashValue, 2, 2);

        return "private/{$type}/{$subfolder1}/{$subfolder2}/{$filename}";
    }

    /**
     * Get the full storage path for a file, ensuring directories exist.
     */
    public static function getStoragePath(string $type, string $filename, ?string $hash = null): string
    {
        $relativePath = self::generateStoragePath($type, $filename, $hash);
        $fullPath = storage_path("app/{$relativePath}");

        // Ensure directory exists
        $directory = dirname($fullPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $fullPath;
    }

    /**
     * Get the file metadata.
     */
    public function metadata(): HasOne
    {
        return $this->hasOne(FileMetadata::class);
    }

    /**
     * Get the containers that this file belongs to.
     */
    public function containers(): BelongsToMany
    {
        return $this->belongsToMany(Container::class);
    }

    /**
     * Get all reactions for this file.
     */
    public function reactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Reaction::class);
    }
}
