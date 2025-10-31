<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Playlist extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'user_id',
        'is_smart',
        'is_system',
        'smart_parameters',
    ];

    protected $casts = [
        'is_smart' => 'boolean',
        'is_system' => 'boolean',
        'smart_parameters' => 'array',
    ];

    protected $attributes = [
        'is_smart' => false,
        'is_system' => false,
    ];

    public function covers(): MorphMany
    {
        return $this->morphMany(Cover::class, 'coverable');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class);
    }
}
