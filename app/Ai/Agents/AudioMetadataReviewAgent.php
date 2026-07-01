<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

class AudioMetadataReviewAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function __construct(private readonly string $schemaVersion) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You review music metadata candidates. Use only supplied JSON evidence. Return strict JSON only.';
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return [
            'metadata' => [
                'schema_version' => $this->schemaVersion,
            ],
            'store' => false,
        ];
    }

    public function temperature(): float
    {
        return 0;
    }
}
