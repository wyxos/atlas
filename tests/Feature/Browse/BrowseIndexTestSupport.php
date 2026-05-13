<?php

use App\Services\Library\LibraryTypesenseGateway;

use function Pest\Laravel\mock;

if (! function_exists('mockLibraryGateway')) {
    function mockLibraryGateway(array $files, mixed $nextCursor = null, ?int $total = null): void
    {
        mock(LibraryTypesenseGateway::class)
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
