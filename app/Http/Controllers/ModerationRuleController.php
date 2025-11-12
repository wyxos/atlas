<?php

namespace App\Http\Controllers;

use App\Models\ModerationRule;
use App\Services\Moderation\Moderator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
     * Show the rule testing page.
     */
    public function test(Request $request): Response
    {
        $this->ensureAdmin($request);

        $rules = ModerationRule::query()->orderByDesc('id')->get();

        return Inertia::render('Moderation/Test', [
            'rules' => $rules,
        ]);
    }

    /**
     * Test text against a rule and return match results.
     * Accepts either rule_id (to test existing rule) or rule object (to test edited rule).
     */
    public function testRule(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $request->validate([
            'text' => ['required', 'string'],
            'rule_id' => ['nullable', 'integer', 'exists:moderation_rules,id'],
            'rule' => ['nullable', 'array'],
        ]);

        $text = $request->string('text')->toString();
        $moderator = new Moderator;

        // If rule object is provided, create a temporary rule model for testing
        if ($request->has('rule') && is_array($request->input('rule'))) {
            $ruleData = $request->input('rule');
            // Create a temporary rule model (not saved to database)
            $rule = new ModerationRule($ruleData);
            $rule->id = $ruleData['id'] ?? null; // Preserve ID if present
            $moderator->loadRule($rule);
        } elseif ($request->has('rule_id')) {
            $rule = ModerationRule::findOrFail($request->integer('rule_id'));
            $moderator->loadRule($rule);
        } else {
            return response()->json(['error' => 'Either rule_id or rule object is required'], 400);
        }

        $matches = $moderator->check($text);
        $hits = $moderator->collectMatches($text);

        return response()->json([
            'matches' => $matches,
            'hits' => $hits,
            'rule' => $rule,
        ]);
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
            'terms.*' => ['nullable'], // Can be string or array with 'term' and 'allow_digit_prefix'
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
