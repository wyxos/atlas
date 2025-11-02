<?php

namespace App\Http\Controllers;

use App\Models\ModerationRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModerationRuleController extends Controller
{
    /**
     * Return a list of moderation rules. Optional filter: ?nsfw=0|1
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $query = ModerationRule::query()->orderByDesc('id');

        if ($request->has('nsfw')) {
            $nsfw = $request->boolean('nsfw');
            $query->where('nsfw', $nsfw);
        }

        return response()->json([
            'rules' => $query->get(),
        ]);
    }

    /**
     * Create a new moderation rule.
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $this->validateRule($request);

        $rule = ModerationRule::create($data);

        return response()->json(['rule' => $rule], 201);
    }

    /**
     * Update an existing moderation rule.
     */
    public function update(Request $request, ModerationRule $rule): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $this->validateRule($request, isUpdate: true);

        $rule->fill($data)->save();

        return response()->json(['rule' => $rule]);
    }

    /**
     * Delete a moderation rule.
     */
    public function destroy(Request $request, ModerationRule $rule): JsonResponse
    {
        $this->ensureAdmin($request);

        $rule->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Shared validator for ModerationRule payloads.
     */
    private function validateRule(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'name' => ['nullable', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
            'nsfw' => ['required', 'boolean'],
            'op' => ['required', 'string', 'in:any,all,not_any,at_least,and,or'],
            'terms' => ['nullable', 'array'],
            'terms.*' => ['string'],
            'min' => ['nullable', 'integer', 'min:1'],
            'options' => ['nullable', 'array'],
            'options.case_sensitive' => ['nullable', 'boolean'],
            'options.whole_word' => ['nullable', 'boolean'],
            'children' => ['nullable', 'array'],
        ];

        $data = $request->validate($rules);

        // Additional conditional: min is required when op is at_least
        $op = (string) ($data['op'] ?? 'any');
        if ($op === 'at_least' && empty($data['min'])) {
            $request->validate(['min' => ['required', 'integer', 'min:1']]);
            $data['min'] = (int) $request->input('min');
        }

        // Normalize arrays/objects to null when empty
        if (array_key_exists('terms', $data) && (is_array($data['terms']) && count($data['terms']) === 0)) {
            $data['terms'] = null;
        }
        if (array_key_exists('children', $data) && (is_array($data['children']) && count($data['children']) === 0)) {
            $data['children'] = null;
        }
        if (array_key_exists('options', $data) && (is_array($data['options']) && count($data['options']) === 0)) {
            $data['options'] = null;
        }

        return $data;
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) ($request->user()?->is_admin ?? false), 403);
    }
}
