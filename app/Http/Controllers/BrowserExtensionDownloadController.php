<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrowserExtensionDownloadController extends Controller
{
    public function download(): BinaryFileResponse
    {
        $downloadsDirectory = storage_path('app/browser-extension-downloads');
        if (! is_dir($downloadsDirectory)) {
            abort(404, 'Extension download is not available yet.');
        }

        $latestArchivePath = $downloadsDirectory.DIRECTORY_SEPARATOR.'atlas-extension.zip';
        if (! is_file($latestArchivePath)) {
            $extensionArchives = glob($downloadsDirectory.DIRECTORY_SEPARATOR.'atlas-extension-*.zip') ?: [];
            if ($extensionArchives === []) {
                abort(404, 'Extension download is not available yet.');
            }

            usort($extensionArchives, static function (string $left, string $right): int {
                $leftModifiedAt = filemtime($left) ?: 0;
                $rightModifiedAt = filemtime($right) ?: 0;

                return $rightModifiedAt <=> $leftModifiedAt;
            });

            $latestArchivePath = $extensionArchives[0];
        }

        if (! is_file($latestArchivePath)) {
            abort(404, 'Extension download is not available yet.');
        }

        return response()->download(
            $latestArchivePath,
            basename($latestArchivePath),
            [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ],
        );
    }
}
