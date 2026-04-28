<?php

namespace App\Services\Extension;

class ExtensionListingMetadataOverridesNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $overrides): array
    {
        if (! is_array($overrides)) {
            return [];
        }

        $normalized = [];

        $postId = isset($overrides['postId']) ? (int) $overrides['postId'] : null;
        if ($postId !== null && $postId > 0) {
            $normalized['postId'] = $postId;
        }

        $username = isset($overrides['username']) && is_string($overrides['username'])
            ? trim($overrides['username'])
            : null;
        if ($username !== null && $username !== '') {
            $normalized['username'] = $username;
        }

        $resourceContainers = [];
        foreach ($overrides['resource_containers'] ?? [] as $resourceContainer) {
            if (! is_array($resourceContainer)) {
                continue;
            }

            $type = isset($resourceContainer['type']) && is_string($resourceContainer['type'])
                ? trim($resourceContainer['type'])
                : null;
            $modelId = isset($resourceContainer['modelId']) ? (int) $resourceContainer['modelId'] : null;
            $modelVersionId = isset($resourceContainer['modelVersionId']) ? (int) $resourceContainer['modelVersionId'] : null;
            $referrerUrl = isset($resourceContainer['referrerUrl']) && is_string($resourceContainer['referrerUrl'])
                ? $this->normalizeOptionalUrl($resourceContainer['referrerUrl'])
                : null;

            if (! in_array($type, ['Checkpoint', 'LoRA'], true)
                || $modelId === null || $modelId <= 0
                || $modelVersionId === null || $modelVersionId <= 0
                || $referrerUrl === null) {
                continue;
            }

            $resourceContainers[] = [
                'type' => $type,
                'modelId' => $modelId,
                'modelVersionId' => $modelVersionId,
                'referrerUrl' => $referrerUrl,
            ];
        }

        if ($resourceContainers !== []) {
            $normalized['resource_containers'] = array_values($resourceContainers);
        }

        return $normalized;
    }

    private function normalizeOptionalUrl(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $trimmed = trim($url);

        return $trimmed !== '' ? $trimmed : null;
    }
}
