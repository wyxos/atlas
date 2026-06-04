<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('downloads', fn ($user) => $user !== null);
Broadcast::channel('library-scans', fn ($user) => $user !== null);
Broadcast::channel('audio-metadata-runs.{runId}', fn ($user, int $runId): bool => \App\Models\AudioMetadataRun::query()
    ->whereKey($runId)
    ->where('user_id', $user?->id)
    ->exists());
Broadcast::channel('extension-downloads.{id}', fn () => false);
