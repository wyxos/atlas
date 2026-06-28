<?php

namespace App\Services\ComfyCompanion;

use App\Models\Tab;
use App\Models\User;
use App\Services\CivitAiImages;
use Illuminate\Support\Facades\DB;

class ComfyCompanionBrowseTabService
{
    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function openCivitAiModelTab(User $user, array $validated): array
    {
        $modelId = (int) $validated['model_id'];
        $modelVersionId = isset($validated['model_version_id']) ? (int) $validated['model_version_id'] : null;
        $nsfw = (bool) ($validated['nsfw'] ?? false);
        $params = array_filter([
            'feed' => 'online',
            'service' => CivitAiImages::key(),
            'page' => 1,
            'limit' => 20,
            'type' => 'all',
            'sort' => 'Newest',
            'period' => 'AllTime',
            'modelId' => $modelId,
            'modelVersionId' => $modelVersionId,
            'nsfw' => $nsfw ? true : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->tabPayload($this->create(
            $user,
            $this->modelLabel($modelId, $modelVersionId),
            $params,
        ));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function create(User $user, string $label, array $params): Tab
    {
        return DB::transaction(function () use ($user, $label, $params): Tab {
            $userId = (int) $user->id;
            $nextPosition = (Tab::forUser($userId)->max('position') ?? -1) + 1;

            Tab::forUser($userId)->update(['is_active' => false]);

            return Tab::query()->create([
                'user_id' => $userId,
                'label' => $label,
                'custom_label' => null,
                'params' => $params,
                'position' => $nextPosition,
                'is_active' => true,
            ]);
        });
    }

    private function modelLabel(int $modelId, ?int $modelVersionId): string
    {
        if ($modelVersionId !== null && $modelVersionId > 0) {
            return "CivitAI Images: Model {$modelId} @ {$modelVersionId} - 1";
        }

        return "CivitAI Images: Model {$modelId} - 1";
    }

    /**
     * @return array<string, mixed>
     */
    private function tabPayload(Tab $tab): array
    {
        return [
            'tab' => [
                'id' => $tab->id,
                'label' => $tab->label,
                'params' => $tab->params ?? [],
            ],
            'browse_url' => url('/browse'),
        ];
    }
}
