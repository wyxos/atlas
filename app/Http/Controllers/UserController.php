<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index(): Response
    {
        $users = User::select('id', 'name', 'email', 'created_at')
            ->with(['loginHistories' => function ($query) {
                $query->latest()->limit(1);
            }])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        // Transform the users to include last login time
        $users->through(function ($user) {
            $lastLogin = $user->loginHistories->first();
            $user->last_login_at = $lastLogin ? $lastLogin->created_at : null;
            $user->last_login_ip = $lastLogin ? $lastLogin->ip_address : null;

            // Remove the loginHistories relationship from the response
            unset($user->loginHistories);

            return $user;
        });

        return Inertia::render('Users/Index', [
            'users' => $users,
        ]);
    }
}
