<?php

namespace App\Services\MediaProcessing;

use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Models\File;
use App\Models\LibraryScanMediaTask;
use App\Models\MediaProcessorTask;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class RemoteMediaProcessorClient
{
    public function __construct(
        private readonly MediaProcessorPathValidator $paths,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('media_processor.enabled')
            && $this->baseUrl() !== null
            && $this->secret() !== null;
    }

    /**
     * @param  array<string, string>  $outputPaths
     * @param  array<string, mixed>  $options
     */
    public function submit(
        File $file,
        string $operation,
        string $inputPath,
        array $outputPaths,
        array $options = [],
        ?LibraryScanMediaTask $libraryScanMediaTask = null,
    ): MediaProcessorTask {
        if (! $this->enabled()) {
            throw new RuntimeException('Remote media processor is not configured.');
        }

        if (! in_array($operation, MediaProcessorOperation::all(), true)) {
            throw new InvalidArgumentException('Unsupported media processor operation.');
        }

        $inputPath = $this->paths->managedPath($inputPath);
        $outputPaths = $this->paths->outputPaths($outputPaths);
        $taskId = (string) Str::uuid();

        $task = MediaProcessorTask::query()->create([
            'id' => $taskId,
            'file_id' => $file->id,
            'library_scan_media_task_id' => $libraryScanMediaTask?->id,
            'operation' => $operation,
            'status' => MediaProcessorTaskStatus::SUBMITTING,
            'phase' => 'submitting',
            'progress' => 0,
            'processor_url' => $this->baseUrl(),
            'storage_profile' => $this->storageProfile(),
            'atlas_instance' => $this->atlasInstance(),
            'input_path' => $inputPath,
            'output_paths' => $outputPaths,
            'options' => $options,
            'attempts' => 1,
        ]);

        $payload = [
            'task_id' => $task->id,
            'atlas_instance' => $this->atlasInstance(),
            'storage_profile' => $this->storageProfile(),
            'operation' => $operation,
            'input_path' => $inputPath,
            'output_paths' => (object) $outputPaths,
            'options' => $options,
            'callback_url' => URL::to("/api/media-processor/tasks/{$task->id}/events"),
            'websocket_required' => (bool) config('media_processor.websocket_required', true),
        ];

        try {
            $this->sendSigned('POST', '/tasks', $payload);
        } catch (RequestException $e) {
            $this->markSubmissionFailed($task, $e);

            throw new RuntimeException('Remote media processor rejected the task.', previous: $e);
        }

        $task->update([
            'status' => MediaProcessorTaskStatus::QUEUED,
            'phase' => 'queued',
            'progress' => 1,
            'submitted_at' => now(),
            'last_event_at' => now(),
        ]);

        return $task->fresh() ?? $task;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(MediaProcessorTask $task): array
    {
        $response = $this->sendSigned('GET', "/tasks/{$task->id}");

        return $response;
    }

    public function verifyIncoming(Request $request): void
    {
        $secret = $this->secret();
        if ($secret === null) {
            throw new RuntimeException('Remote media processor secret is not configured.');
        }

        $timestamp = (string) $request->header('X-Atlas-Timestamp', '');
        $signature = (string) $request->header('X-Atlas-Signature', '');
        $body = $request->getContent();
        $expected = $this->signature($request->method(), $request->getRequestUri(), $body, $timestamp, $secret);

        if ($timestamp === '' || $signature === '' || ! hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid media processor signature.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function sendSigned(string $method, string $path, ?array $payload = null): array
    {
        $baseUrl = $this->baseUrl();
        $secret = $this->secret();
        if ($baseUrl === null || $secret === null) {
            throw new RuntimeException('Remote media processor is not configured.');
        }

        $url = rtrim($baseUrl, '/').$path;
        $body = $payload === null
            ? ''
            : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $headers = [
            'Accept' => 'application/json',
            'X-Atlas-Timestamp' => $timestamp,
            'X-Atlas-Signature' => $this->signature($method, $path, $body, $timestamp, $secret),
        ];

        $request = Http::timeout((int) config('media_processor.timeout_seconds', 15))
            ->withHeaders($headers);

        $response = $method === 'GET'
            ? $request->get($url)
            : $request->withBody($body, 'application/json')->send($method, $url);

        $response->throw();

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function signature(string $method, string $path, string $body, string $timestamp, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', strtoupper($method)."\n{$path}\n{$timestamp}\n{$body}", $secret);
    }

    private function markSubmissionFailed(MediaProcessorTask $task, \Throwable $e): void
    {
        $task->update([
            'status' => MediaProcessorTaskStatus::FAILED,
            'phase' => 'submission_failed',
            'progress' => 100,
            'failed_at' => now(),
            'last_event_at' => now(),
            'error_code' => 'remote_submission_failed',
            'error_message' => $e->getMessage(),
        ]);
    }

    private function baseUrl(): ?string
    {
        $url = trim((string) config('media_processor.url'));

        return $url !== '' ? $url : null;
    }

    private function secret(): ?string
    {
        $secret = trim((string) config('media_processor.secret'));

        return $secret !== '' ? $secret : null;
    }

    private function storageProfile(): string
    {
        return trim((string) config('media_processor.storage_profile')) ?: 'atlas-local';
    }

    private function atlasInstance(): string
    {
        return trim((string) config('media_processor.instance')) ?: 'local';
    }
}
