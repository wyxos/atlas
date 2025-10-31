<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Queue extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'active_file_id',
        'position',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'active_file_id' => 'integer',
        'position' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activeFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'active_file_id');
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class)->withPivot('position')->orderBy('position');
    }
}
