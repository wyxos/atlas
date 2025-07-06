<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Album extends Model
{
    use HasFactory;

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
}
