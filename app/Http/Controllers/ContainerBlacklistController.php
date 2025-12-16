<?php

namespace App\Http\Controllers;

use App\Models\Container;
use App\Services\BaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContainerBlacklistController extends Controller
{
    /**
     * Get all blacklisted containers.
     */
    public function index(): JsonResponse
    {
        $containers = Container::whereNotNull('blacklisted_at')
            ->orderBy('blacklisted_at', 'desc')
            ->get();

        return response()->json($containers);
    }

    /**
     * Create or update a container blacklist entry.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'container_id' => ['required', 'integer', 'exists:containers,id'],
            'action_type' => ['required', 'string', 'in:ui_countdown,auto_dislike,blacklist'],
        ]);

        $container = Container::findOrFail($validated['container_id']);

        // Check if container type is blacklistable
        $service = $this->getServiceForContainer($container);
        $blacklistableTypes = $service->getBlacklistableContainerTypes();

        if (! in_array($container->type, $blacklistableTypes, true)) {
            return response()->json([
                'message' => "Container type '{$container->type}' is not blacklistable.",
            ], 422);
        }

        $container->update([
            'action_type' => $validated['action_type'],
            'blacklisted_at' => now(),
        ]);

        return response()->json($container, 201);
    }

    /**
     * Check if a container is blacklisted.
     */
    public function check(Container $container): JsonResponse
    {
        return response()->json([
            'blacklisted' => $container->blacklisted_at !== null,
            'blacklisted_at' => $container->blacklisted_at?->toIso8601String(),
            'action_type' => $container->action_type,
        ]);
    }

    /**
     * Remove a container from blacklist (whitelist).
     */
    public function destroy(Container $container): JsonResponse
    {
        if ($container->blacklisted_at === null) {
            return response()->json(['message' => 'Container is not blacklisted'], 404);
        }

        $container->update([
            'blacklisted_at' => null,
            'action_type' => null,
        ]);

        return response()->json(['message' => 'Container removed from blacklist successfully']);
    }

    /**
     * Get the service instance for a container based on its source.
     */
    private function getServiceForContainer(Container $container): BaseService
    {
        // Get available services (using reflection to access protected method)
        $browser = new \App\Browser;
        $reflection = new \ReflectionClass($browser);
        $method = $reflection->getMethod('getAvailableServices');
        $method->setAccessible(true);
        $services = $method->invoke($browser);

        // Find service by source - try matching by source name first, then by key
        foreach ($services as $key => $serviceClass) {
            $serviceInstance = app($serviceClass);
            // Match by source name (e.g., "CivitAI") - this is what's stored in containers.source
            if ($serviceInstance::source() === $container->source) {
                return $serviceInstance;
            }
            // Also try matching by key (e.g., "civit-ai-images") in case source is stored as key
            if ($key === $container->source) {
                return $serviceInstance;
            }
        }

        // Fallback to default service
        return app(\App\Services\CivitAiImages::class);
    }
}
