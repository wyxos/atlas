<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Cover extends Model
{
    use HasFactory;

    protected $fillable = [
        'path',
        'coverable_id',
        'coverable_type',
        'hash',
    ];

    protected $appends = ['url'];

    /**
     * Get the URL for the cover image.
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->path) {
                    return null;
                }

                $normalized = ltrim($this->path, '/');

                return route('storage.atlas_app', ['path' => $normalized]);
            }
        );
    }

    public function coverable(): MorphTo
    {
        return $this->morphTo();
    }
}
