<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Scout\Searchable;

class Artist extends Model
{
    use HasFactory, Searchable;

    public array $searchableFields = [
        'name',
    ];

    protected $fillable = [
        'name',
    ];

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class);
    }

    public function covers(): MorphMany
    {
        return $this->morphMany(Cover::class, 'coverable');
    }

    public function toSearchableArray(): array
    {
        if (! $this->relationLoaded('files')) {
            $this->load('files');
        }

        $array = [
            'id' => (string) $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at?->timestamp,
            'updated_at' => $this->updated_at?->timestamp,
        ];

        if ($this->files) {
            $array['files_count'] = $this->files->count();
            $audioFilesCount = $this->files->filter(fn ($file) => str_starts_with($file->mime_type ?? '', 'audio/'))->count();
            $array['audio_files_count'] = $audioFilesCount;
        }

        return $array;
    }
}
