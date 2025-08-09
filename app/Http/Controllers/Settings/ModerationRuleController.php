<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ModerationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as ValidationRule;
use Inertia\Inertia;
use Inertia\Response;

class ModerationRuleController extends Controller
{
    public function index(): Response
    {
        $rules = ModerationRule::orderByDesc('id')->get();

        return Inertia::render('settings/Moderation', [
            'rules' => $rules,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        ModerationRule::create($data);
        return back();
    }

    public function update(Request $request, ModerationRule $rule): RedirectResponse
    {
        $data = $this->validateData($request, $rule->id);
        $rule->update($data);
        return back();
    }

    public function destroy(ModerationRule $rule): RedirectResponse
    {
        $rule->delete();
        return back();
    }

    protected function validateData(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', ValidationRule::in(['contains', 'contains-combo'])],
            'terms' => ['required', 'array', 'min:1'],
            'terms.*' => ['string', 'min:1'],
            'match' => ['nullable', ValidationRule::in(['any', 'all'])],
            'unless' => ['nullable', 'array'],
            'unless.*' => ['string', 'min:1'],
            'with_terms' => ['nullable', 'array'],
            'with_terms.*' => ['string', 'min:1'],
            'action' => ['required', ValidationRule::in(['block', 'flag', 'warn'])],
            'active' => ['required', 'boolean'],
            'description' => ['nullable', 'string'],
        ]);

        // Normalize based on type
        if ($data['type'] === 'contains') {
            $data['with_terms'] = null;
            $data['match'] = $data['match'] ?? 'any';
        }
        if ($data['type'] === 'contains-combo') {
            $data['match'] = 'any';
            $data['unless'] = null;
        }

        return $data;
    }
}
