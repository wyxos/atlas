<?php

namespace App\Services\Audio;

use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Models\File;
use App\Models\MediaProcessorTask;
use App\Services\MediaProcessing\MediaProcessorPathValidator;
use App\Services\MediaProcessing\RemoteMediaProcessorClient;
use App\Support\AtlasPathResolver;
use Symfony\Component\Process\Process;
use Throwable;

class AudioFingerprintService
{
    public function __construct(
        private readonly RemoteMediaProcessorClient $remoteProcessor,
        private readonly MediaProcessorPathValidator $mediaProcessorPaths,
    ) {}

    public function forFile(File $file): ?AudioFingerprint
    {
        if (! (bool) config('services.audio_metadata.fingerprinting_enabled', true)) {
            return null;
        }

        return $this->remoteForFile($file) ?? $this->localForFile($file);
    }

    private function remoteForFile(File $file): ?AudioFingerprint
    {
        if (! (bool) config('services.audio_metadata.remote_fingerprinting_enabled', true) || ! $this->remoteProcessor->enabled()) {
            return null;
        }

        try {
            $inputPath = $this->mediaProcessorPaths->managedPath($file->path);
            $task = $this->remoteProcessor->submit(
                $file,
                MediaProcessorOperation::AUDIO_FINGERPRINT,
                $inputPath,
                [],
                ['engine' => 'chromaprint'],
            );
        } catch (Throwable) {
            return null;
        }

        $fingerprint = $this->waitForRemoteFingerprint($task);
        if ($fingerprint) {
            $this->redactStoredRemoteFingerprint($task, $fingerprint);
        }

        return $fingerprint;
    }

    private function localForFile(File $file): ?AudioFingerprint
    {
        if (! (bool) config('services.audio_metadata.local_fingerprinting_enabled', true)) {
            return null;
        }

        $resolved = AtlasPathResolver::resolveExistingPath($file->path);
        if (! $resolved || ! is_file($resolved['full_path'])) {
            return null;
        }

        $fpcalc = trim((string) config('services.audio_metadata.fpcalc_path', 'fpcalc'));
        if ($fpcalc === '') {
            return null;
        }

        try {
            $process = new Process([
                $fpcalc,
                '-json',
                $resolved['full_path'],
            ]);
            $process->setTimeout((float) config('services.audio_metadata.fpcalc_timeout_seconds', 45));
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        return $this->parseOutput($process->getOutput(), $resolved['full_path']);
    }

    private function waitForRemoteFingerprint(MediaProcessorTask $task): ?AudioFingerprint
    {
        $timeoutSeconds = max(1, (int) config('services.audio_metadata.remote_fingerprint_timeout_seconds', 75));
        $sleepMilliseconds = max(50, min(1000, (int) config('services.audio_metadata.remote_fingerprint_poll_milliseconds', 250)));
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $task->refresh();

            if ($task->status === MediaProcessorTaskStatus::COMPLETED) {
                return $this->fromRemoteResult($task);
            }

            if ($task->status === MediaProcessorTaskStatus::FAILED) {
                return null;
            }

            usleep($sleepMilliseconds * 1000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function fromRemoteResult(MediaProcessorTask $task): ?AudioFingerprint
    {
        $result = is_array($task->result) ? $task->result : [];
        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $fingerprint = $this->cleanString($metadata['fingerprint'] ?? null);
        $duration = $this->positiveInteger($metadata['duration_seconds'] ?? $metadata['duration'] ?? null);

        if ($fingerprint === null || $duration === null) {
            return null;
        }

        return new AudioFingerprint(
            $fingerprint,
            $duration,
            (string) $task->input_path,
            $this->cleanString($metadata['engine'] ?? null) ?? 'chromaprint',
        );
    }

    private function redactStoredRemoteFingerprint(MediaProcessorTask $task, AudioFingerprint $fingerprint): void
    {
        try {
            $task->refresh();
            $result = is_array($task->result) ? $task->result : [];
            $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
            unset($metadata['fingerprint']);
            $metadata['fingerprint_size'] = $fingerprint->fingerprintSize();
            $metadata['duration_seconds'] = $fingerprint->durationSeconds;
            $metadata['engine'] = $fingerprint->engine;
            $task->forceFill(['result' => [...$result, 'metadata' => $metadata]])->save();
        } catch (Throwable) {
            // The fingerprint has already been extracted for this run; stale task metadata is non-critical.
        }
    }

    private function parseOutput(string $output, string $path): ?AudioFingerprint
    {
        $payload = json_decode($output, true);
        if (is_array($payload)) {
            return $this->fromPayload($payload, $path);
        }

        $payload = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $payload[mb_strtolower(trim($key))] = trim($value);
        }

        return $this->fromPayload($payload, $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fromPayload(array $payload, string $path): ?AudioFingerprint
    {
        $fingerprint = $this->cleanString($payload['fingerprint'] ?? null);
        $duration = $this->positiveInteger($payload['duration'] ?? null);

        if ($fingerprint === null || $duration === null) {
            return null;
        }

        return new AudioFingerprint($fingerprint, $duration, $path);
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = trim((string) $value);

        return $clean !== '' ? $clean : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) round((float) $value);

        return $value > 0 ? $value : null;
    }
}
