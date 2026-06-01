<?php

namespace App\Http\Requests;

use App\Services\Extension\ExtensionRequestAuthenticator;
use App\Services\ExtensionApiKeyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreExtensionSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = app(ExtensionRequestAuthenticator::class)->resolveUser(
            $this,
            app(ExtensionApiKeyService::class)
        );

        if (! $user) {
            return false;
        }

        $this->attributes->set('extension_user_id', $user->id);

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.version' => ['nullable', 'integer'],
            'settings.siteCustomizations' => ['nullable', 'array', 'max:250'],
            'settings.siteCustomizations.*' => ['array'],
            'settings.siteCustomizations.*.enabled' => ['nullable', 'boolean'],
            'settings.siteCustomizations.*.domain' => ['required', 'string', 'max:255'],
            'settings.siteCustomizations.*.matchRules' => ['nullable', 'array', 'max:100'],
            'settings.siteCustomizations.*.matchRules.*' => ['nullable', 'string', 'max:2048'],
            'settings.siteCustomizations.*.widget' => ['nullable', 'array'],
            'settings.siteCustomizations.*.widget.minImageWidth' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'settings.siteCustomizations.*.referrerCleaner' => ['nullable', 'array'],
            'settings.siteCustomizations.*.referrerCleaner.stripQueryParams' => ['nullable', 'array', 'max:100'],
            'settings.siteCustomizations.*.referrerCleaner.stripQueryParams.*' => ['string', 'max:255'],
            'settings.siteCustomizations.*.mediaCleaner' => ['nullable', 'array'],
            'settings.siteCustomizations.*.mediaCleaner.stripQueryParams' => ['nullable', 'array', 'max:100'],
            'settings.siteCustomizations.*.mediaCleaner.stripQueryParams.*' => ['string', 'max:255'],
            'settings.siteCustomizations.*.mediaCleaner.rewriteRules' => ['nullable', 'array', 'max:100'],
            'settings.siteCustomizations.*.mediaCleaner.rewriteRules.*' => ['array'],
            'settings.siteCustomizations.*.mediaCleaner.rewriteRules.*.pattern' => ['required', 'string', 'max:2048'],
            'settings.siteCustomizations.*.mediaCleaner.rewriteRules.*.replace' => ['nullable', 'string', 'max:2048'],
            'settings.siteCustomizations.*.mediaCleaner.strategies' => ['nullable', 'array', 'max:20'],
            'settings.siteCustomizations.*.mediaCleaner.strategies.*' => ['string', 'max:64'],
            'settings.closeTabAfterQueueByDomain' => ['nullable', 'array', 'max:250'],
            'settings.closeTabAfterQueueByDomain.*' => ['nullable'],
            'settings.reactAllItemsInPostByDomain' => ['nullable', 'array', 'max:250'],
            'settings.reactAllItemsInPostByDomain.*' => ['nullable'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Invalid extension API key.',
        ], 401));
    }
}
