<?php

namespace App\Services\Extension;

use App\Models\File;
use App\Models\FileMetadata;
use App\Services\CivitAiImages;
use Illuminate\Validation\ValidationException;

class CompanionCivitaiMediaPreparer
{
    public function __construct(private readonly CivitAiImages $civitAiImages) {}

    /**
     * @return array<string, mixed>
     */
    public function prepare(array $item, int $index): array
    {
        $row = $this->normalizeCivitaiRow($item);
        $transformed = $this->civitAiImages->transform([
            'items' => [$row],
            'metadata' => [
                'nextCursor' => null,
            ],
        ])['files'][0] ?? null;

        if (! is_array($transformed)) {
            throw ValidationException::withMessages([
                'items' => 'The submitted Civitai media item could not be normalized.',
            ]);
        }

        return [
            'key' => (string) $index,
            'request_id' => $this->requestIdFor($item, $index),
            'request_index' => $index,
            'item' => $item,
            'row' => $row,
            'transformed' => $transformed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listingMetadataFor(array $prepared): array
    {
        $metadata = data_get($prepared, 'transformed.file.listing_metadata');
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    public function unsavedFileFor(array $prepared): File
    {
        $fileRow = $prepared['transformed']['file'];
        $metadataPayload = $prepared['transformed']['metadata']['payload'] ?? [];
        if (is_string($metadataPayload)) {
            $decodedPayload = json_decode($metadataPayload, true);
            $metadataPayload = is_array($decodedPayload) ? $decodedPayload : [];
        }

        $file = new File($fileRow);
        $metadata = new FileMetadata([
            'payload' => $metadataPayload,
        ]);
        $file->setRelation('metadata', $metadata);

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCivitaiRow(array $item): array
    {
        $row = [
            'id' => (int) $item['id'],
            'url' => trim((string) $item['url']),
            'type' => (string) ($item['type'] ?? 'image'),
            'meta' => is_array($item['meta'] ?? null) ? $item['meta'] : [],
        ];

        foreach (['nsfw', 'nsfwLevel', 'width', 'height', 'hash', 'postId', 'username'] as $key) {
            if (array_key_exists($key, $item)) {
                $row[$key] = $item[$key];
            }
        }

        $resourceContainers = $this->resourceContainersFor($item);
        if ($resourceContainers !== []) {
            $row['resource_containers'] = $resourceContainers;
        }

        return $row;
    }

    /**
     * @return list<array{type: string, modelId: int, modelVersionId: int, referrerUrl: string}>
     */
    private function resourceContainersFor(array $item): array
    {
        $submitted = $item['resource_containers'] ?? null;
        if (is_array($submitted)) {
            return collect($submitted)
                ->filter(fn (mixed $container): bool => is_array($container))
                ->map(fn (array $container): array => [
                    'type' => (string) ($container['type'] ?? ''),
                    'modelId' => (int) ($container['modelId'] ?? 0),
                    'modelVersionId' => (int) ($container['modelVersionId'] ?? 0),
                    'referrerUrl' => (string) ($container['referrerUrl'] ?? ''),
                ])
                ->filter(fn (array $container): bool => in_array($container['type'], ['Checkpoint', 'LoRA'], true)
                    && $container['modelId'] > 0
                    && $container['modelVersionId'] > 0
                    && trim($container['referrerUrl']) !== '')
                ->values()
                ->all();
        }

        $modelId = (int) ($item['modelId'] ?? 0);
        $modelVersionId = (int) ($item['modelVersionId'] ?? 0);
        if ($modelId <= 0 || $modelVersionId <= 0) {
            return [];
        }

        return [[
            'type' => $this->resourceContainerType((string) ($item['modelType'] ?? '')),
            'modelId' => $modelId,
            'modelVersionId' => $modelVersionId,
            'referrerUrl' => "https://civitai.com/models/{$modelId}?modelVersionId={$modelVersionId}",
        ]];
    }

    private function resourceContainerType(string $modelType): string
    {
        return in_array(strtolower(trim($modelType)), ['lora', 'lycoris'], true) ? 'LoRA' : 'Checkpoint';
    }

    private function requestIdFor(array $item, int $index): string
    {
        $requestId = trim((string) ($item['request_id'] ?? ''));

        return $requestId !== '' ? $requestId : 'item-'.$index;
    }
}
