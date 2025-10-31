<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! $user || ! ($user->is_admin ?? false)) {
            abort(403);
        }

        $users = User::query()
            ->select(['id', 'name', 'email', 'created_at'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('users/Index', [
            'users' => $users,
        ]);
    }
}
