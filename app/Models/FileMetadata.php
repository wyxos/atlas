<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileMetadata extends Model
{
    use HasFactory;

    protected $fillable = [
        'file_id',
        'payload',
        'is_review_required',
        'is_extracted',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_review_required' => 'boolean',
            'is_extracted' => 'boolean',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
