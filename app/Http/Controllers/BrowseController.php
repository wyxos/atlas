<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrowseController extends Controller
{
    /**
     * Get a page of browse items.
     */
    public function index(Request $request): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $itemsPerPage = 40;
        $totalPages = 100;

        $items = [];

        for ($i = 0; $i < $itemsPerPage; $i++) {
            $indexOffset = ($page - 1) * $itemsPerPage + $i;
            $width = rand(200, 500);
            $height = rand(200, 500);

            // Determine item type (90% images, 5% videos, 5% special cases)
            $rand = rand(1, 100);
            $type = 'image';
            $notFound = false;

            if ($rand <= 5) {
                $type = 'video';
            } elseif ($rand <= 7) {
                $notFound = true;
            } elseif ($rand <= 9) {
                // Invalid URL for error testing
                $src = "https://invalid-domain-that-does-not-exist-{$indexOffset}.com/image.jpg";
            }

            if (! isset($src)) {
                if ($type === 'video') {
                    $src = 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4';
                } else {
                    $src = "https://picsum.photos/id/{$indexOffset}/{$width}/{$height}";
                }
            }

            $items[] = [
                'id' => "item-{$page}-{$i}",
                'width' => $width,
                'height' => $height,
                'src' => $src,
                'type' => $type,
                'page' => $page,
                'index' => $i,
                'notFound' => $notFound,
            ];
        }

        return response()->json([
            'items' => $items,
            'nextPage' => $page < $totalPages ? $page + 1 : null,
        ]);
    }
}
