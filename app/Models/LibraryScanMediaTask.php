<?php

namespace App\Models;

use App\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryScanMediaTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'library_scan_item_id',
        'file_id',
        'type',
        'status',
        'phase',
        'progress',
        'result',
        'error_code',
        'error_message',
        'error_context',
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'result' => 'array',
            'error_context' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LibraryScanItem::class, 'library_scan_item_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
