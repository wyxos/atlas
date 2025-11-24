<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class UsersController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $perPage = request()->integer('per_page', 15);
        $query = User::query();

        // Search filter (name or email)
        if (request()->has('search') && request()->string('search')->isNotEmpty()) {
            $search = request()->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Date range filter (created_at)
        if (request()->has('date_from')) {
            $dateFrom = request()->string('date_from')->toString();
            if ($dateFrom !== '') {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
        }

        if (request()->has('date_to')) {
            $dateTo = request()->string('date_to')->toString();
            if ($dateTo !== '') {
                $query->whereDate('created_at', '<=', $dateTo);
            }
        }

        // Status filter (verified/unverified)
        if (request()->has('status')) {
            $status = request()->string('status')->toString();
            if ($status === 'verified') {
                $query->whereNotNull('email_verified_at');
            } elseif ($status === 'unverified') {
                $query->whereNull('email_verified_at');
            }
        }

        $users = $query->orderBy('name')->paginate($perPage);

        return UserResource::collection($users);
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
