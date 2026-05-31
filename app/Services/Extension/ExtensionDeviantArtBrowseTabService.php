<?php

namespace App\Services\Extension;

use App\Models\Tab;
use App\Models\User;
use App\Services\DeviantArtImages;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExtensionDeviantArtBrowseTabService
{
    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function openUserTab(User $user, array $validated): array
    {
        $username = trim((string) $validated['username']);
        if ($username === '' || ! $this->isValidUsername($username)) {
            throw ValidationException::withMessages([
                'username' => 'The username field must be a valid DeviantArt username.',
            ]);
        }

        return $this->tabPayload($this->create(
            $user,
            "DeviantArt Images: User $username - 1",
            [
                'feed' => 'online',
                'service' => DeviantArtImages::key(),
                'page' => 1,
                'limit' => 20,
                'username' => $username,
            ],
        ));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function create(User $user, string $label, array $params): Tab
    {
        return DB::transaction(function () use ($user, $label, $params) {
            $userId = (int) $user->id;
            $nextPosition = (Tab::forUser($userId)->max('position') ?? -1) + 1;

            Tab::forUser($userId)->update(['is_active' => false]);

            return Tab::query()->create([
                'user_id' => $userId,
                'label' => $label,
                'custom_label' => null,
                'params' => $params,
                'position' => $nextPosition,
                'is_active' => true,
            ]);
        });
    }

    private function isValidUsername(string $value): bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, [
            'about',
            'art',
            'browse',
            'daily-deviations',
            'gallery',
            'morelikethis',
            'notifications',
            'prints',
            'search',
            'settings',
            'shop',
            'watch',
        ], true)) {
            return false;
        }

        return preg_match('/^[a-z0-9_-]+$/i', $value) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function tabPayload(Tab $tab): array
    {
        return [
            'tab' => [
                'id' => $tab->id,
                'label' => $tab->label,
                'params' => $tab->params ?? [],
            ],
            'browse_url' => url('/browse'),
        ];
    }
}
