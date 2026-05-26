<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Playlist extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'kind',
        'membership_mode',
        'membership_rules',
        'source_key',
        'description',
        'is_smart',
        'is_system',
        'is_editable',
        'is_deletable',
        'sort_order',
        'smart_parameters',
        'user_id',
        'cover_mode',
        'cover_file_ids',
    ];

    protected function casts(): array
    {
        return [
            'membership_rules' => 'array',
            'smart_parameters' => 'array',
            'is_smart' => 'boolean',
            'is_system' => 'boolean',
            'is_editable' => 'boolean',
            'is_deletable' => 'boolean',
            'cover_file_ids' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class)
            ->withPivot('position')
            ->withTimestamps();
    }
}
