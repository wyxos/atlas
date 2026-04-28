<?php

use App\Services\Local\LocalBrowseTypesenseGateway;

use function Pest\Laravel\mock;

if (! function_exists('mockLocalBrowseGateway')) {
    function mockLocalBrowseGateway(array $files, mixed $nextCursor = null, ?int $total = null): void
    {
        mock(LocalBrowseTypesenseGateway::class)
            ->shouldReceive('search')
            ->andReturn([
                'files' => $files,
                'metadata' => [
                    'nextCursor' => $nextCursor,
                    'total' => $total,
                ],
            ]);
    }
}
