<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Artist extends Model
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

    public function metadataAliases(): MorphMany
    {
        return $this->morphMany(MetadataAlias::class, 'aliasable');
    }
}
