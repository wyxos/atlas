<?php

namespace App\Services\Audio;

use App\Ai\Agents\AudioMetadataReviewAgent;
use Laravel\Ai\AiManager;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;

class AudioMetadataAiGatewayClient
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function review(string $baseUrl, array $input, string $prompt, string $schemaVersion): array
    {
        $this->configureProvider($baseUrl);

        $response = AudioMetadataReviewAgent::make($schemaVersion)->prompt(
            $prompt,
            provider: $this->providerName(),
            model: $this->model(),
            timeout: $this->timeoutSeconds(),
        );

        return $this->jsonPayload($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonPayload(mixed $payload): array
    {
        if ($payload instanceof TextResponse) {
            return $this->decodeJson($payload->text);
        }

        if (is_array($payload) && isset($payload['output']) && is_array($payload['output'])) {
            return $this->decodeJson($this->responseOutputText($payload['output']));
        }

        if (is_array($payload) && isset($payload['choices'][0]['message']['content']) && is_string($payload['choices'][0]['message']['content'])) {
            return $this->decodeJson($payload['choices'][0]['message']['content']);
        }

        if (is_array($payload) && isset($payload['message']['content']) && is_string($payload['message']['content'])) {
            return $this->decodeJson($payload['message']['content']);
        }

        if (is_array($payload) && isset($payload['response']) && is_string($payload['response'])) {
            return $this->decodeJson($payload['response']);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('AI response was not JSON.');
        }

        return $payload;
    }

    private function configureProvider(string $baseUrl): void
    {
        $provider = $this->providerName();

        config([
            'ai.providers.'.$provider => array_replace_recursive(
                (array) config('ai.providers.'.$provider, []),
                [
                    'driver' => 'openai',
                    'key' => (string) config('services.audio_metadata.ai_token', ''),
                    'url' => $this->baseUrl($baseUrl),
                    'models' => [
                        'text' => [
                            'default' => $this->model(),
                        ],
                    ],
                ],
            ),
        ]);

        app(AiManager::class)->forgetInstance($provider);
    }

    private function providerName(): string
    {
        return (string) config('services.audio_metadata.ai_provider', 'audio_metadata');
    }

    private function model(): string
    {
        return (string) config('services.audio_metadata.ai_model', 'local-fast');
    }

    private function timeoutSeconds(): int
    {
        return (int) config('services.audio_metadata.ai_timeout_seconds', 90);
    }

    private function baseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        return str_ends_with($baseUrl, '/v1') ? $baseUrl : $baseUrl.'/v1';
    }

    /**
     * @param  array<int, mixed>  $output
     */
    private function responseOutputText(array $output): string
    {
        $lastOutput = end($output);

        if (is_array($lastOutput) && is_string($lastOutput['content'][0]['text'] ?? null)) {
            return $lastOutput['content'][0]['text'];
        }

        throw new RuntimeException('AI response JSON could not be decoded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $content): array
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('AI response JSON could not be decoded.');
        }

        return $decoded;
    }
}
