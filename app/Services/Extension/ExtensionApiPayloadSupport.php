<?php

namespace App\Services\Extension;

use App\Models\File;
use App\Services\BrowsePersister;

class ExtensionApiPayloadSupport
{
    public function channelHash(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @param  array<string, mixed>  $listingMetadataOverrides
     */
    public function attachDerivedContainers(array $payloads, array $listingMetadataOverrides): void
    {
        if ($listingMetadataOverrides === []) {
            return;
        }

        $fileIds = [];
        foreach ($payloads as $payload) {
            $fileId = data_get($payload, 'file.id');
            if (is_numeric($fileId)) {
                $fileIds[] = (int) $fileId;
            }
        }

        if ($fileIds === []) {
            return;
        }

        app(BrowsePersister::class)->attachContainersForFiles(
            File::query()
                ->whereIn('id', array_values(array_unique($fileIds)))
                ->get()
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @param  array<int, \App\Models\DownloadTransfer>  $activeTransfersByFileId
     */
    public function attachActiveTransfers(array &$payloads, array $activeTransfersByFileId): void
    {
        foreach ($payloads as &$payload) {
            $fileId = data_get($payload, 'file.id');
            if (! is_numeric($fileId)) {
                continue;
            }

            $download = is_array($payload['download'] ?? null) ? $payload['download'] : [];
            $activeTransfer = $activeTransfersByFileId[(int) $fileId] ?? null;
            $payload['download'] = [
                'requested' => $download['requested'] ?? false,
                'transfer_id' => $activeTransfer?->id,
                'status' => $activeTransfer?->status,
                'progress_percent' => $activeTransfer?->last_broadcast_percent,
                'downloaded_at' => $download['downloaded_at'] ?? null,
            ];
        }
        unset($payload);
    }

    /**
     * @return array{enabled: bool, key: string, host: string, port: int, scheme: string, channel: string}
     */
    public function reverbPayload(string $extensionChannel): array
    {
        $reverb = config('broadcasting.connections.reverb');
        $key = trim((string) data_get($reverb, 'key', ''));
        $rawHost = trim((string) data_get($reverb, 'options.host', ''));
        $host = '';
        $hostPort = null;
        if ($rawHost !== '') {
            $hostCandidate = str_contains($rawHost, '://') ? $rawHost : 'https://'.$rawHost;
            $parsedHost = parse_url($hostCandidate, PHP_URL_HOST);
            $parsedPort = parse_url($hostCandidate, PHP_URL_PORT);
            $host = is_string($parsedHost) ? trim($parsedHost) : '';
            $hostPort = is_int($parsedPort) ? $parsedPort : null;
        }
        if ($host === '') {
            $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
            $host = is_string($appHost) ? $appHost : '';
        }
        $configuredPort = (int) data_get($reverb, 'options.port', 443);
        $port = $hostPort ?? $configuredPort;
        $scheme = strtolower((string) data_get($reverb, 'options.scheme', 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }

        return [
            'enabled' => $key !== '' && $host !== '',
            'key' => $key,
            'host' => $host,
            'port' => $port > 0 ? $port : 443,
            'scheme' => $scheme,
            'channel' => 'private-extension-downloads.'.$extensionChannel,
        ];
    }
}
