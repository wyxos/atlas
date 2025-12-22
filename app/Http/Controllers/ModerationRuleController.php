<?php

namespace App\Http\Controllers;

use App\Enums\ActionType;
use App\Models\ModerationRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModerationRuleController extends Controller
{
    /**
     * Get all moderation rules.
     */
    public function index(): JsonResponse
    {
        $rules = ModerationRule::orderBy('name')->get();

        return response()->json($rules);
    }

    /**
     * Create a new moderation rule.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'active' => ['boolean'],
            'nsfw' => ['boolean'],
            'action_type' => ['nullable', 'string', 'in:ui_countdown,auto_dislike,blacklist'],
            'op' => ['required', 'string', 'in:any,all,not_any,at_least,and,or'],
            'terms' => ['nullable', 'array'],
            'terms.*' => ['nullable'], // Can be string or object with term and allow_digit_prefix
            'min' => ['nullable', 'integer', 'min:1'],
            'options' => ['nullable', 'array'],
            'options.case_sensitive' => ['boolean'],
            'options.whole_word' => ['boolean'],
            'children' => ['nullable', 'array'],
        ]);

        $rule = ModerationRule::create([
            'name' => $validated['name'] ?? null,
            'active' => $validated['active'] ?? true,
            'nsfw' => $validated['nsfw'] ?? false,
            'action_type' => $validated['action_type'] ?? 'ui_countdown',
            'op' => $validated['op'],
            'terms' => $validated['terms'] ?? null,
            'min' => $validated['min'] ?? null,
            'options' => $validated['options'] ?? null,
            'children' => $validated['children'] ?? null,
        ]);

        return response()->json($rule, 201);
    }

    /**
     * Get a specific moderation rule.
     */
    public function show(ModerationRule $moderationRule): JsonResponse
    {
        return response()->json($moderationRule);
    }

    /**
     * Update an existing moderation rule.
     */
    public function update(Request $request, ModerationRule $moderationRule): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'active' => ['boolean'],
            'nsfw' => ['boolean'],
            'action_type' => ['nullable', 'string', 'in:ui_countdown,auto_dislike,blacklist'],
            'op' => ['string', 'in:any,all,not_any,at_least,and,or'],
            'terms' => ['nullable', 'array'],
            'terms.*' => ['nullable'], // Can be string or object with term and allow_digit_prefix
            'min' => ['nullable', 'integer', 'min:1'],
            'options' => ['nullable', 'array'],
            'options.case_sensitive' => ['boolean'],
            'options.whole_word' => ['boolean'],
            'children' => ['nullable', 'array'],
        ]);

        $moderationRule->update($validated);

        return response()->json($moderationRule);
    }

    /**
     * Delete a moderation rule.
     */
    public function destroy(ModerationRule $moderationRule): JsonResponse
    {
        $moderationRule->delete();

        return response()->json(['message' => 'Moderation rule deleted successfully']);
    }

    /**
     * Test text against a rule and return match results.
     * Accepts rule_id to test an existing rule.
     */
    public function testRule(Request $request): JsonResponse
    {
        $request->validate([
            'text' => ['required', 'string'],
            'rule_id' => ['required', 'integer', 'exists:moderation_rules,id'],
        ]);

        $text = $request->string('text')->toString();
        $rule = ModerationRule::findOrFail($request->integer('rule_id'));

        $moderator = new \App\Services\Moderation\Moderator;
        $moderator->loadRule($rule);

        $matches = $moderator->check($text);
        $hits = $moderator->collectMatches($text);

        return response()->json([
            'matches' => $matches,
            'hits' => $hits,
            'rule' => $rule,
        ]);
    }
}
