<?php

namespace App\Http\Requests;

use App\Services\Moderation\FeedRemovalBackfillService;
use Illuminate\Foundation\Http\FormRequest;

class StartModerationFeedRemovalRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'chunk_size' => ['nullable', 'integer', 'min:1', 'max:'.FeedRemovalBackfillService::MAX_CHUNK_SIZE],
        ];
    }
}
