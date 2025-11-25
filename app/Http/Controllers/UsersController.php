<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
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

        $result = $listing->handle();

        // Transform Harmonie's response format to match Laravel's pagination format
        $listingData = $result['listing'];
        $currentPage = request()->integer('page', 1);
        $perPage = $listingData['perPage'];
        $total = $listingData['total'];
        $lastPage = (int) ceil($total / $perPage);

        // Transform items using UserResource
        $data = collect($listingData['items'])->map(fn ($item) => new UserResource($item));

        // Preserve query parameters in pagination links
        $queryParams = request()->except('page');
        $buildUrl = function ($page) use ($queryParams) {
            $params = array_merge($queryParams, ['page' => $page]);

            return request()->url().'?'.http_build_query($params);
        };

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $currentPage,
                'from' => $listingData['showing'] - count($listingData['items']) + 1,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'to' => $listingData['showing'],
                'total' => $total,
            ],
            'links' => [
                'first' => $buildUrl(1),
                'last' => $buildUrl($lastPage),
                'prev' => $currentPage > 1 ? $buildUrl($currentPage - 1) : null,
                'next' => $listingData['nextPage'] ? $buildUrl($listingData['nextPage']) : null,
            ],
        ]);
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
