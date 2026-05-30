<?php

namespace App\Services\Extension;

use App\Models\User;
use App\Services\ExtensionApiKeyService;
use Illuminate\Http\Request;

class ExtensionRequestAuthenticator
{
    public function resolveUser(Request $request, ExtensionApiKeyService $extensionApiKey): ?User
    {
        $apiKey = trim((string) $request->header('X-Atlas-Api-Key', ''));
        if ($apiKey !== '') {
            return $extensionApiKey->resolveUserForApiKey($apiKey);
        }

        if (! $this->allowsLocalSessionExtensionAuth($request)) {
            return null;
        }

        $user = $request->user();
        if ($user instanceof User) {
            return User::query()
                ->select('id')
                ->find($user->id);
        }

        if (! $this->allowsTokenlessLocalExtensionAuth($request)) {
            return null;
        }

        return User::query()
            ->select('id')
            ->oldest('id')
            ->first();
    }

    public function resolveChannel(Request $request, User $user): string
    {
        $apiKey = trim((string) $request->header('X-Atlas-Api-Key', ''));
        $channelKey = $apiKey !== '' ? $apiKey : 'local-session:'.(string) $user->id;

        return app(ExtensionApiPayloadSupport::class)->channelHash($channelKey);
    }

    private function allowsLocalSessionExtensionAuth(Request $request): bool
    {
        return app()->environment(['local', 'testing'])
            && strtolower($request->getHost()) === 'atlas.test';
    }

    private function allowsTokenlessLocalExtensionAuth(Request $request): bool
    {
        if ($request->header('X-Atlas-Local-Extension') !== '1') {
            return false;
        }

        $origin = trim((string) $request->header('Origin', ''));

        return preg_match('#^chrome-extension://[a-p]{32}$#', $origin) === 1
            || preg_match('#^moz-extension://[0-9a-f-]+$#i', $origin) === 1;
    }
}
