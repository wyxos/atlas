<?php

namespace App\Http\Controllers;

use App\Listings\UserListing;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UsersController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index(UserListing $listing): JsonResponse
    {
        return response()->json($listing->handle());
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }
}
