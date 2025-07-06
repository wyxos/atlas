<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Playlist extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'is_smart',
        'smart_parameters',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_smart' => 'boolean',
        'smart_parameters' => 'array',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_smart' => false,
    ];

    /**
     * Get all of the playlist's covers.
     */
    public function covers(): MorphMany
    {
        return $this->morphMany(Cover::class, 'coverable');
    }

    /**
     * Get the files associated with this playlist.
     */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class);
    }
}
