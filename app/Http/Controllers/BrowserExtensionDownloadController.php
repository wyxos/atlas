<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class BrowserExtensionDownloadController extends Controller
{
    public function download(): Response
    {
        $downloadsDirectory = public_path('downloads');
        if (! is_dir($downloadsDirectory)) {
            abort(404, 'Extension download is not available yet.');
        }

        $extensionArchives = glob($downloadsDirectory.DIRECTORY_SEPARATOR.'atlas-extension*.zip') ?: [];
        if ($extensionArchives === []) {
            abort(404, 'Extension download is not available yet.');
        }

        usort($extensionArchives, static function (string $left, string $right): int {
            $leftModifiedAt = filemtime($left) ?: 0;
            $rightModifiedAt = filemtime($right) ?: 0;

            return $rightModifiedAt <=> $leftModifiedAt;
        });

        $latestArchivePath = $extensionArchives[0];

        return response()->download(
            $latestArchivePath,
            basename($latestArchivePath),
            [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ],
        );
    }
}
