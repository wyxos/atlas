<?php

namespace App\Support;

final class ListingOptions
{
    public function __construct(
        public readonly int $limit,
        public readonly int $page,
        public readonly string $sort,
        public readonly ?int $randSeed = null,
        public readonly mixed $requestedRandSeed = null,
        public readonly string $pageName = 'page',
    ) {}

    public function isRandom(): bool
    {
        return $this->sort === 'random';
    }
}
