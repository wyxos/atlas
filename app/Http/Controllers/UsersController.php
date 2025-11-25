<?php

namespace App\Http\Controllers;

use App\Listings\UserListing;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class UsersController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index(UserListing $listing): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        return response()->json($listing->handle());
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }
}
