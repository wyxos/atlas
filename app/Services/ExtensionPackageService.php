<?php

namespace App\Services;

use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RuntimeException;

class ExtensionPackageService
{
    public function package(bool $force = false): array
    {
        $extensionPath = base_path('extension/atlas-downloader');
        $manifestPath = $extensionPath.'/manifest.json';

        if (! is_dir($extensionPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('Extension folder not found.');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $version = is_array($manifest) && isset($manifest['version'])
            ? (string) $manifest['version']
            : 'dev';

        $filename = "atlas-extension-{$version}.zip";
        $zipPath = storage_path("app/{$filename}");

        $latestChange = $this->latestMtime($extensionPath);
        $needsRebuild = $force || ! is_file($zipPath) || filemtime($zipPath) < $latestChange;

        if ($needsRebuild) {
            $this->buildZip($extensionPath, $zipPath);
        }

        return [
            'path' => $zipPath,
            'filename' => $filename,
            'version' => $version,
        ];
    }

    private function buildZip(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create extension archive.');
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $filePath = $file->getRealPath();
            if (! $filePath) {
                continue;
            }

            $relative = ltrim(str_replace($sourceDir, '', $filePath), DIRECTORY_SEPARATOR);
            $zip->addFile($filePath, $relative);
        }

        $zip->close();
    }

    private function latestMtime(string $path): int
    {
        $latest = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $latest = max($latest, $file->getMTime());
        }

        return $latest;
    }
}
