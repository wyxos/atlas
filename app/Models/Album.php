<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Album extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'normalized_name',
    ];

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class);
    }

    public function covers(): HasMany
    {
        return $this->hasMany(AlbumCover::class);
    }

    public function defaultCover(): HasOne
    {
        return $this->hasOne(AlbumCover::class)
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
