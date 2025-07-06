<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Cover extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'path',
        'coverable_id',
        'coverable_type',
        'hash',
    ];

    /**
     * Get the parent coverable model (Artist or Album).
     */
    public function coverable(): MorphTo
    {
        return $this->morphTo();
    }
}
