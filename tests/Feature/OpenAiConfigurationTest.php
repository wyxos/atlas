<?php

it('routes audio metadata directly to OpenAI', function () {
    $envExample = file_get_contents(base_path('.env.example'));

    expect(config('ai.providers.audio_metadata.url'))->toBe('https://api.openai.com/v1')
        ->and(config('ai.providers.audio_metadata.models.text.default'))->toBe('gpt-5.6-sol')
        ->and(config('services.audio_metadata.ai_base_url'))->toBe('https://api.openai.com/v1')
        ->and(config('services.audio_metadata.ai_model'))->toBe('gpt-5.6-sol')
        ->and($envExample)->not->toContain('ai.wyxos.com', 'LITELLM_', 'OLLAMA_', 'AUDIO_METADATA_AI_BASE_URL', 'AUDIO_METADATA_AI_TOKEN');
});
