<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlbumCover extends Model
{
    use HasFactory;

    protected $fillable = [
        'album_id',
        'file_id',
        'path',
        'path_hash',
        'hash',
        'size',
        'mime_type',
        'picture_type',
        'sort_order',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }
}
