<?php

namespace App\Http\Controllers;

use App\Enums\SourceMediaVariant;
use App\Http\Requests\ResolveSourceMediaRequest;
use App\Models\File;
use App\Models\User;
use App\Services\SourceMedia\SourceMediaRefreshService;
use Illuminate\Http\RedirectResponse;

class ResolveSourceMediaController extends Controller
{
    public function __invoke(
        ResolveSourceMediaRequest $request,
        File $file,
        string $variant,
        SourceMediaRefreshService $sourceMediaRefreshes,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $mediaVariant = SourceMediaVariant::tryFrom($variant);
        abort_unless($mediaVariant instanceof SourceMediaVariant, 404);

        $url = $sourceMediaRefreshes->resolveMediaUrl(
            $file,
            $user,
            $mediaVariant,
            $request->boolean('refresh'),
        );
        abort_unless($url !== null, 502, 'Unable to resolve source media.');

        return redirect()->away($url, 302, [
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Cookie',
        ]);
    }
}
