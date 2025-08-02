<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CivitaiTestController extends Controller
{
    public function testQuery(): array
    {
        // users

        // collections

        // models
        $response = Http::get('https://civitai.com/api/trpc/model.getAll', [
            'input' => json_encode([
                'json' => [
                    'period' => 'Month',
                    'periodMode' => 'published',
                    'sort' => 'Highest Rated',
                    'pending' => false,
                    'browsingLevel' => 31,
                    'excludedTagIds' => [
                        415792, 426772, 5188, 5249, 130818, 130820, 133182,
                        5351, 306619, 154326, 161829, 163032
                    ],
                    'disablePoi' => true,
                    'disableMinor' => true,
                    'cursor' => null,
                    'authed' => true,
                ],
                'meta' => [
                    'values' => [
                        'cursor' => ['undefined']
                    ]
                ]
            ])
        ]);

        // images
        $response = Http::get('https://civitai.com/api/trpc/image.getInfinite', [
            'input' => json_encode([
                'json' => [
                    'period' => 'AllTime',
                    'sort' => 'Newest',
                    'types' => ['image', 'video'],
                    'withMeta' => false,
                    'followed' => true,
                    'useIndex' => true,
                    'browsingLevel' => 31,
                    'include' => ['cosmetics'],
                    'excludedTagIds' => [
                        415792, 426772, 5188, 5249, 130818, 130820, 133182,
                        5351, 306619, 154326, 161829, 163032
                    ],
                    'disablePoi' => true,
                    'disableMinor' => true,
                    'cursor' => null,
                    'authed' => true,
                ],
                'meta' => [
                    'values' => [
                        'cursor' => ['undefined']
                    ]
                ]
            ])
        ]);

        // posts
        $url = "https://civitai.com/api/trpc/post.getInfinite";

        $queryParams = [
            'input' => json_encode([
                'json' => [
                    'browsingLevel' => 31,
                    'period' => 'Week',
                    'periodMode' => 'published',
                    'sort' => 'Newest',
                    'include' => ['cosmetics'],
                    'excludedTagIds' => [415792, 426772, 5188, 5249, 130818, 130820, 133182, 5351, 306619, 154326, 161829, 163032],
                    'disablePoi' => true,
                    'disableMinor' => true,
                    'cursor' => '2025-07-31T20:33:57.046Z',
                    'authed' => true
                ],
                'meta' => [
                    'values' => [
                        'cursor' => ['Date']
                    ]
                ]
            ])
        ];

        try {
            $response = Http::timeout(30)
                ->get($url, $queryParams);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => $response->status(),
                    'data' => $response->json(),
                    'headers' => $response->headers()
                ];
            } else {
                return [
                    'success' => false,
                    'status' => $response->status(),
                    'error' => $response->body(),
                    'headers' => $response->headers()
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }
}
