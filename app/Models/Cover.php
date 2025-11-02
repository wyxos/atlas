<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

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
     * Get the temporary URL for the cover image.
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->path) {
                    return null;
                }

                // Prefer atlas_app disk (new location under .app).
                return Storage::disk('atlas_app')->temporaryUrl($this->path, now()->addHours(1));
            }
        );
    }

    public function coverable(): MorphTo
    {
        return $this->morphTo();
    }
}
