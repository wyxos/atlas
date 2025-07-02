<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginHistory extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
    ];

    /**
     * Get the user that owns the login history.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
