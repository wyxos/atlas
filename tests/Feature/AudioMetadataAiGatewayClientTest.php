<?php

use App\Services\Audio\AudioMetadataAiGatewayClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('gateway client uses laravel ai responses transport and parses assistant json', function () {
    config([
        'services.audio_metadata.ai_provider' => 'audio_metadata',
        'services.audio_metadata.ai_base_url' => 'https://ollama.test/v1',
        'services.audio_metadata.ai_model' => 'qwen-test',
        'services.audio_metadata.ai_token' => 'ai-token',
    ]);

    $gatewayRequest = null;

    Http::fake(function (Request $request) use (&$gatewayRequest) {
        $gatewayRequest = $request->data();

        return Http::response([
            'id' => 'resp_test',
            'status' => 'completed',
            'model' => 'qwen-test',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'verdict' => 'accept',
                        'reason' => 'The candidate matches the supplied evidence.',
                        'model' => 'qwen-test',
                    ]),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 12,
                'output_tokens' => 8,
                'input_tokens_details' => [
                    'cached_tokens' => 0,
                ],
                'output_tokens_details' => [
                    'reasoning_tokens' => 0,
                ],
            ],
        ]);
    });

    $result = app(AudioMetadataAiGatewayClient::class)->review(
        'https://ollama.test/v1',
        ['candidate' => ['title' => 'Overture']],
        'Return JSON only.',
        'atlas-audio-metadata-review-v1',
    );

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ollama.test/v1/responses');

    expect($result)
        ->toMatchArray([
            'verdict' => 'accept',
            'reason' => 'The candidate matches the supplied evidence.',
            'model' => 'qwen-test',
        ])
        ->and($gatewayRequest['model'] ?? null)->toBe('qwen-test')
        ->and(data_get($gatewayRequest, 'metadata.schema_version'))->toBe('atlas-audio-metadata-review-v1')
        ->and(data_get($gatewayRequest, 'input.0.role'))->toBe('system')
        ->and(data_get($gatewayRequest, 'input.1.content.0.text'))->toBe('Return JSON only.')
        ->and($gatewayRequest['temperature'] ?? null)->toBe(0.0);
});
