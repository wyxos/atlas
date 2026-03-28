<?php

namespace App\Models;

use App\Enums\ActionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class File extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $file): void {
            $url = trim((string) ($file->url ?? ''));
            $file->url = $url !== '' ? $url : null;

            $referrerUrl = trim((string) ($file->referrer_url ?? ''));
            $file->referrer_url = $referrerUrl !== '' ? $referrerUrl : null;

            $file->url_hash = $url !== '' ? hash('sha256', $url) : null;
            $file->referrer_url_hash = $referrerUrl !== '' ? hash('sha256', $referrerUrl) : null;
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'source',
        'source_id',
        'url',
        'url_hash',
        'referrer_url',
        'referrer_url_hash',
        'path',
        'filename',
        'ext',
        'size',
        'mime_type',
        'hash',
        'title',
        'description',
        'preview_url',
        'preview_path',
        'poster_path',
        'tags',
        'parent_id',
        'chapter',
        'previewed_at',
        'previewed_count',
        'seen_at',
        'seen_count',
        'blacklisted_at',
        'blacklist_reason',
        'not_found',
        'listing_metadata',
        'detail_metadata',
        'downloaded',
        'downloaded_at',
        'download_progress',
        'auto_disliked',
    ];

    /**
     * The attributes that should be cast.
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
     */
    public static function generateStoragePath(string $type, string $filename, ?string $hash = null): string
    {
        // Use provided hash or generate one from filename
        $hashValue = self::normalizeHash($hash) ?? hash('sha256', $filename);

        // Take first 4 characters for 2-level subfolder structure (256^2 = 65,536 possible folders)
        // This distributes files evenly and keeps folder sizes manageable
        $subfolder1 = substr($hashValue, 0, 2);
        $subfolder2 = substr($hashValue, 2, 2);

        return "private/{$type}/{$subfolder1}/{$subfolder2}/{$filename}";
    }

    private static function normalizeHash(?string $hash): ?string
    {
        if (! $hash) {
            return null;
        }

        $hash = strtolower(trim($hash));
        if ($hash === '') {
            return null;
        }

        return preg_match('/^[a-f0-9]{4,}$/', $hash) === 1 ? $hash : null;
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
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    public function moderationActions(): HasMany
    {
        return $this->hasMany(FileModerationAction::class);
    }

    public function autoDislikeModerationAction(): HasOne
    {
        return $this->hasOne(FileModerationAction::class)
            ->where('action_type', ActionType::DISLIKE);
    }

    public function autoBlacklistModerationAction(): HasOne
    {
        return $this->hasOne(FileModerationAction::class)
            ->where('action_type', ActionType::BLACKLIST);
    }
}
