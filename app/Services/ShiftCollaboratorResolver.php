<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Wyxos\Shift\Contracts\ResolvesShiftCollaborators;

class ShiftCollaboratorResolver implements ResolvesShiftCollaborators
{
    public function resolve(?string $search = null): array
    {
        if (config('app.env') !== 'local') {
            // TODO: Replace this empty default with the exact app-specific rules
            // that determine which external users SHIFT may tag in this environment.
            return [];
        }

        $modelClass = $this->userModelClass();

        return $modelClass::query()
            ->get()
            ->filter(fn (Model $user) => filled($user->getAttribute('email')))
            ->map(function (Model $user) {
                return [
                    'id' => (string) $user->getKey(),
                    'name' => $this->displayName($user),
                    'email' => (string) $user->getAttribute('email'),
                ];
            })
            ->when(
                filled($search),
                fn ($users) => $users->filter(function (array $user) use ($search) {
                    $needle = Str::lower(trim((string) $search));
                    $haystack = Str::lower(implode(' ', [$user['name'], $user['email']]));

                    return Str::contains($haystack, $needle);
                })
            )
            ->values()
            ->all();
    }

    /**
     * @return class-string<Model>
     */
    private function userModelClass(): string
    {
        $guard = (string) config('auth.defaults.guard', 'web');
        $provider = (string) config("auth.guards.{$guard}.provider", 'users');
        $modelClass = config("auth.providers.{$provider}.model", \App\Models\User::class);

        return is_string($modelClass) && is_subclass_of($modelClass, Model::class)
            ? $modelClass
            : \App\Models\User::class;
    }

    private function displayName(Model $user): string
    {
        $name = trim((string) $user->getAttribute('name'));

        if ($name !== '') {
            return $name;
        }

        $firstName = trim((string) $user->getAttribute('first_name'));
        $lastName = trim((string) $user->getAttribute('last_name'));
        $fullName = trim("{$firstName} {$lastName}");

        if ($fullName !== '') {
            return $fullName;
        }

        return (string) $user->getAttribute('email');
    }
}
