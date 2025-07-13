<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class Album extends Model
{
    use HasFactory, Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * Get the files associated with this album.
     */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class);
    }

    /**
     * Get all of the album's covers.
     */
    public function covers(): MorphMany
    {
        return $this->morphMany(Cover::class, 'coverable');
    }

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        // Load files relationship if not already loaded
        if (! $this->relationLoaded('files')) {
            $this->load('files');
        }

        $array = [
            'id' => (string) $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at?->timestamp,
            'updated_at' => $this->updated_at?->timestamp,
        ];

        // Include file count for relevance scoring
        if ($this->files) {
            $array['files_count'] = $this->files->count();

            // Include audio files count specifically
            $audioFilesCount = $this->files->filter(function ($file) {
                return str_starts_with($file->mime_type ?? '', 'audio/');
            })->count();
            $array['audio_files_count'] = $audioFilesCount;
        }

        return $array;
    }
}
