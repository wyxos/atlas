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
        'release_label',
        'catalog_number',
        'barcode',
        'release_date',
        'release_country',
        'musicbrainz_release_id',
        'discogs_release_id',
    ];

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class)
            ->withPivot(['track_number', 'disc_number']);
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
