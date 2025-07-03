<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class UserController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index(): Response
    {
        // Check if the user is an admin
        if (!Auth::user()->is_admin) {
            abort(HttpResponse::HTTP_FORBIDDEN, 'Access denied. Only admins can access this page.');
        }
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

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user): Response
    {
        // Check if the user is an admin
        if (!Auth::user()->is_admin) {
            abort(HttpResponse::HTTP_FORBIDDEN, 'Access denied. Only admins can access this page.');
        }

        return Inertia::render('Users/Edit', [
            'user' => $user,
        ]);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        // Check if the user is an admin
        if (!Auth::user()->is_admin) {
            abort(HttpResponse::HTTP_FORBIDDEN, 'Access denied. Only admins can update users.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
        ]);

        $user->update($validated);

        return redirect()->route('users.index')->with('success', 'User updated successfully.');
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user)
    {
        // Check if the user is an admin
        if (!Auth::user()->is_admin) {
            abort(HttpResponse::HTTP_FORBIDDEN, 'Access denied. Only admins can delete users.');
        }

        // Prevent deleting the currently authenticated user
        if (auth()->id() === $user->id) {
            return redirect()->route('users.index')->with('error', 'You cannot delete your own account.');
        }

        // Prevent deleting admin users (if not an admin)
        if ($user->is_admin && !auth()->user()->is_admin) {
            return redirect()->route('users.index')->with('error', 'You do not have permission to delete an admin.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted successfully.');
    }
}
