<?php

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        // Virtual playlists are now handled via Scout/Typesense queries
        // No need to create physical playlist records
    }
}
