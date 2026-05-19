<?php

namespace App\Models;

use App\Model;

/**
 * Columns
 *
 * @property int id
 * @property string source
 * @property int total_file_count
 * @property int active_file_count
 * @property Carbon|null last_seen_at
 * @property Carbon|null created_at
 * @property Carbon|null updated_at
 *
 * Relationships
 *
 * Getters
 */
class FileSource extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'source',
        'total_file_count',
        'active_file_count',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'total_file_count' => 'integer',
            'active_file_count' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}
