<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpotifyToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'scope',
        'last_synced_added_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_synced_added_at' => 'datetime',
    ];
}
