<?php

namespace App\Services;

use App\Models\User;

class ExtensionUserResolver
{
    public function resolve(): User
    {
        $configured = (int) config('downloads.extension_user_id', 0);
        if ($configured > 0) {
            /** @var User|null $user */
            $user = User::query()->find($configured);
            if ($user) {
                return $user;
            }
        }

        $users = User::query()->orderBy('id')->limit(2)->get();
        if ($users->count() === 1) {
            /** @var User $user */
            $user = $users->first();

            return $user;
        }

        throw new \RuntimeException('Unable to resolve extension user. Set ATLAS_EXTENSION_USER_ID.');
    }
}
